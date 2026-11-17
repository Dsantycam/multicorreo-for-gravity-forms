<?php
/**
 * The shuffle-bag: fair, atomic distribution of a list's slots.
 *
 * Every (list, form) pair has its own bag, so each form runs its own round:
 * the bag starts as the whole list (repetitions included), shuffled; every
 * new submission takes the next address; when the bag is empty it is refilled
 * and reshuffled. No slot repeats until the whole round has been handed out.
 * If the list content changes, the round restarts with the new list.
 *
 * Sticky: the same person (same email typed in the form) is sent to the same
 * address for 12 hours without spending a slot. Only a hash of the person's
 * email is stored, in a transient that expires on its own.
 *
 * Concurrency: the pop runs inside a MySQL named lock (GET_LOCK) and reads /
 * writes the option straight from the database, bypassing any object cache,
 * so two simultaneous submissions never take the same slot.
 *
 * @package MulticorreoGF
 */

defined( 'WPINC' ) || exit;

/**
 * Bag class.
 */
class MCGF_Bag {

	const PREFIX        = 'mcgf_bag_';
	const STICKY_PREFIX = 'mcgf_st_';

	/**
	 * Option name of a bag.
	 *
	 * @param int $list_id List.
	 * @param int $form_id Form.
	 * @return string
	 */
	public static function option( $list_id, $form_id ) {
		return self::PREFIX . (int) $list_id . '_' . (int) $form_id;
	}

	/**
	 * Hours a person stays with the same address.
	 *
	 * @return int
	 */
	public static function sticky_hours() {
		return max( 0, (int) apply_filters( 'mcgf_sticky_hours', 12 ) );
	}

	/**
	 * Take an address for a submission.
	 *
	 * @param array  $list    List (from MCGF_Lists).
	 * @param int    $form_id Form id.
	 * @param string $person  The submitter's email ('' = no sticky).
	 * @return array [ 'email' => string, 'how' => 'pop'|'sticky'|'none' ]
	 */
	public static function take( $list, $form_id, $person = '' ) {

		global $wpdb;

		$emails = isset( $list['emails'] ) ? array_values( $list['emails'] ) : array();

		if ( empty( $emails ) ) {
			return array(
				'email' => '',
				'how'   => 'none',
			);
		}

		$list_id = (int) $list['id'];
		$option  = self::option( $list_id, $form_id );
		$lock    = 'mcgf_' . $list_id . '_' . (int) $form_id;
		$locked  = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 5 ) );

		try {

			// Same person within the sticky window: same address, no slot spent.
			$sticky_key = '';
			$hours      = self::sticky_hours();
			$person     = MCGF_Lists::normalize( $person );

			if ( $hours > 0 && MCGF_Lists::is_email( $person ) ) {
				$sticky_key = self::STICKY_PREFIX . md5( $list_id . '|' . (int) $form_id . '|' . $person . '|' . wp_salt( 'auth' ) );
				$prev       = get_transient( $sticky_key );

				if ( is_string( $prev ) && in_array( $prev, $emails, true ) ) {
					return array(
						'email' => $prev,
						'how'   => 'sticky',
					);
				}
			}

			$bag   = self::read( $option );
			$sig   = MCGF_Lists::signature( $emails );
			$queue = ( isset( $bag['sig'] ) && $bag['sig'] === $sig && isset( $bag['queue'] ) && is_array( $bag['queue'] ) ) ? $bag['queue'] : array();
			$round = ( isset( $bag['sig'] ) && $bag['sig'] === $sig && isset( $bag['round'] ) ) ? (int) $bag['round'] : 0;

			// Only addresses of the list (a damaged bag can't hand out anything else).
			$queue = array_values( array_intersect( $queue, $emails ) );

			// Refill + shuffle when the round is over (or the list changed).
			if ( empty( $queue ) ) {
				$queue = $emails;
				shuffle( $queue );
				$round++;
			}

			$email = (string) array_shift( $queue );

			self::write(
				$option,
				array(
					'sig'   => $sig,
					'queue' => $queue,
					'round' => $round,
					'size'  => count( $emails ),
				)
			);

			if ( '' !== $sticky_key ) {
				set_transient( $sticky_key, $email, $hours * HOUR_IN_SECONDS );
			}

			return array(
				'email' => $email,
				'how'   => 'pop',
			);

		} finally {
			if ( $locked ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
	}

	/**
	 * Current state of a bag, for the panel.
	 *
	 * @param array $list    List.
	 * @param int   $form_id Form.
	 * @return array [ round, size, left, given, valid ]
	 */
	public static function state( $list, $form_id ) {

		$bag   = self::read( self::option( $list['id'], $form_id ) );
		$size  = count( $list['emails'] );
		$valid = isset( $bag['sig'] ) && MCGF_Lists::signature( $list['emails'] ) === $bag['sig'];
		$left  = $valid && isset( $bag['queue'] ) && is_array( $bag['queue'] ) ? count( array_intersect( $bag['queue'], $list['emails'] ) ) : 0;

		// A finished round (left = 0) or a changed list means the next
		// submission starts a fresh round.
		return array(
			'round' => $valid && isset( $bag['round'] ) ? (int) $bag['round'] : 0,
			'size'  => $size,
			'left'  => $left,
			'given' => $valid && $left > 0 ? $size - $left : ( $valid ? $size : 0 ),
			'valid' => $valid,
		);
	}

	/**
	 * Restart the rounds of a list (every form).
	 *
	 * @param int $list_id List.
	 * @return void
	 */
	public static function delete_list_bags( $list_id ) {
		global $wpdb;
		$like = $wpdb->esc_like( self::PREFIX . (int) $list_id . '_' ) . '%';
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		foreach ( (array) $names as $name ) {
			delete_option( $name );
		}
	}

	/**
	 * Read a bag straight from the DB (bypass any object cache).
	 *
	 * @param string $option Option.
	 * @return array
	 */
	private static function read( $option ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		$bag = $raw ? maybe_unserialize( $raw ) : array();
		return is_array( $bag ) ? $bag : array();
	}

	/**
	 * Write a bag straight to the DB (bypass any object cache).
	 *
	 * @param string $option Option.
	 * @param array  $bag    Bag.
	 * @return void
	 */
	private static function write( $option, $bag ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
				ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
				$option,
				maybe_serialize( $bag )
			)
		);
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}

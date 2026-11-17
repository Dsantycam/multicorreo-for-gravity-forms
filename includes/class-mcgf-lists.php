<?php
/**
 * Email lists: storage, parsing and lookups.
 *
 * A list is a name plus an ordered set of email addresses. An address can
 * appear several times: every appearance is one "cupo" (slot) of the round,
 * so an address written three times gets three submissions per round.
 *
 * All lists live in one non-autoloaded option:
 *   mcgf_lists = [ id => [ name, emails[], created, updated ] ]
 *
 * @package MulticorreoGF
 */

defined( 'WPINC' ) || exit;

/**
 * Lists class.
 */
class MCGF_Lists {

	const OPTION = 'mcgf_lists';

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Every list, keyed by id.
	 *
	 * @return array
	 */
	public static function all() {

		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$raw   = get_option( self::OPTION, array() );
		$lists = array();

		// A damaged option must never break a submission: keep only sane lists.
		if ( is_array( $raw ) ) {
			foreach ( $raw as $id => $list ) {
				$id = (int) $id;
				if ( $id <= 0 || ! is_array( $list ) ) {
					continue;
				}
				$lists[ $id ] = array(
					'id'      => $id,
					'name'    => isset( $list['name'] ) ? (string) $list['name'] : '',
					'emails'  => isset( $list['emails'] ) && is_array( $list['emails'] ) ? array_values( array_filter( $list['emails'], array( __CLASS__, 'is_email' ) ) ) : array(),
					'created' => isset( $list['created'] ) ? (int) $list['created'] : 0,
					'updated' => isset( $list['updated'] ) ? (int) $list['updated'] : 0,
				);
			}
		}

		uasort(
			$lists,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		self::$cache = $lists;

		return $lists;
	}

	/**
	 * One list (null when it doesn't exist).
	 *
	 * @param int $id List id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$lists = self::all();
		$id    = (int) $id;
		return isset( $lists[ $id ] ) ? $lists[ $id ] : null;
	}

	/**
	 * Create or update a list.
	 *
	 * @param int    $id     List id (0 = new).
	 * @param string $name   Name.
	 * @param array  $emails Valid, normalized emails (with repetitions).
	 * @return int List id.
	 */
	public static function save( $id, $name, $emails ) {

		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$id  = (int) $id;
		$now = time();

		if ( $id <= 0 || ! isset( $raw[ $id ] ) ) {
			$id = $raw ? max( array_map( 'intval', array_keys( $raw ) ) ) + 1 : 1;
			$raw[ $id ] = array( 'created' => $now );
		}

		$raw[ $id ]['name']    = $name;
		$raw[ $id ]['emails']  = array_values( $emails );
		$raw[ $id ]['updated'] = $now;

		update_option( self::OPTION, $raw, false );
		self::$cache = null;

		return $id;
	}

	/**
	 * Delete a list and its bags.
	 *
	 * @param int $id List id.
	 * @return void
	 */
	public static function delete( $id ) {

		$raw = get_option( self::OPTION, array() );
		$id  = (int) $id;

		if ( is_array( $raw ) && isset( $raw[ $id ] ) ) {
			unset( $raw[ $id ] );
			update_option( self::OPTION, $raw, false );
		}

		self::$cache = null;
		MCGF_Bag::delete_list_bags( $id );
	}

	/**
	 * Parse the textarea: one email per line (commas, semicolons and spaces
	 * also separate). Keeps repetitions and order.
	 *
	 * @param string $text Raw text.
	 * @return array [ 'valid' => [], 'invalid' => [] ]
	 */
	public static function parse( $text ) {

		$parts   = preg_split( '/[\s,;]+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		$valid   = array();
		$invalid = array();

		foreach ( $parts as $part ) {
			$email = self::normalize( $part );
			if ( self::is_email( $email ) ) {
				$valid[] = $email;
			} else {
				$invalid[] = $part;
			}
		}

		return array(
			'valid'   => $valid,
			'invalid' => array_values( array_unique( $invalid ) ),
		);
	}

	/**
	 * Normalize an address (trim, strip mailto: and <>, lowercase).
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function normalize( $email ) {
		$email = trim( (string) $email, " \t\n\r\0\x0B<>\"'" );
		$email = preg_replace( '/^mailto:/i', '', $email );
		return strtolower( $email );
	}

	/**
	 * Valid address?
	 *
	 * @param mixed $email Email.
	 * @return bool
	 */
	public static function is_email( $email ) {
		return is_string( $email ) && '' !== $email && strlen( $email ) <= 254 && false !== is_email( $email );
	}

	/**
	 * Slots per address: [ email => cupos ], in order of first appearance.
	 *
	 * @param array $emails Emails with repetitions.
	 * @return array
	 */
	public static function slots( $emails ) {
		$out = array();
		foreach ( (array) $emails as $email ) {
			$out[ $email ] = isset( $out[ $email ] ) ? $out[ $email ] + 1 : 1;
		}
		return $out;
	}

	/**
	 * Signature of a list content. The bag restarts when it changes.
	 *
	 * @param array $emails Emails.
	 * @return string
	 */
	public static function signature( $emails ) {
		$emails = array_values( (array) $emails );
		sort( $emails );
		return md5( implode( "\n", $emails ) );
	}

	/**
	 * Notifications that use each list: [ list_id => [ [form_id, form_title, nid, name], ... ] ].
	 *
	 * @return array
	 */
	public static function usage() {

		$out = array();

		if ( ! class_exists( 'GFFormsModel' ) ) {
			return $out;
		}

		foreach ( (array) GFFormsModel::get_forms( null, 'title' ) as $row ) {
			$form = GFFormsModel::get_form_meta( $row->id );
			if ( empty( $form['notifications'] ) || ! is_array( $form['notifications'] ) ) {
				continue;
			}
			foreach ( $form['notifications'] as $nid => $notification ) {
				if ( 'multicorreo' !== rgar( $notification, 'toType' ) ) {
					continue;
				}
				$list_id           = (int) rgar( $notification, 'mcgfList' );
				$out[ $list_id ][] = array(
					'form_id'    => (int) $row->id,
					'form_title' => (string) $row->title,
					'form_trash' => ! empty( $row->is_trash ),
					'nid'        => (string) $nid,
					'name'       => (string) rgar( $notification, 'name' ),
					'active'     => ! isset( $notification['isActive'] ) || $notification['isActive'],
				);
			}
		}

		return $out;
	}
}

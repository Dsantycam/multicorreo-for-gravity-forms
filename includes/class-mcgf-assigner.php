<?php
/**
 * Assign an address of a list to an entry.
 *
 * The assignment is stored in the entry (meta "mcgf_assigned": list => email)
 * so that every later use of the same entry and list (a second notification,
 * the {multicorreo} merge tag, a resend from the admin, a retry) reuses the
 * same address and never spends another slot.
 *
 * @package MulticorreoGF
 */

defined( 'WPINC' ) || exit;

/**
 * Assigner class.
 */
class MCGF_Assigner {

	const META       = 'mcgf_assigned';
	const META_EMAIL = 'mcgf_email';

	/**
	 * Request cache: [ entry_id ][ list_id ] => email.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Address for an entry and a list.
	 *
	 * @param array      $list         List (MCGF_Lists::get()).
	 * @param array      $form         Form.
	 * @param array      $entry        Entry (may have no id, e.g. save & continue).
	 * @param array|null $notification Notification (for the sticky setting).
	 * @return array [ 'email' => string, 'how' => 'saved'|'pop'|'sticky'|'none' ]
	 */
	public static function assign( $list, $form, $entry, $notification = null ) {

		$list_id  = (int) $list['id'];
		$form_id  = (int) rgar( $form, 'id' );
		$entry_id = (int) rgar( $entry, 'id' );

		// Already assigned to this entry (and still in the list).
		if ( $entry_id ) {
			$saved = self::saved( $entry_id );
			if ( isset( $saved[ $list_id ] ) && in_array( $saved[ $list_id ], $list['emails'], true ) ) {
				return array(
					'email' => $saved[ $list_id ],
					'how'   => 'saved',
				);
			}
		}

		$person = self::person( $form, $entry, $notification );
		$taken  = MCGF_Bag::take( $list, $form_id, $person );

		if ( '' === $taken['email'] ) {
			return $taken;
		}

		$item = MCGF_Stats::item( $list_id, $form_id, $taken['email'] );
		MCGF_Stats::record( 'pop' === $taken['how'] ? 'assign' : 'sticky', $item );

		if ( $entry_id ) {
			$saved             = self::saved( $entry_id );
			$saved[ $list_id ] = $taken['email'];

			self::$cache[ $entry_id ] = $saved;

			gform_update_meta( $entry_id, self::META, $saved, $form_id );
			gform_update_meta( $entry_id, self::META_EMAIL, implode( ', ', array_unique( array_values( $saved ) ) ), $form_id );

			$text = 'sticky' === $taken['how']
				? sprintf(
					/* translators: 1: email, 2: list name, 3: hours */
					__( 'Asignado a %1$s (lista «%2$s»): la misma persona ya escribió en las últimas %3$d horas, así que se mantiene el mismo correo y no se gasta cupo.', 'multicorreo-gf' ),
					$taken['email'],
					$list['name'],
					MCGF_Bag::sticky_hours()
				)
				: sprintf(
					/* translators: 1: email, 2: list name */
					__( 'Asignado a %1$s (lista «%2$s»).', 'multicorreo-gf' ),
					$taken['email'],
					$list['name']
				);

			self::note( $entry_id, $text );
		}

		return $taken;
	}

	/**
	 * Saved assignments of an entry.
	 *
	 * @param int $entry_id Entry.
	 * @return array [ list_id => email ]
	 */
	public static function saved( $entry_id ) {

		if ( ! isset( self::$cache[ $entry_id ] ) ) {
			$saved = gform_get_meta( $entry_id, self::META );
			$out   = array();
			if ( is_array( $saved ) ) {
				foreach ( $saved as $list_id => $email ) {
					if ( MCGF_Lists::is_email( $email ) ) {
						$out[ (int) $list_id ] = $email;
					}
				}
			}
			self::$cache[ $entry_id ] = $out;
		}

		return self::$cache[ $entry_id ];
	}

	/**
	 * The submitter's email, used to recognize the same person (sticky).
	 *
	 * Notification setting "mcgfSticky": 'auto' (first Email field, default),
	 * 'off', or the id of an Email field.
	 *
	 * @param array      $form         Form.
	 * @param array      $entry        Entry.
	 * @param array|null $notification Notification.
	 * @return string
	 */
	public static function person( $form, $entry, $notification = null ) {

		$mode = $notification ? (string) rgar( $notification, 'mcgfSticky' ) : '';
		$mode = '' === $mode ? 'auto' : $mode;

		if ( 'off' === $mode || empty( $form['fields'] ) ) {
			return '';
		}

		foreach ( $form['fields'] as $field ) {
			if ( 'email' !== GFFormsModel::get_input_type( $field ) ) {
				continue;
			}
			if ( 'auto' !== $mode && (string) $field->id !== $mode ) {
				continue;
			}
			// Email fields with confirmation store the value in the field id.
			$value = rgar( $entry, (string) $field->id );
			if ( MCGF_Lists::is_email( MCGF_Lists::normalize( $value ) ) ) {
				return $value;
			}
			if ( 'auto' !== $mode ) {
				break;
			}
		}

		return '';
	}

	/**
	 * Add a note to the entry.
	 *
	 * @param int    $entry_id Entry.
	 * @param string $text     Text.
	 * @param string $subtype  success|error|warning.
	 * @return void
	 */
	public static function note( $entry_id, $text, $subtype = 'success' ) {
		if ( $entry_id && method_exists( 'GFFormsModel', 'add_note' ) ) {
			GFFormsModel::add_note( $entry_id, 0, 'Multicorreo', $text, 'mcgf', $subtype );
		}
	}
}

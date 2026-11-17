<?php
/**
 * Gravity Forms integration.
 *
 * - Adds "Reparto aleatorio (Multicorreo)" to the "Send To" options of every
 *   notification, with the list to use and how to recognize the same person.
 * - Right before a notification is sent (gform_notification) it takes an
 *   address from the list and turns the notification into a normal "Enter
 *   Email" one, so the rest of Gravity Forms works exactly as always.
 * - Watches the result of that very email (gform_pre_send_email,
 *   gform_after_email, gform_send_email_failed) and hands failures to the
 *   retry queue.
 * - Entry column / filter, {multicorreo} merge tag.
 *
 * Spam entries never reach gform_notification (Gravity Forms doesn't send
 * their notifications), so they never spend a slot.
 *
 * @package MulticorreoGF
 */

defined( 'WPINC' ) || exit;

/**
 * Notifications class.
 */
class MCGF_Notifications {

	const TO_TYPE   = 'multicorreo';
	const MERGE_TAG = '{multicorreo}';

	/**
	 * Singleton.
	 *
	 * @var MCGF_Notifications|null
	 */
	private static $instance = null;

	/**
	 * The Multicorreo email being sent right now (null when none).
	 *
	 * @var array|null
	 */
	private $current = null;

	/**
	 * Queue row being retried (forces its address).
	 *
	 * @var array|null
	 */
	private $retrying = null;

	/**
	 * Get the instance.
	 *
	 * @return MCGF_Notifications
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks.
	 */
	private function __construct() {

		// Settings UI.
		add_filter( 'gform_notification_settings_fields', array( $this, 'settings_fields' ), 10, 3 );
		add_filter( 'gform_pre_notification_save', array( $this, 'pre_save' ), 10, 2 );

		// Sending. Late priority: other plugins see the notification as it is
		// configured, and we have the last word on the recipient.
		add_filter( 'gform_notification', array( $this, 'resolve' ), 99, 3 );
		add_filter( 'gform_pre_send_email', array( $this, 'pre_send' ), PHP_INT_MAX, 4 );
		add_action( 'gform_after_email', array( $this, 'after_email' ), 10, 12 );
		add_action( 'gform_send_email_failed', array( $this, 'send_failed' ), 10, 3 );
		add_action( 'wp_mail_failed', array( $this, 'mail_error' ) );

		// Entries.
		add_filter( 'gform_entry_meta', array( $this, 'entry_meta' ), 10, 2 );

		// Merge tag.
		add_filter( 'gform_custom_merge_tags', array( $this, 'custom_merge_tags' ), 10, 2 );
		add_filter( 'gform_replace_merge_tags', array( $this, 'replace_merge_tags' ), 10, 3 );
	}

	// ------------------------------------------------------------ settings.

	/**
	 * Add our option and fields to the notification settings.
	 *
	 * @param array $fields       Settings sections.
	 * @param array $notification Notification.
	 * @param array $form         Form.
	 * @return array
	 */
	public function settings_fields( $fields, $notification, $form ) {

		if ( ! isset( $fields[0]['fields'] ) || ! is_array( $fields[0]['fields'] ) ) {
			return $fields;
		}

		$to_index    = null;
		$after_index = null;

		foreach ( $fields[0]['fields'] as $i => $field ) {
			$name = rgar( $field, 'name' );
			if ( 'toType' === $name ) {
				$to_index = $i;
			}
			if ( in_array( $name, array( 'toType', 'toEmail', 'toField', 'routing' ), true ) ) {
				$after_index = $i;
			}
		}

		// Hidden "Send To" (some add-ons) or an unknown layout: don't touch it.
		if ( null === $to_index || 'radio' !== rgar( $fields[0]['fields'][ $to_index ], 'type' ) ) {
			return $fields;
		}

		$fields[0]['fields'][ $to_index ]['choices'][] = array(
			'label' => esc_html__( 'Reparto aleatorio (Multicorreo)', 'multicorreo-gf' ),
			'value' => self::TO_TYPE,
		);

		$dependency = array(
			'live'   => true,
			'fields' => array(
				array(
					'field'  => 'toType',
					'values' => array( self::TO_TYPE ),
				),
			),
		);

		$lists_url = admin_url( 'admin.php?page=' . MCGF_Admin::PAGE_LISTS );

		$list_choices = array(
			array(
				'label' => esc_html__( '— Elige una lista —', 'multicorreo-gf' ),
				'value' => '',
			),
		);

		foreach ( MCGF_Lists::all() as $list ) {
			$slots          = MCGF_Lists::slots( $list['emails'] );
			$list_choices[] = array(
				'label' => esc_html(
					sprintf(
						'%1$s (%2$s, %3$s)',
						$list['name'],
						/* translators: %d: slots */
						sprintf( _n( '%d cupo', '%d cupos', count( $list['emails'] ), 'multicorreo-gf' ), count( $list['emails'] ) ),
						/* translators: %d: distinct emails */
						sprintf( _n( '%d correo', '%d correos', count( $slots ), 'multicorreo-gf' ), count( $slots ) )
					)
				),
				'value' => (string) $list['id'],
			);
		}

		$sticky_choices = array(
			array(
				'label' => sprintf(
					/* translators: %d: hours */
					esc_html__( 'Sí, por el primer campo Email del formulario (%d horas)', 'multicorreo-gf' ),
					MCGF_Bag::sticky_hours()
				),
				'value' => 'auto',
			),
		);

		foreach ( (array) rgar( $form, 'fields' ) as $field ) {
			if ( 'email' === GFFormsModel::get_input_type( $field ) ) {
				$sticky_choices[] = array(
					/* translators: %s: field label */
					'label' => sprintf( esc_html__( 'Sí, por el campo «%s»', 'multicorreo-gf' ), wp_strip_all_tags( GFCommon::get_label( $field ) ) ),
					'value' => (string) $field->id,
				);
			}
		}

		$sticky_choices[] = array(
			'label' => esc_html__( 'No, repartir siempre', 'multicorreo-gf' ),
			'value' => 'off',
		);

		$ours = array(
			array(
				'name'                => 'mcgfList',
				'label'               => esc_html__( 'Lista de correos', 'multicorreo-gf' ),
				'type'                => 'select',
				'required'            => true,
				'choices'             => $list_choices,
				'dependency'          => $dependency,
				'after_input'         => '<p class="description" style="margin-top:6px">' .
					( count( $list_choices ) > 1
						? esc_html__( 'Cada envío de este formulario irá a un correo distinto de la lista, por cupos. Cada formulario lleva su propia ronda.', 'multicorreo-gf' )
						: esc_html__( 'Todavía no hay listas.', 'multicorreo-gf' ) ) .
					' <a href="' . esc_url( $lists_url ) . '" target="_blank">' . esc_html__( 'Gestionar listas', 'multicorreo-gf' ) . '</a></p>',
				'validation_callback' => function ( $field, $value ) {
					$list = MCGF_Lists::get( $value );
					if ( ! $list ) {
						$field->set_error( __( 'Elige una lista de correos.', 'multicorreo-gf' ) );
					} elseif ( empty( $list['emails'] ) ) {
						$field->set_error( __( 'Esa lista no tiene correos. Añádelos en Multicorreo › Listas de correos.', 'multicorreo-gf' ) );
					}
				},
			),
			array(
				'name'          => 'mcgfSticky',
				'label'         => esc_html__( 'Misma persona, mismo correo', 'multicorreo-gf' ),
				'type'          => 'select',
				'choices'       => $sticky_choices,
				'default_value' => 'auto',
				'dependency'    => $dependency,
				'after_input'   => '<p class="description" style="margin-top:6px">' . esc_html__( 'Si la misma persona vuelve a enviar el formulario, le llega al mismo correo que la primera vez sin gastar otro cupo.', 'multicorreo-gf' ) . '</p>',
			),
		);

		array_splice( $fields[0]['fields'], $after_index + 1, 0, $ours );

		return $fields;
	}

	/**
	 * Clean our settings before the notification is saved.
	 *
	 * @param array $notification Notification.
	 * @param array $form         Form.
	 * @return array
	 */
	public function pre_save( $notification, $form ) {

		if ( self::TO_TYPE === rgar( $notification, 'toType' ) ) {
			$notification['to']       = '';
			$notification['mcgfList'] = (string) absint( rgar( $notification, 'mcgfList' ) );

			$sticky = (string) rgar( $notification, 'mcgfSticky' );
			if ( ! in_array( $sticky, array( 'auto', 'off' ), true ) && ! preg_match( '/^\d+$/', $sticky ) ) {
				$sticky = 'auto';
			}
			$notification['mcgfSticky'] = $sticky;
		}

		return $notification;
	}

	// ------------------------------------------------------------- sending.

	/**
	 * Resolve the recipient of a Multicorreo notification.
	 *
	 * @param array $notification Notification.
	 * @param array $form         Form.
	 * @param array $entry        Entry.
	 * @return array
	 */
	public function resolve( $notification, $form, $entry ) {

		$this->current = null;

		if ( self::TO_TYPE !== rgar( $notification, 'toType' ) ) {
			return $notification;
		}

		$entry_id = (int) rgar( $entry, 'id' );
		$list_id  = (int) rgar( $notification, 'mcgfList' );
		$list     = MCGF_Lists::get( $list_id );
		$email    = '';

		if ( $this->retrying && (int) $this->retrying['entry_id'] === $entry_id && (string) $this->retrying['nid'] === (string) rgar( $notification, 'id' ) ) {

			// A retry goes to the address that was assigned, whatever happened
			// to the list since then: that person's slot is already theirs.
			$email = $this->retrying['email'];

		} elseif ( $list && $list['emails'] ) {

			try {
				$taken = MCGF_Assigner::assign( $list, $form, $entry, $notification );
				$email = $taken['email'];
			} catch ( \Throwable $e ) {
				// Never lose the submission: any address of the list will do.
				$email = $list['emails'][ array_rand( $list['emails'] ) ];
				GFCommon::log_error( __METHOD__ . '(): ' . $e->getMessage() );
			}
		}

		if ( '' === $email ) {

			// The list was deleted or emptied: the email still goes out, to the
			// site administrator, and the entry says why.
			$email = get_bloginfo( 'admin_email' );

			MCGF_Assigner::note(
				$entry_id,
				sprintf(
					/* translators: 1: notification name, 2: email */
					__( 'La notificación «%1$s» no tiene una lista de correos válida (se borró o está vacía). Se envió al administrador del sitio (%2$s).', 'multicorreo-gf' ),
					rgar( $notification, 'name' ),
					$email
				),
				'warning'
			);
			GFCommon::log_error( __METHOD__ . '(): notification #' . rgar( $notification, 'id' ) . ' has no valid list; sent to the admin email.' );
		}

		GFCommon::log_debug( __METHOD__ . '(): Multicorreo notification #' . rgar( $notification, 'id' ) . ' for entry #' . $entry_id . ' => ' . $email );

		$notification['toType'] = 'email';
		$notification['to']     = $email;

		$this->current = array(
			'entry_id' => $entry_id,
			'form_id'  => (int) rgar( $form, 'id' ),
			'nid'      => (string) rgar( $notification, 'id' ),
			'name'     => (string) rgar( $notification, 'name' ),
			'list_id'  => $list_id,
			'email'    => $email,
			'aborted'  => false,
			'error'    => '',
		);

		return $notification;
	}

	/**
	 * Remember if another plugin aborted the email on purpose.
	 *
	 * @param array  $email          Email.
	 * @param string $message_format Format.
	 * @param array  $notification   Notification.
	 * @param array  $entry          Entry.
	 * @return array
	 */
	public function pre_send( $email, $message_format, $notification, $entry ) {

		if ( $this->current && (string) rgar( $notification, 'id' ) === $this->current['nid'] ) {
			$this->current['aborted'] = ! empty( $email['abort_email'] );
		}

		return $email;
	}

	/**
	 * Result of the email.
	 *
	 * @param bool   $is_success     Sent?
	 * @param string $to             To.
	 * @param string $subject        Subject.
	 * @param string $message        Message.
	 * @param array  $headers        Headers.
	 * @param array  $attachments    Attachments.
	 * @param string $message_format Format.
	 * @param string $from           From.
	 * @param string $from_name      From name.
	 * @param string $bcc            Bcc.
	 * @param string $reply_to       Reply to.
	 * @param array  $entry          Entry.
	 * @return void
	 */
	public function after_email( $is_success, $to, $subject, $message, $headers, $attachments, $message_format, $from, $from_name, $bcc, $reply_to, $entry ) {

		if ( ! $this->current ) {
			return;
		}

		$ctx           = $this->current;
		$this->current = null;

		// Another plugin decided not to send it: nothing to retry.
		if ( $ctx['aborted'] ) {
			return;
		}

		if ( ! is_wp_error( $is_success ) && $is_success ) {
			$this->succeeded( $ctx );
			return;
		}

		global $phpmailer;
		$error = is_wp_error( $is_success ) ? $is_success->get_error_message() : $ctx['error'];
		if ( '' === $error && is_object( $phpmailer ) && ! empty( $phpmailer->ErrorInfo ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$error = $phpmailer->ErrorInfo; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}

		$this->failed( $ctx, '' !== $error ? $error : __( 'WordPress no pudo enviar el correo.', 'multicorreo-gf' ) );
	}

	/**
	 * Keep the real error of wp_mail (SMTP message, plugin errors...).
	 *
	 * @param WP_Error $error Error.
	 * @return void
	 */
	public function mail_error( $error ) {
		if ( $this->current && is_wp_error( $error ) ) {
			$this->current['error'] = $error->get_error_message();
		}
	}

	/**
	 * Gravity Forms refused to send (invalid From / To, empty message...).
	 *
	 * @param WP_Error $error Error.
	 * @param array    $email Email details.
	 * @param array    $entry Entry.
	 * @return void
	 */
	public function send_failed( $error, $email, $entry ) {

		if ( ! $this->current ) {
			return;
		}

		$ctx           = $this->current;
		$this->current = null;

		$this->failed( $ctx, is_wp_error( $error ) ? $error->get_error_message() : (string) $error );
	}

	/**
	 * An email went out.
	 *
	 * @param array $ctx Context.
	 * @return void
	 */
	private function succeeded( $ctx ) {

		$item = MCGF_Stats::item( $ctx['list_id'], $ctx['form_id'], $ctx['email'] );
		MCGF_Stats::record( 'sent', $item );

		if ( $ctx['entry_id'] && MCGF_Queue::success( $ctx['entry_id'], $ctx['nid'] ) ) {
			MCGF_Stats::record( 'retried', $item );
			MCGF_Assigner::note(
				$ctx['entry_id'],
				/* translators: 1: notification, 2: email */
				sprintf( __( 'Reintento correcto: la notificación «%1$s» se envió a %2$s.', 'multicorreo-gf' ), $ctx['name'], $ctx['email'] )
			);
		}
	}

	/**
	 * An email failed: queue it again.
	 *
	 * @param array  $ctx   Context.
	 * @param string $error Error.
	 * @return void
	 */
	private function failed( $ctx, $error ) {

		$item = MCGF_Stats::item( $ctx['list_id'], $ctx['form_id'], $ctx['email'] );
		MCGF_Stats::record( 'fail', $item );

		// Without an entry (save & continue...) there is nothing to resend.
		if ( ! $ctx['entry_id'] ) {
			return;
		}

		$status = MCGF_Queue::failure( $ctx, $error );

		if ( 'failed' === $status ) {
			MCGF_Stats::record( 'lost', $item );
			$text = sprintf(
				/* translators: 1: notification, 2: email, 3: error */
				__( 'La notificación «%1$s» para %2$s no se pudo enviar tras todos los reintentos (%3$s). Reintenta desde Multicorreo › Reparto.', 'multicorreo-gf' ),
				$ctx['name'],
				$ctx['email'],
				$error
			);
			MCGF_Assigner::note( $ctx['entry_id'], $text, 'error' );
		} else {
			$row  = MCGF_Queue::find( $ctx['entry_id'], $ctx['nid'] );
			$when = $row ? get_date_from_gmt( $row['next_try'], 'Y-m-d H:i' ) : '';
			$text = sprintf(
				/* translators: 1: notification, 2: email, 3: error, 4: date */
				__( 'La notificación «%1$s» para %2$s falló (%3$s). Se reintentará automáticamente (%4$s), siempre al mismo correo.', 'multicorreo-gf' ),
				$ctx['name'],
				$ctx['email'],
				$error,
				$when
			);
			MCGF_Assigner::note( $ctx['entry_id'], $text, 'warning' );
		}
	}

	/**
	 * Resend a queued notification (called by the queue).
	 *
	 * @param array $row Queue row.
	 * @return void
	 */
	public function retry( $row ) {

		$entry = GFAPI::get_entry( (int) $row['entry_id'] );
		if ( is_wp_error( $entry ) || ! $entry ) {
			MCGF_Queue::abandon( $row, __( 'La entrada ya no existe.', 'multicorreo-gf' ) );
			return;
		}

		$form = GFAPI::get_form( (int) $entry['form_id'] );
		$nid  = (string) $row['nid'];

		if ( ! $form || empty( $form['notifications'][ $nid ] ) ) {
			MCGF_Queue::abandon( $row, __( 'La notificación ya no existe.', 'multicorreo-gf' ) );
			return;
		}

		$notification = $form['notifications'][ $nid ];

		// Someone switched it to another kind of recipient: send it as it is
		// now configured (the queue only watches Multicorreo emails).
		$this->retrying = $row;

		try {
			GFCommon::send_notification( $notification, $form, $entry );
		} finally {
			$this->retrying = null;
		}

		// The email never got to the sending step (another plugin stopped it
		// or it is not a Multicorreo notification any more): don't loop.
		if ( $this->current ) {
			$this->current = null;
		}

		$fresh = MCGF_Queue::get( (int) $row['id'] );
		if ( $fresh && 'pending' === $fresh['status'] && (int) $fresh['attempts'] === (int) $row['attempts'] && $fresh['updated'] === $row['updated'] ) {
			if ( self::TO_TYPE === rgar( $notification, 'toType' ) ) {
				// Aborted on purpose by another plugin: not ours to insist.
				MCGF_Queue::abandon( $row, __( 'Otro plugin canceló el envío.', 'multicorreo-gf' ) );
			} else {
				MCGF_Queue::success( (int) $row['entry_id'], $nid );
			}
		}
	}

	// ------------------------------------------------------------- entries.

	/**
	 * "Multicorreo" entry column and filter (only in forms that use it).
	 *
	 * @param array $meta    Entry meta.
	 * @param int   $form_id Form.
	 * @return array
	 */
	public function entry_meta( $meta, $form_id ) {

		if ( ! self::form_uses( GFFormsModel::get_form_meta( $form_id ) ) ) {
			return $meta;
		}

		$meta[ MCGF_Assigner::META_EMAIL ] = array(
			'label'             => __( 'Multicorreo: asignado a', 'multicorreo-gf' ),
			'is_numeric'        => false,
			'is_default_column' => true,
			'filter'            => array(
				'operators' => array( 'is', 'isnot', 'contains' ),
			),
		);

		return $meta;
	}

	/**
	 * Does a form have a Multicorreo notification?
	 *
	 * @param array|null $form Form.
	 * @return bool
	 */
	public static function form_uses( $form ) {
		foreach ( (array) rgar( (array) $form, 'notifications' ) as $notification ) {
			if ( self::TO_TYPE === rgar( $notification, 'toType' ) ) {
				return true;
			}
		}
		return false;
	}

	// ----------------------------------------------------------- merge tag.

	/**
	 * Offer {multicorreo} in the merge tags menu.
	 *
	 * @param array $merge_tags Tags.
	 * @param int   $form_id    Form.
	 * @return array
	 */
	public function custom_merge_tags( $merge_tags, $form_id ) {

		if ( self::form_uses( GFFormsModel::get_form_meta( $form_id ) ) ) {
			$merge_tags[] = array(
				'label' => __( 'Correo asignado (Multicorreo)', 'multicorreo-gf' ),
				'tag'   => self::MERGE_TAG,
			);
		}

		return $merge_tags;
	}

	/**
	 * Replace {multicorreo} with the address assigned to the entry.
	 *
	 * If the tag is used before the Multicorreo notification has run (e.g. in
	 * a notification to the customer that is processed first), the address
	 * is assigned right then, with the first Multicorreo notification of the
	 * form that applies: the Multicorreo notification will reuse it.
	 *
	 * @param string $text  Text.
	 * @param array  $form  Form.
	 * @param array  $entry Entry.
	 * @return string
	 */
	public function replace_merge_tags( $text, $form, $entry ) {

		if ( ! is_string( $text ) || false === strpos( $text, self::MERGE_TAG ) ) {
			return $text;
		}

		$entry_id = (int) rgar( (array) $entry, 'id' );
		$email    = '';

		if ( $entry_id ) {
			$saved = MCGF_Assigner::saved( $entry_id );

			if ( ! $saved && 'spam' !== rgar( $entry, 'status' ) ) {
				foreach ( (array) rgar( $form, 'notifications' ) as $notification ) {
					if ( self::TO_TYPE !== rgar( $notification, 'toType' ) || 'form_submission' !== rgar( $notification, 'event' ) ) {
						continue;
					}
					if ( isset( $notification['isActive'] ) && ! $notification['isActive'] ) {
						continue;
					}
					if ( ! GFCommon::evaluate_conditional_logic( rgar( $notification, 'conditionalLogic' ), $form, $entry ) ) {
						continue;
					}
					$list = MCGF_Lists::get( rgar( $notification, 'mcgfList' ) );
					if ( $list && $list['emails'] ) {
						MCGF_Assigner::assign( $list, $form, $entry, $notification );
						$saved = MCGF_Assigner::saved( $entry_id );
						break;
					}
				}
			}

			$email = implode( ', ', array_unique( array_values( $saved ) ) );
		}

		return str_replace( self::MERGE_TAG, esc_html( $email ), $text );
	}
}

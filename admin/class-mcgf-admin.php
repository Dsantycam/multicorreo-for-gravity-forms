<?php
/**
 * Admin: menu and the email lists page.
 *
 * @package MulticorreoGF
 */

defined( 'WPINC' ) || exit;

/**
 * Admin class.
 */
class MCGF_Admin {

	const PAGE_LISTS = 'mcgf-lists';

	/**
	 * Hook suffix of the lists page.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Errors of the submitted form (kept to show the form again).
	 *
	 * @var array
	 */
	private $errors = array();

	/**
	 * Submitted values when the form has errors.
	 *
	 * @var array|null
	 */
	private $posted = null;

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_mcgf_delete_list', array( $this, 'delete_list' ) );
		add_action( 'admin_post_mcgf_reset_rounds', array( $this, 'reset_rounds' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MCGF_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Menu.
	 *
	 * @return void
	 */
	public function menu() {

		add_menu_page(
			__( 'Multicorreo', 'multicorreo-gf' ),
			__( 'Multicorreo', 'multicorreo-gf' ),
			MCGF_Roles::CAP,
			self::PAGE_LISTS,
			array( $this, 'page' ),
			'dashicons-email-alt',
			'16.95'
		);

		$this->hook = add_submenu_page(
			self::PAGE_LISTS,
			__( 'Listas de correos', 'multicorreo-gf' ),
			__( 'Listas de correos', 'multicorreo-gf' ),
			MCGF_Roles::CAP,
			self::PAGE_LISTS,
			array( $this, 'page' )
		);

		add_action( 'load-' . $this->hook, array( $this, 'handle_save' ) );
	}

	/**
	 * CSS / JS of our pages.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function assets( $hook ) {

		if ( false === strpos( (string) $hook, 'mcgf' ) ) {
			return;
		}

		wp_enqueue_style( 'mcgf-admin', MCGF_URL . 'admin/css/mcgf-admin.css', array(), MCGF_VERSION );
		wp_enqueue_script( 'mcgf-admin', MCGF_URL . 'admin/js/mcgf-admin.js', array(), MCGF_VERSION, true );
	}

	/**
	 * "Listas" link in the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Listas', 'multicorreo-gf' ) . '</a>' );
		return $links;
	}

	/**
	 * URL of the lists page.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE_LISTS ) );
	}

	/**
	 * Edit link of a notification.
	 *
	 * @param int    $form_id Form.
	 * @param string $nid     Notification.
	 * @return string
	 */
	public static function notification_url( $form_id, $nid ) {
		return admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=notification&id=' . (int) $form_id . '&nid=' . rawurlencode( $nid ) );
	}

	// --------------------------------------------------------------- save.

	/**
	 * Save a list (runs before any output, so it can redirect).
	 *
	 * @return void
	 */
	public function handle_save() {

		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['mcgf_save_list'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}

		check_admin_referer( 'mcgf_save_list' );

		if ( ! current_user_can( MCGF_Roles::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'multicorreo-gf' ) );
		}

		$id     = isset( $_POST['list_id'] ) ? absint( $_POST['list_id'] ) : 0;
		$name   = isset( $_POST['list_name'] ) ? sanitize_text_field( wp_unslash( $_POST['list_name'] ) ) : '';
		$text   = isset( $_POST['list_emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['list_emails'] ) ) : '';
		$parsed = MCGF_Lists::parse( $text );

		if ( '' === $name ) {
			$this->errors[] = __( 'Ponle un nombre a la lista.', 'multicorreo-gf' );
		}

		if ( $parsed['invalid'] ) {
			$this->errors[] = sprintf(
				/* translators: %s: invalid values */
				__( 'Estos valores no son correos válidos, corrígelos o bórralos: %s', 'multicorreo-gf' ),
				implode( ', ', $parsed['invalid'] )
			);
		}

		if ( ! $parsed['valid'] ) {
			$this->errors[] = __( 'Añade al menos un correo.', 'multicorreo-gf' );
		}

		if ( $id && ! MCGF_Lists::get( $id ) ) {
			$id = 0;
		}

		if ( $this->errors ) {
			$this->posted = compact( 'id', 'name', 'text' );
			return;
		}

		$before  = $id ? MCGF_Lists::get( $id ) : null;
		$changed = $before && MCGF_Lists::signature( $before['emails'] ) !== MCGF_Lists::signature( $parsed['valid'] );

		$id = MCGF_Lists::save( $id, $name, $parsed['valid'] );

		wp_safe_redirect(
			self::url(
				array(
					'mcgf_msg' => $before ? ( $changed ? 'updated_reset' : 'updated' ) : 'created',
				)
			)
		);
		exit;
	}

	/**
	 * Delete a list (only when no form uses it).
	 *
	 * @return void
	 */
	public function delete_list() {

		$id = isset( $_GET['list'] ) ? absint( $_GET['list'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		check_admin_referer( 'mcgf_delete_list_' . $id );

		if ( ! current_user_can( MCGF_Roles::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'multicorreo-gf' ) );
		}

		$usage = MCGF_Lists::usage();
		$used  = array_filter(
			isset( $usage[ $id ] ) ? $usage[ $id ] : array(),
			function ( $use ) {
				return ! $use['form_trash'];
			}
		);

		if ( $used ) {
			wp_safe_redirect( self::url( array( 'mcgf_msg' => 'in_use' ) ) );
			exit;
		}

		MCGF_Lists::delete( $id );

		wp_safe_redirect( self::url( array( 'mcgf_msg' => 'deleted' ) ) );
		exit;
	}

	/**
	 * Restart every round of a list.
	 *
	 * @return void
	 */
	public function reset_rounds() {

		$id = isset( $_GET['list'] ) ? absint( $_GET['list'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		check_admin_referer( 'mcgf_reset_rounds_' . $id );

		if ( ! current_user_can( MCGF_Roles::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'multicorreo-gf' ) );
		}

		MCGF_Bag::delete_list_bags( $id );

		wp_safe_redirect( self::url( array( 'mcgf_msg' => 'reset' ) ) );
		exit;
	}

	// ------------------------------------------------------------- render.

	/**
	 * Page router.
	 *
	 * @return void
	 */
	public function page() {

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="wrap mcgf-wrap">';

		if ( null !== $this->posted || 'edit' === $action || 'new' === $action ) {
			$this->render_form();
		} else {
			$this->render_table();
		}

		echo '</div>';
	}

	/**
	 * Messages after a redirect.
	 *
	 * @return void
	 */
	private function messages() {

		$msg = isset( $_GET['mcgf_msg'] ) ? sanitize_key( $_GET['mcgf_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$texts = array(
			'created'       => array( 'success', __( 'Lista creada. Ya puedes elegirla en cualquier notificación (Enviar a › Reparto aleatorio).', 'multicorreo-gf' ) ),
			'updated'       => array( 'success', __( 'Lista guardada.', 'multicorreo-gf' ) ),
			'updated_reset' => array( 'success', __( 'Lista guardada. Como cambiaron los correos, las rondas de esta lista empiezan de nuevo con la lista nueva.', 'multicorreo-gf' ) ),
			'deleted'       => array( 'success', __( 'Lista borrada.', 'multicorreo-gf' ) ),
			'reset'         => array( 'success', __( 'Rondas reiniciadas: el próximo envío de cada formulario empieza una ronda nueva.', 'multicorreo-gf' ) ),
			'in_use'        => array( 'error', __( 'No se puede borrar: hay notificaciones que usan esta lista. Cámbialas a otra lista primero.', 'multicorreo-gf' ) ),
		);

		if ( isset( $texts[ $msg ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $texts[ $msg ][0] ), esc_html( $texts[ $msg ][1] ) );
		}
	}

	/**
	 * Lists table.
	 *
	 * @return void
	 */
	private function render_table() {

		$lists = MCGF_Lists::all();
		$usage = MCGF_Lists::usage();

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Listas de correos', 'multicorreo-gf' ) . '</h1> ';
		echo '<a href="' . esc_url( self::url( array( 'action' => 'new' ) ) ) . '" class="page-title-action">' . esc_html__( 'Añadir lista', 'multicorreo-gf' ) . '</a>';
		echo '<hr class="wp-header-end">';

		$this->messages();

		echo '<p class="mcgf-intro">' . esc_html__( 'Crea las listas que quieras. Luego, en la notificación de cualquier formulario, elige «Enviar a: Reparto aleatorio (Multicorreo)» y la lista. Cada envío irá a un correo distinto, por cupos: un correo repetido 3 veces recibe 3 envíos por ronda.', 'multicorreo-gf' ) . '</p>';

		if ( ! $lists ) {
			echo '<div class="mcgf-empty"><p>' . esc_html__( 'Todavía no hay listas.', 'multicorreo-gf' ) . '</p>';
			echo '<p><a href="' . esc_url( self::url( array( 'action' => 'new' ) ) ) . '" class="button button-primary">' . esc_html__( 'Crear la primera lista', 'multicorreo-gf' ) . '</a></p></div>';
			return;
		}

		echo '<table class="widefat striped mcgf-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Lista', 'multicorreo-gf' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Cupos', 'multicorreo-gf' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Correos', 'multicorreo-gf' ) . '</th>';
		echo '<th>' . esc_html__( 'Usada en', 'multicorreo-gf' ) . '</th>';
		echo '<th>' . esc_html__( 'Modificada', 'multicorreo-gf' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $lists as $list ) {

			$id   = $list['id'];
			$uses = isset( $usage[ $id ] ) ? $usage[ $id ] : array();

			$edit   = self::url(
				array(
					'action' => 'edit',
					'list'   => $id,
				)
			);
			$delete = wp_nonce_url( admin_url( 'admin-post.php?action=mcgf_delete_list&list=' . $id ), 'mcgf_delete_list_' . $id );
			$reset  = wp_nonce_url( admin_url( 'admin-post.php?action=mcgf_reset_rounds&list=' . $id ), 'mcgf_reset_rounds_' . $id );
			$stats  = admin_url( 'admin.php?page=' . MCGF_Dashboard::PAGE . '&list=' . $id );

			echo '<tr><td class="mcgf-name"><strong><a href="' . esc_url( $edit ) . '">' . esc_html( $list['name'] ) . '</a></strong>';
			echo '<div class="row-actions">';
			echo '<span><a href="' . esc_url( $edit ) . '">' . esc_html__( 'Editar', 'multicorreo-gf' ) . '</a> | </span>';
			echo '<span><a href="' . esc_url( $stats ) . '">' . esc_html__( 'Ver reparto', 'multicorreo-gf' ) . '</a> | </span>';
			echo '<span><a href="' . esc_url( $reset ) . '" data-mcgf-confirm="' . esc_attr__( '¿Reiniciar las rondas de esta lista en todos los formularios? El próximo envío empezará una ronda nueva.', 'multicorreo-gf' ) . '">' . esc_html__( 'Reiniciar rondas', 'multicorreo-gf' ) . '</a> | </span>';
			echo '<span class="trash"><a href="' . esc_url( $delete ) . '" data-mcgf-confirm="' . esc_attr__( '¿Borrar esta lista? No se puede deshacer.', 'multicorreo-gf' ) . '">' . esc_html__( 'Borrar', 'multicorreo-gf' ) . '</a></span>';
			echo '</div></td>';

			echo '<td class="num">' . esc_html( number_format_i18n( count( $list['emails'] ) ) ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( count( MCGF_Lists::slots( $list['emails'] ) ) ) ) . '</td>';

			echo '<td>';
			if ( $uses ) {
				echo '<ul class="mcgf-uses">';
				foreach ( $uses as $use ) {
					$label = $use['form_title'] . ' › ' . $use['name'];
					echo '<li><a href="' . esc_url( self::notification_url( $use['form_id'], $use['nid'] ) ) . '">' . esc_html( $label ) . '</a>';
					if ( ! $use['active'] ) {
						echo ' <span class="mcgf-tag">' . esc_html__( 'inactiva', 'multicorreo-gf' ) . '</span>';
					}
					if ( $use['form_trash'] ) {
						echo ' <span class="mcgf-tag">' . esc_html__( 'en la papelera', 'multicorreo-gf' ) . '</span>';
					}
					echo '</li>';
				}
				echo '</ul>';
			} else {
				echo '<span class="mcgf-muted">' . esc_html__( 'Ninguna notificación', 'multicorreo-gf' ) . '</span>';
			}
			echo '</td>';

			echo '<td>' . esc_html( $list['updated'] ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $list['updated'] ), get_option( 'date_format' ) . ' H:i' ) : '—' ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Create / edit form.
	 *
	 * @return void
	 */
	private function render_form() {

		if ( null !== $this->posted ) {
			$id   = (int) $this->posted['id'];
			$name = $this->posted['name'];
			$text = $this->posted['text'];
		} else {
			$id   = isset( $_GET['list'] ) ? absint( $_GET['list'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$list = $id ? MCGF_Lists::get( $id ) : null;
			$id   = $list ? $id : 0;
			$name = $list ? $list['name'] : '';
			$text = $list ? implode( "\n", $list['emails'] ) : '';
		}

		$usage = MCGF_Lists::usage();
		$uses  = $id && isset( $usage[ $id ] ) ? $usage[ $id ] : array();

		echo '<h1>' . esc_html( $id ? __( 'Editar lista', 'multicorreo-gf' ) : __( 'Nueva lista', 'multicorreo-gf' ) ) . '</h1>';
		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'Volver a las listas', 'multicorreo-gf' ) . '</a></p>';

		if ( $this->errors ) {
			echo '<div class="notice notice-error"><p>' . implode( '</p><p>', array_map( 'esc_html', $this->errors ) ) . '</p></div>';
		}

		echo '<form method="post" class="mcgf-form">';
		wp_nonce_field( 'mcgf_save_list' );
		echo '<input type="hidden" name="mcgf_save_list" value="1">';
		echo '<input type="hidden" name="list_id" value="' . esc_attr( $id ) . '">';

		echo '<div class="mcgf-columns"><div class="mcgf-col-main">';

		echo '<p><label for="mcgf-name"><strong>' . esc_html__( 'Nombre de la lista', 'multicorreo-gf' ) . '</strong></label><br>';
		echo '<input type="text" id="mcgf-name" name="list_name" class="regular-text" maxlength="100" required value="' . esc_attr( $name ) . '" placeholder="' . esc_attr__( 'Ej.: Ventas Bogotá', 'multicorreo-gf' ) . '"></p>';

		echo '<p><label for="mcgf-emails"><strong>' . esc_html__( 'Correos (uno por línea)', 'multicorreo-gf' ) . '</strong></label><br>';
		echo '<textarea id="mcgf-emails" name="list_emails" rows="16" class="large-text code" spellcheck="false" placeholder="ventas1@tuempresa.com&#10;ventas2@tuempresa.com&#10;ventas2@tuempresa.com">' . esc_textarea( $text ) . '</textarea></p>';
		echo '<p class="description">' . esc_html__( 'Sin límite. Repite un correo para darle más cupos: cada vez que aparece es un envío más por ronda. También se aceptan correos separados por comas o punto y coma.', 'multicorreo-gf' ) . '</p>';

		if ( $id ) {
			echo '<p class="description">' . esc_html__( 'Si cambias los correos, las rondas de esta lista empiezan de nuevo con la lista nueva. Los envíos que estén pendientes de reintento se mantienen con el correo que ya tenían asignado.', 'multicorreo-gf' ) . '</p>';
		}

		submit_button( $id ? __( 'Guardar lista', 'multicorreo-gf' ) : __( 'Crear lista', 'multicorreo-gf' ) );

		echo '</div><div class="mcgf-col-side">';

		echo '<div class="mcgf-card"><h2>' . esc_html__( 'Resumen', 'multicorreo-gf' ) . '</h2>';
		echo '<p class="mcgf-summary" id="mcgf-summary">—</p>';
		echo '<table class="mcgf-preview" id="mcgf-preview"><thead><tr><th>' . esc_html__( 'Correo', 'multicorreo-gf' ) . '</th><th class="num">' . esc_html__( 'Cupos', 'multicorreo-gf' ) . '</th><th class="num">%</th></tr></thead><tbody></tbody></table>';
		echo '<p class="mcgf-invalid" id="mcgf-invalid" hidden></p>';
		echo '</div>';

		if ( $uses ) {
			echo '<div class="mcgf-card"><h2>' . esc_html__( 'Usada en', 'multicorreo-gf' ) . '</h2><ul class="mcgf-uses">';
			foreach ( $uses as $use ) {
				echo '<li><a href="' . esc_url( self::notification_url( $use['form_id'], $use['nid'] ) ) . '">' . esc_html( $use['form_title'] . ' › ' . $use['name'] ) . '</a></li>';
			}
			echo '</ul></div>';
		}

		echo '</div></div></form>';
	}
}

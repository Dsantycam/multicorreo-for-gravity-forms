<?php
/**
 * Choose which roles can manage Multicorreo (lists and distribution panel).
 *
 * The pages check a custom capability that administrators always have and
 * that is granted on the fly to the roles selected in Multicorreo ›
 * Permisos. Roles are not modified in the database, so deactivating the
 * plugin leaves nothing behind.
 *
 * @package MulticorreoGF
 */

defined( 'WPINC' ) || exit;

/**
 * Roles class.
 */
class MCGF_Roles {

	const CAP    = 'mcgf_manage';
	const OPTION = 'mcgf_roles';
	const PAGE   = 'mcgf-permissions';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_filter( 'user_has_cap', array( $this, 'grant_cap' ), 10, 4 );
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	/**
	 * Give the capability to admins and to the selected roles.
	 *
	 * @param array   $allcaps All capabilities.
	 * @param array   $caps    Required primitive capabilities.
	 * @param array   $args    Arguments.
	 * @param WP_User $user    User.
	 * @return array
	 */
	public function grant_cap( $allcaps, $caps, $args, $user ) {

		if ( ! in_array( self::CAP, (array) $caps, true ) ) {
			return $allcaps;
		}

		if ( ! empty( $allcaps['manage_options'] ) || array_intersect( (array) $user->roles, self::roles() ) ) {
			$allcaps[ self::CAP ] = true;
		}

		return $allcaps;
	}

	/**
	 * Selected roles.
	 *
	 * @return array
	 */
	public static function roles() {
		return array_values( array_filter( (array) get_option( self::OPTION, array() ), 'is_string' ) );
	}

	/**
	 * Settings page (administrators only).
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			MCGF_Admin::PAGE_LISTS,
			__( 'Permisos de Multicorreo', 'multicorreo-gf' ),
			__( 'Permisos', 'multicorreo-gf' ),
			'manage_options',
			self::PAGE,
			array( $this, 'page' )
		);
	}

	/**
	 * Register the option.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	/**
	 * Keep only existing roles.
	 *
	 * @param mixed $input Submitted roles.
	 * @return array
	 */
	public function sanitize( $input ) {
		$valid = array_keys( wp_roles()->get_names() );
		return array_values( array_intersect( array_map( 'sanitize_key', (array) $input ), $valid ) );
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function page() {

		$selected = self::roles();

		echo '<div class="wrap mcgf-wrap"><h1>' . esc_html__( 'Permisos de Multicorreo', 'multicorreo-gf' ) . '</h1>';
		echo '<p>' . esc_html__( 'Elige qué roles, además de los administradores, pueden crear y editar las listas de correos y ver el panel de reparto.', 'multicorreo-gf' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Para elegir una lista dentro de una notificación también hace falta poder editar formularios en Gravity Forms.', 'multicorreo-gf' ) . '</p>';
		echo '<form method="post" action="options.php">';

		settings_fields( self::PAGE );

		echo '<fieldset>';

		foreach ( wp_roles()->get_names() as $role => $name ) {
			if ( 'administrator' === $role ) {
				continue;
			}

			printf(
				'<p><label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label></p>',
				esc_attr( self::OPTION ),
				esc_attr( $role ),
				checked( in_array( $role, $selected, true ), true, false ),
				esc_html( translate_user_role( $name ) )
			);
		}

		echo '</fieldset>';

		submit_button();

		echo '</form></div>';
	}
}

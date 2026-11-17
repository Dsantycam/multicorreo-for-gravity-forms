<?php
/**
 * Plugin Name: Multicorreo for Gravity Forms
 * Plugin URI:  https://github.com/Dsantycam/multicorreo-for-gravity-forms
 * Description: Reparte las notificaciones de <strong>Gravity Forms</strong> entre listas de correos. Reparto <strong>equitativo por cupos</strong> (bolsa barajada por formulario), asignación <strong>fija por persona</strong> durante 12 horas y <strong>reintentos automáticos</strong> para que ningún envío se pierda.
 * Version:     1.0.0
 * Author:      Santiago Camacho
 * Author URI:  https://santiagocamachomkt.com
 * License:     GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Requires at least: 5.0.0
 * Tested up to: 6.9
 * Requires PHP: 7.0
 * Text Domain: multicorreo-gf
 *
 * @package MulticorreoGF
 */

defined( 'WPINC' ) || exit;

define( 'MCGF_VERSION', '1.0.0' );
define( 'MCGF_FILE', __FILE__ );
define( 'MCGF_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCGF_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimum Gravity Forms version (notification settings use the 2.5 framework).
 */
define( 'MCGF_MIN_GF', '2.5' );

/**
 * GitHub repository the updates are downloaded from (public repo + Releases).
 */
define( 'MCGF_GITHUB', 'https://github.com/Dsantycam/multicorreo-for-gravity-forms/' );

require_once MCGF_DIR . 'includes/class-mcgf-lists.php';
require_once MCGF_DIR . 'includes/class-mcgf-stats.php';
require_once MCGF_DIR . 'includes/class-mcgf-queue.php';
require_once MCGF_DIR . 'includes/class-mcgf-bag.php';

register_activation_hook( __FILE__, 'mcgf_activate' );
register_deactivation_hook( __FILE__, 'mcgf_deactivate' );
add_action( 'plugins_loaded', 'mcgf_load_plugin' );

/**
 * Create the tables on activation (updates create them on first use).
 *
 * @return void
 */
function mcgf_activate() {
	MCGF_Stats::install();
	MCGF_Queue::install();
}

/**
 * Stop the retry cron on deactivation (data is kept).
 *
 * @return void
 */
function mcgf_deactivate() {
	wp_clear_scheduled_hook( MCGF_Queue::CRON );
}

/**
 * Is a compatible Gravity Forms active?
 *
 * @return bool
 */
function mcgf_gf_ready() {
	return class_exists( 'GFForms' ) && class_exists( 'GFCommon' ) && version_compare( GFCommon::$version, MCGF_MIN_GF, '>=' );
}

/**
 * Start everything.
 *
 * @return void
 */
function mcgf_load_plugin() {

	// Always (admin, cron and WP-CLI), so background and CLI updates see it.
	mcgf_init_updater();

	if ( ! mcgf_gf_ready() ) {
		add_action( 'admin_notices', 'mcgf_gf_required_notice' );
		return;
	}

	require_once MCGF_DIR . 'includes/class-mcgf-roles.php';
	require_once MCGF_DIR . 'includes/class-mcgf-assigner.php';
	require_once MCGF_DIR . 'includes/class-mcgf-notifications.php';

	new MCGF_Roles();
	MCGF_Notifications::instance();
	MCGF_Queue::hooks();

	if ( is_admin() ) {
		require_once MCGF_DIR . 'admin/class-mcgf-admin.php';
		require_once MCGF_DIR . 'admin/class-mcgf-dashboard.php';
		new MCGF_Admin();
		new MCGF_Dashboard();
	}
}

/**
 * Gravity Forms missing / too old.
 *
 * @return void
 */
function mcgf_gf_required_notice() {

	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p><strong>Multicorreo for Gravity Forms</strong> — ' .
		esc_html(
			sprintf(
				/* translators: %s: minimum Gravity Forms version */
				__( 'necesita Gravity Forms %s o superior instalado y activo.', 'multicorreo-gf' ),
				MCGF_MIN_GF
			)
		) .
		'</p></div>';
}

/**
 * GitHub based Plugin Update Checker (PUC v5).
 *
 * Publish an update by creating a GitHub "Release" whose tag is the new
 * version (the same as "Version:" in this header and "Stable tag" in readme).
 *
 * @return void
 */
function mcgf_init_updater() {

	require_once MCGF_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

	$checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		MCGF_GITHUB,
		MCGF_FILE,
		'multicorreo-for-gravity-forms'
	);

	// Install the clean zip attached to the release (multicorreo-for-gravity-forms.zip).
	// Without it, the source code zip of the tag is used instead.
	$checker->getVcsApi()->enableReleaseAssets( '/multicorreo-for-gravity-forms\.zip$/i' );
}

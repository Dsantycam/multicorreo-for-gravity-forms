<?php
/**
 * Fired when the plugin is uninstalled: removes every Multicorreo data.
 *
 * The assignment stored in each Gravity Forms entry (meta mcgf_assigned /
 * mcgf_email) and the entry notes are kept: they are part of the entry
 * history.
 *
 * @package MulticorreoGF
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'mcgf_lists' );
delete_option( 'mcgf_roles' );
delete_option( 'mcgf_stats_db' );
delete_option( 'mcgf_queue_db' );
delete_transient( 'mcgf_stats_install_retry' );
delete_transient( 'mcgf_queue_install_retry' );

// Bags (one option per list and form) and sticky transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mcgf\_bag\_%' OR option_name LIKE '\_transient\_mcgf\_st\_%' OR option_name LIKE '\_transient\_timeout\_mcgf\_st\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcgf_stats" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcgf_queue" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

wp_clear_scheduled_hook( 'mcgf_retry_queue' );

<?php
/**
 * Uninstall: remove the delivery log, endpoints and settings.
 *
 * @package NodeThemes_Webhooks
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ntwh_deliveries" ); // phpcs:ignore WordPress.DB
foreach ( array( 'ntwh_endpoints', 'ntwh_health', 'ntwh_settings', 'ntwh_db_version' ) as $ntwh_o ) {
	delete_option( $ntwh_o );
}
delete_site_transient( 'ntwh_free_update' );
wp_clear_scheduled_hook( 'ntwh_cleanup' );
wp_clear_scheduled_hook( 'ntwh_heal' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'ntwh_deliver' );
}
// Order meta (_ntwh_created / _ntwh_paid) is kept on purpose: it only prevents duplicate events after a reinstall.

<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package JetlinezWooCommerceInvoice
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'jlwi_send_daily_report' );
wp_clear_scheduled_hook( 'jlwi_send_weekly_report' );

$settings = get_option( 'jlwi_settings', array() );
if ( ! is_array( $settings ) || 'yes' !== ( isset( $settings['delete_data_on_uninstall'] ) ? $settings['delete_data_on_uninstall'] : 'no' ) ) {
	return;
}

delete_option( 'jlwi_settings' );
delete_option( 'jlwi_daily_report_state' );
delete_option( 'jlwi_weekly_report_state' );
delete_transient( 'jlwi_daily_report_lock' );
delete_transient( 'jlwi_weekly_report_lock' );

global $wpdb;
$like = $wpdb->esc_like( 'jlwi_lock_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

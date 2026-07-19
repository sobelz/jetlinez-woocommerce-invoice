<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package JetlinezWooCommerceInvoice
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'jlwi_settings', array() );
if ( ! is_array( $settings ) || 'yes' !== ( isset( $settings['delete_data_on_uninstall'] ) ? $settings['delete_data_on_uninstall'] : 'no' ) ) {
	return;
}

delete_option( 'jlwi_settings' );

global $wpdb;
$like = $wpdb->esc_like( 'jlwi_lock_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

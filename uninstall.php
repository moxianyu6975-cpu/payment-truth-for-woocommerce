<?php
/**
 * Optional clean uninstall.
 *
 * @package PaymentTruthForWooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ptwc_settings = get_option( 'ptwc_settings', array() );
if ( empty( $ptwc_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$ptwc_table = $wpdb->prefix . 'ptwc_findings';
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $ptwc_table ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
);

delete_option( 'ptwc_settings' );
delete_option( 'ptwc_db_version' );
delete_option( 'ptwc_last_scan' );
delete_transient( 'ptwc_scan_lock' );

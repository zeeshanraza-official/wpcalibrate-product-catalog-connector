<?php
/**
 * Uninstallation cleanup for WPCalibrate Product Catalog Connector.
 *
 * @package WPCalibrate\ProductCatalogConnector
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Check if user chose to preserve data on uninstall.
$settings = get_option( 'wpcal_pcc_settings', [] );
$keep_data = is_array( $settings ) && ! empty( $settings['keep_data_on_uninstall'] );

if ( ! $keep_data ) {
	// 1. Delete plugin options.
	delete_option( 'wpcal_pcc_settings' );
	delete_option( 'wpcal_pcc_feed_token' );
	delete_option( 'wpcal_pcc_feed_token_meta' );
	delete_option( 'wpcal_pcc_db_version' );
	delete_option( 'wpcal_pcc_version' );
	delete_option( 'wpcal_pcc_activated_at' );
	delete_option( 'wpcal_pcc_catalog_dirty' );
	delete_option( 'wpcal_pcc_pinterest_tokens' );
	delete_option( 'wpcal_pcc_pinterest_state' );
	delete_option( 'wpcal_pcc_license_key' );
	delete_option( 'wpcal_pcc_license_status' );

	// 2. Delete plugin transients.
	delete_transient( 'wpcal_pcc_generation_lock' );

	// 3. Clear scheduled hooks.
	wp_clear_scheduled_hook( 'wpcal_pcc_scheduled_sync' );
	wp_clear_scheduled_hook( 'wpcal_pcc_dirty_sync' );
	wp_clear_scheduled_hook( 'wpcal_pcc_start_sync' );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'wpcal_pcc_start_sync', [], 'wpcalibrate-pcc' );
		as_unschedule_all_actions( 'wpcal_pcc_scheduled_sync', [], 'wpcalibrate-pcc' );
		as_unschedule_all_actions( 'wpcal_pcc_process_batch', [], 'wpcalibrate-pcc' );
		as_unschedule_all_actions( 'wpcal_pcc_dirty_sync', [], 'wpcalibrate-pcc' );
	}

	// 4. Remove generated feed files from uploads directory.
	$upload_dir = wp_upload_dir();
	$feed_dir = trailingslashit( $upload_dir['basedir'] ) . 'wpcalibrate-catalog-feeds';

	if ( is_dir( $feed_dir ) ) {
		$files = glob( $feed_dir . '/*' );
		if ( false !== $files ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					@unlink( $file );
				}
			}
		}
		@rmdir( $feed_dir );
	}

	// 5. Drop custom database tables.
	global $wpdb;
	$sync_runs_table = $wpdb->prefix . 'wpcal_pcc_sync_runs';
	$item_issues_table = $wpdb->prefix . 'wpcal_pcc_item_issues';

	$wpdb->query( "DROP TABLE IF EXISTS {$sync_runs_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$item_issues_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

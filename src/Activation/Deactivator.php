<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Activation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes plugin deactivation cleanups.
 */
class Deactivator {
	/**
	 * Run deactivation logic.
	 */
	public static function deactivate(): void {
		// Clear WP-Cron scheduled hooks.
		wp_clear_scheduled_hook( 'wpcal_pcc_scheduled_sync' );
		wp_clear_scheduled_hook( 'wpcal_pcc_dirty_sync' );
		wp_clear_scheduled_hook( 'wpcal_pcc_start_sync' );

		// Clear Action Scheduler actions if present.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'wpcal_pcc_start_sync', [], 'wpcalibrate-pcc' );
			as_unschedule_all_actions( 'wpcal_pcc_scheduled_sync', [], 'wpcalibrate-pcc' );
			as_unschedule_all_actions( 'wpcal_pcc_process_batch', [], 'wpcalibrate-pcc' );
			as_unschedule_all_actions( 'wpcal_pcc_dirty_sync', [], 'wpcalibrate-pcc' );
		}

		// Release any active locks.
		delete_transient( 'wpcal_pcc_generation_lock' );
		delete_option( 'wpcal_pcc_catalog_dirty' );
	}
}

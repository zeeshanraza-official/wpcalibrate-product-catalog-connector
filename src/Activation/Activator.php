<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Activation;

use WPCalibrate\ProductCatalogConnector\Feed\FeedToken;
use WPCalibrate\ProductCatalogConnector\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes plugin activation tasks safely.
 */
class Activator {
	/**
	 * Run activation logic.
	 */
	public static function activate(): void {
		// Verify minimum PHP requirement.
		if ( version_compare( PHP_VERSION, WPCAL_PCC_MIN_PHP_VERSION, '<' ) ) {
			deactivate_plugins( WPCAL_PCC_BASENAME );
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: Required PHP version, 2: Current PHP version */
						__( 'WPCalibrate Product Catalog Connector requires PHP %1$s or higher. Your server is running PHP %2$s.', 'wpcalibrate-product-catalog-connector' ),
						WPCAL_PCC_MIN_PHP_VERSION,
						PHP_VERSION
					)
				)
			);
		}

		// Initialize default settings if not already present.
		$existing = get_option( Settings::OPTION_NAME, null );
		if ( null === $existing ) {
			update_option( Settings::OPTION_NAME, Settings::get_defaults(), false );
		}

		// Generate secure initial feed token.
		FeedToken::get_token();

		// Run database migrations.
		Migrator::migrate();

		// Set initial activation metadata.
		update_option( 'wpcal_pcc_version', WPCAL_PCC_VERSION, false );
		update_option( 'wpcal_pcc_activated_at', time(), false );

		// Schedule initial async catalog sync safely.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'wpcal_pcc_start_sync', [ 'activation' ], 'wpcalibrate-pcc' );
		} else {
			wp_schedule_single_event( time() + 30, 'wpcal_pcc_start_sync', [ 'activation' ] );
		}
	}
}

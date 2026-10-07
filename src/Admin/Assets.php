<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles asset registration and conditional enqueuing strictly on plugin screens.
 */
class Assets {
	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue styles and scripts only on our plugin administration page.
	 *
	 * @param mixed $hook Screen hook suffix.
	 */
	public function enqueue_assets( mixed $hook = '' ): void {
		$hook = (string) $hook;
		// Only enqueue on our plugin page: wpcalibrate_page_wpcalibrate-product-catalog-connector.
		if ( empty( $hook ) || ! str_contains( $hook, Menu::PAGE_SLUG ) ) {
			return;
		}

		// Use filemtime for asset cache busting so browser caches immediately refresh upon updates.
		$css_file = WPCAL_PCC_PATH . 'assets/css/admin.css';
		$js_file  = WPCAL_PCC_PATH . 'assets/js/admin.js';
		$css_ver  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : WPCAL_PCC_VERSION;
		$js_ver   = file_exists( $js_file ) ? (string) filemtime( $js_file ) : WPCAL_PCC_VERSION;

		// Enqueue WordPress core dependencies for tabs, code editors, or tooltips if needed.
		wp_enqueue_style(
			'wpcal-pcc-admin',
			WPCAL_PCC_URL . 'assets/css/admin.css',
			[],
			$css_ver
		);

		// If RTL language is active, also enqueue RTL stylesheet if available.
		if ( is_rtl() ) {
			wp_enqueue_style(
				'wpcal-pcc-admin-rtl',
				WPCAL_PCC_URL . 'assets/css/admin-rtl.css',
				[ 'wpcal-pcc-admin' ],
				$css_ver
			);
		}

		wp_enqueue_script(
			'wpcal-pcc-admin',
			WPCAL_PCC_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			$js_ver,
			true
		);

		wp_localize_script(
			'wpcal-pcc-admin',
			'wpcalPccData',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'wpcal_pcc_admin_action' ),
				'strings'   => [
					'copied'        => __( 'Copied to clipboard!', 'wpcalibrate-product-catalog-connector' ),
					'copyFailed'    => __( 'Failed to copy. Please select and copy manually.', 'wpcalibrate-product-catalog-connector' ),
					'confirmRotate' => __( 'Are you sure you want to rotate the feed token? The existing feed URL will immediately stop working until updated in Pinterest.', 'wpcalibrate-product-catalog-connector' ),
					'confirmSync'   => __( 'Start manual catalog feed regeneration now?', 'wpcalibrate-product-catalog-connector' ),
				],
			]
		);
	}
}

<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Compatibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks High-Performance Order Storage (HPOS) status.
 */
class HPOS {
	/**
	 * Check if HPOS is active and in use.
	 */
	public static function is_hpos_enabled(): bool {
		if ( ! WooCommerce::is_active() ) {
			return false;
		}

		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && method_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class, 'custom_orders_table_usage_is_enabled' ) ) {
			return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		return false;
	}

	/**
	 * Get human-readable HPOS status label.
	 */
	public static function get_status_label(): string {
		if ( ! WooCommerce::is_active() ) {
			return __( 'N/A (WooCommerce not active)', 'wpcalibrate-product-catalog-connector' );
		}

		return self::is_hpos_enabled()
			? __( 'Enabled (Custom Order Tables active)', 'wpcalibrate-product-catalog-connector' )
			: __( 'Standard (Posts table or HPOS sync mode)', 'wpcalibrate-product-catalog-connector' );
	}
}

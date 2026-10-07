<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Compatibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks WooCommerce availability and environment state.
 */
class WooCommerce {
	/**
	 * Check if WooCommerce is installed and active.
	 */
	public static function is_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Get current WooCommerce version.
	 */
	public static function get_version(): string {
		if ( defined( 'WC_VERSION' ) ) {
			return (string) WC_VERSION;
		}

		return '0.0.0';
	}

	/**
	 * Check if WooCommerce meets minimum version requirement.
	 */
	public static function is_supported_version(): bool {
		if ( ! self::is_active() ) {
			return false;
		}

		return version_compare( self::get_version(), WPCAL_PCC_MIN_WC_VERSION, '>=' );
	}
}

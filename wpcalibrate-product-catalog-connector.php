<?php
/**
 * Plugin Name:       WPCalibrate Product Catalog Connector
 * Plugin URI:        https://marketplace.wpcalibrate.com/wpcalibrate-product-catalog-connector
 * Description:       Synchronize WooCommerce product catalogs with Pinterest using reliable hosted feed URLs (CSV/TSV) and optional Pinterest API v5 integration.
 * Version:           1.0.2
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            WPCalibrate
 * Author URI:        https://wpcalibrate.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpcalibrate-product-catalog-connector
 * Domain Path:       /languages
 * WC requires at least: 8.5
 * WC tested up to:   11.1
 *
 * @package WPCalibrate\ProductCatalogConnector
 */

declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
if ( ! defined( 'WPCAL_PCC_VERSION' ) ) {
	define( 'WPCAL_PCC_VERSION', '1.0.2' );
}
if ( ! defined( 'WPCAL_PCC_FILE' ) ) {
	define( 'WPCAL_PCC_FILE', __FILE__ );
}
if ( ! defined( 'WPCAL_PCC_PATH' ) ) {
	define( 'WPCAL_PCC_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WPCAL_PCC_URL' ) ) {
	define( 'WPCAL_PCC_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'WPCAL_PCC_BASENAME' ) ) {
	define( 'WPCAL_PCC_BASENAME', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'WPCAL_PCC_TEXT_DOMAIN' ) ) {
	define( 'WPCAL_PCC_TEXT_DOMAIN', 'wpcalibrate-product-catalog-connector' );
}
if ( ! defined( 'WPCAL_PCC_MIN_PHP_VERSION' ) ) {
	define( 'WPCAL_PCC_MIN_PHP_VERSION', '8.2' );
}
if ( ! defined( 'WPCAL_PCC_MIN_WP_VERSION' ) ) {
	define( 'WPCAL_PCC_MIN_WP_VERSION', '6.4' );
}
if ( ! defined( 'WPCAL_PCC_MIN_WC_VERSION' ) ) {
	define( 'WPCAL_PCC_MIN_WC_VERSION', '8.5' );
}

/**
 * Autoloader for WPCalibrate\ProductCatalogConnector namespace.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'WPCalibrate\\ProductCatalogConnector\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$file = WPCAL_PCC_PATH . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Declare WooCommerce HPOS and Cart/Checkout Blocks compatibility.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WPCAL_PCC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WPCAL_PCC_FILE, true );
		}
	}
);

/**
 * Activation hook.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		Activation\Activator::activate();
	}
);

/**
 * Deactivation hook.
 */
register_deactivation_hook(
	__FILE__,
	static function (): void {
		Activation\Deactivator::deactivate();
	}
);

/**
 * Bootstrap the plugin on plugins_loaded.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		// Verify minimum PHP requirement.
		if ( version_compare( PHP_VERSION, WPCAL_PCC_MIN_PHP_VERSION, '<' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
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
			);
			return;
		}

		// Initialize plugin singleton.
		Plugin::get_instance()->init();
	},
	10
);

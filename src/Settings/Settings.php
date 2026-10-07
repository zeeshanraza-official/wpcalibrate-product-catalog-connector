<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages plugin settings storage, retrieval, defaults, and security isolation.
 */
class Settings {
	public const OPTION_NAME = 'wpcal_pcc_settings';

	/**
	 * Get default configuration values.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		$default_currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'USD';
		$base_location    = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : null;
		$default_country  = ( is_array( $base_location ) && isset( $base_location['country'] ) && '' !== $base_location['country'] )
			? (string) $base_location['country']
			: 'US';
		$default_locale   = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';

		return [
			// Catalog
			'mode'                    => 'feed_only',
			'feed_format'             => 'csv',
			'country'                 => $default_country,
			'locale'                  => $default_locale,
			'currency'                => $default_currency,
			'schedule'                => 'daily',
			'http_basic_auth_enabled' => false,
			'http_basic_auth_user'    => '',
			'http_basic_auth_pass'    => '',

			// Product Selection / Eligibility
			'included_categories'     => [],
			'excluded_categories'     => [],
			'included_products'       => [],
			'excluded_products'       => [],
			'included_types'          => [ 'simple', 'variable' ],
			'out_of_stock_action'     => 'include',
			'include_variations'      => true,
			'exclude_missing_image'   => true,
			'exclude_missing_price'   => true,
			'include_virtual'         => true,
			'include_downloadable'    => true,

			// Field Mapping
			'description_source'      => 'short_first',
			'brand_source'            => 'none',
			'brand_attribute'         => '',
			'brand_custom_field'      => '',
			'condition_default'       => 'new',
			'google_product_category_default' => '',
			'google_product_category_source'  => 'static',
			'gtin_source'             => 'sku',
			'gtin_custom_field'       => '',
			'mpn_source'              => 'sku',
			'mpn_custom_field'        => '',
			'additional_images_count' => 5,

			// Pinterest API
			'pinterest_app_id'        => '',
			'pinterest_app_secret'    => '',
			'pinterest_ad_account_id' => '',
			'pinterest_catalog_id'    => '',
			'pinterest_feed_id'       => '',

			// Advanced / System
			'logging_level'           => 'standard',
			'batch_size'              => 50,
			'keep_data_on_uninstall'  => false,
		];
	}

	/**
	 * Get all active plugin settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_all(): array {
		$stored = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return wp_parse_args( $stored, self::get_defaults() );
	}

	/**
	 * Get a specific setting by key.
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		$all = self::get_all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $default;
	}

	/**
	 * Save updated settings.
	 *
	 * @param array<string, mixed> $input
	 * @return bool
	 */
	public static function update( array $input ): bool {
		$sanitized = Sanitizer::sanitize( $input );
		return update_option( self::OPTION_NAME, $sanitized, false );
	}

	/**
	 * Mask sensitive secrets for UI display.
	 */
	public static function mask_secret( string $secret ): string {
		$length = strlen( $secret );
		if ( 0 === $length ) {
			return '';
		}

		if ( $length <= 6 ) {
			return str_repeat( '•', $length );
		}

		return substr( $secret, 0, 3 ) . str_repeat( '•', max( 4, $length - 6 ) ) . substr( $secret, -3 );
	}
}

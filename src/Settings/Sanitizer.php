<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and sanitizes all plugin settings.
 */
class Sanitizer {
	/**
	 * Sanitize raw settings array into clean validated values.
	 *
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $raw ): array {
		$clean = [];

		// Catalog Mode.
		$mode = isset( $raw['mode'] ) ? sanitize_key( (string) $raw['mode'] ) : 'feed_only';
		$clean['mode'] = in_array( $mode, [ 'feed_only', 'api_feed' ], true ) ? $mode : 'feed_only';

		// Feed Format.
		$format = isset( $raw['feed_format'] ) ? strtolower( sanitize_key( (string) $raw['feed_format'] ) ) : 'csv';
		$clean['feed_format'] = in_array( $format, [ 'csv', 'tsv' ], true ) ? $format : 'csv';

		// Country (ISO 3166-1 alpha-2).
		$country = isset( $raw['country'] ) ? strtoupper( sanitize_text_field( (string) $raw['country'] ) ) : 'US';
		$clean['country'] = (bool) preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'US';

		// Locale (e.g. en-US, en_US).
		$locale = isset( $raw['locale'] ) ? sanitize_text_field( (string) $raw['locale'] ) : 'en_US';
		$clean['locale'] = (bool) preg_match( '/^[a-zA-Z]{2}([_-][a-zA-Z]{2})?$/', $locale ) ? $locale : 'en_US';

		// Currency (ISO 4217).
		$currency = isset( $raw['currency'] ) ? strtoupper( sanitize_text_field( (string) $raw['currency'] ) ) : 'USD';
		$clean['currency'] = (bool) preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'USD';

		// Schedule.
		$schedule = isset( $raw['schedule'] ) ? sanitize_key( (string) $raw['schedule'] ) : 'daily';
		$allowed_schedules = [ 'manual', 'twelve_hours', 'daily', 'twice_weekly', 'weekly' ];
		$clean['schedule'] = in_array( $schedule, $allowed_schedules, true ) ? $schedule : 'daily';

		// HTTP Basic Auth.
		$clean['http_basic_auth_enabled'] = ! empty( $raw['http_basic_auth_enabled'] );
		$clean['http_basic_auth_user']    = isset( $raw['http_basic_auth_user'] ) ? sanitize_user( (string) $raw['http_basic_auth_user'], true ) : '';
		
		// If password is submitted and not empty, update it; otherwise preserve existing.
		if ( isset( $raw['http_basic_auth_pass'] ) && '' !== trim( (string) $raw['http_basic_auth_pass'] ) ) {
			$clean['http_basic_auth_pass'] = sanitize_text_field( (string) $raw['http_basic_auth_pass'] );
		} else {
			$existing = Settings::get_all();
			$clean['http_basic_auth_pass'] = (string) ( $existing['http_basic_auth_pass'] ?? '' );
		}

		// Product Selection.
		$clean['included_categories'] = self::sanitize_int_array( $raw['included_categories'] ?? [] );
		$clean['excluded_categories'] = self::sanitize_int_array( $raw['excluded_categories'] ?? [] );
		$clean['included_products']   = self::sanitize_int_array( $raw['included_products'] ?? [] );
		$clean['excluded_products']   = self::sanitize_int_array( $raw['excluded_products'] ?? [] );

		$allowed_types = [ 'simple', 'variable', 'external', 'grouped' ];
		$types = isset( $raw['included_types'] ) && is_array( $raw['included_types'] ) ? array_map( 'sanitize_key', $raw['included_types'] ) : [ 'simple', 'variable' ];
		$clean['included_types'] = array_values( array_intersect( $types, $allowed_types ) );

		$stock_action = isset( $raw['out_of_stock_action'] ) ? sanitize_key( (string) $raw['out_of_stock_action'] ) : 'include';
		$clean['out_of_stock_action'] = in_array( $stock_action, [ 'include', 'exclude' ], true ) ? $stock_action : 'include';

		$clean['include_variations']     = ! empty( $raw['include_variations'] );
		$clean['exclude_missing_image']  = ! empty( $raw['exclude_missing_image'] );
		$clean['exclude_missing_price']  = ! empty( $raw['exclude_missing_price'] );
		$clean['include_virtual']        = ! empty( $raw['include_virtual'] );
		$clean['include_downloadable']   = ! empty( $raw['include_downloadable'] );

		// Field Mapping.
		$desc_source = isset( $raw['description_source'] ) ? sanitize_key( (string) $raw['description_source'] ) : 'short_first';
		$clean['description_source'] = in_array( $desc_source, [ 'short_first', 'full_first', 'short_only', 'full_only' ], true ) ? $desc_source : 'short_first';

		$clean['brand_source']       = isset( $raw['brand_source'] ) ? sanitize_key( (string) $raw['brand_source'] ) : 'none';
		$clean['brand_attribute']    = isset( $raw['brand_attribute'] ) ? sanitize_text_field( (string) $raw['brand_attribute'] ) : '';
		$clean['brand_custom_field'] = isset( $raw['brand_custom_field'] ) ? sanitize_key( (string) $raw['brand_custom_field'] ) : '';

		$clean['condition_default'] = isset( $raw['condition_default'] ) && in_array( $raw['condition_default'], [ 'new', 'refurbished', 'used' ], true )
			? (string) $raw['condition_default']
			: 'new';

		$clean['google_product_category_default'] = isset( $raw['google_product_category_default'] ) ? sanitize_text_field( (string) $raw['google_product_category_default'] ) : '';
		$clean['google_product_category_source']  = isset( $raw['google_product_category_source'] ) ? sanitize_key( (string) $raw['google_product_category_source'] ) : 'static';

		$clean['gtin_source']       = isset( $raw['gtin_source'] ) ? sanitize_key( (string) $raw['gtin_source'] ) : 'sku';
		$clean['gtin_custom_field'] = isset( $raw['gtin_custom_field'] ) ? sanitize_key( (string) $raw['gtin_custom_field'] ) : '';

		$clean['mpn_source']       = isset( $raw['mpn_source'] ) ? sanitize_key( (string) $raw['mpn_source'] ) : 'sku';
		$clean['mpn_custom_field'] = isset( $raw['mpn_custom_field'] ) ? sanitize_key( (string) $raw['mpn_custom_field'] ) : '';

		$images_count = isset( $raw['additional_images_count'] ) ? absint( $raw['additional_images_count'] ) : 5;
		$clean['additional_images_count'] = min( 10, max( 0, $images_count ) );

		// Pinterest API Settings.
		$clean['pinterest_app_id'] = isset( $raw['pinterest_app_id'] ) ? sanitize_text_field( trim( (string) $raw['pinterest_app_id'] ) ) : '';

		// Pinterest App Secret: If entered and not empty, update; if empty or masked placeholder, retain existing.
		if ( isset( $raw['pinterest_app_secret'] ) && '' !== trim( (string) $raw['pinterest_app_secret'] ) && ! str_contains( (string) $raw['pinterest_app_secret'], '••••' ) ) {
			$clean['pinterest_app_secret'] = sanitize_text_field( trim( (string) $raw['pinterest_app_secret'] ) );
		} else {
			$existing = Settings::get_all();
			$clean['pinterest_app_secret'] = (string) ( $existing['pinterest_app_secret'] ?? '' );
		}

		$clean['pinterest_ad_account_id'] = isset( $raw['pinterest_ad_account_id'] ) ? sanitize_text_field( trim( (string) $raw['pinterest_ad_account_id'] ) ) : '';
		$clean['pinterest_catalog_id']    = isset( $raw['pinterest_catalog_id'] ) ? sanitize_text_field( trim( (string) $raw['pinterest_catalog_id'] ) ) : '';
		$clean['pinterest_feed_id']       = isset( $raw['pinterest_feed_id'] ) ? sanitize_text_field( trim( (string) $raw['pinterest_feed_id'] ) ) : '';

		// System / Advanced.
		$log_level = isset( $raw['logging_level'] ) ? sanitize_key( (string) $raw['logging_level'] ) : 'standard';
		$clean['logging_level'] = in_array( $log_level, [ 'none', 'standard', 'debug' ], true ) ? $log_level : 'standard';

		$batch_size = isset( $raw['batch_size'] ) ? absint( $raw['batch_size'] ) : 50;
		$clean['batch_size'] = min( 250, max( 10, $batch_size ) );

		$clean['keep_data_on_uninstall'] = ! empty( $raw['keep_data_on_uninstall'] );

		return $clean;
	}

	/**
	 * Sanitize array of integers.
	 *
	 * @param mixed $input
	 * @return array<int>
	 */
	public static function sanitize_int_array( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			if ( is_string( $input ) && '' !== trim( $input ) ) {
				$input = explode( ',', $input );
			} else {
				return [];
			}
		}

		$ints = array_map( 'absint', $input );
		return array_values( array_unique( array_filter( $ints, static fn( int $val ) => $val > 0 ) ) );
	}
}

<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Catalog;

use WPCalibrate\ProductCatalogConnector\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles attribute value mapping, availability normalization, description sanitization, and price formatting.
 */
class FieldMapper {
	/**
	 * Map WooCommerce stock status to Pinterest standard availability enum.
	 * Pinterest standard values: 'in_stock', 'out_of_stock', 'preorder'.
	 */
	public static function map_availability( string $wc_stock_status, bool $is_in_stock ): string {
		if ( 'onbackorder' === $wc_stock_status ) {
			return 'preorder';
		}

		if ( ! $is_in_stock || 'outofstock' === $wc_stock_status ) {
			return 'out_of_stock';
		}

		return 'in_stock';
	}

	/**
	 * Format price according to Pinterest feed specification: numeric with ISO-4217 currency.
	 * Example: "19.99 USD".
	 */
	public static function format_price( mixed $amount, string $currency ): string {
		if ( '' === $amount || null === $amount ) {
			return '';
		}

		$numeric = (float) $amount;
		if ( $numeric < 0 ) {
			return '';
		}

		return sprintf( '%.2f %s', $numeric, strtoupper( trim( $currency ) ) );
	}

	/**
	 * Clean and format catalog description according to Pinterest requirements.
	 * Strips HTML and shortcodes, decodes entities, and enforces maximum length.
	 */
	public static function clean_description( string $raw_text, int $max_length = 9999 ): string {
		if ( '' === trim( $raw_text ) ) {
			return '';
		}

		// 1. Strip WordPress shortcodes.
		$clean = strip_shortcodes( $raw_text );

		// 2. Strip HTML tags.
		$clean = wp_strip_all_tags( $clean );

		// 3. Decode HTML entities.
		$clean = html_entity_decode( $clean, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 4. Normalize multiple whitespaces and newlines.
		$clean = (string) preg_replace( '/\s+/', ' ', $clean );
		$clean = trim( $clean );

		// 5. Length boundary check.
		if ( mb_strlen( $clean, 'UTF-8' ) > $max_length ) {
			$clean = mb_substr( $clean, 0, $max_length - 3, 'UTF-8' ) . '...';
		}

		return $clean;
	}

	/**
	 * Clean product title.
	 */
	public static function clean_title( string $raw_title, int $max_length = 500 ): string {
		$clean = wp_strip_all_tags( $raw_title );
		$clean = html_entity_decode( $clean, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$clean = (string) preg_replace( '/\s+/', ' ', $clean );
		$clean = trim( $clean );

		if ( mb_strlen( $clean, 'UTF-8' ) > $max_length ) {
			$clean = mb_substr( $clean, 0, $max_length - 3, 'UTF-8' ) . '...';
		}

		return $clean;
	}

	/**
	 * Get description based on configured priority.
	 */
	public static function resolve_description( \WC_Product $product, string $source_preference ): string {
		$short = $product->get_short_description();
		$full  = $product->get_description();

		$chosen = match ( $source_preference ) {
			'short_only' => $short,
			'full_only'  => $full,
			'full_first' => ! empty( trim( $full ) ) ? $full : $short,
			default      => ! empty( trim( $short ) ) ? $short : $full, // 'short_first'
		};

		return self::clean_description( $chosen );
	}

	/**
	 * Resolve brand using configured source and fallback chain.
	 */
	public static function resolve_brand( \WC_Product $product, array $settings ): string {
		$source = (string) ( $settings['brand_source'] ?? 'none' );

		if ( 'attribute' === $source && ! empty( $settings['brand_attribute'] ) ) {
			$val = $product->get_attribute( sanitize_text_field( $settings['brand_attribute'] ) );
			if ( ! empty( $val ) ) {
				return trim( $val );
			}
		}

		if ( 'custom_field' === $source && ! empty( $settings['brand_custom_field'] ) ) {
			$val = $product->get_meta( sanitize_key( $settings['brand_custom_field'] ), true );
			if ( ! empty( $val ) && is_scalar( $val ) ) {
				return trim( (string) $val );
			}
		}

		// Fallback check: 'brand' or 'pa_brand' taxonomy/attribute if present.
		$brand_attr = $product->get_attribute( 'brand' ) ?: $product->get_attribute( 'pa_brand' );
		if ( ! empty( $brand_attr ) ) {
			return trim( $brand_attr );
		}

		return '';
	}

	/**
	 * Resolve GTIN or MPN identifier with fallback chain.
	 */
	public static function resolve_identifier( \WC_Product $product, string $source_type, string $custom_field ): string {
		if ( 'sku' === $source_type ) {
			$sku = $product->get_sku();
			if ( ! empty( $sku ) ) {
				return trim( $sku );
			}
		}

		if ( 'custom_field' === $source_type && ! empty( $custom_field ) ) {
			$val = $product->get_meta( sanitize_key( $custom_field ), true );
			if ( ! empty( $val ) && is_scalar( $val ) ) {
				return trim( (string) $val );
			}
		}

		return '';
	}

	/**
	 * Resolve Google Product Category.
	 */
	public static function resolve_google_product_category( \WC_Product $product, array $settings ): string {
		$source = (string) ( $settings['google_product_category_source'] ?? 'static' );

		if ( 'custom_field' === $source ) {
			$val = $product->get_meta( '_google_product_category', true ) ?: $product->get_meta( 'google_product_category', true );
			if ( ! empty( $val ) && is_scalar( $val ) ) {
				return trim( (string) $val );
			}
		}

		if ( 'category' === $source ) {
			$cats = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
			if ( ! empty( $cats ) && is_array( $cats ) && ! is_wp_error( $cats ) ) {
				return implode( ' > ', $cats );
			}
		}

		return (string) ( $settings['google_product_category_default'] ?? '' );
	}

	/**
	 * Resolve product category breadcrumb (product_type field).
	 */
	public static function resolve_product_type( \WC_Product $product ): string {
		$terms = wp_get_post_terms( $product->get_id(), 'product_cat' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		$primary_term = $terms[0];
		$ancestors    = get_ancestors( $primary_term->term_id, 'product_cat', 'taxonomy' );
		$hierarchy    = [];

		if ( ! empty( $ancestors ) && is_array( $ancestors ) ) {
			$ancestors = array_reverse( $ancestors );
			foreach ( $ancestors as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, 'product_cat' );
				if ( $ancestor && ! is_wp_error( $ancestor ) ) {
					$hierarchy[] = $ancestor->name;
				}
			}
		}

		$hierarchy[] = $primary_term->name;
		return implode( ' > ', $hierarchy );
	}

	/**
	 * Retrieve product gallery image URLs (excluding main image).
	 *
	 * @return array<string>
	 */
	public static function get_gallery_image_urls( \WC_Product $product, int $limit = 5 ): array {
		$gallery_ids = $product->get_gallery_image_ids();
		if ( empty( $gallery_ids ) ) {
			return [];
		}

		$urls = [];
		foreach ( $gallery_ids as $attachment_id ) {
			if ( count( $urls ) >= $limit ) {
				break;
			}
			$src = wp_get_attachment_url( $attachment_id );
			if ( $src && filter_var( $src, FILTER_VALIDATE_URL ) ) {
				$urls[] = $src;
			}
		}

		return array_unique( $urls );
	}
}

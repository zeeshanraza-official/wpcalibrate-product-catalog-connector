<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Catalog;

use WPCalibrate\ProductCatalogConnector\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluates whether products and variations meet eligibility criteria for catalog export.
 */
class EligibilityService {
	/**
	 * @var array<string, mixed>
	 */
	private array $settings;

	public function __construct( ?array $settings = null ) {
		$this->settings = $settings ?? Settings::get_all();
	}

	/**
	 * Evaluate product eligibility.
	 *
	 * @param \WC_Product $product
	 * @return array{eligible: bool, reason: string}
	 */
	public function is_product_eligible( \WC_Product $product ): array {
		$product_id = $product->get_id();

		// 1. Post Status.
		if ( 'publish' !== $product->get_status() ) {
			return [
				'eligible' => false,
				'reason'   => sprintf( 'Status is "%s" (only published products exported)', $product->get_status() ),
			];
		}

		// 2. Password Protection.
		$post = get_post( $product_id );
		if ( $post && ! empty( $post->post_password ) ) {
			return [
				'eligible' => false,
				'reason'   => 'Product is password protected',
			];
		}

		// 3. Catalog Visibility.
		$visibility = $product->get_catalog_visibility();
		if ( 'hidden' === $visibility ) {
			return [
				'eligible' => false,
				'reason'   => 'Catalog visibility is set to hidden',
			];
		}

		// 4. Product Type.
		$type = $product->get_type();
		$included_types = (array) ( $this->settings['included_types'] ?? [ 'simple', 'variable' ] );
		if ( ! in_array( $type, $included_types, true ) ) {
			return [
				'eligible' => false,
				'reason'   => sprintf( 'Product type "%s" is excluded by settings', $type ),
			];
		}

		// 5. Explicit Product ID Inclusions / Exclusions.
		$included_products = (array) ( $this->settings['included_products'] ?? [] );
		if ( ! empty( $included_products ) && ! in_array( $product_id, $included_products, true ) ) {
			return [
				'eligible' => false,
				'reason'   => 'Product is not in the explicit included products list',
			];
		}

		$excluded_products = (array) ( $this->settings['excluded_products'] ?? [] );
		if ( ! empty( $excluded_products ) && in_array( $product_id, $excluded_products, true ) ) {
			return [
				'eligible' => false,
				'reason'   => 'Product is in the explicit excluded products list',
			];
		}

		// 6. Category Inclusions / Exclusions.
		$category_ids = $product->get_category_ids();
		$included_categories = (array) ( $this->settings['included_categories'] ?? [] );
		if ( ! empty( $included_categories ) ) {
			$matching_categories = array_intersect( $category_ids, $included_categories );
			if ( empty( $matching_categories ) ) {
				return [
					'eligible' => false,
					'reason'   => 'Product does not belong to any included category',
				];
			}
		}

		$excluded_categories = (array) ( $this->settings['excluded_categories'] ?? [] );
		if ( ! empty( $excluded_categories ) ) {
			$excluded_matches = array_intersect( $category_ids, $excluded_categories );
			if ( ! empty( $excluded_matches ) ) {
				return [
					'eligible' => false,
					'reason'   => 'Product belongs to an excluded category',
				];
			}
		}

		// 7. Virtual / Downloadable Filters.
		if ( empty( $this->settings['include_virtual'] ) && $product->is_virtual() ) {
			return [
				'eligible' => false,
				'reason'   => 'Virtual products are excluded by settings',
			];
		}

		if ( empty( $this->settings['include_downloadable'] ) && $product->is_downloadable() ) {
			return [
				'eligible' => false,
				'reason'   => 'Downloadable products are excluded by settings',
			];
		}

		// 8. Stock Status Check.
		$stock_action = (string) ( $this->settings['out_of_stock_action'] ?? 'include' );
		if ( 'exclude' === $stock_action && ! $product->is_in_stock() ) {
			return [
				'eligible' => false,
				'reason'   => 'Product is out of stock (excluded by settings)',
			];
		}

		// 9. Missing Image Check (for non-variable products, or variable fallback).
		if ( ! empty( $this->settings['exclude_missing_image'] ) && ! $product->get_image_id() ) {
			return [
				'eligible' => false,
				'reason'   => 'Product has no primary featured image',
			];
		}

		// 10. Missing Price Check (for simple, external, grouped).
		if ( 'variable' !== $type && ! empty( $this->settings['exclude_missing_price'] ) ) {
			$price = $product->get_price();
			if ( '' === $price || null === $price || (float) $price <= 0.0 ) {
				return [
					'eligible' => false,
					'reason'   => 'Product has no valid price',
				];
			}
		}

		// 11. URL Check.
		$permalink = $product->get_permalink();
		if ( empty( $permalink ) || ! filter_var( $permalink, FILTER_VALIDATE_URL ) ) {
			return [
				'eligible' => false,
				'reason'   => 'Product has no valid permalink URL',
			];
		}

		$result = [
			'eligible' => true,
			'reason'   => '',
		];

		/**
		 * Filter product eligibility result.
		 *
		 * @param array{eligible: bool, reason: string} $result
		 * @param \WC_Product $product
		 */
		return apply_filters( 'wpcal_pcc_product_eligibility', $result, $product );
	}

	/**
	 * Evaluate variation eligibility.
	 *
	 * @param \WC_Product_Variation $variation
	 * @param \WC_Product_Variable  $parent
	 * @return array{eligible: bool, reason: string}
	 */
	public function is_variation_eligible( \WC_Product_Variation $variation, \WC_Product_Variable $parent ): array {
		if ( empty( $this->settings['include_variations'] ) ) {
			return [
				'eligible' => false,
				'reason'   => 'Variations are excluded by settings',
			];
		}

		$variation_id = $variation->get_id();

		// Check explicit product exclusion on variation ID.
		$excluded_products = (array) ( $this->settings['excluded_products'] ?? [] );
		if ( in_array( $variation_id, $excluded_products, true ) ) {
			return [
				'eligible' => false,
				'reason'   => 'Variation ID is in excluded products list',
			];
		}

		// Status check.
		if ( 'publish' !== $variation->get_status() ) {
			return [
				'eligible' => false,
				'reason'   => sprintf( 'Variation status is "%s"', $variation->get_status() ),
			];
		}

		// Stock status check.
		$stock_action = (string) ( $this->settings['out_of_stock_action'] ?? 'include' );
		if ( 'exclude' === $stock_action && ! $variation->is_in_stock() ) {
			return [
				'eligible' => false,
				'reason'   => 'Variation is out of stock (excluded by settings)',
			];
		}

		// Missing price check.
		if ( ! empty( $this->settings['exclude_missing_price'] ) ) {
			$price = $variation->get_price();
			if ( '' === $price || null === $price || (float) $price <= 0.0 ) {
				return [
					'eligible' => false,
					'reason'   => 'Variation has no valid price',
				];
			}
		}

		// Image check: variation image or parent fallback.
		if ( ! empty( $this->settings['exclude_missing_image'] ) ) {
			$image_id = $variation->get_image_id() ?: $parent->get_image_id();
			if ( ! $image_id ) {
				return [
					'eligible' => false,
					'reason'   => 'Variation has no image (and parent has no image)',
				];
			}
		}

		$result = [
			'eligible' => true,
			'reason'   => '',
		];

		/**
		 * Filter variation eligibility result.
		 *
		 * @param array{eligible: bool, reason: string} $result
		 * @param \WC_Product_Variation $variation
		 * @param \WC_Product_Variable $parent
		 */
		return apply_filters( 'wpcal_pcc_variation_eligibility', $result, $variation, $parent );
	}
}

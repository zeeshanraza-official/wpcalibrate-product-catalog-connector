<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Catalog;

use WPCalibrate\ProductCatalogConnector\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps WooCommerce standard products (simple, external, grouped) to Pinterest feed row.
 */
class ProductMapper {
	/**
	 * @var array<string, mixed>
	 */
	private array $settings;

	public function __construct( ?array $settings = null ) {
		$this->settings = $settings ?? Settings::get_all();
	}

	/**
	 * Map WooCommerce product to Pinterest feed attributes.
	 *
	 * @param \WC_Product $product
	 * @return array<string, string>
	 */
	public function map( \WC_Product $product ): array {
		$product_id = $product->get_id();
		$currency   = (string) ( $this->settings['currency'] ?? 'USD' );

		// Stable unique identifier.
		$feed_id = sprintf( 'wc_prod_%d', $product_id );
		$feed_id = (string) apply_filters( 'wpcal_pcc_product_feed_id', $feed_id, $product );

		// Image links.
		$main_image_url = '';
		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$src = wp_get_attachment_url( (int) $image_id );
			if ( $src && filter_var( $src, FILTER_VALIDATE_URL ) ) {
				$main_image_url = $src;
			}
		}

		$additional_images = FieldMapper::get_gallery_image_urls(
			$product,
			(int) ( $this->settings['additional_images_count'] ?? 5 )
		);

		// Availability.
		$availability = FieldMapper::map_availability(
			$product->get_stock_status(),
			$product->is_in_stock()
		);

		// Prices.
		$regular_price = $product->get_regular_price();
		$active_price  = $product->get_price();
		$sale_price    = $product->is_on_sale() ? $product->get_sale_price() : '';

		// If regular price is not set, fallback to active price.
		$formatted_price = FieldMapper::format_price( ! empty( $regular_price ) ? $regular_price : $active_price, $currency );
		$formatted_sale_price = ( ! empty( $sale_price ) && (float) $sale_price < (float) $regular_price )
			? FieldMapper::format_price( $sale_price, $currency )
			: '';

		// Title & Description.
		$title       = FieldMapper::clean_title( $product->get_name() );
		$description = FieldMapper::resolve_description( $product, (string) ( $this->settings['description_source'] ?? 'short_first' ) );

		// Identifiers and categorization.
		$brand = FieldMapper::resolve_brand( $product, $this->settings );
		$gtin  = FieldMapper::resolve_identifier( $product, (string) ( $this->settings['gtin_source'] ?? 'sku' ), (string) ( $this->settings['gtin_custom_field'] ?? '' ) );
		$mpn   = FieldMapper::resolve_identifier( $product, (string) ( $this->settings['mpn_source'] ?? 'sku' ), (string) ( $this->settings['mpn_custom_field'] ?? '' ) );
		$gpc   = FieldMapper::resolve_google_product_category( $product, $this->settings );
		$ptype = FieldMapper::resolve_product_type( $product );

		$item = [
			'id'                     => $feed_id,
			'title'                  => $title,
			'description'            => $description,
			'link'                   => (string) $product->get_permalink(),
			'image_link'             => $main_image_url,
			'additional_image_link'  => implode( ',', $additional_images ),
			'price'                  => $formatted_price,
			'sale_price'             => $formatted_sale_price,
			'availability'           => $availability,
			'item_group_id'          => '',
			'brand'                  => $brand,
			'condition'              => (string) ( $this->settings['condition_default'] ?? 'new' ),
			'google_product_category'=> $gpc,
			'product_type'           => $ptype,
			'gtin'                   => $gtin,
			'mpn'                    => $mpn,
		];

		/**
		 * Filter mapped product feed item.
		 *
		 * @param array<string, string> $item
		 * @param \WC_Product           $product
		 */
		return apply_filters( 'wpcal_pcc_mapped_product_item', $item, $product );
	}
}

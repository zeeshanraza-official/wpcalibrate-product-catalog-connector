<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Catalog;

use WPCalibrate\ProductCatalogConnector\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps individual WooCommerce product variations to separate Pinterest feed rows linked by item_group_id.
 */
class VariationMapper {
	/**
	 * @var array<string, mixed>
	 */
	private array $settings;

	public function __construct( ?array $settings = null ) {
		$this->settings = $settings ?? Settings::get_all();
	}

	/**
	 * Map a variation to a Pinterest feed row.
	 *
	 * @param \WC_Product_Variation $variation
	 * @param \WC_Product_Variable  $parent
	 * @return array<string, string>
	 */
	public function map( \WC_Product_Variation $variation, \WC_Product_Variable $parent ): array {
		$variation_id = $variation->get_id();
		$parent_id    = $parent->get_id();
		$currency     = (string) ( $this->settings['currency'] ?? 'USD' );

		// Stable unique identifiers.
		$feed_id       = sprintf( 'wc_var_%d', $variation_id );
		$item_group_id = sprintf( 'wc_prod_%d', $parent_id );

		$feed_id       = (string) apply_filters( 'wpcal_pcc_variation_feed_id', $feed_id, $variation, $parent );
		$item_group_id = (string) apply_filters( 'wpcal_pcc_item_group_id', $item_group_id, $parent );

		// Image links: variation image with parent fallback.
		$main_image_url = '';
		$image_id = $variation->get_image_id() ?: $parent->get_image_id();
		if ( $image_id ) {
			$src = wp_get_attachment_url( (int) $image_id );
			if ( $src && filter_var( $src, FILTER_VALIDATE_URL ) ) {
				$main_image_url = $src;
			}
		}

		$additional_images = FieldMapper::get_gallery_image_urls(
			$parent,
			(int) ( $this->settings['additional_images_count'] ?? 5 )
		);

		// Availability.
		$availability = FieldMapper::map_availability(
			$variation->get_stock_status(),
			$variation->is_in_stock()
		);

		// Prices.
		$regular_price = $variation->get_regular_price();
		$active_price  = $variation->get_price();
		$sale_price    = $variation->is_on_sale() ? $variation->get_sale_price() : '';

		$formatted_price = FieldMapper::format_price( ! empty( $regular_price ) ? $regular_price : $active_price, $currency );
		$formatted_sale_price = ( ! empty( $sale_price ) && (float) $sale_price < (float) $regular_price )
			? FieldMapper::format_price( $sale_price, $currency )
			: '';

		// Title & Description.
		$title = FieldMapper::clean_title( $variation->get_name() );

		$variation_desc = $variation->get_description();
		$description    = ! empty( trim( $variation_desc ) )
			? FieldMapper::clean_description( $variation_desc )
			: FieldMapper::resolve_description( $parent, (string) ( $this->settings['description_source'] ?? 'short_first' ) );

		// Identifiers: try variation first, then parent fallback.
		$gtin_source = (string) ( $this->settings['gtin_source'] ?? 'sku' );
		$gtin_field  = (string) ( $this->settings['gtin_custom_field'] ?? '' );
		$gtin        = FieldMapper::resolve_identifier( $variation, $gtin_source, $gtin_field );
		if ( empty( $gtin ) ) {
			$gtin = FieldMapper::resolve_identifier( $parent, $gtin_source, $gtin_field );
		}

		$mpn_source = (string) ( $this->settings['mpn_source'] ?? 'sku' );
		$mpn_field  = (string) ( $this->settings['mpn_custom_field'] ?? '' );
		$mpn        = FieldMapper::resolve_identifier( $variation, $mpn_source, $mpn_field );
		if ( empty( $mpn ) ) {
			$mpn = FieldMapper::resolve_identifier( $parent, $mpn_source, $mpn_field );
		}

		// Categorization & Brand inherited from parent.
		$brand = FieldMapper::resolve_brand( $parent, $this->settings );
		$gpc   = FieldMapper::resolve_google_product_category( $parent, $this->settings );
		$ptype = FieldMapper::resolve_product_type( $parent );

		$item = [
			'id'                     => $feed_id,
			'title'                  => $title,
			'description'            => $description,
			'link'                   => (string) $variation->get_permalink(),
			'image_link'             => $main_image_url,
			'additional_image_link'  => implode( ',', $additional_images ),
			'price'                  => $formatted_price,
			'sale_price'             => $formatted_sale_price,
			'availability'           => $availability,
			'item_group_id'          => $item_group_id,
			'brand'                  => $brand,
			'condition'              => (string) ( $this->settings['condition_default'] ?? 'new' ),
			'google_product_category'=> $gpc,
			'product_type'           => $ptype,
			'gtin'                   => $gtin,
			'mpn'                    => $mpn,
		];

		/**
		 * Filter mapped variation feed item.
		 *
		 * @param array<string, string>  $item
		 * @param \WC_Product_Variation $variation
		 * @param \WC_Product_Variable  $parent
		 */
		return apply_filters( 'wpcal_pcc_mapped_variation_item', $item, $variation, $parent );
	}
}

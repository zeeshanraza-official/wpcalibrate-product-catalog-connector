<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Catalog;

use WPCalibrate\ProductCatalogConnector\Compatibility\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles batched and memory-efficient querying of WooCommerce products.
 */
class ProductQuery {
	/**
	 * Get total count of candidate products.
	 */
	public function get_total_candidates(): int {
		if ( ! WooCommerce::is_active() ) {
			return 0;
		}

		$counts = wp_count_posts( 'product' );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * Retrieve a batch of products for feed generation.
	 *
	 * @param int $limit Number of products per batch.
	 * @param int $offset Product offset.
	 * @return array<\WC_Product>
	 */
	public function get_batch( int $limit, int $offset ): array {
		if ( ! WooCommerce::is_active() ) {
			return [];
		}

		$args = [
			'status'   => 'publish',
			'limit'    => $limit,
			'offset'   => $offset,
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'return'   => 'objects',
		];

		/**
		 * Filter the arguments passed to wc_get_products.
		 *
		 * @param array<string, mixed> $args
		 */
		$args = apply_filters( 'wpcal_pcc_product_query_args', $args );

		return wc_get_products( $args );
	}
}

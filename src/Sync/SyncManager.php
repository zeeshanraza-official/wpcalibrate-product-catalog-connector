<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Sync;

use WPCalibrate\ProductCatalogConnector\Diagnostics\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens to WooCommerce product lifecycle and taxonomy changes to debounce dirty-state catalog updates.
 */
class SyncManager {
	public const DIRTY_HOOK = 'wpcal_pcc_dirty_sync';
	public const DEBOUNCE_DELAY = 600; // 10 minutes debounce delay

	/**
	 * Register change hooks.
	 */
	public function register(): void {
		// Product changes.
		add_action( 'save_post_product', [ $this, 'on_product_change' ], 20, 2 );
		add_action( 'woocommerce_new_product', [ $this, 'on_product_change_id' ], 20, 1 );
		add_action( 'woocommerce_update_product', [ $this, 'on_product_change_id' ], 20, 1 );
		add_action( 'woocommerce_product_duplicate', [ $this, 'on_product_duplicate' ], 20, 2 );
		add_action( 'wp_trash_post', [ $this, 'on_post_trash' ], 20, 1 );
		add_action( 'before_delete_post', [ $this, 'on_post_trash' ], 20, 1 );

		// Stock changes.
		add_action( 'woocommerce_product_set_stock_status', [ $this, 'on_stock_change' ], 20, 3 );
		add_action( 'woocommerce_variation_set_stock_status', [ $this, 'on_stock_change' ], 20, 3 );

		// Debounced action callback.
		add_action( self::DIRTY_HOOK, [ $this, 'run_dirty_sync' ] );
	}

	public function on_product_change( mixed $post_id = 0, mixed $post = null, mixed ...$extra ): void {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return;
		}

		// Avoid auto-drafts and revisions.
		if ( ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) ||
		     ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) ) {
			return;
		}

		if ( $post instanceof \WP_Post && 'auto-draft' === $post->post_status ) {
			return;
		}

		$this->mark_dirty();
	}

	public function on_product_change_id( mixed $product_id = 0, mixed ...$extra ): void {
		$product_id = absint( $product_id );
		if ( $product_id > 0 ) {
			$this->mark_dirty();
		}
	}

	public function on_product_duplicate( mixed $duplicate = null, mixed $original = null, mixed ...$extra ): void {
		$this->mark_dirty();
	}

	public function on_post_trash( mixed $post_id = 0, mixed ...$extra ): void {
		$post_id = absint( $post_id );
		if ( $post_id > 0 && ( ! function_exists( 'get_post_type' ) || 'product' === get_post_type( $post_id ) ) ) {
			$this->mark_dirty();
		}
	}

	public function on_stock_change( mixed $product_id = 0, mixed $stock_status = '', mixed $product = null, mixed ...$extra ): void {
		$this->mark_dirty();
	}

	/**
	 * Flag catalog dirty and schedule debounced sync.
	 */
	public function mark_dirty(): void {
		update_option( 'wpcal_pcc_catalog_dirty', time(), false );

		// Debounce: ensure only one future dirty-sync action is scheduled.
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( self::DIRTY_HOOK, [], 'wpcalibrate-pcc' ) ) {
				as_schedule_single_action( time() + self::DEBOUNCE_DELAY, self::DIRTY_HOOK, [], 'wpcalibrate-pcc' );
				Logger::debug( 'Catalog marked dirty; scheduled debounced sync in 10 minutes.' );
			}
		} else {
			if ( ! wp_next_scheduled( self::DIRTY_HOOK ) ) {
				wp_schedule_single_event( time() + self::DEBOUNCE_DELAY, self::DIRTY_HOOK );
				Logger::debug( 'Catalog marked dirty; scheduled debounced WP-Cron sync in 10 minutes.' );
			}
		}
	}

	/**
	 * Run synchronization for dirty catalog.
	 */
	public function run_dirty_sync(): void {
		if ( ! get_option( 'wpcal_pcc_catalog_dirty' ) ) {
			return;
		}

		$processor = new BatchProcessor();
		$processor->start_sync( 'dirty' );
	}

	/**
	 * Check if catalog currently has pending uncommitted product changes.
	 */
	public static function is_dirty(): bool {
		return (bool) get_option( 'wpcal_pcc_catalog_dirty', false );
	}
}

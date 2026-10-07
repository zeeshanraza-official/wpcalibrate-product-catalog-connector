<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Tests\Integration;

use WPCalibrate\ProductCatalogConnector\Compatibility\HPOS;
use WPCalibrate\ProductCatalogConnector\Compatibility\WooCommerce;
use WPCalibrate\ProductCatalogConnector\Diagnostics\SyncRunRepository;
use WPCalibrate\ProductCatalogConnector\Feed\FeedStorage;
use WPCalibrate\ProductCatalogConnector\Feed\FeedToken;
use WPCalibrate\ProductCatalogConnector\Settings\Settings;
use WPCalibrate\ProductCatalogConnector\Sync\BatchProcessor;
use WPCalibrate\ProductCatalogConnector\Sync\Lock;
use WPCalibrate\ProductCatalogConnector\Sync\SyncManager;

class FeedGenerationIntegrationTest {
	public function run(): array {
		$results = [];

		$results['test_concurrency_locking']               = $this->test_concurrency_locking();
		$results['test_feed_generation_atomic_publish']     = $this->test_feed_generation_atomic_publish();
		$results['test_failed_generation_retains_old_feed'] = $this->test_failed_generation_retains_old_feed();
		$results['test_dirty_state_tracking']               = $this->test_dirty_state_tracking();
		$results['test_token_rotation_invalidates_old_feed'] = $this->test_token_rotation_invalidates_old_feed();
		$results['test_compatibility_helpers']              = $this->test_compatibility_helpers();
		$results['test_php8_named_args_and_action_safety']   = $this->test_php8_named_args_and_action_safety();
		$results['test_defensive_hook_callbacks']           = $this->test_defensive_hook_callbacks();

		return $results;
	}

	private function test_concurrency_locking(): bool {
		\WP_Mock_State::reset();

		$run1 = 'run_test_1';
		$run2 = 'run_test_2';

		assert( Lock::acquire( $run1, 300 ) );
		assert( Lock::is_locked() );

		// Duplicate concurrent run must fail to acquire lock.
		assert( false === Lock::acquire( $run2, 300 ) );

		// Same run is allowed to re-acquire / continue.
		assert( true === Lock::acquire( $run1, 300 ) );

		// Release lock.
		assert( Lock::release( $run1 ) );
		assert( false === Lock::is_locked() );

		// Now run2 can acquire.
		assert( Lock::acquire( $run2, 300 ) );
		Lock::force_release();
		assert( false === Lock::is_locked() );

		return true;
	}

	private function test_feed_generation_atomic_publish(): bool {
		\WP_Mock_State::reset();

		// Populate mock products: 1 simple and 1 variable with 2 variations.
		$simple   = new \WC_Product( 101 );
		$variable = new \WC_Product_Variable( 102 );
		$variable->set_children( [ 201, 202 ] );

		$GLOBALS['mock_products'] = [ $simple, $variable ];

		$processor = new BatchProcessor();
		$run_id    = $processor->start_sync( 'manual' );

		assert( false !== $run_id );

		// Process batches synchronously.
		$processor->process_all_batches_synchronously( $run_id );

		// Verify that active feed file was published.
		$active_feed_path = FeedStorage::get_active_feed_path( 'csv' );
		assert( file_exists( $active_feed_path ) );

		$content = file_get_contents( $active_feed_path );
		assert( str_contains( $content, 'wc_prod_101' ) );
		assert( str_contains( $content, 'wc_var_201' ) );
		assert( str_contains( $content, 'wc_var_202' ) );
		assert( str_contains( $content, 'wc_prod_102' ) ); // item_group_id

		// Verify database run record marked completed.
		$latest = SyncRunRepository::get_latest_run();
		assert( null !== $latest );
		assert( 'completed' === $latest['status'] );
		assert( $latest['items_exported'] >= 3 );

		@unlink( $active_feed_path );
		return true;
	}

	private function test_failed_generation_retains_old_feed(): bool {
		\WP_Mock_State::reset();

		$active_path = FeedStorage::get_active_feed_path( 'csv' );
		$dir = dirname( $active_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Create an existing valid feed.
		$original_content = "id,title,description,link,image_link,price,availability\nwc_prod_1,Original,Desc,https://example.com/p1,https://example.com/img1.jpg,10.00 USD,in_stock\n";
		file_put_contents( $active_path, $original_content );
		assert( file_exists( $active_path ) );

		// Configure products to have invalid empty data that fails validation.
		$bad_product = new \WC_Product( 999 );
		$bad_product->set_regular_price( '' ); // Invalid price
		$GLOBALS['mock_products'] = [ $bad_product ];

		// Run sync where all items fail validation.
		$processor = new BatchProcessor();
		$run_id = $processor->start_sync( 'manual' );
		$processor->process_all_batches_synchronously( $run_id );

		// The original feed MUST still exist and remain untouched!
		assert( file_exists( $active_path ) );
		assert( file_get_contents( $active_path ) === $original_content );

		// Database run should be marked failed.
		$latest = SyncRunRepository::get_latest_run();
		assert( 'failed' === $latest['status'] );

		@unlink( $active_path );
		return true;
	}

	private function test_dirty_state_tracking(): bool {
		\WP_Mock_State::reset();

		$sync_manager = new SyncManager();
		assert( false === SyncManager::is_dirty() );

		// Trigger product change.
		$sync_manager->mark_dirty();
		assert( true === SyncManager::is_dirty() );

		return true;
	}

	private function test_token_rotation_invalidates_old_feed(): bool {
		\WP_Mock_State::reset();

		$token1 = FeedToken::get_token();
		$path1  = FeedStorage::get_active_feed_path( 'csv', $token1 );
		file_put_contents( $path1, 'test feed content' );
		assert( file_exists( $path1 ) );

		// Rotate token.
		$token2 = FeedToken::rotate_token();
		$path2  = FeedStorage::get_active_feed_path( 'csv', $token2 );

		// Old token URL and path are different.
		assert( $token1 !== $token2 );
		assert( $path1 !== $path2 );
		assert( ! FeedToken::validate_token( $token1 ) );
		assert( FeedToken::validate_token( $token2 ) );

		@unlink( $path1 );
		@unlink( $path2 );
		return true;
	}

	private function test_compatibility_helpers(): bool {
		assert( WooCommerce::is_active() );
		assert( is_string( HPOS::get_status_label() ) );
		return true;
	}

	private function test_php8_named_args_and_action_safety(): bool {
		\WP_Mock_State::reset();

		$processor = new BatchProcessor();

		// 1. Emulate Action Scheduler / WP-Cron invoking hook with named parameter array (PHP 8 unpacking).
		call_user_func_array( [ $processor, 'handle_start_sync_action' ], [ 'type' => 'activation' ] );
		assert( true, 'Named parameter call succeeded without fatal error.' );

		// 2. Positional string argument invocation.
		$processor->handle_start_sync_action( 'manual' );
		assert( true, 'Positional string call succeeded.' );

		// 3. Array payload argument invocation.
		$processor->handle_start_sync_action( [ 'type' => 'dirty' ] );
		assert( true, 'Array payload call succeeded.' );

		// 4. Zero argument invocation.
		$processor->handle_start_sync_action();
		assert( true, 'Zero argument call succeeded.' );

		// 5. Named parameter invocation on process_batch.
		call_user_func_array( [ $processor, 'process_batch' ], [ 'run_id' => 'nonexistent_run', 'offset' => 0 ] );
		assert( true, 'process_batch named parameter call succeeded.' );

		return true;
	}

	private function test_defensive_hook_callbacks(): bool {
		\WP_Mock_State::reset();

		$sync_manager = new SyncManager();

		// Test on_product_change with 0 arguments, invalid IDs, or auto-draft.
		$sync_manager->on_product_change();
		$sync_manager->on_product_change( 0 );
		$sync_manager->on_product_change( 101, null );

		$auto_draft_post = (object) [ 'ID' => 102, 'post_status' => 'auto-draft' ];
		$sync_manager->on_product_change( 102, $auto_draft_post );

		// Test stock and trash callbacks with missing/variadic args.
		$sync_manager->on_stock_change();
		$sync_manager->on_stock_change( 101, 'instock' );
		$sync_manager->on_post_trash();
		$sync_manager->on_product_duplicate();

		assert( true, 'All defensive hook callbacks handled missing arguments safely.' );

		// Test Settings::get_defaults returns valid values.
		$defaults = Settings::get_defaults();
		assert( ! empty( $defaults['country'] ) );
		assert( ! empty( $defaults['currency'] ) );

		return true;
	}
}

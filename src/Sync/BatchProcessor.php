<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Sync;

use WPCalibrate\ProductCatalogConnector\Catalog\EligibilityService;
use WPCalibrate\ProductCatalogConnector\Catalog\FeedValidator;
use WPCalibrate\ProductCatalogConnector\Catalog\ProductMapper;
use WPCalibrate\ProductCatalogConnector\Catalog\ProductQuery;
use WPCalibrate\ProductCatalogConnector\Catalog\VariationMapper;
use WPCalibrate\ProductCatalogConnector\Diagnostics\Logger;
use WPCalibrate\ProductCatalogConnector\Diagnostics\SyncRunRepository;
use WPCalibrate\ProductCatalogConnector\Feed\CsvWriter;
use WPCalibrate\ProductCatalogConnector\Feed\FeedStorage;
use WPCalibrate\ProductCatalogConnector\Feed\FeedWriterInterface;
use WPCalibrate\ProductCatalogConnector\Feed\TsvWriter;
use WPCalibrate\ProductCatalogConnector\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates batched, idempotent catalog generation via Action Scheduler with atomic publication.
 */
class BatchProcessor {
	public const STATE_OPTION = 'wpcal_pcc_run_state';

	/**
	 * Register Action Scheduler / Cron callbacks.
	 */
	public function register(): void {
		add_action( 'wpcal_pcc_start_sync', [ $this, 'handle_start_sync_action' ] );
		add_action( 'wpcal_pcc_process_batch', [ $this, 'process_batch' ], 10, 2 );
	}

	/**
	 * Callback for 'wpcal_pcc_start_sync' action.
	 * Supports both positional and named argument invocations, string trigger types, and array payloads.
	 *
	 * @param mixed $type Trigger type string or args array.
	 * @param mixed ...$extra Additional parameters if passed by custom hooks.
	 */
	public function handle_start_sync_action( mixed $type = 'scheduled', mixed ...$extra ): void {
		$trigger = 'scheduled';
		if ( is_array( $type ) ) {
			$trigger = isset( $type['type'] ) ? (string) $type['type'] : 'scheduled';
		} elseif ( is_string( $type ) && '' !== $type ) {
			$trigger = $type;
		}

		$this->start_sync( sanitize_key( $trigger ) );
	}

	/**
	 * Initiate a new catalog feed generation run.
	 *
	 * @param string $type 'manual', 'scheduled', 'dirty', 'activation'
	 * @return string|false Run ID on success, false on failure or lock contention.
	 */
	public function start_sync( string $type = 'manual' ): string|false {
		$run_id = 'run_' . bin2hex( random_bytes( 8 ) );

		if ( ! Lock::acquire( $run_id ) ) {
			Logger::warning( 'Could not start catalog synchronization: generation lock is already held.' );
			return false;
		}

		$settings = Settings::get_all();
		$format   = (string) ( $settings['feed_format'] ?? 'csv' );
		$format   = in_array( $format, [ 'csv', 'tsv' ], true ) ? $format : 'csv';

		$temp_path = FeedStorage::get_temp_feed_path( $run_id, $format );
		$writer    = $this->create_writer( $format );

		if ( ! $writer->open( $temp_path ) ) {
			Lock::release( $run_id );
			Logger::error( sprintf( 'Failed to open temp feed file at "%s" for writing.', $temp_path ) );
			return false;
		}

		// Write column headers.
		$headers = [
			'id',
			'title',
			'description',
			'link',
			'image_link',
			'additional_image_link',
			'price',
			'sale_price',
			'availability',
			'item_group_id',
			'brand',
			'condition',
			'google_product_category',
			'product_type',
			'gtin',
			'mpn',
		];

		$writer->write_header( $headers );
		$writer->close();

		// Record in database.
		SyncRunRepository::create_run( $run_id, $type, $format );

		$state = [
			'run_id'    => $run_id,
			'format'    => $format,
			'temp_path' => $temp_path,
			'type'      => $type,
			'started'   => time(),
		];
		update_option( self::STATE_OPTION, $state, false );

		Logger::info( sprintf( 'Catalog feed generation started [Run ID: %s, Format: %s, Trigger: %s]', $run_id, $format, $type ) );

		// Schedule first batch processing.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'wpcal_pcc_process_batch', [ 'run_id' => $run_id, 'offset' => 0 ], 'wpcalibrate-pcc' );
		} else {
			// Fallback: process in synchronous loop or schedule via WP-Cron.
			$this->process_all_batches_synchronously( $run_id );
		}

		return $run_id;
	}

	/**
	 * Process a single batch of products.
	 *
	 * @param mixed $run_id Run ID string.
	 * @param mixed $offset Numeric batch offset.
	 * @param mixed ...$extra Additional parameters if passed by custom hooks.
	 */
	public function process_batch( mixed $run_id = '', mixed $offset = 0, mixed ...$extra ): void {
		$run_id = (string) $run_id;
		$offset = absint( $offset );
		$state = get_option( self::STATE_OPTION, [] );
		if ( ! is_array( $state ) || ( $state['run_id'] ?? '' ) !== $run_id ) {
			Logger::warning( sprintf( 'Mismatched run state for Run ID: %s. Aborting batch.', $run_id ) );
			return;
		}

		$temp_path = (string) ( $state['temp_path'] ?? '' );
		$format    = (string) ( $state['format'] ?? 'csv' );

		if ( ! file_exists( $temp_path ) ) {
			Logger::error( sprintf( 'Temp feed file missing at "%s" for Run ID: %s.', $temp_path, $run_id ) );
			SyncRunRepository::fail_run( $run_id, 'Temp feed file missing.' );
			Lock::release( $run_id );
			delete_option( self::STATE_OPTION );
			return;
		}

		$settings = Settings::get_all();
		$limit    = (int) ( $settings['batch_size'] ?? 50 );
		$limit    = (int) apply_filters( 'wpcal_pcc_batch_size', $limit );

		$query = new ProductQuery();
		$products = $query->get_batch( $limit, $offset );

		$eligibility      = new EligibilityService( $settings );
		$product_mapper   = new ProductMapper( $settings );
		$variation_mapper = new VariationMapper( $settings );

		$handle = fopen( $temp_path, 'ab' );
		if ( false === $handle ) {
			Logger::error( sprintf( 'Failed to open temp feed file for appending at "%s".', $temp_path ) );
			SyncRunRepository::fail_run( $run_id, 'Failed to open temp file for writing.' );
			Lock::release( $run_id );
			return;
		}

		$scanned_count  = 0;
		$exported_count = 0;
		$skipped_count  = 0;
		$warnings_count = 0;
		$errors_count   = 0;

		$delimiter = ( 'tsv' === $format ) ? "\t" : ',';

		foreach ( $products as $product ) {
			$scanned_count++;
			$product_id = $product->get_id();
			$sku        = (string) $product->get_sku();

			$check = $eligibility->is_product_eligible( $product );
			if ( ! $check['eligible'] ) {
				$skipped_count++;
				SyncRunRepository::record_item_issue(
					$run_id,
					$product_id,
					$sku,
					'skipped_eligibility',
					'info',
					$check['reason']
				);
				continue;
			}

			// Variable product handling.
			if ( $product->is_type( 'variable' ) ) {
				/** @var \WC_Product_Variable $product */
				$children = $product->get_children();
				$has_exported_variation = false;

				foreach ( $children as $child_id ) {
					$variation = wc_get_product( $child_id );
					if ( ! $variation instanceof \WC_Product_Variation ) {
						continue;
					}

					$var_check = $eligibility->is_variation_eligible( $variation, $product );
					if ( ! $var_check['eligible'] ) {
						SyncRunRepository::record_item_issue(
							$run_id,
							$child_id,
							(string) $variation->get_sku(),
							'skipped_variation',
							'info',
							$var_check['reason']
						);
						continue;
					}

					$row = $variation_mapper->map( $variation, $product );
					$row_errors = FeedValidator::validate_item( $row );

					if ( ! empty( $row_errors ) ) {
						$warnings_count += count( $row_errors );
						SyncRunRepository::record_item_issue(
							$run_id,
							$child_id,
							(string) $variation->get_sku(),
							'validation_warning',
							'warning',
							implode( '; ', $row_errors )
						);
					}

					$this->write_row_to_handle( $handle, $row, $delimiter, $format );
					$exported_count++;
					$has_exported_variation = true;
				}

				if ( ! $has_exported_variation ) {
					// Record notice if no variations were exportable.
					SyncRunRepository::record_item_issue(
						$run_id,
						$product_id,
						$sku,
						'no_valid_variations',
						'warning',
						'Variable product has no eligible variations to export.'
					);
				}
			} else {
				// Simple / External / Grouped products.
				$row = $product_mapper->map( $product );
				$row_errors = FeedValidator::validate_item( $row );

				if ( ! empty( $row_errors ) ) {
					$warnings_count += count( $row_errors );
					SyncRunRepository::record_item_issue(
						$run_id,
						$product_id,
						$sku,
						'validation_warning',
						'warning',
						implode( '; ', $row_errors )
					);
				}

				$this->write_row_to_handle( $handle, $row, $delimiter, $format );
				$exported_count++;
			}
		}

		fclose( $handle );

		// Update database stats.
		SyncRunRepository::update_run_progress(
			$run_id,
			$scanned_count,
			$exported_count,
			$skipped_count,
			$warnings_count,
			$errors_count
		);

		// Determine if more products remain.
		if ( count( $products ) === $limit ) {
			$next_offset = $offset + $limit;
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action(
					'wpcal_pcc_process_batch',
					[ 'run_id' => $run_id, 'offset' => $next_offset ],
					'wpcalibrate-pcc'
				);
			}
		} else {
			// All products processed: finalize and publish.
			$this->finalize_run( $run_id, $temp_path, $format );
		}
	}

	/**
	 * Synchronously process all batches when Action Scheduler is not installed.
	 */
	public function process_all_batches_synchronously( string $run_id ): void {
		$settings = Settings::get_all();
		$limit    = (int) ( $settings['batch_size'] ?? 50 );
		$offset   = 0;

		$query = new ProductQuery();
		$total = $query->get_total_candidates();

		while ( $offset <= $total ) {
			$this->process_batch( $run_id, $offset );
			$offset += $limit;

			// If current run state was removed, generation finalized.
			if ( ! get_option( self::STATE_OPTION ) ) {
				break;
			}
		}
	}

	/**
	 * Validate and atomically publish completed feed artifact.
	 */
	private function finalize_run( string $run_id, string $temp_path, string $format ): void {
		$validation = FeedValidator::validate_file( $temp_path, $format );

		if ( ! $validation['valid'] ) {
			$err_msg = 'Feed validation failed: ' . implode( '; ', $validation['errors'] );
			Logger::error( sprintf( 'Run ID %s: %s', $run_id, $err_msg ) );

			SyncRunRepository::fail_run( $run_id, $err_msg );
			@unlink( $temp_path );
			Lock::release( $run_id );
			delete_option( self::STATE_OPTION );
			return;
		}

		// Atomically replace live feed file.
		$published = FeedStorage::publish_temp_feed( $temp_path, $format );

		if ( ! $published ) {
			$err_msg = 'Failed to atomically move temporary feed to active destination.';
			Logger::error( sprintf( 'Run ID %s: %s', $run_id, $err_msg ) );

			SyncRunRepository::fail_run( $run_id, $err_msg );
			Lock::release( $run_id );
			delete_option( self::STATE_OPTION );
			return;
		}

		$active_path = FeedStorage::get_active_feed_path( $format );
		$checksum    = file_exists( $active_path ) ? (string) hash_file( 'sha256', $active_path ) : '';
		$filesize    = file_exists( $active_path ) ? (int) filesize( $active_path ) : 0;

		SyncRunRepository::complete_run(
			$run_id,
			$checksum,
			$filesize,
			sprintf( 'Successfully published %d items (%s).', $validation['total_rows'], strtoupper( $format ) )
		);

		// Clear dirty state.
		delete_option( 'wpcal_pcc_catalog_dirty' );
		Lock::release( $run_id );
		delete_option( self::STATE_OPTION );

		Logger::info( sprintf( 'Catalog feed successfully published [Run ID: %s, Rows: %d, Size: %d bytes]', $run_id, $validation['total_rows'], $filesize ) );

		/**
		 * Fires when a catalog feed is successfully published.
		 *
		 * @param string $run_id
		 * @param string $format
		 * @param int    $total_rows
		 */
		do_action( 'wpcal_pcc_feed_published', $run_id, $format, $validation['total_rows'] );
	}

	/**
	 * Write a single row array to an open stream handle.
	 *
	 * @param resource $handle
	 * @param array<string, string> $row
	 */
	private function write_row_to_handle( $handle, array $row, string $delimiter, string $format ): void {
		if ( 'tsv' === $format ) {
			$cleaned = array_map(
				static fn( $val ) => str_replace( [ "\t", "\r\n", "\r", "\n" ], [ ' ', ' ', ' ', ' ' ], (string) $val ),
				array_values( $row )
			);
			fwrite( $handle, implode( "\t", $cleaned ) . "\n" );
		} else {
			fputcsv( $handle, array_values( $row ), ',', '"', '\\' );
		}
	}

	/**
	 * Instantiate writer based on format.
	 */
	private function create_writer( string $format ): FeedWriterInterface {
		return ( 'tsv' === $format ) ? new TsvWriter() : new CsvWriter();
	}
}

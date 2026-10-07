<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repository for catalog synchronization run logs and individual product issues.
 */
class SyncRunRepository {
	/**
	 * Get sync runs table name.
	 */
	public static function get_runs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpcal_pcc_sync_runs';
	}

	/**
	 * Get item issues table name.
	 */
	public static function get_issues_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpcal_pcc_item_issues';
	}

	/**
	 * Create a new synchronization run entry.
	 */
	public static function create_run( string $run_id, string $type, string $format ): int {
		global $wpdb;

		$table = self::get_runs_table();
		$now   = current_time( 'mysql' );

		$wpdb->insert(
			$table,
			[
				'run_id'         => $run_id,
				'run_type'       => $type,
				'status'         => 'running',
				'items_scanned'  => 0,
				'items_exported' => 0,
				'items_skipped'  => 0,
				'warnings_count' => 0,
				'errors_count'   => 0,
				'checksum'       => '',
				'file_format'    => $format,
				'file_size'      => 0,
				'started_at'     => $now,
			],
			[ '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Increment counters on an active run.
	 */
	public static function update_run_progress(
		string $run_id,
		int $scanned_add,
		int $exported_add,
		int $skipped_add,
		int $warnings_add,
		int $errors_add
	): void {
		global $wpdb;

		$table = self::get_runs_table();
		$sql = "UPDATE {$table} SET 
			items_scanned = items_scanned + %d,
			items_exported = items_exported + %d,
			items_skipped = items_skipped + %d,
			warnings_count = warnings_count + %d,
			errors_count = errors_count + %d
			WHERE run_id = %s";

		$wpdb->query( $wpdb->prepare( $sql, $scanned_add, $exported_add, $skipped_add, $warnings_add, $errors_add, $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Mark a run as successfully completed.
	 */
	public static function complete_run( string $run_id, string $checksum, int $file_size, string $message = '' ): void {
		global $wpdb;

		$table = self::get_runs_table();
		$now   = current_time( 'mysql' );

		$wpdb->update(
			$table,
			[
				'status'      => 'completed',
				'checksum'    => $checksum,
				'file_size'   => $file_size,
				'message'     => $message,
				'finished_at' => $now,
			],
			[ 'run_id' => $run_id ],
			[ '%s', '%s', '%d', '%s', '%s' ],
			[ '%s' ]
		);

		self::clean_old_runs();
	}

	/**
	 * Mark a run as failed.
	 */
	public static function fail_run( string $run_id, string $error_message ): void {
		global $wpdb;

		$table = self::get_runs_table();
		$now   = current_time( 'mysql' );

		$wpdb->update(
			$table,
			[
				'status'      => 'failed',
				'message'     => $error_message,
				'finished_at' => $now,
			],
			[ 'run_id' => $run_id ],
			[ '%s', '%s', '%s' ],
			[ '%s' ]
		);
	}

	/**
	 * Record a specific issue encountered for a product.
	 */
	public static function record_item_issue(
		string $run_id,
		int $product_id,
		string $sku,
		string $issue_type,
		string $severity,
		string $message
	): void {
		global $wpdb;

		$table = self::get_issues_table();
		$now   = current_time( 'mysql' );

		$wpdb->insert(
			$table,
			[
				'run_id'      => $run_id,
				'product_id'  => $product_id,
				'product_sku' => $sku,
				'issue_type'  => $issue_type,
				'severity'    => $severity,
				'message'     => $message,
				'created_at'  => $now,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Retrieve the most recent sync run.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_latest_run(): ?array {
		global $wpdb;

		$table = self::get_runs_table();
		$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", \ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Retrieve the most recent completed run.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_latest_completed_run(): ?array {
		global $wpdb;

		$table = self::get_runs_table();
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE status = 'completed' ORDER BY id DESC LIMIT 1", \ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get recent synchronization runs.
	 *
	 * @param int $limit
	 * @return array<array<string, mixed>>
	 */
	public static function get_recent_runs( int $limit = 30 ): array {
		global $wpdb;

		$table = self::get_runs_table();
		$results = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			\ARRAY_A
		);

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Get item issues for a run or globally recent issues.
	 *
	 * @param string|null $run_id
	 * @param int         $limit
	 * @return array<array<string, mixed>>
	 */
	public static function get_issues( ?string $run_id = null, int $limit = 50 ): array {
		global $wpdb;

		$table = self::get_issues_table();

		if ( $run_id ) {
			$results = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %s ORDER BY id DESC LIMIT %d", $run_id, max( 1, $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				\ARRAY_A
			);
		} else {
			$results = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				\ARRAY_A
			);
		}

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Prune old runs beyond retention limit (default 30).
	 */
	public static function clean_old_runs( int $retention_limit = 30 ): void {
		global $wpdb;

		$retention_limit = (int) apply_filters( 'wpcal_pcc_sync_runs_retention', $retention_limit );
		$runs_table      = self::get_runs_table();
		$issues_table    = self::get_issues_table();

		// Find ID cutoff.
		$cutoff_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$runs_table} ORDER BY id DESC LIMIT 1 OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$retention_limit
			)
		);

		if ( $cutoff_id ) {
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM {$runs_table} WHERE id <= %d", (int) $cutoff_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			// Clean orphaned issues.
			$wpdb->query( "DELETE FROM {$issues_table} WHERE run_id NOT IN (SELECT run_id FROM {$runs_table})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}

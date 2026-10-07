<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Concurrency locking mechanism preventing overlapping catalog generation processes.
 */
class Lock {
	public const TRANSIENT_KEY = 'wpcal_pcc_generation_lock';
	public const DEFAULT_TTL   = 900; // 15 minutes

	/**
	 * Attempt to acquire the lock.
	 *
	 * @param string $run_id Unique identifier for the run.
	 * @param int    $ttl    Time-to-live in seconds.
	 * @return bool True if lock was acquired, false if already held.
	 */
	public static function acquire( string $run_id, int $ttl = self::DEFAULT_TTL ): bool {
		$existing = get_transient( self::TRANSIENT_KEY );

		if ( false !== $existing && is_array( $existing ) ) {
			// If lock was acquired by the same run, allow continuation.
			if ( isset( $existing['run_id'] ) && $existing['run_id'] === $run_id ) {
				return true;
			}

			// Lock is held by another active run.
			return false;
		}

		$lock_data = [
			'run_id'     => $run_id,
			'started_at' => time(),
			'expires_at' => time() + $ttl,
		];

		return set_transient( self::TRANSIENT_KEY, $lock_data, $ttl );
	}

	/**
	 * Release the lock if owned by the specified run_id.
	 */
	public static function release( string $run_id ): bool {
		$existing = get_transient( self::TRANSIENT_KEY );

		if ( false === $existing ) {
			return true;
		}

		if ( is_array( $existing ) && isset( $existing['run_id'] ) && $existing['run_id'] === $run_id ) {
			return delete_transient( self::TRANSIENT_KEY );
		}

		return false;
	}

	/**
	 * Force release the lock regardless of owner.
	 */
	public static function force_release(): bool {
		return delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Check if lock is currently held.
	 */
	public static function is_locked(): bool {
		return false !== get_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Get current lock details.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_lock_info(): ?array {
		$lock = get_transient( self::TRANSIENT_KEY );
		return is_array( $lock ) ? $lock : null;
	}
}

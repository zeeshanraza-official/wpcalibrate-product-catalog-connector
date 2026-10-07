<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Feed;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages physical storage, atomic replacement, and directory protection of feed files.
 */
class FeedStorage {
	public const SUBDIR = 'wpcalibrate-catalog-feeds';

	/**
	 * Get the feed storage directory on disk.
	 */
	public static function get_storage_dir(): string {
		$upload_dir = wp_upload_dir();
		$dir = trailingslashit( $upload_dir['basedir'] ) . self::SUBDIR;

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			self::protect_directory( $dir );
		}

		return $dir;
	}

	/**
	 * Ensure directory index protection.
	 */
	private static function protect_directory( string $dir ): void {
		$index_file = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, '<!DOCTYPE html><html><head><title>Access Denied</title></head><body><h1>Access Denied</h1></body></html>' );
		}
	}

	/**
	 * Get absolute path to active feed file.
	 */
	public static function get_active_feed_path( string $format = 'csv', ?string $token = null ): string {
		$token = $token ?: FeedToken::get_token();
		$dir   = self::get_storage_dir();
		return sprintf( '%s/catalog-%s.%s', $dir, $token, strtolower( $format ) );
	}

	/**
	 * Get absolute path for temporary building file during a sync run.
	 */
	public static function get_temp_feed_path( string $run_id, string $format = 'csv' ): string {
		$token = FeedToken::get_token();
		$dir   = self::get_storage_dir();
		return sprintf( '%s/catalog-%s-%s.%s.tmp', $dir, $token, sanitize_key( $run_id ), strtolower( $format ) );
	}

	/**
	 * Atomically replace active feed with validated temporary feed artifact.
	 */
	public static function publish_temp_feed( string $temp_path, string $format = 'csv' ): bool {
		if ( ! file_exists( $temp_path ) ) {
			return false;
		}

		$active_path = self::get_active_feed_path( $format );

		// Atomic move / rename.
		if ( file_exists( $active_path ) ) {
			@unlink( $active_path );
		}

		return @rename( $temp_path, $active_path );
	}

	/**
	 * Check if an active feed exists.
	 */
	public static function has_active_feed( string $format = 'csv' ): bool {
		return file_exists( self::get_active_feed_path( $format ) );
	}

	/**
	 * Get public feed retrieval URL.
	 */
	public static function get_feed_url( string $format = 'csv' ): string {
		$token = FeedToken::get_token();
		return add_query_arg(
			[
				'wpcal_pcc_feed' => '1',
				'token'          => $token,
				'format'         => strtolower( $format ),
			],
			home_url( '/' )
		);
	}

	/**
	 * Get details about active feed file.
	 *
	 * @return array{exists: bool, size: int, mtime: int, age: int, path: string}
	 */
	public static function get_feed_info( string $format = 'csv' ): array {
		$path = self::get_active_feed_path( $format );
		if ( ! file_exists( $path ) ) {
			return [
				'exists' => false,
				'size'   => 0,
				'mtime'  => 0,
				'age'    => 0,
				'path'   => $path,
			];
		}

		$mtime = (int) filemtime( $path );
		return [
			'exists' => true,
			'size'   => (int) filesize( $path ),
			'mtime'  => $mtime,
			'age'    => max( 0, time() - $mtime ),
			'path'   => $path,
		];
	}

	/**
	 * Delete all feed artifacts associated with a specific token.
	 */
	public static function delete_token_files( string $token ): void {
		$dir = self::get_storage_dir();
		$pattern = sprintf( '%s/catalog-%s.*', $dir, $token );
		$files = glob( $pattern );
		if ( false !== $files ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					@unlink( $file );
				}
			}
		}
	}
}

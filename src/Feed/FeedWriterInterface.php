<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Feed;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for streaming feed format writers (CSV, TSV, extensible to XML).
 */
interface FeedWriterInterface {
	/**
	 * Open the destination file stream.
	 */
	public function open( string $file_path ): bool;

	/**
	 * Write column header row.
	 *
	 * @param array<string> $headers
	 */
	public function write_header( array $headers ): bool;

	/**
	 * Write single data row.
	 *
	 * @param array<string, string> $row
	 */
	public function write_row( array $row ): bool;

	/**
	 * Flush and close stream.
	 */
	public function close(): void;

	/**
	 * Get format slug.
	 */
	public function get_format(): string;
}

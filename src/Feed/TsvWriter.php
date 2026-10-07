<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Feed;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streaming TSV (tab-separated values) feed writer.
 */
class TsvWriter implements FeedWriterInterface {
	/**
	 * @var resource|null
	 */
	private $handle = null;

	public function open( string $file_path ): bool {
		$dir = dirname( $file_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$handle = fopen( $file_path, 'wb' );
		if ( false === $handle ) {
			return false;
		}

		$this->handle = $handle;
		return true;
	}

	public function write_header( array $headers ): bool {
		if ( ! $this->handle ) {
			return false;
		}

		$cleaned = array_map( [ $this, 'clean_cell' ], $headers );
		$line    = implode( "\t", $cleaned ) . "\n";

		return false !== fwrite( $this->handle, $line );
	}

	public function write_row( array $row ): bool {
		if ( ! $this->handle ) {
			return false;
		}

		$cleaned = array_map( [ $this, 'clean_cell' ], array_values( $row ) );
		$line    = implode( "\t", $cleaned ) . "\n";

		return false !== fwrite( $this->handle, $line );
	}

	/**
	 * Remove tabs and newlines from a TSV cell to preserve column structure.
	 */
	private function clean_cell( mixed $cell ): string {
		$val = (string) $cell;
		return str_replace( [ "\t", "\r\n", "\r", "\n" ], [ ' ', ' ', ' ', ' ' ], $val );
	}

	public function close(): void {
		if ( $this->handle ) {
			fflush( $this->handle );
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	public function get_format(): string {
		return 'tsv';
	}
}

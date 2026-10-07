<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Feed;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streaming CSV feed writer complying with RFC 4180.
 */
class CsvWriter implements FeedWriterInterface {
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

		return false !== fputcsv( $this->handle, $headers, ',', '"', '\\' );
	}

	public function write_row( array $row ): bool {
		if ( ! $this->handle ) {
			return false;
		}

		return false !== fputcsv( $this->handle, array_values( $row ), ',', '"', '\\' );
	}

	public function close(): void {
		if ( $this->handle ) {
			fflush( $this->handle );
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	public function get_format(): string {
		return 'csv';
	}
}

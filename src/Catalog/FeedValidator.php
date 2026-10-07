<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates generated catalog feed structure, required fields, formatting, and integrity.
 */
class FeedValidator {
	public const REQUIRED_FIELDS = [
		'id',
		'title',
		'description',
		'link',
		'image_link',
		'price',
		'availability',
	];

	public const ALLOWED_AVAILABILITY = [
		'in_stock',
		'out_of_stock',
		'preorder',
	];

	/**
	 * Validate a feed item data array before writing.
	 *
	 * @param array<string, string> $item
	 * @return array<string> List of validation error messages (empty if valid).
	 */
	public static function validate_item( array $item ): array {
		$errors = [];

		// 1. Required fields presence and non-emptiness.
		foreach ( self::REQUIRED_FIELDS as $field ) {
			if ( ! isset( $item[ $field ] ) || '' === trim( (string) $item[ $field ] ) ) {
				$errors[] = sprintf( 'Missing required field "%s"', $field );
			}
		}

		// 2. ID stability and format.
		if ( ! empty( $item['id'] ) && ! preg_match( '/^[a-zA-Z0-9_\-\.]+$/', $item['id'] ) ) {
			$errors[] = sprintf( 'Invalid characters in ID: "%s"', $item['id'] );
		}

		// 3. Link URL syntax.
		if ( ! empty( $item['link'] ) && ! filter_var( $item['link'], FILTER_VALIDATE_URL ) ) {
			$errors[] = sprintf( 'Invalid product link URL: "%s"', $item['link'] );
		}

		// 4. Image Link URL syntax.
		if ( ! empty( $item['image_link'] ) && ! filter_var( $item['image_link'], FILTER_VALIDATE_URL ) ) {
			$errors[] = sprintf( 'Invalid image link URL: "%s"', $item['image_link'] );
		}

		// 5. Price formatting. Must match "0.00 CUR".
		if ( ! empty( $item['price'] ) && ! preg_match( '/^\d+(\.\d{1,2})?\s+[A-Z]{3}$/', $item['price'] ) ) {
			$errors[] = sprintf( 'Invalid price format: "%s" (expected e.g. "19.99 USD")', $item['price'] );
		}

		// 6. Sale Price formatting (if provided).
		if ( ! empty( $item['sale_price'] ) && ! preg_match( '/^\d+(\.\d{1,2})?\s+[A-Z]{3}$/', $item['sale_price'] ) ) {
			$errors[] = sprintf( 'Invalid sale price format: "%s"', $item['sale_price'] );
		}

		// 7. Availability enum.
		if ( ! empty( $item['availability'] ) && ! in_array( $item['availability'], self::ALLOWED_AVAILABILITY, true ) ) {
			$errors[] = sprintf( 'Invalid availability value: "%s" (expected: in_stock, out_of_stock, or preorder)', $item['availability'] );
		}

		// 8. Field length constraints.
		if ( ! empty( $item['title'] ) && mb_strlen( $item['title'], 'UTF-8' ) > 500 ) {
			$errors[] = 'Title exceeds maximum allowed length of 500 characters';
		}

		if ( ! empty( $item['description'] ) && mb_strlen( $item['description'], 'UTF-8' ) > 9999 ) {
			$errors[] = 'Description exceeds maximum allowed length of 9999 characters';
		}

		return $errors;
	}

	/**
	 * Validate complete generated feed file before atomically activating it.
	 *
	 * @param string $file_path Path to the generated temporary feed file.
	 * @param string $format 'csv' or 'tsv'.
	 * @return array{valid: bool, errors: array<string>, warnings: array<string>, total_rows: int}
	 */
	public static function validate_file( string $file_path, string $format ): array {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return [
				'valid'      => false,
				'errors'     => [ 'Feed file does not exist or is not readable.' ],
				'warnings'   => [],
				'total_rows' => 0,
			];
		}

		$handle = fopen( $file_path, 'rb' );
		if ( ! $handle ) {
			return [
				'valid'      => false,
				'errors'     => [ 'Failed to open feed file for validation.' ],
				'warnings'   => [],
				'total_rows' => 0,
			];
		}

		$delimiter = ( 'tsv' === $format ) ? "\t" : ',';
		$header    = fgetcsv( $handle, 0, $delimiter );

		if ( ! is_array( $header ) || empty( $header ) ) {
			fclose( $handle );
			return [
				'valid'      => false,
				'errors'     => [ 'Feed file is empty or missing headers.' ],
				'warnings'   => [],
				'total_rows' => 0,
			];
		}

		// Clean BOM or invisible characters from first header item if present.
		$header[0] = preg_replace( '/[\x00-\x1F\x80-\xFF]/', '', (string) $header[0] );
		$header    = array_map( 'trim', $header );

		$errors   = [];
		$warnings = [];

		// Check required headers.
		foreach ( self::REQUIRED_FIELDS as $required ) {
			if ( ! in_array( $required, $header, true ) ) {
				$errors[] = sprintf( 'Missing required column header: "%s"', $required );
			}
		}

		if ( ! empty( $errors ) ) {
			fclose( $handle );
			return [
				'valid'      => false,
				'errors'     => $errors,
				'warnings'   => $warnings,
				'total_rows' => 0,
			];
		}

		$header_count = count( $header );
		$id_col_index = array_search( 'id', $header, true );
		$seen_ids     = [];
		$row_number   = 1;
		$max_checks   = 50000;

		while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
			$row_number++;

			// Check column count match.
			if ( count( $row ) !== $header_count ) {
				$errors[] = sprintf( 'Row %d column count mismatch (found %d, expected %d)', $row_number, count( $row ), $header_count );
				if ( count( $errors ) >= 20 ) {
					break;
				}
				continue;
			}

			// Check duplicate ID.
			$row_id = trim( (string) $row[ $id_col_index ] );
			if ( '' === $row_id ) {
				$errors[] = sprintf( 'Row %d has empty ID', $row_number );
			} elseif ( isset( $seen_ids[ $row_id ] ) ) {
				$errors[] = sprintf( 'Duplicate ID "%s" on row %d (first seen row %d)', $row_id, $row_number, $seen_ids[ $row_id ] );
			} else {
				$seen_ids[ $row_id ] = $row_number;
			}

			if ( count( $errors ) >= 20 ) {
				$errors[] = 'Validation stopped after encountering 20 errors.';
				break;
			}

			if ( $row_number > $max_checks ) {
				$warnings[] = sprintf( 'File exceeded %d rows; sample validation concluded successfully.', $max_checks );
				break;
			}
		}

		fclose( $handle );

		$total_rows = $row_number - 1;
		if ( 0 === $total_rows ) {
			$errors[] = 'Feed file contains headers but zero product rows.';
		}

		return [
			'valid'      => empty( $errors ),
			'errors'     => $errors,
			'warnings'   => $warnings,
			'total_rows' => $total_rows,
		];
	}
}

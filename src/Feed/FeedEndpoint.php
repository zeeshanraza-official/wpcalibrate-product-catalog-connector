<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Feed;

use WPCalibrate\ProductCatalogConnector\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves cached catalog feeds via direct HTTP streaming with token verification and optional basic auth.
 */
class FeedEndpoint {
	/**
	 * Register hook for feed requests.
	 */
	public function register(): void {
		add_action( 'template_redirect', [ $this, 'handle_feed_request' ], 1 );
	}

	/**
	 * Intercept and handle incoming feed requests.
	 */
	public function handle_feed_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['wpcal_pcc_feed'] ) || '1' !== $_GET['wpcal_pcc_feed'] ) {
			return;
		}

		// 1. Verify Feed Token.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$provided_token = isset( $_GET['token'] ) ? sanitize_text_field( (string) $_GET['token'] ) : '';

		if ( ! FeedToken::validate_token( $provided_token ) ) {
			status_header( 403 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo 'Access Denied: Invalid or expired catalog feed token.';
			exit;
		}

		// 2. Verify HTTP Basic Authentication if enabled in settings.
		$basic_auth_enabled = (bool) Settings::get( 'http_basic_auth_enabled', false );
		if ( $basic_auth_enabled ) {
			$expected_user = (string) Settings::get( 'http_basic_auth_user', '' );
			$expected_pass = (string) Settings::get( 'http_basic_auth_pass', '' );

			$auth_user = $_SERVER['PHP_AUTH_USER'] ?? '';
			$auth_pass = $_SERVER['PHP_AUTH_PW'] ?? '';

			// Support Authorization header if mod_rewrite strips PHP_AUTH_*
			if ( empty( $auth_user ) && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
				$auth_header = (string) $_SERVER['HTTP_AUTHORIZATION'];
				if ( str_starts_with( $auth_header, 'Basic ' ) ) {
					$decoded = base64_decode( substr( $auth_header, 6 ), true );
					if ( false !== $decoded && str_contains( $decoded, ':' ) ) {
						[ $auth_user, $auth_pass ] = explode( ':', $decoded, 2 );
					}
				}
			}

			if ( empty( $auth_user ) || ! hash_equals( $expected_user, $auth_user ) || ! hash_equals( $expected_pass, $auth_pass ) ) {
				header( 'WWW-Authenticate: Basic realm="WPCalibrate Catalog Feed"' );
				status_header( 401 );
				nocache_headers();
				header( 'Content-Type: text/plain; charset=UTF-8' );
				echo 'Authentication Required.';
				exit;
			}
		}

		// 3. Format determination.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$format = isset( $_GET['format'] ) ? strtolower( sanitize_key( (string) $_GET['format'] ) ) : 'csv';
		if ( ! in_array( $format, [ 'csv', 'tsv' ], true ) ) {
			$format = 'csv';
		}

		// 4. File existence check.
		$feed_path = FeedStorage::get_active_feed_path( $format );
		if ( ! file_exists( $feed_path ) || ! is_readable( $feed_path ) ) {
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo 'Feed not found: The catalog feed has not been generated yet. Please generate it from WordPress admin.';
			exit;
		}

		// 5. Check If-Modified-Since HTTP header for caching efficiency.
		$mtime = filemtime( $feed_path ) ?: time();
		if ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
			$if_modified_since = strtotime( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
			if ( false !== $if_modified_since && $mtime <= $if_modified_since ) {
				status_header( 304 );
				exit;
			}
		}

		// 6. Send streaming headers.
		$content_type = ( 'tsv' === $format ) ? 'text/tab-separated-values; charset=UTF-8' : 'text/csv; charset=UTF-8';
		$filename     = sprintf( 'pinterest-catalog.%s', $format );
		$filesize     = (int) filesize( $feed_path );

		// Clear any output buffers before streaming.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( 200 );
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . $filesize );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Content-Type-Options: nosniff' );

		// Efficient stream delivery.
		readfile( $feed_path );
		exit;
	}
}

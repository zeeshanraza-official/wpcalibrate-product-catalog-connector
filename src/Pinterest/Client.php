<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Pinterest;

use WPCalibrate\ProductCatalogConnector\Diagnostics\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP client for Pinterest API v5 requests with rate-limiting and error handling.
 */
class Client {
	public const BASE_URL = 'https://api.pinterest.com/v5';

	private TokenStore $token_store;

	public function __construct( ?TokenStore $token_store = null ) {
		$this->token_store = $token_store ?? new TokenStore();
	}

	/**
	 * Perform an authenticated GET request.
	 *
	 * @param string               $endpoint Relative endpoint (e.g. '/catalogs/feeds')
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get( string $endpoint, array $query = [] ): array|\WP_Error {
		return $this->request( 'GET', $endpoint, [ 'query' => $query ] );
	}

	/**
	 * Perform an authenticated POST request.
	 *
	 * @param string               $endpoint Relative endpoint
	 * @param array<string, mixed> $body
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>|\WP_Error
	 */
	public function post( string $endpoint, array $body = [], array $query = [] ): array|\WP_Error {
		return $this->request( 'POST', $endpoint, [ 'body' => $body, 'query' => $query ] );
	}

	/**
	 * Execute an HTTP request against Pinterest API v5.
	 *
	 * @param string               $method
	 * @param string               $endpoint
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public function request( string $method, string $endpoint, array $args = [] ): array|\WP_Error {
		$token = $this->token_store->get_access_token();

		if ( empty( $token ) ) {
			return new \WP_Error(
				'wpcal_pcc_no_token',
				__( 'No Pinterest access token configured. Please connect your Pinterest account.', 'wpcalibrate-product-catalog-connector' )
			);
		}

		$url = self::BASE_URL . '/' . ltrim( $endpoint, '/' );

		if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
			$url = add_query_arg( $args['query'], $url );
		}

		$headers = [
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
			'User-Agent'    => 'WPCalibrate-ProductCatalogConnector/' . WPCAL_PCC_VERSION,
		];

		$request_args = [
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => 25,
			'redirection' => 2,
			'httpversion' => '1.1',
		];

		if ( in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) && ! empty( $args['body'] ) ) {
			$request_args['body'] = wp_json_encode( $args['body'] );
		}

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			Logger::error( sprintf( 'Pinterest API HTTP error on %s %s: %s', $method, $endpoint, $response->get_error_message() ) );
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body_raw, true );

		if ( ! is_array( $data ) ) {
			$data = [];
		}

		// Handle Rate Limiting (429).
		if ( 429 === $status_code ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			Logger::warning( sprintf( 'Pinterest API rate limit exceeded (429). Retry after: %s seconds.', (string) $retry_after ) );
			return new \WP_Error(
				'wpcal_pcc_rate_limited',
				sprintf(
					/* translators: %s: number of seconds to retry after */
					__( 'Pinterest API rate limit exceeded. Please wait %s seconds before trying again.', 'wpcalibrate-product-catalog-connector' ),
					esc_html( (string) ( $retry_after ?: '60' ) )
				)
			);
		}

		// Handle Authentication Errors (401).
		if ( 401 === $status_code ) {
			$this->token_store->mark_revoked();
			Logger::warning( 'Pinterest API returned 401 Unauthorized. Access token marked expired/revoked.' );
			return new \WP_Error(
				'wpcal_pcc_unauthorized',
				__( 'Pinterest authorization expired or was revoked. Please reconnect your account.', 'wpcalibrate-product-catalog-connector' )
			);
		}

		// Handle Other Client/Server Errors (4xx, 5xx).
		if ( $status_code >= 400 ) {
			$err_msg = $data['message'] ?? $data['error'] ?? sprintf( 'HTTP %d error returned by Pinterest API', $status_code );
			Logger::error( sprintf( 'Pinterest API %s %s returned error: %s', $method, $endpoint, $err_msg ) );
			return new \WP_Error( 'wpcal_pcc_api_error', (string) $err_msg, [ 'status' => $status_code, 'data' => $data ] );
		}

		return $data;
	}
}

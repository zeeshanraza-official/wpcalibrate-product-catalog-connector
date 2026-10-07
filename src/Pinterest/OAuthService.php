<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Pinterest;

use WPCalibrate\ProductCatalogConnector\Diagnostics\Logger;
use WPCalibrate\ProductCatalogConnector\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles OAuth 2.0 Authorization Code flow, secure state verification, and token exchange.
 */
class OAuthService {
	public const AUTH_URL  = 'https://www.pinterest.com/oauth/';
	public const TOKEN_URL = 'https://api.pinterest.com/v5/oauth/token';
	public const SCOPES    = 'catalogs:read,catalogs:write';

	private TokenStore $token_store;

	public function __construct( ?TokenStore $token_store = null ) {
		$this->token_store = $token_store ?? new TokenStore();
	}

	/**
	 * Get the OAuth callback redirect URI.
	 */
	public static function get_redirect_uri(): string {
		return admin_url( 'admin.php?page=wpcalibrate-product-catalog-connector&tab=pinterest' );
	}

	/**
	 * Generate a cryptographically secure, user- and site-bound state string with 15-minute expiration.
	 */
	public function generate_state(): string {
		$random = bin2hex( random_bytes( 24 ) );
		$user_id = get_current_user_id();
		$site_hash = substr( hash( 'sha256', get_site_url() ), 0, 12 );

		$state_value = sprintf( '%s.%d.%s', $random, $user_id, $site_hash );
		$state_key   = 'wpcal_pcc_oauth_state_' . hash( 'sha256', $state_value );

		// 15-minute TTL.
		set_transient( $state_key, [ 'user_id' => $user_id, 'created' => time() ], 900 );

		return $state_value;
	}

	/**
	 * Verify an incoming state value and prevent replay by immediately consuming it.
	 */
	public function verify_state( string $state ): bool {
		if ( empty( $state ) ) {
			return false;
		}

		$parts = explode( '.', $state );
		if ( 3 !== count( $parts ) ) {
			return false;
		}

		[ $random, $user_id, $site_hash ] = $parts;

		// Verify user and site bindings.
		if ( (int) $user_id !== get_current_user_id() ) {
			return false;
		}

		$expected_site_hash = substr( hash( 'sha256', get_site_url() ), 0, 12 );
		if ( ! hash_equals( $expected_site_hash, $site_hash ) ) {
			return false;
		}

		$state_key = 'wpcal_pcc_oauth_state_' . hash( 'sha256', $state );
		$data      = get_transient( $state_key );

		if ( false === $data ) {
			return false;
		}

		// Replay prevention: delete state immediately.
		delete_transient( $state_key );

		return true;
	}

	/**
	 * Build the Pinterest authorization URL.
	 */
	public function get_authorization_url(): string {
		$app_id = (string) Settings::get( 'pinterest_app_id', '' );
		if ( empty( $app_id ) ) {
			return '';
		}

		$state = $this->generate_state();

		return add_query_arg(
			[
				'client_id'     => $app_id,
				'redirect_uri'  => self::get_redirect_uri(),
				'response_type' => 'code',
				'scope'         => self::SCOPES,
				'state'         => $state,
			],
			self::AUTH_URL
		);
	}

	/**
	 * Exchange authorization code for access and refresh tokens.
	 *
	 * @param string $code
	 * @return array<string, mixed>|\WP_Error
	 */
	public function exchange_code( string $code ): array|\WP_Error {
		$app_id     = (string) Settings::get( 'pinterest_app_id', '' );
		$app_secret = (string) Settings::get( 'pinterest_app_secret', '' );

		if ( empty( $app_id ) || empty( $app_secret ) ) {
			return new \WP_Error(
				'wpcal_pcc_missing_credentials',
				__( 'Pinterest App ID or App Secret is not configured.', 'wpcalibrate-product-catalog-connector' )
			);
		}

		$auth_header = 'Basic ' . base64_encode( $app_id . ':' . $app_secret );

		$body = [
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => self::get_redirect_uri(),
		];

		$response = wp_remote_post(
			self::TOKEN_URL,
			[
				'headers' => [
					'Authorization' => $auth_header,
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
					'User-Agent'    => 'WPCalibrate-ProductCatalogConnector/' . WPCAL_PCC_VERSION,
				],
				'body'    => $body,
				'timeout' => 25,
			]
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'OAuth token exchange network error: ' . $response->get_error_message() );
			return $response;
		}

		$code_status = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body_raw, true );

		if ( ! is_array( $data ) || 200 !== $code_status ) {
			$err = $data['message'] ?? $data['error_description'] ?? $data['error'] ?? 'Unknown token exchange failure';
			Logger::error( sprintf( 'OAuth token exchange failed (HTTP %d): %s', $code_status, $err ) );
			return new \WP_Error( 'wpcal_pcc_exchange_failed', (string) $err );
		}

		if ( empty( $data['access_token'] ) ) {
			return new \WP_Error( 'wpcal_pcc_no_token', __( 'Malformed token response from Pinterest: missing access token.', 'wpcalibrate-product-catalog-connector' ) );
		}

		// Save tokens.
		$this->token_store->save_tokens( $data );
		Logger::info( 'Successfully connected to Pinterest via OAuth 2.0.' );

		return $data;
	}

	/**
	 * Refresh access token using stored refresh token.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function refresh_token(): array|\WP_Error {
		$refresh_token = $this->token_store->get_refresh_token();
		$app_id        = (string) Settings::get( 'pinterest_app_id', '' );
		$app_secret    = (string) Settings::get( 'pinterest_app_secret', '' );

		if ( empty( $refresh_token ) || empty( $app_id ) || empty( $app_secret ) ) {
			return new \WP_Error( 'wpcal_pcc_no_refresh_token', __( 'Cannot refresh token: missing refresh token or credentials.', 'wpcalibrate-product-catalog-connector' ) );
		}

		$auth_header = 'Basic ' . base64_encode( $app_id . ':' . $app_secret );

		$response = wp_remote_post(
			self::TOKEN_URL,
			[
				'headers' => [
					'Authorization' => $auth_header,
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
				],
				'body'    => [
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
				],
				'timeout' => 25,
			]
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'OAuth token refresh error: ' . $response->get_error_message() );
			return $response;
		}

		$code_status = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body_raw, true );

		if ( ! is_array( $data ) || 200 !== $code_status ) {
			$err = $data['message'] ?? $data['error_description'] ?? 'Token refresh failed';
			$this->token_store->mark_revoked();
			Logger::warning( 'OAuth token refresh failed. Marked revoked.' );
			return new \WP_Error( 'wpcal_pcc_refresh_failed', (string) $err );
		}

		$this->token_store->save_tokens( $data );
		Logger::info( 'Successfully refreshed Pinterest access token.' );

		return $data;
	}

	/**
	 * Disconnect Pinterest connection.
	 */
	public function disconnect(): void {
		$this->token_store->clear();
		Logger::info( 'Pinterest account disconnected by administrator.' );
	}
}

<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Pinterest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages secure storage, retrieval, and status tracking of Pinterest OAuth tokens.
 */
class TokenStore {
	public const TOKEN_OPTION = 'wpcal_pcc_pinterest_tokens';

	/**
	 * Get stored token data.
	 *
	 * @return array<string, mixed>
	 */
	public function get_data(): array {
		// Advanced configuration: allow token supply via PHP constants.
		if ( defined( 'WPCAL_PCC_PINTEREST_ACCESS_TOKEN' ) ) {
			return [
				'access_token'  => (string) WPCAL_PCC_PINTEREST_ACCESS_TOKEN,
				'refresh_token' => defined( 'WPCAL_PCC_PINTEREST_REFRESH_TOKEN' ) ? (string) WPCAL_PCC_PINTEREST_REFRESH_TOKEN : '',
				'token_type'    => 'bearer',
				'expires_at'    => time() + 86400,
				'scope'         => 'catalogs:read,catalogs:write',
				'connected_at'  => time(),
				'status'        => 'connected',
			];
		}

		$stored = get_option( self::TOKEN_OPTION, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		return $stored;
	}

	/**
	 * Get raw access token.
	 */
	public function get_access_token(): string {
		$data = $this->get_data();
		return (string) ( $data['access_token'] ?? '' );
	}

	/**
	 * Get raw refresh token.
	 */
	public function get_refresh_token(): string {
		$data = $this->get_data();
		return (string) ( $data['refresh_token'] ?? '' );
	}

	/**
	 * Get masked access token for admin screen display.
	 */
	public function get_masked_access_token(): string {
		$token = $this->get_access_token();
		if ( empty( $token ) ) {
			return '';
		}

		$len = strlen( $token );
		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}

		return substr( $token, 0, 4 ) . str_repeat( '•', max( 8, $len - 8 ) ) . substr( $token, -4 );
	}

	/**
	 * Check if currently connected with a token.
	 */
	public function is_connected(): bool {
		$data = $this->get_data();
		return ! empty( $data['access_token'] ) && 'revoked' !== ( $data['status'] ?? '' );
	}

	/**
	 * Check if token has expired.
	 */
	public function is_expired(): bool {
		$data = $this->get_data();
		if ( empty( $data['expires_at'] ) ) {
			return false;
		}

		// Buffer of 60 seconds.
		return time() >= ( (int) $data['expires_at'] - 60 );
	}

	/**
	 * Save token data securely with autoload = false.
	 *
	 * @param array<string, mixed> $token_response
	 */
	public function save_tokens( array $token_response ): void {
		$existing   = $this->get_data();
		$expires_in = isset( $token_response['expires_in'] ) ? absint( $token_response['expires_in'] ) : 30 * DAY_IN_SECONDS;

		$data = [
			'access_token'  => sanitize_text_field( (string) ( $token_response['access_token'] ?? '' ) ),
			'refresh_token' => sanitize_text_field( (string) ( $token_response['refresh_token'] ?? ( $existing['refresh_token'] ?? '' ) ) ),
			'token_type'    => sanitize_text_field( (string) ( $token_response['token_type'] ?? 'bearer' ) ),
			'scope'         => sanitize_text_field( (string) ( $token_response['scope'] ?? 'catalogs:read,catalogs:write' ) ),
			'expires_at'    => time() + $expires_in,
			'connected_at'  => $existing['connected_at'] ?? time(),
			'last_refreshed'=> time(),
			'status'        => 'connected',
		];

		update_option( self::TOKEN_OPTION, $data, false );
	}

	/**
	 * Mark token as revoked / unauthorized.
	 */
	public function mark_revoked(): void {
		$data = $this->get_data();
		$data['status'] = 'revoked';
		update_option( self::TOKEN_OPTION, $data, false );
	}

	/**
	 * Disconnect and remove all tokens.
	 */
	public function clear(): void {
		delete_option( self::TOKEN_OPTION );
	}
}

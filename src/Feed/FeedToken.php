<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Feed;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages cryptographically secure feed access tokens and rotation.
 */
class FeedToken {
	public const TOKEN_OPTION = 'wpcal_pcc_feed_token';
	public const TOKEN_META_OPTION = 'wpcal_pcc_feed_token_meta';

	/**
	 * Get the active feed token, generating one if not already set.
	 */
	public static function get_token(): string {
		$token = (string) get_option( self::TOKEN_OPTION, '' );

		if ( empty( $token ) ) {
			$token = self::rotate_token();
		}

		return $token;
	}

	/**
	 * Generate and save a new secure feed token, invalidating the old one.
	 */
	public static function rotate_token(): string {
		$old_token = (string) get_option( self::TOKEN_OPTION, '' );

		try {
			$new_token = bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable ) {
			$new_token = wp_generate_password( 64, false, false );
		}

		update_option( self::TOKEN_OPTION, $new_token, false );

		$meta = [
			'created_at'        => time(),
			'last_rotation_at'  => time(),
			'previous_token_hash' => ! empty( $old_token ) ? hash( 'sha256', $old_token ) : '',
		];

		update_option( self::TOKEN_META_OPTION, $meta, false );

		/**
		 * Fires after the feed token has been rotated.
		 *
		 * @param string $new_token The new active token.
		 * @param string $old_token The previous token.
		 */
		do_action( 'wpcal_pcc_feed_token_rotated', $new_token, $old_token );

		return $new_token;
	}

	/**
	 * Validate a supplied token against the stored active token using constant-time comparison.
	 */
	public static function validate_token( string $supplied_token ): bool {
		$active_token = self::get_token();

		if ( empty( $supplied_token ) || empty( $active_token ) ) {
			return false;
		}

		return hash_equals( $active_token, $supplied_token );
	}

	/**
	 * Get metadata about token creation and rotation.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_meta(): array {
		$meta = get_option( self::TOKEN_META_OPTION, [] );
		if ( ! is_array( $meta ) ) {
			$meta = [];
		}

		return wp_parse_args(
			$meta,
			[
				'created_at'        => time(),
				'last_rotation_at'  => 0,
				'previous_token_hash' => '',
			]
		);
	}
}

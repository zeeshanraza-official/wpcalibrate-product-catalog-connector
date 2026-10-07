<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Diagnostics;

use WPCalibrate\ProductCatalogConnector\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles sanitized, masked logging to WooCommerce Logger.
 */
class Logger {
	public const SOURCE = 'wpcalibrate-catalog-connector';

	/**
	 * Log an info-level event.
	 */
	public static function info( string $message, array $context = [] ): void {
		self::log( 'info', $message, $context );
	}

	/**
	 * Log a warning-level event.
	 */
	public static function warning( string $message, array $context = [] ): void {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Log an error-level event.
	 */
	public static function error( string $message, array $context = [] ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * Log a debug-level event (only if logging_level is 'debug').
	 */
	public static function debug( string $message, array $context = [] ): void {
		$level = (string) Settings::get( 'logging_level', 'standard' );
		if ( 'debug' === $level ) {
			self::log( 'debug', $message, $context );
		}
	}

	/**
	 * Central logging handler with secret scrubbing.
	 */
	public static function log( string $level, string $message, array $context = [] ): void {
		$configured_level = (string) Settings::get( 'logging_level', 'standard' );
		if ( 'none' === $configured_level ) {
			return;
		}

		$clean_message = self::redact_sensitive_strings( $message );
		$clean_context = self::redact_context_array( $context );

		if ( function_exists( 'wc_get_logger' ) ) {
			$wc_logger = wc_get_logger();
			$clean_context['source'] = self::SOURCE;
			$wc_logger->log( $level, $clean_message, $clean_context );
		}
	}

	/**
	 * Redact known sensitive tokens and credentials from text strings.
	 */
	public static function redact_sensitive_strings( string $text ): string {
		$settings = Settings::get_all();

		$sensitive_values = [
			$settings['pinterest_app_secret'] ?? '',
			$settings['http_basic_auth_pass'] ?? '',
			get_option( 'wpcal_pcc_license_key', '' ),
			get_option( 'wpcal_pcc_feed_token', '' ),
		];

		$tokens = get_option( 'wpcal_pcc_pinterest_tokens', [] );
		if ( is_array( $tokens ) ) {
			if ( ! empty( $tokens['access_token'] ) ) {
				$sensitive_values[] = $tokens['access_token'];
			}
			if ( ! empty( $tokens['refresh_token'] ) ) {
				$sensitive_values[] = $tokens['refresh_token'];
			}
		}

		foreach ( $sensitive_values as $secret ) {
			if ( is_string( $secret ) && strlen( trim( $secret ) ) > 3 ) {
				$text = str_replace( $secret, '••••[REDACTED]••••', $text );
			}
		}

		// Also regex redact any Bearer tokens.
		$text = (string) preg_replace( '/Bearer\s+[a-zA-Z0-9_\-\.]+/i', 'Bearer ••••[REDACTED]••••', $text );

		return $text;
	}

	/**
	 * Redact sensitive fields from associative context arrays.
	 *
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	public static function redact_context_array( array $context ): array {
		$sensitive_keys = [
			'secret', 'password', 'pass', 'token', 'access_token', 'refresh_token',
			'authorization', 'auth', 'key', 'license', 'license_key',
		];

		$clean = [];
		foreach ( $context as $k => $v ) {
			if ( is_string( $k ) && in_array( strtolower( $k ), $sensitive_keys, true ) ) {
				$clean[ $k ] = '••••[REDACTED]••••';
			} elseif ( is_array( $v ) ) {
				$clean[ $k ] = self::redact_context_array( $v );
			} elseif ( is_string( $v ) ) {
				$clean[ $k ] = self::redact_sensitive_strings( $v );
			} else {
				$clean[ $k ] = $v;
			}
		}

		return $clean;
	}
}

<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages license key storage, masking, provider delegation, and status.
 */
class LicenseManager {
	public const KEY_OPTION    = 'wpcal_pcc_license_key';
	public const STATUS_OPTION = 'wpcal_pcc_license_status';

	private LicenseProviderInterface $provider;

	public function __construct( ?LicenseProviderInterface $provider = null ) {
		$this->provider = $provider ?? new DefaultProvider();
	}

	/**
	 * Get raw stored license key.
	 */
	public function get_key(): string {
		return (string) get_option( self::KEY_OPTION, '' );
	}

	/**
	 * Get masked license key for administrative screen display.
	 */
	public function get_masked_key(): string {
		$key = $this->get_key();
		if ( empty( $key ) ) {
			return '';
		}

		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}

		return substr( $key, 0, 4 ) . str_repeat( '•', max( 8, $len - 8 ) ) . substr( $key, -4 );
	}

	/**
	 * Get license status information.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		$stored = get_option( self::STATUS_OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return wp_parse_args(
			$stored,
			[
				'status'      => 'unconfigured',
				'message'     => __( 'Licensing server is not configured in this release.', 'wpcalibrate-product-catalog-connector' ),
				'expires_at'  => null,
				'updated_at'  => 0,
			]
		);
	}

	/**
	 * Save license key and attempt activation with provider.
	 *
	 * @param string $key
	 * @return array<string, mixed>
	 */
	public function save_and_activate( string $key ): array {
		$key = sanitize_text_field( trim( $key ) );
		update_option( self::KEY_OPTION, $key, false );

		$result = $this->provider->activate( $key );

		$status_data = [
			'status'     => $result['status'] ?? 'unconfigured',
			'message'    => $result['message'] ?? '',
			'expires_at' => $result['expires_at'] ?? null,
			'updated_at' => time(),
		];

		update_option( self::STATUS_OPTION, $status_data, false );

		return $result;
	}

	/**
	 * Deactivate and clear license key.
	 */
	public function deactivate(): void {
		$key = $this->get_key();
		if ( ! empty( $key ) ) {
			$this->provider->deactivate( $key );
		}

		delete_option( self::KEY_OPTION );
		delete_option( self::STATUS_OPTION );
	}

	/**
	 * Check if provider is configured.
	 */
	public function is_provider_configured(): bool {
		return $this->provider->is_configured();
	}
}

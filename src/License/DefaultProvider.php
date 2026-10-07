<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Standard WPCalibrate license provider adapter.
 * Clearly reports unconfigured status when remote provider endpoints have not been supplied.
 */
class DefaultProvider implements LicenseProviderInterface {
	public function is_configured(): bool {
		return false; // Remote API endpoint and provider credentials not yet supplied in V1.
	}

	public function activate( string $license_key ): array {
		return [
			'success'    => false,
			'message'    => __( 'Remote license activation is currently unavailable because the WPCalibrate licensing server endpoint has not been configured.', 'wpcalibrate-product-catalog-connector' ),
			'status'     => 'unconfigured',
			'expires_at' => null,
		];
	}

	public function deactivate( string $license_key ): array {
		return [
			'success' => true,
			'message' => __( 'License cleared locally.', 'wpcalibrate-product-catalog-connector' ),
		];
	}

	public function check_status( string $license_key ): array {
		return [
			'valid'      => false,
			'status'     => 'unconfigured',
			'expires_at' => null,
		];
	}
}

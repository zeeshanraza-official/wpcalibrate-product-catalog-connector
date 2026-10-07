<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface defining contract for commercial license validation providers.
 */
interface LicenseProviderInterface {
	/**
	 * Attempt remote license activation.
	 *
	 * @param string $license_key
	 * @return array{success: bool, message: string, status: string, expires_at: ?int}
	 */
	public function activate( string $license_key ): array;

	/**
	 * Attempt remote license deactivation.
	 *
	 * @param string $license_key
	 * @return array{success: bool, message: string}
	 */
	public function deactivate( string $license_key ): array;

	/**
	 * Query current license validation status from provider.
	 *
	 * @param string $license_key
	 * @return array{valid: bool, status: string, expires_at: ?int}
	 */
	public function check_status( string $license_key ): array;

	/**
	 * Check if provider remote endpoints are currently configured.
	 */
	public function is_configured(): bool;
}

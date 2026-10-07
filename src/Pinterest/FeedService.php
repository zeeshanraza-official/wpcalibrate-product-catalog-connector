<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Pinterest;

use WPCalibrate\ProductCatalogConnector\Diagnostics\Logger;
use WPCalibrate\ProductCatalogConnector\Feed\FeedStorage;
use WPCalibrate\ProductCatalogConnector\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages Pinterest remote catalog feed registration, status verification, and ingestion results.
 */
class FeedService {
	private Client $client;

	public function __construct( ?Client $client = null ) {
		$this->client = $client ?? new Client();
	}

	/**
	 * List all catalog feeds accessible on Pinterest account.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_feeds(): array|\WP_Error {
		$query = [];
		$ad_account = (string) Settings::get( 'pinterest_ad_account_id', '' );
		if ( ! empty( $ad_account ) ) {
			$query['ad_account_id'] = $ad_account;
		}

		return $this->client->get( '/catalogs/feeds', $query );
	}

	/**
	 * Register the plugin's hosted catalog feed with Pinterest API v5.
	 * Includes reconciliation to prevent duplicate feed registration.
	 *
	 * @param string $feed_name
	 * @return array<string, mixed>|\WP_Error
	 */
	public function register_feed( string $feed_name ): array|\WP_Error {
		$settings = Settings::get_all();
		$format   = (string) ( $settings['feed_format'] ?? 'csv' );
		$feed_url = FeedStorage::get_feed_url( $format );

		// 1. Check if feed is already linked in local settings.
		if ( ! empty( $settings['pinterest_feed_id'] ) ) {
			return new \WP_Error(
				'wpcal_pcc_feed_already_registered',
				sprintf(
					/* translators: %s: Pinterest Feed ID */
					__( 'A Pinterest Feed ID (%s) is already configured. Unlink it or update existing feed.', 'wpcalibrate-product-catalog-connector' ),
					esc_html( (string) $settings['pinterest_feed_id'] )
				)
			);
		}

		// 2. Duplicate Prevention: Check remote feeds before creating a new one.
		$existing_remote = $this->list_feeds();
		if ( ! is_wp_error( $existing_remote ) && ! empty( $existing_remote['items'] ) && is_array( $existing_remote['items'] ) ) {
			foreach ( $existing_remote['items'] as $item ) {
				if ( isset( $item['location'] ) && $item['location'] === $feed_url ) {
					// Feed already exists on Pinterest with this URL! Link it instead of duplicating.
					$matched_id = (string) $item['id'];
					Settings::update( [ 'pinterest_feed_id' => $matched_id ] );
					Logger::info( sprintf( 'Reconciled existing remote Pinterest feed: %s', $matched_id ) );
					return $item;
				}
			}
		}

		// 3. Prepare feed creation payload.
		$payload = [
			'name'            => sanitize_text_field( $feed_name ),
			'catalog_type'    => 'RETAIL',
			'default_locale'  => (string) ( $settings['locale'] ?? 'en_US' ),
			'default_country' => (string) ( $settings['country'] ?? 'US' ),
			'format'          => strtoupper( $format ),
			'location'        => $feed_url,
		];

		// If HTTP basic auth enabled, include credentials.
		if ( ! empty( $settings['http_basic_auth_enabled'] ) ) {
			$payload['credentials'] = [
				'username' => (string) ( $settings['http_basic_auth_user'] ?? '' ),
				'password' => (string) ( $settings['http_basic_auth_pass'] ?? '' ),
			];
		}

		$query = [];
		$ad_account = (string) ( $settings['pinterest_ad_account_id'] ?? '' );
		if ( ! empty( $ad_account ) ) {
			$query['ad_account_id'] = $ad_account;
		}

		$response = $this->client->post( '/catalogs/feeds', $payload, $query );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['id'] ) ) {
			$feed_id = (string) $response['id'];
			Settings::update( [ 'pinterest_feed_id' => $feed_id ] );
			Logger::info( sprintf( 'Successfully registered new Pinterest catalog feed: %s', $feed_id ) );
		}

		return $response;
	}

	/**
	 * Retrieve details for a specific feed on Pinterest.
	 *
	 * @param string $feed_id
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_feed( string $feed_id ): array|\WP_Error {
		$query = [];
		$ad_account = (string) Settings::get( 'pinterest_ad_account_id', '' );
		if ( ! empty( $ad_account ) ) {
			$query['ad_account_id'] = $ad_account;
		}

		return $this->client->get( sprintf( '/catalogs/feeds/%s', sanitize_text_field( $feed_id ) ), $query );
	}

	/**
	 * Retrieve processing results and diagnostics for a feed.
	 *
	 * @param string $feed_id
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_processing_results( string $feed_id ): array|\WP_Error {
		$query = [];
		$ad_account = (string) Settings::get( 'pinterest_ad_account_id', '' );
		if ( ! empty( $ad_account ) ) {
			$query['ad_account_id'] = $ad_account;
		}

		return $this->client->get( sprintf( '/catalogs/feeds/%s/processing_results', sanitize_text_field( $feed_id ) ), $query );
	}
}

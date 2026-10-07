<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Tests\Api;

use WPCalibrate\ProductCatalogConnector\Pinterest\Client;
use WPCalibrate\ProductCatalogConnector\Pinterest\FeedService;
use WPCalibrate\ProductCatalogConnector\Pinterest\OAuthService;
use WPCalibrate\ProductCatalogConnector\Pinterest\TokenStore;
use WPCalibrate\ProductCatalogConnector\Settings\Settings;

class PinterestApiMockTest {
	public function run(): array {
		$results = [];

		$results['test_oauth_state_generation_and_replay_prevention'] = $this->test_oauth_state_generation_and_replay_prevention();
		$results['test_oauth_token_exchange_success']                 = $this->test_oauth_token_exchange_success();
		$results['test_oauth_token_exchange_failure']                 = $this->test_oauth_token_exchange_failure();
		$results['test_token_refresh_success']                        = $this->test_token_refresh_success();
		$results['test_api_client_rate_limiting_429']                 = $this->test_api_client_rate_limiting_429();
		$results['test_api_client_unauthorized_401']                  = $this->test_api_client_unauthorized_401();
		$results['test_api_client_network_timeout']                   = $this->test_api_client_network_timeout();
		$results['test_feed_registration_success']                    = $this->test_feed_registration_success();
		$results['test_feed_registration_duplicate_prevention']       = $this->test_feed_registration_duplicate_prevention();

		return $results;
	}

	private function test_oauth_state_generation_and_replay_prevention(): bool {
		\WP_Mock_State::reset();

		$oauth = new OAuthService();
		$state = $oauth->generate_state();

		assert( ! empty( $state ) );
		assert( true === $oauth->verify_state( $state ) );

		// Replay attempt with same state MUST fail immediately.
		assert( false === $oauth->verify_state( $state ) );

		// Tampered state must fail.
		assert( false === $oauth->verify_state( 'invalid.state.123' ) );

		return true;
	}

	private function test_oauth_token_exchange_success(): bool {
		\WP_Mock_State::reset();

		Settings::update( [
			'pinterest_app_id'     => 'test_app_id',
			'pinterest_app_secret' => 'test_app_secret',
		] );

		// Mock Pinterest /v5/oauth/token success response.
		\WP_Mock_State::$mock_http_response = [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'headers'  => [ 'content-type' => 'application/json' ],
			'body'     => json_encode( [
				'access_token'  => 'pina_mock_access_token_1234567890',
				'refresh_token' => 'pinr_mock_refresh_token_0987654321',
				'token_type'    => 'bearer',
				'expires_in'    => 2592000,
				'scope'         => 'catalogs:read,catalogs:write',
			] ),
		];

		$token_store = new TokenStore();
		$oauth       = new OAuthService( $token_store );

		$res = $oauth->exchange_code( 'valid_auth_code' );
		assert( ! is_wp_error( $res ) );
		assert( $token_store->is_connected() );
		assert( 'pina_mock_access_token_1234567890' === $token_store->get_access_token() );
		assert( 'pinr_mock_refresh_token_0987654321' === $token_store->get_refresh_token() );

		return true;
	}

	private function test_oauth_token_exchange_failure(): bool {
		\WP_Mock_State::reset();

		Settings::update( [
			'pinterest_app_id'     => 'test_app_id',
			'pinterest_app_secret' => 'test_app_secret',
		] );

		// Mock Pinterest 400 error response.
		\WP_Mock_State::$mock_http_response = [
			'response' => [ 'code' => 400, 'message' => 'Bad Request' ],
			'headers'  => [ 'content-type' => 'application/json' ],
			'body'     => json_encode( [
				'code'    => 1,
				'message' => 'Invalid authorization code or code expired.',
			] ),
		];

		$oauth = new OAuthService();
		$res   = $oauth->exchange_code( 'expired_code' );

		assert( is_wp_error( $res ) );
		assert( str_contains( $res->get_error_message(), 'Invalid authorization code' ) );

		return true;
	}

	private function test_token_refresh_success(): bool {
		\WP_Mock_State::reset();

		$token_store = new TokenStore();
		$token_store->save_tokens( [
			'access_token'  => 'old_access_token',
			'refresh_token' => 'valid_refresh_token',
			'expires_in'    => 3600,
		] );

		Settings::update( [
			'pinterest_app_id'     => 'test_app_id',
			'pinterest_app_secret' => 'test_app_secret',
		] );

		\WP_Mock_State::$mock_http_response = [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'headers'  => [ 'content-type' => 'application/json' ],
			'body'     => json_encode( [
				'access_token' => 'refreshed_access_token_999',
				'token_type'   => 'bearer',
				'expires_in'   => 86400,
			] ),
		];

		$oauth = new OAuthService( $token_store );
		$res   = $oauth->refresh_token();

		assert( ! is_wp_error( $res ) );
		assert( 'refreshed_access_token_999' === $token_store->get_access_token() );

		return true;
	}

	private function test_api_client_rate_limiting_429(): bool {
		\WP_Mock_State::reset();

		$token_store = new TokenStore();
		$token_store->save_tokens( [ 'access_token' => 'mock_token' ] );

		\WP_Mock_State::$mock_http_response = [
			'response' => [ 'code' => 429, 'message' => 'Too Many Requests' ],
			'headers'  => [ 'retry-after' => '45' ],
			'body'     => json_encode( [ 'message' => 'Rate limit exceeded' ] ),
		];

		$client = new Client( $token_store );
		$res    = $client->get( '/catalogs/feeds' );

		assert( is_wp_error( $res ) );
		assert( 'wpcal_pcc_rate_limited' === $res->get_error_code() );
		assert( str_contains( $res->get_error_message(), '45' ) );

		return true;
	}

	private function test_api_client_unauthorized_401(): bool {
		\WP_Mock_State::reset();

		$token_store = new TokenStore();
		$token_store->save_tokens( [ 'access_token' => 'revoked_token' ] );

		\WP_Mock_State::$mock_http_response = [
			'response' => [ 'code' => 401, 'message' => 'Unauthorized' ],
			'headers'  => [],
			'body'     => json_encode( [ 'message' => 'The access token provided has been revoked.' ] ),
		];

		$client = new Client( $token_store );
		$res    = $client->get( '/catalogs/feeds' );

		assert( is_wp_error( $res ) );
		assert( 'wpcal_pcc_unauthorized' === $res->get_error_code() );
		// TokenStore should mark status as revoked.
		assert( false === $token_store->is_connected() );

		return true;
	}

	private function test_api_client_network_timeout(): bool {
		\WP_Mock_State::reset();

		$token_store = new TokenStore();
		$token_store->save_tokens( [ 'access_token' => 'mock_token' ] );

		\WP_Mock_State::$mock_http_response = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$client = new Client( $token_store );
		$res    = $client->get( '/catalogs/feeds' );

		assert( is_wp_error( $res ) );
		assert( 'http_request_failed' === $res->get_error_code() );

		return true;
	}

	private function test_feed_registration_success(): bool {
		\WP_Mock_State::reset();

		$token_store = new TokenStore();
		$token_store->save_tokens( [ 'access_token' => 'mock_token' ] );

		// 1st request list_feeds returns empty items.
		// 2nd request post /catalogs/feeds returns created feed object.
		\WP_Mock_State::$mock_http_response = [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'headers'  => [],
			'body'     => json_encode( [
				'id'           => 'pin_feed_987654',
				'name'         => 'Store Catalog',
				'catalog_type' => 'RETAIL',
				'format'       => 'CSV',
				'status'       => 'ACTIVE',
			] ),
		];

		$service = new FeedService( new Client( $token_store ) );
		$res     = $service->register_feed( 'Store Catalog' );

		assert( ! is_wp_error( $res ) );
		assert( 'pin_feed_987654' === $res['id'] );
		assert( 'pin_feed_987654' === Settings::get( 'pinterest_feed_id' ) );

		return true;
	}

	private function test_feed_registration_duplicate_prevention(): bool {
		\WP_Mock_State::reset();

		$token_store = new TokenStore();
		$token_store->save_tokens( [ 'access_token' => 'mock_token' ] );

		// Mock list_feeds returning an existing feed with the exact matching URL.
		$feed_url = \WPCalibrate\ProductCatalogConnector\Feed\FeedStorage::get_feed_url( 'csv' );
		\WP_Mock_State::$mock_http_response = [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'headers'  => [],
			'body'     => json_encode( [
				'items' => [
					[
						'id'       => 'existing_remote_feed_111',
						'name'     => 'Store Catalog Existing',
						'location' => $feed_url,
					],
				],
			] ),
		];

		$service = new FeedService( new Client( $token_store ) );
		$res     = $service->register_feed( 'Store Catalog' );

		assert( ! is_wp_error( $res ) );
		// Duplicate prevention MUST reconcile and link the existing remote feed!
		assert( 'existing_remote_feed_111' === $res['id'] );
		assert( 'existing_remote_feed_111' === Settings::get( 'pinterest_feed_id' ) );

		return true;
	}
}

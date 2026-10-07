<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Tests\Unit;

use WPCalibrate\ProductCatalogConnector\Catalog\EligibilityService;
use WPCalibrate\ProductCatalogConnector\Catalog\FeedValidator;
use WPCalibrate\ProductCatalogConnector\Catalog\FieldMapper;
use WPCalibrate\ProductCatalogConnector\Catalog\ProductMapper;
use WPCalibrate\ProductCatalogConnector\Catalog\VariationMapper;
use WPCalibrate\ProductCatalogConnector\Feed\CsvWriter;
use WPCalibrate\ProductCatalogConnector\Feed\FeedToken;
use WPCalibrate\ProductCatalogConnector\Feed\TsvWriter;
use WPCalibrate\ProductCatalogConnector\Settings\Settings;

class DomainLogicTest {
	public function run(): array {
		$results = [];

		$results['test_availability_mapping']        = $this->test_availability_mapping();
		$results['test_price_formatting']            = $this->test_price_formatting();
		$results['test_description_sanitization']    = $this->test_description_sanitization();
		$results['test_product_eligibility']         = $this->test_product_eligibility();
		$results['test_variation_mapping']           = $this->test_variation_mapping();
		$results['test_stable_identifiers']          = $this->test_stable_identifiers();
		$results['test_feed_validator_item']         = $this->test_feed_validator_item();
		$results['test_csv_streaming_writer']        = $this->test_csv_streaming_writer();
		$results['test_tsv_streaming_writer']        = $this->test_tsv_streaming_writer();
		$results['test_feed_token_security']         = $this->test_feed_token_security();

		return $results;
	}

	private function test_availability_mapping(): bool {
		assert( 'in_stock' === FieldMapper::map_availability( 'instock', true ) );
		assert( 'out_of_stock' === FieldMapper::map_availability( 'outofstock', false ) );
		assert( 'preorder' === FieldMapper::map_availability( 'onbackorder', false ) );
		assert( 'out_of_stock' === FieldMapper::map_availability( 'instock', false ) );
		return true;
	}

	private function test_price_formatting(): bool {
		assert( '29.99 USD' === FieldMapper::format_price( 29.99, 'USD' ) );
		assert( '100.00 GBP' === FieldMapper::format_price( '100', 'GBP' ) );
		assert( '0.00 EUR' === FieldMapper::format_price( 0.0, 'EUR' ) );
		assert( '' === FieldMapper::format_price( -5.0, 'USD' ) );
		assert( '' === FieldMapper::format_price( '', 'USD' ) );
		return true;
	}

	private function test_description_sanitization(): bool {
		$raw = '<p>Hello <strong>World!</strong> [custom_shortcode arg="1"] Check out <a href="http://example.com">link</a> &amp; enjoy.</p>';
		$cleaned = FieldMapper::clean_description( $raw );
		assert( ! str_contains( $cleaned, '<p>' ) );
		assert( ! str_contains( $cleaned, '<strong>' ) );
		assert( ! str_contains( $cleaned, '[custom_shortcode' ) );
		assert( str_contains( $cleaned, 'Hello World! Check out link & enjoy.' ) );

		// Max length truncation.
		$long_text = str_repeat( 'A', 200 );
		$truncated = FieldMapper::clean_description( $long_text, 50 );
		assert( strlen( $truncated ) <= 50 );
		assert( str_ends_with( $truncated, '...' ) );
		return true;
	}

	private function test_product_eligibility(): bool {
		$eligibility = new EligibilityService( Settings::get_defaults() );

		// 1. Valid published simple product.
		$product = new \WC_Product( 501 );
		$res = $eligibility->is_product_eligible( $product );
		assert( true === $res['eligible'] );

		// 2. Draft product.
		$draft_product = new \WC_Product( 502 );
		$draft_product->set_status( 'draft' );
		$res = $eligibility->is_product_eligible( $draft_product );
		assert( false === $res['eligible'] );
		assert( str_contains( $res['reason'], 'Status is "draft"' ) );

		// 3. Hidden catalog visibility.
		$hidden_product = new \WC_Product( 503 );
		$hidden_product->set_catalog_visibility( 'hidden' );
		$res = $eligibility->is_product_eligible( $hidden_product );
		assert( false === $res['eligible'] );

		// 4. Missing image.
		$no_image_product = new \WC_Product( 504 );
		$no_image_product->set_image_id( 0 );
		$res = $eligibility->is_product_eligible( $no_image_product );
		assert( false === $res['eligible'] );
		assert( str_contains( $res['reason'], 'featured image' ) );

		// 5. Excluded category.
		$custom_settings = Settings::get_defaults();
		$custom_settings['excluded_categories'] = [ 99 ];
		$cat_eligibility = new EligibilityService( $custom_settings );

		$cat_product = new \WC_Product( 505 );
		$cat_product->set_category_ids( [ 99, 10 ] );
		$res = $cat_eligibility->is_product_eligible( $cat_product );
		assert( false === $res['eligible'] );
		assert( str_contains( $res['reason'], 'excluded category' ) );

		return true;
	}

	private function test_variation_mapping(): bool {
		$parent    = new \WC_Product_Variable( 101 );
		$variation = new \WC_Product_Variation( 201, 101 );
		$variation->set_regular_price( '19.99' );

		$mapper = new VariationMapper( Settings::get_defaults() );
		$mapped = $mapper->map( $variation, $parent );

		assert( 'wc_var_201' === $mapped['id'] );
		assert( 'wc_prod_101' === $mapped['item_group_id'] );
		assert( '19.99 USD' === $mapped['price'] );
		assert( 'in_stock' === $mapped['availability'] );
		assert( ! empty( $mapped['image_link'] ) );

		return true;
	}

	private function test_stable_identifiers(): bool {
		$product = new \WC_Product( 345 );
		$mapper  = new ProductMapper( Settings::get_defaults() );
		$mapped  = $mapper->map( $product );

		assert( 'wc_prod_345' === $mapped['id'] );
		assert( '' === $mapped['item_group_id'] ); // Simple products have empty item_group_id.

		// Changing product title does NOT change stable feed ID.
		$product->set_name( 'Brand New Title 2026' );
		$mapped2 = $mapper->map( $product );
		assert( $mapped['id'] === $mapped2['id'] );

		return true;
	}

	private function test_feed_validator_item(): bool {
		// Valid item.
		$valid_item = [
			'id'           => 'wc_prod_101',
			'title'        => 'High Quality Cotton T-Shirt',
			'description'  => 'Comfortable modern everyday t-shirt',
			'link'         => 'https://example.com/product/t-shirt/',
			'image_link'   => 'https://example.com/uploads/tshirt.jpg',
			'price'        => '24.99 USD',
			'availability' => 'in_stock',
		];
		$errors = FeedValidator::validate_item( $valid_item );
		assert( empty( $errors ) );

		// Missing required field (link).
		$invalid_item = $valid_item;
		$invalid_item['link'] = '';
		$errors = FeedValidator::validate_item( $invalid_item );
		assert( ! empty( $errors ) );
		assert( str_contains( $errors[0], 'Missing required field "link"' ) );

		// Invalid price format.
		$bad_price_item = $valid_item;
		$bad_price_item['price'] = '24.99'; // Missing currency code
		$errors = FeedValidator::validate_item( $bad_price_item );
		assert( ! empty( $errors ) );
		assert( str_contains( $errors[0], 'Invalid price format' ) );

		// Invalid availability enum.
		$bad_avail_item = $valid_item;
		$bad_avail_item['availability'] = 'available'; // Not in standard enum
		$errors = FeedValidator::validate_item( $bad_avail_item );
		assert( ! empty( $errors ) );
		assert( str_contains( $errors[0], 'Invalid availability value' ) );

		return true;
	}

	private function test_csv_streaming_writer(): bool {
		$tmp_file = sys_get_temp_dir() . '/test_feed_' . uniqid() . '.csv';
		$writer   = new CsvWriter();

		assert( $writer->open( $tmp_file ) );
		assert( $writer->write_header( [ 'id', 'title', 'price' ] ) );
		assert( $writer->write_row( [ 'id' => '1', 'title' => 'Product with, comma & "quotes"', 'price' => '10.00 USD' ] ) );
		$writer->close();

		assert( file_exists( $tmp_file ) );
		$content = file_get_contents( $tmp_file );
		assert( str_contains( $content, 'id,title,price' ) );
		assert( str_contains( $content, '"Product with, comma & ""quotes"""' ) );

		@unlink( $tmp_file );
		return true;
	}

	private function test_tsv_streaming_writer(): bool {
		$tmp_file = sys_get_temp_dir() . '/test_feed_' . uniqid() . '.tsv';
		$writer   = new TsvWriter();

		assert( $writer->open( $tmp_file ) );
		assert( $writer->write_header( [ 'id', 'title', 'price' ] ) );
		// Value containing tab and newlines should be sanitized to spaces.
		assert( $writer->write_row( [ 'id' => '2', 'title' => "Title\twith\ntab", 'price' => '15.00 USD' ] ) );
		$writer->close();

		assert( file_exists( $tmp_file ) );
		$content = file_get_contents( $tmp_file );
		assert( str_contains( $content, "id\ttitle\tprice\n" ) );
		assert( str_contains( $content, "2\tTitle with tab\t15.00 USD\n" ) );

		@unlink( $tmp_file );
		return true;
	}

	private function test_feed_token_security(): bool {
		\WP_Mock_State::reset();

		$token = FeedToken::get_token();
		assert( 64 === strlen( $token ) );
		assert( FeedToken::validate_token( $token ) );
		assert( ! FeedToken::validate_token( 'incorrect-token' ) );

		// Rotation invalidates previous token.
		$new_token = FeedToken::rotate_token();
		assert( $new_token !== $token );
		assert( FeedToken::validate_token( $new_token ) );
		assert( ! FeedToken::validate_token( $token ) ); // Old token is invalid

		return true;
	}
}

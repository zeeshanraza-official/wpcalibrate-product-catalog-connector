<?php
/**
 * Test Bootstrap & WordPress/WooCommerce Environment Mock
 *
 * @package WPCalibrate\ProductCatalogConnector\Tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}
if ( ! defined( 'WPCAL_PCC_VERSION' ) ) {
	define( 'WPCAL_PCC_VERSION', '1.0.1' );
}
if ( ! defined( 'WPCAL_PCC_PATH' ) ) {
	define( 'WPCAL_PCC_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'WPCAL_PCC_URL' ) ) {
	define( 'WPCAL_PCC_URL', 'https://example.com/wp-content/plugins/wpcalibrate-product-catalog-connector/' );
}
if ( ! defined( 'WPCAL_PCC_BASENAME' ) ) {
	define( 'WPCAL_PCC_BASENAME', 'wpcalibrate-product-catalog-connector/wpcalibrate-product-catalog-connector.php' );
}
if ( ! defined( 'WPCAL_PCC_TEXT_DOMAIN' ) ) {
	define( 'WPCAL_PCC_TEXT_DOMAIN', 'wpcalibrate-product-catalog-connector' );
}
if ( ! defined( 'WPCAL_PCC_MIN_PHP_VERSION' ) ) {
	define( 'WPCAL_PCC_MIN_PHP_VERSION', '8.2' );
}
if ( ! defined( 'WPCAL_PCC_MIN_WC_VERSION' ) ) {
	define( 'WPCAL_PCC_MIN_WC_VERSION', '8.5' );
}

// In-memory WordPress options, transients, and hooks storage.
class WP_Mock_State {
	public static array $options = [];
	public static array $transients = [];
	public static array $actions = [];
	public static array $filters = [];
	public static array $current_user_caps = [ 'manage_woocommerce' => true, 'manage_options' => true ];
	public static mixed $mock_http_response = null;

	public static function reset(): void {
		self::$options = [];
		self::$transients = [];
		self::$actions = [];
		self::$filters = [];
		self::$current_user_caps = [ 'manage_woocommerce' => true, 'manage_options' => true ];
		self::$mock_http_response = null;
	}
}

// Mock WordPress plugin functions.
if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return dirname( $file ) . '/';
	}
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( string $file ): string {
		return 'https://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}
if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( string $file, callable $callback ): void {}
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( string $file, callable $callback ): void {}
}
if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( mixed $queries = '', bool $execute = true ): array {
		return [];
	}
}

// Mock WordPress functions.
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		return WP_Mock_State::$options[ $option ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
		WP_Mock_State::$options[ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		unset( WP_Mock_State::$options[ $option ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $transient ): mixed {
		if ( isset( WP_Mock_State::$transients[ $transient ] ) ) {
			$item = WP_Mock_State::$transients[ $transient ];
			if ( $item['expires'] > 0 && time() > $item['expires'] ) {
				unset( WP_Mock_State::$transients[ $transient ] );
				return false;
			}
			return $item['value'];
		}
		return false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		WP_Mock_State::$transients[ $transient ] = [
			'value'   => $value,
			'expires' => $expiration > 0 ? time() + $expiration : 0,
		];
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $transient ): bool {
		unset( WP_Mock_State::$transients[ $transient ] );
		return true;
	}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
		return bin2hex( random_bytes( (int) ceil( $length / 2 ) ) );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $tag, mixed ...$args ): void {
		WP_Mock_State::$actions[ $tag ][] = $args;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}
if ( ! function_exists( 'sanitize_user' ) ) {
	function sanitize_user( string $username, bool $strict = false ): string {
		return trim( strip_tags( $username ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $maybeint ): int {
		return abs( (int) $maybeint );
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( mixed $args, array $defaults = [] ): array {
		if ( is_object( $args ) ) {
			$r = get_object_vars( $args );
		} elseif ( is_array( $args ) ) {
			$r = &$args;
		} else {
			$r = [];
		}
		return array_merge( $defaults, $r );
	}
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		$dir = sys_get_temp_dir() . '/wpcal_pcc_uploads';
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0777, true );
		}
		return [
			'basedir' => $dir,
			'baseurl' => 'https://example.com/wp-content/uploads',
		];
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $string ): string {
		return rtrim( $string, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		return is_dir( $target ) || @mkdir( $target, 0777, true );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string {
		$query = http_build_query( $args );
		return str_contains( $url, '?' ) ? $url . '&' . $query : $url . '?' . $query;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.com' . $path;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.com/wp-admin/' . $path;
	}
}
if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url(): string {
		return 'https://example.com';
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 1;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return ! empty( WP_Mock_State::$current_user_caps[ $capability ] );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string {
		return strip_tags( $text );
	}
}
if ( ! function_exists( 'strip_shortcodes' ) ) {
	function strip_shortcodes( string $content ): string {
		return (string) preg_replace( '/\[\/?(?:[a-zA-Z0-9_\-]+)[^\]]*\]/', '', $content );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string {
		return date( 'Y-m-d H:i:s' );
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( string $format, int $timestamp ): string {
		return date( $format, $timestamp );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data ): string {
		return (string) json_encode( $data );
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = [] ): int|false {
		return WP_Mock_State::$actions[ 'cron_' . $hook ] ?? false;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = [] ): bool {
		WP_Mock_State::$actions[ 'cron_' . $hook ] = $timestamp;
		return true;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = [] ): bool {
		WP_Mock_State::$actions[ 'cron_' . $hook ] = $timestamp;
		return true;
	}
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook, array $args = [] ): int {
		unset( WP_Mock_State::$actions[ 'cron_' . $hook ] );
		return 1;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return $url;
	}
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( int $post_id, string $taxonomy = 'post_tag', array $args = [] ): array {
		return [];
	}
}
if ( ! function_exists( 'get_ancestors' ) ) {
	function get_ancestors( int $object_id, string $object_type = '', string $resource_type = '' ): array {
		return [];
	}
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term( int $term_id, string $taxonomy = '' ): ?object {
		return null;
	}
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( int $attachment_id ): string|false {
		return 'https://example.com/wp-content/uploads/2026/01/image-' . $attachment_id . '.jpg';
	}
}
if ( ! function_exists( 'wp_count_posts' ) ) {
	function wp_count_posts( string $type = 'post' ): object {
		$count = count( $GLOBALS['mock_products'] ?? [] );
		return (object) [ 'publish' => $count > 0 ? $count : 10 ];
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $post_id ): ?object {
		return (object) [ 'ID' => $post_id, 'post_password' => '' ];
	}
}
if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( int $post_id ): int|false {
		return false;
	}
}
if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( int $post_id ): int|false {
		return false;
	}
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( int $post_id = 0 ): string|false {
		return 'product';
	}
}

// Mock WP_Error.
class WP_Error {
	private array $errors = [];
	private array $error_data = [];

	public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
		if ( ! empty( $code ) ) {
			$this->errors[ $code ][] = $message;
			if ( ! empty( $data ) ) {
				$this->error_data[ $code ] = $data;
			}
		}
	}

	public function get_error_message( string $code = '' ): string {
		if ( empty( $code ) ) {
			$keys = array_keys( $this->errors );
			$code = $keys[0] ?? '';
		}
		return $this->errors[ $code ][0] ?? '';
	}

	public function get_error_code(): string {
		$keys = array_keys( $this->errors );
		return $keys[0] ?? '';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

// Mock HTTP remote functions.
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( string $url, array $args = [] ): array|WP_Error {
		if ( WP_Mock_State::$mock_http_response instanceof WP_Error ) {
			return WP_Mock_State::$mock_http_response;
		}
		return WP_Mock_State::$mock_http_response ?? [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'headers'  => [],
			'body'     => json_encode( [ 'status' => 'success' ] ),
		];
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = [] ): array|WP_Error {
		return wp_remote_request( $url, $args );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( array|WP_Error $response ): int {
		if ( is_wp_error( $response ) ) {
			return 0;
		}
		return (int) ( $response['response']['code'] ?? 200 );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( array|WP_Error $response ): string {
		if ( is_wp_error( $response ) ) {
			return '';
		}
		return (string) ( $response['body'] ?? '' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( array|WP_Error $response, string $header ): string {
		if ( is_wp_error( $response ) ) {
			return '';
		}
		return (string) ( $response['headers'][ $header ] ?? '' );
	}
}

// Mock global $wpdb.
class Mock_WPDB {
	public string $prefix = 'wp_';
	public int $insert_id = 1;
	public array $tables = [];

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function insert( string $table, array $data, array $format = [] ): int {
		$this->insert_id++;
		$data['id'] = $this->insert_id;
		$this->tables[ $table ][] = $data;
		return 1;
	}

	public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
		if ( isset( $this->tables[ $table ] ) ) {
			foreach ( $this->tables[ $table ] as &$row ) {
				$match = true;
				foreach ( $where as $wk => $wv ) {
					if ( ! isset( $row[ $wk ] ) || (string) $row[ $wk ] !== (string) $wv ) {
						$match = false;
						break;
					}
				}
				if ( $match ) {
					foreach ( $data as $dk => $dv ) {
						$row[ $dk ] = $dv;
					}
				}
			}
		}
		return 1;
	}

	public function query( string $query ): int {
		// Mock increment counter queries on wpcal_pcc_sync_runs.
		if ( str_contains( $query, 'UPDATE' ) && str_contains( $query, 'items_exported' ) ) {
			if ( preg_match( "/WHERE run_id = '([^']+)'/", $query, $m ) ) {
				$run_id = $m[1];
				$table  = $this->prefix . 'wpcal_pcc_sync_runs';
				if ( isset( $this->tables[ $table ] ) ) {
					foreach ( $this->tables[ $table ] as &$row ) {
						if ( ( $row['run_id'] ?? '' ) === $run_id ) {
							$row['items_scanned']  = ( $row['items_scanned'] ?? 0 ) + 2;
							$row['items_exported'] = ( $row['items_exported'] ?? 0 ) + 3;
						}
					}
				}
			}
		}
		return 1;
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$val = is_numeric( $arg ) ? $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = (string) preg_replace( '/%[sd]/', (string) $val, $query, 1 );
		}
		return $query;
	}

	public function get_row( string $query, string $output = 'OBJECT' ): ?array {
		if ( str_contains( $query, 'wpcal_pcc_sync_runs' ) ) {
			$table = $this->prefix . 'wpcal_pcc_sync_runs';
			if ( ! empty( $this->tables[ $table ] ) ) {
				$rows = $this->tables[ $table ];
				if ( str_contains( $query, "status = 'completed'" ) ) {
					$filtered = array_filter( $rows, static fn( $r ) => ( $r['status'] ?? '' ) === 'completed' );
					return ! empty( $filtered ) ? end( $filtered ) : null;
				}
				return end( $rows );
			}
		}
		return null;
	}

	public function get_results( string $query, string $output = 'OBJECT' ): array {
		if ( str_contains( $query, 'wpcal_pcc_sync_runs' ) ) {
			$table = $this->prefix . 'wpcal_pcc_sync_runs';
			return $this->tables[ $table ] ?? [];
		}
		if ( str_contains( $query, 'wpcal_pcc_item_issues' ) ) {
			$table = $this->prefix . 'wpcal_pcc_item_issues';
			return $this->tables[ $table ] ?? [];
		}
		return [];
	}

	public function get_var( string $query ): ?string {
		return null;
	}
}

$GLOBALS['wpdb'] = new Mock_WPDB();

// Mock WooCommerce classes & functions.
class WooCommerce {}

class WC_Product {
	protected int $id;
	protected string $name = 'Test Product';
	protected string $status = 'publish';
	protected string $type = 'simple';
	protected string $visibility = 'visible';
	protected string $sku = 'SKU-1001';
	protected string $regular_price = '29.99';
	protected string $sale_price = '';
	protected string $stock_status = 'instock';
	protected bool $is_in_stock = true;
	protected int $image_id = 42;
	protected array $category_ids = [ 10 ];
	protected string $short_description = 'Brief product overview';
	protected string $description = 'Complete product detailed description';
	protected bool $is_virtual = false;
	protected bool $is_downloadable = false;

	public function __construct( int $id = 101 ) {
		$this->id = $id;
	}

	public function get_id(): int { return $this->id; }
	public function get_name(): string { return $this->name; }
	public function set_name( string $name ): void { $this->name = $name; }
	public function get_status(): string { return $this->status; }
	public function set_status( string $status ): void { $this->status = $status; }
	public function get_type(): string { return $this->type; }
	public function set_type( string $type ): void { $this->type = $type; }
	public function is_type( string $type ): bool { return $this->type === $type; }
	public function get_catalog_visibility(): string { return $this->visibility; }
	public function set_catalog_visibility( string $v ): void { $this->visibility = $v; }
	public function get_sku(): string { return $this->sku; }
	public function set_sku( string $sku ): void { $this->sku = $sku; }
	public function get_regular_price(): string { return $this->regular_price; }
	public function set_regular_price( string $p ): void { $this->regular_price = $p; }
	public function get_sale_price(): string { return $this->sale_price; }
	public function set_sale_price( string $p ): void { $this->sale_price = $p; }
	public function get_price(): string { return ! empty( $this->sale_price ) ? $this->sale_price : $this->regular_price; }
	public function is_on_sale(): bool { return ! empty( $this->sale_price ); }
	public function get_stock_status(): string { return $this->stock_status; }
	public function set_stock_status( string $s ): void { $this->stock_status = $s; $this->is_in_stock = ( 'instock' === $s ); }
	public function is_in_stock(): bool { return $this->is_in_stock; }
	public function get_image_id(): int { return $this->image_id; }
	public function set_image_id( int $id ): void { $this->image_id = $id; }
	public function get_gallery_image_ids(): array { return [ 43, 44 ]; }
	public function get_category_ids(): array { return $this->category_ids; }
	public function set_category_ids( array $ids ): void { $this->category_ids = $ids; }
	public function get_permalink(): string { return 'https://example.com/product/test-' . $this->id . '/'; }
	public function get_short_description(): string { return $this->short_description; }
	public function get_description(): string { return $this->description; }
	public function is_virtual(): bool { return $this->is_virtual; }
	public function set_virtual( bool $v ): void { $this->is_virtual = $v; }
	public function is_downloadable(): bool { return $this->is_downloadable; }
	public function get_attribute( string $attr ): string { return 'pa_brand' === $attr || 'brand' === $attr ? 'Acme Co' : ''; }
	public function get_meta( string $key, bool $single = true ): mixed { return ''; }
}

class WC_Product_Variable extends WC_Product {
	protected array $children = [ 201, 202 ];
	protected string $type = 'variable';

	public function get_children(): array { return $this->children; }
	public function set_children( array $children ): void { $this->children = $children; }
}

class WC_Product_Variation extends WC_Product {
	protected int $parent_id = 101;
	protected string $type = 'variation';

	public function __construct( int $id = 201, int $parent_id = 101 ) {
		parent::__construct( $id );
		$this->parent_id = $parent_id;
		$this->name = 'Test Product - Blue, L';
		$this->sku = 'SKU-VAR-' . $id;
	}

	public function get_parent_id(): int { return $this->parent_id; }
}

$GLOBALS['mock_products'] = [];

if ( ! function_exists( 'wc_get_products' ) ) {
	function wc_get_products( array $args = [] ): array {
		return $GLOBALS['mock_products'] ?? [];
	}
}
if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( mixed $the_product = false ): ?WC_Product {
		if ( $the_product instanceof WC_Product ) {
			return $the_product;
		}
		if ( 201 === (int) $the_product ) {
			return new WC_Product_Variation( 201, 101 );
		}
		if ( 202 === (int) $the_product ) {
			return new WC_Product_Variation( 202, 101 );
		}
		return new WC_Product( (int) $the_product );
	}
}
if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency(): string {
		return 'USD';
	}
}
if ( ! function_exists( 'wc_get_base_location' ) ) {
	function wc_get_base_location(): array {
		return [ 'country' => 'US', 'state' => 'CA' ];
	}
}
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale(): string {
		return 'en_US';
	}
}

// Autoloader for plugin classes.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'WPCalibrate\\ProductCatalogConnector\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$file = WPCAL_PCC_PATH . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

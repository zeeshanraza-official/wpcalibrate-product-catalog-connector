<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector;

use WPCalibrate\ProductCatalogConnector\Admin\Assets;
use WPCalibrate\ProductCatalogConnector\Admin\Menu;
use WPCalibrate\ProductCatalogConnector\Admin\SettingsPage;
use WPCalibrate\ProductCatalogConnector\Compatibility\WooCommerce;
use WPCalibrate\ProductCatalogConnector\Feed\FeedEndpoint;
use WPCalibrate\ProductCatalogConnector\Sync\BatchProcessor;
use WPCalibrate\ProductCatalogConnector\Sync\Scheduler;
use WPCalibrate\ProductCatalogConnector\Sync\SyncManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin orchestration singleton.
 */
final class Plugin {
	private static ?Plugin $instance = null;

	private SettingsPage $settings_page;
	private Menu $menu;
	private Assets $assets;
	private FeedEndpoint $feed_endpoint;
	private BatchProcessor $batch_processor;
	private Scheduler $scheduler;
	private SyncManager $sync_manager;

	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->settings_page   = new SettingsPage();
		$this->menu            = new Menu( $this->settings_page );
		$this->assets          = new Assets();
		$this->feed_endpoint   = new FeedEndpoint();
		$this->batch_processor = new BatchProcessor();
		$this->scheduler       = new Scheduler();
		$this->sync_manager    = new SyncManager();
	}

	/**
	 * Initialize plugin components and hooks.
	 */
	public function init(): void {
		// 1. Localization.
		add_action( 'init', [ $this, 'load_textdomain' ] );

		// 2. Admin UI and settings (always accessible).
		$this->menu->register();
		$this->assets->register();
		$this->settings_page->register();

		// 3. Check WooCommerce dependency.
		if ( ! WooCommerce::is_active() ) {
			add_action( 'admin_notices', [ $this, 'render_missing_woocommerce_notice' ] );
			return;
		}

		// 4. WooCommerce catalog generation and synchronization services.
		$this->feed_endpoint->register();
		$this->batch_processor->register();
		$this->scheduler->register();
		$this->sync_manager->register();
	}

	/**
	 * Load plugin textdomain for translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			WPCAL_PCC_TEXT_DOMAIN,
			false,
			dirname( WPCAL_PCC_BASENAME ) . '/languages'
		);
	}

	/**
	 * Notice when WooCommerce is inactive.
	 */
	public function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s:</strong> %s</p></div>',
			esc_html__( 'WPCalibrate Product Catalog Connector', 'wpcalibrate-product-catalog-connector' ),
			esc_html__( 'WooCommerce is not active. Catalog generation and synchronization are paused until WooCommerce is activated.', 'wpcalibrate-product-catalog-connector' )
		);
	}
}

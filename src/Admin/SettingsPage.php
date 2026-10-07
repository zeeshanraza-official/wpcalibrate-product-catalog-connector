<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Admin;

use WPCalibrate\ProductCatalogConnector\Catalog\ProductQuery;
use WPCalibrate\ProductCatalogConnector\Compatibility\WooCommerce;
use WPCalibrate\ProductCatalogConnector\Diagnostics\DiagnosticsPage;
use WPCalibrate\ProductCatalogConnector\Diagnostics\SyncRunRepository;
use WPCalibrate\ProductCatalogConnector\Feed\FeedStorage;
use WPCalibrate\ProductCatalogConnector\Feed\FeedToken;
use WPCalibrate\ProductCatalogConnector\License\LicenseManager;
use WPCalibrate\ProductCatalogConnector\Pinterest\FeedService;
use WPCalibrate\ProductCatalogConnector\Pinterest\OAuthService;
use WPCalibrate\ProductCatalogConnector\Pinterest\TokenStore;
use WPCalibrate\ProductCatalogConnector\Settings\Settings;
use WPCalibrate\ProductCatalogConnector\Support\SupportPage;
use WPCalibrate\ProductCatalogConnector\Sync\BatchProcessor;
use WPCalibrate\ProductCatalogConnector\Sync\Lock;
use WPCalibrate\ProductCatalogConnector\Sync\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the complete administration interface and coordinates actions across tabs.
 */
class SettingsPage {
	private LicenseManager $license_manager;
	private DiagnosticsPage $diagnostics_page;
	private SupportPage $support_page;
	private OAuthService $oauth_service;
	private TokenStore $token_store;

	public function __construct() {
		$this->license_manager  = new LicenseManager();
		$this->diagnostics_page = new DiagnosticsPage();
		$this->support_page     = new SupportPage();
		$this->oauth_service    = new OAuthService();
		$this->token_store      = new TokenStore();
	}

	/**
	 * Register hooks for form processing and OAuth callbacks.
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'process_actions' ] );
	}

	/**
	 * Process form submissions, action buttons, and OAuth returns.
	 */
	public function process_actions(): void {
		// Verify page context.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || Menu::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		// Handle OAuth callback from Pinterest.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['code'], $_GET['state'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unauthorized: You lack permissions to configure Pinterest integration.', 'wpcalibrate-product-catalog-connector' ) );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$code  = sanitize_text_field( (string) $_GET['code'] );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$state = sanitize_text_field( (string) $_GET['state'] );

			if ( ! $this->oauth_service->verify_state( $state ) ) {
				wp_safe_redirect( add_query_arg( [ 'tab' => 'pinterest', 'error' => 'invalid_state' ], admin_url( 'admin.php?page=' . Menu::PAGE_SLUG ) ) );
				exit;
			}

			$exchange = $this->oauth_service->exchange_code( $code );
			if ( is_wp_error( $exchange ) ) {
				wp_safe_redirect( add_query_arg( [ 'tab' => 'pinterest', 'error' => urlencode( $exchange->get_error_message() ) ], admin_url( 'admin.php?page=' . Menu::PAGE_SLUG ) ) );
				exit;
			}

			wp_safe_redirect( add_query_arg( [ 'tab' => 'pinterest', 'connected' => '1' ], admin_url( 'admin.php?page=' . Menu::PAGE_SLUG ) ) );
			exit;
		}

		// Process POST actions.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['wpcal_pcc_action'] ) ) {
			return;
		}

		$action = sanitize_key( (string) $_POST['wpcal_pcc_action'] );

		// 1. Save Settings.
		if ( 'save_settings' === $action ) {
			check_admin_referer( 'wpcal_pcc_save_settings_nonce', 'wpcal_pcc_nonce' );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Unauthorized to manage WooCommerce settings.', 'wpcalibrate-product-catalog-connector' ) );
			}

			$tab = isset( $_POST['current_tab'] ) ? sanitize_key( (string) $_POST['current_tab'] ) : 'overview';
			Settings::update( (array) ( $_POST['wpcal_pcc'] ?? [] ) );
			Scheduler::reschedule();

			wp_safe_redirect( add_query_arg( [ 'tab' => $tab, 'saved' => '1' ], admin_url( 'admin.php?page=' . Menu::PAGE_SLUG ) ) );
			exit;
		}

		// 2. Trigger Manual Sync.
		if ( 'trigger_sync' === $action ) {
			check_admin_referer( 'wpcal_pcc_sync_nonce', 'wpcal_pcc_nonce' );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Unauthorized.', 'wpcalibrate-product-catalog-connector' ) );
			}

			$processor = new BatchProcessor();
			$res = $processor->start_sync( 'manual' );

			$query_args = [ 'tab' => 'sync' ];
			if ( false === $res ) {
				$query_args['sync_error'] = 'lock_held';
			} else {
				$query_args['sync_started'] = '1';
			}

			wp_safe_redirect( add_query_arg( $query_args, admin_url( 'admin.php?page=' . Menu::PAGE_SLUG ) ) );
			exit;
		}

		// 3. Rotate Feed Token.
		if ( 'rotate_token' === $action ) {
			check_admin_referer( 'wpcal_pcc_token_nonce', 'wpcal_pcc_nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unauthorized.', 'wpcalibrate-product-catalog-connector' ) );
			}

			FeedToken::rotate_token();

			wp_safe_redirect( add_query_arg( [ 'tab' => 'overview', 'token_rotated' => '1' ], admin_url( 'admin.php?page=' . Menu::PAGE_SLUG ) ) );
			exit;
		}

		// 4. Force Clear Lock.
		if ( 'clear_lock' === $action ) {
			check_admin_referer( 'wpcal_pcc_lock_nonce', 'wpcal_pcc_nonce' );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Unauthorized.', 'wpcalibrate-product-catalog-connector' ) );
			}

			Lock::force_release();

			wp_safe_redirect( add_query_arg( [ 'tab' => 'sync', 'lock_cleared' => '1' ], admin_url( 'admin.php?page=' . Menu::PAGE_SLUG ) ) );
			exit;
		}

		// 5. Pinterest Disconnect.
		if ( 'pinterest_disconnect' === $action ) {
			check_admin_referer( 'wpcal_pcc_pinterest_disconnect_nonce', 'wpcal_pcc_nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unauthorized.', 'wpcalibrate-product-catalog-connector' ) );
			}

			$this->oauth_service->disconnect();

			wp_safe_redirect( add_query_arg( [ 'tab' => 'pinterest', 'disconnected' => '1' ], admin_url( 'admin.php?page=' . Menu::PAGE_SLUG ) ) );
			exit;
		}

		// 6. Pinterest Register Remote Feed.
		if ( 'pinterest_register_feed' === $action ) {
			check_admin_referer( 'wpcal_pcc_register_feed_nonce', 'wpcal_pcc_nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unauthorized.', 'wpcalibrate-product-catalog-connector' ) );
			}

			$feed_name = sanitize_text_field( (string) ( $_POST['feed_name'] ?? get_bloginfo( 'name' ) . ' Catalog' ) );
			$feed_service = new FeedService();
			$res = $feed_service->register_feed( $feed_name );

			if ( is_wp_error( $res ) ) {
				wp_safe_redirect( add_query_arg( [ 'tab' => 'pinterest', 'error' => urlencode( $res->get_error_message() ) ], admin_url( 'admin.php?page=' . Menu::PAGE_SLUG ) ) );
				exit;
			}

			wp_safe_redirect( add_query_arg( [ 'tab' => 'pinterest', 'feed_registered' => '1' ], admin_url( 'admin.php?page=' . Menu::PAGE_SLUG ) ) );
			exit;
		}

		// 7. License Key Action.
		if ( 'save_license' === $action ) {
			check_admin_referer( 'wpcal_pcc_license_nonce', 'wpcal_pcc_nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unauthorized.', 'wpcalibrate-product-catalog-connector' ) );
			}

			if ( isset( $_POST['deactivate_license'] ) ) {
				$this->license_manager->deactivate();
				wp_safe_redirect( add_query_arg( [ 'tab' => 'license', 'license_cleared' => '1' ], admin_url( 'admin.php?page=' . Menu::PAGE_SLUG ) ) );
				exit;
			}

			$key = sanitize_text_field( (string) ( $_POST['license_key'] ?? '' ) );
			$this->license_manager->save_and_activate( $key );

			wp_safe_redirect( add_query_arg( [ 'tab' => 'license', 'license_saved' => '1' ], admin_url( 'admin.php?page=' . Menu::PAGE_SLUG ) ) );
			exit;
		}
	}

	/**
	 * Render the main plugin administration page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpcalibrate-product-catalog-connector' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'overview';
		$allowed_tabs = [
			'overview'        => __( 'Overview', 'wpcalibrate-product-catalog-connector' ),
			'catalog'         => __( 'Catalog', 'wpcalibrate-product-catalog-connector' ),
			'product_rules'   => __( 'Product Rules', 'wpcalibrate-product-catalog-connector' ),
			'field_mapping'   => __( 'Field Mapping', 'wpcalibrate-product-catalog-connector' ),
			'pinterest'       => __( 'Pinterest', 'wpcalibrate-product-catalog-connector' ),
			'sync'            => __( 'Sync', 'wpcalibrate-product-catalog-connector' ),
			'diagnostics'     => __( 'Diagnostics', 'wpcalibrate-product-catalog-connector' ),
			'license'         => __( 'License', 'wpcalibrate-product-catalog-connector' ),
			'support'         => __( 'Support', 'wpcalibrate-product-catalog-connector' ),
		];

		if ( ! array_key_exists( $current_tab, $allowed_tabs ) ) {
			$current_tab = 'overview';
		}

		$settings = Settings::get_all();
		?>
		<div class="wrap wpcal-pcc-wrap">
			<!-- Header -->
			<div class="wpcal-pcc-header">
				<div class="wpcal-pcc-header-branding">
					<img src="<?php echo esc_url( WPCAL_PCC_URL . 'assets/branding/icon-white.png' ); ?>" alt="WPCalibrate" class="wpcal-pcc-logo" />
					<div class="wpcal-pcc-header-title">
						<h1><?php esc_html_e( 'Product Catalog Connector', 'wpcalibrate-product-catalog-connector' ); ?></h1>
						<p><?php esc_html_e( 'Retail Catalog Synchronization for Pinterest & WooCommerce', 'wpcalibrate-product-catalog-connector' ); ?></p>
					</div>
				</div>
				<div class="wpcal-pcc-header-meta">
					<span class="wpcal-pcc-badge wpcal-pcc-badge-v1">v<?php echo esc_html( WPCAL_PCC_VERSION ); ?></span>
					<?php if ( $this->token_store->is_connected() ) : ?>
						<span class="wpcal-pcc-badge wpcal-pcc-badge-connected">● <?php esc_html_e( 'Pinterest API Active', 'wpcalibrate-product-catalog-connector' ); ?></span>
					<?php else : ?>
						<span class="wpcal-pcc-badge wpcal-pcc-badge-disconnected">○ <?php esc_html_e( 'Feed Only Mode', 'wpcalibrate-product-catalog-connector' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<!-- Notices -->
			<?php $this->render_notices(); ?>

			<!-- Tabs Navigation -->
			<nav class="wpcal-pcc-nav-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings Tabs', 'wpcalibrate-product-catalog-connector' ); ?>">
				<?php foreach ( $allowed_tabs as $tab_key => $tab_label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_SLUG . '&tab=' . $tab_key ) ); ?>"
						class="wpcal-pcc-nav-tab <?php echo $current_tab === $tab_key ? 'active' : ''; ?>"
						role="tab"
						aria-selected="<?php echo $current_tab === $tab_key ? 'true' : 'false'; ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<!-- Tab Contents -->
			<main class="wpcal-pcc-content" role="tabpanel">
				<?php
				match ( $current_tab ) {
					'overview'      => $this->render_overview_tab( $settings ),
					'catalog'       => $this->render_catalog_tab( $settings ),
					'product_rules' => $this->render_product_rules_tab( $settings ),
					'field_mapping' => $this->render_field_mapping_tab( $settings ),
					'pinterest'     => $this->render_pinterest_tab( $settings ),
					'sync'          => $this->render_sync_tab( $settings ),
					'diagnostics'   => $this->diagnostics_page->render(),
					'license'       => $this->render_license_tab(),
					'support'       => $this->support_page->render(),
				};
				?>
			</main>
		</div>
		<?php
	}

	/**
	 * Render system feedback notices.
	 */
	private function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings successfully saved.', 'wpcalibrate-product-catalog-connector' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['sync_started'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Catalog feed regeneration successfully started in the background.', 'wpcalibrate-product-catalog-connector' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['sync_error'] ) && 'lock_held' === $_GET['sync_error'] ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Catalog generation is already in progress or locked by an active process.', 'wpcalibrate-product-catalog-connector' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['token_rotated'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Feed token rotated. The previous feed URL has been immediately invalidated.', 'wpcalibrate-product-catalog-connector' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['lock_cleared'] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Generation lock cleared.', 'wpcalibrate-product-catalog-connector' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['connected'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Connected to Pinterest API successfully.', 'wpcalibrate-product-catalog-connector' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['disconnected'] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Pinterest account disconnected.', 'wpcalibrate-product-catalog-connector' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['feed_registered'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Hosted catalog feed registered with Pinterest.', 'wpcalibrate-product-catalog-connector' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['error'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) ) . '</p></div>';
		}

		if ( ! WooCommerce::is_active() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WooCommerce is required for catalog generation. Please install and activate WooCommerce.', 'wpcalibrate-product-catalog-connector' ) . '</p></div>';
		}
	}

	/**
	 * Tab 1: Overview.
	 *
	 * @param array<string, mixed> $settings
	 */
	private function render_overview_tab( array $settings ): void {
		$format     = (string) ( $settings['feed_format'] ?? 'csv' );
		$feed_url   = FeedStorage::get_feed_url( $format );
		$feed_info  = FeedStorage::get_feed_info( $format );
		$latest_run = SyncRunRepository::get_latest_completed_run();
		$next_sched = Scheduler::get_next_scheduled_time();

		$query = new ProductQuery();
		$total_candidates = $query->get_total_candidates();
		?>
		<div class="wpcal-pcc-grid">
			<div class="wpcal-pcc-stat-box">
				<div class="wpcal-pcc-stat-label"><?php esc_html_e( 'Integration Mode', 'wpcalibrate-product-catalog-connector' ); ?></div>
				<div class="wpcal-pcc-stat-value" style="font-size: 20px;">
					<?php echo 'api_feed' === $settings['mode'] ? esc_html__( 'Pinterest API + Feed', 'wpcalibrate-product-catalog-connector' ) : esc_html__( 'Hosted Feed Only', 'wpcalibrate-product-catalog-connector' ); ?>
				</div>
				<div class="wpcal-pcc-stat-desc"><?php esc_html_e( 'Primary retail feed layer active', 'wpcalibrate-product-catalog-connector' ); ?></div>
			</div>

			<div class="wpcal-pcc-stat-box">
				<div class="wpcal-pcc-stat-label"><?php esc_html_e( 'Published Products', 'wpcalibrate-product-catalog-connector' ); ?></div>
				<div class="wpcal-pcc-stat-value"><?php echo esc_html( (string) $total_candidates ); ?></div>
				<div class="wpcal-pcc-stat-desc"><?php esc_html_e( 'Candidate catalog items in WooCommerce', 'wpcalibrate-product-catalog-connector' ); ?></div>
			</div>

			<div class="wpcal-pcc-stat-box">
				<div class="wpcal-pcc-stat-label"><?php esc_html_e( 'Last Successful Sync', 'wpcalibrate-product-catalog-connector' ); ?></div>
				<div class="wpcal-pcc-stat-value" style="font-size: 18px;">
					<?php echo $latest_run ? esc_html( (string) $latest_run['finished_at'] ) : esc_html__( 'Never', 'wpcalibrate-product-catalog-connector' ); ?>
				</div>
				<div class="wpcal-pcc-stat-desc">
					<?php
					if ( $latest_run ) {
						printf(
							/* translators: %d: items count */
							esc_html__( '%d items exported', 'wpcalibrate-product-catalog-connector' ),
							(int) $latest_run['items_exported']
						);
					} else {
						esc_html_e( 'Pending initial sync', 'wpcalibrate-product-catalog-connector' );
					}
					?>
				</div>
			</div>

			<div class="wpcal-pcc-stat-box">
				<div class="wpcal-pcc-stat-label"><?php esc_html_e( 'Next Scheduled Job', 'wpcalibrate-product-catalog-connector' ); ?></div>
				<div class="wpcal-pcc-stat-value" style="font-size: 18px;">
					<?php echo $next_sched ? esc_html( wp_date( 'Y-m-d H:i', $next_sched ) ?: '' ) : esc_html__( 'Manual Only', 'wpcalibrate-product-catalog-connector' ); ?>
				</div>
				<div class="wpcal-pcc-stat-desc">
					<?php printf( /* translators: %s: schedule */ esc_html__( 'Schedule: %s', 'wpcalibrate-product-catalog-connector' ), esc_html( strtoupper( (string) $settings['schedule'] ) ) ); ?>
				</div>
			</div>
		</div>

		<!-- Feed Access Card -->
		<div class="wpcal-pcc-card">
			<h2 class="wpcal-pcc-card-title">
				<span><?php esc_html_e( 'Hosted Pinterest Retail Feed URL', 'wpcalibrate-product-catalog-connector' ); ?></span>
				<span class="wpcal-pcc-badge wpcal-pcc-badge-v1"><?php echo esc_html( strtoupper( $format ) ); ?></span>
			</h2>
			<p>
				<?php esc_html_e( 'Provide this direct HTTPS feed URL in your Pinterest Business Catalog manager. Pinterest fetches and updates your shopping pins automatically.', 'wpcalibrate-product-catalog-connector' ); ?>
			</p>

			<div class="wpcal-pcc-url-box">
				<input type="text" readonly id="wpcal-pcc-feed-url" class="wpcal-pcc-url-input" value="<?php echo esc_attr( $feed_url ); ?>" />
				<button type="button" class="button wpcal-pcc-copy-btn" data-target="wpcal-pcc-feed-url">
					<?php esc_html_e( 'Copy Feed URL', 'wpcalibrate-product-catalog-connector' ); ?>
				</button>
			</div>

			<div class="wpcal-pcc-actions-bar">
				<!-- Trigger Sync Form -->
				<form method="post" style="display:inline;">
					<?php wp_nonce_field( 'wpcal_pcc_sync_nonce', 'wpcal_pcc_nonce' ); ?>
					<input type="hidden" name="wpcal_pcc_action" value="trigger_sync" />
					<button type="submit" class="button button-primary wpcal-btn-primary wpcal-pcc-confirm-sync">
						<?php esc_html_e( 'Regenerate Feed Now', 'wpcalibrate-product-catalog-connector' ); ?>
					</button>
				</form>

				<!-- Rotate Token Form -->
				<form method="post" style="display:inline;">
					<?php wp_nonce_field( 'wpcal_pcc_token_nonce', 'wpcal_pcc_nonce' ); ?>
					<input type="hidden" name="wpcal_pcc_action" value="rotate_token" />
					<button type="submit" class="button wpcal-pcc-confirm-rotate">
						<?php esc_html_e( 'Rotate Feed Token', 'wpcalibrate-product-catalog-connector' ); ?>
					</button>
				</form>
			</div>
		</div>

		<!-- Purpose & Overview Card -->
		<div class="wpcal-pcc-card">
			<h2 class="wpcal-pcc-card-title"><?php esc_html_e( 'Purpose & Key Capabilities', 'wpcalibrate-product-catalog-connector' ); ?></h2>
			<p style="font-size: 14px; line-height: 1.6; color: var(--wpcal-text); margin-bottom: 16px;">
				<?php esc_html_e( 'WPCalibrate Product Catalog Connector bridges your WooCommerce store with Pinterest Business. It transforms your live store inventory into an enterprise-grade, high-performance retail catalog feed that keeps your Pinterest Product Pins, dynamic shopping ads, and store catalog continuously synchronized.', 'wpcalibrate-product-catalog-connector' ); ?>
			</p>

			<div class="wpcal-pcc-method-grid">
				<div class="wpcal-pcc-method-card">
					<div class="wpcal-pcc-method-title">
						<span class="dashicons dashicons-cart" style="color: var(--wpcal-primary);"></span>
						<?php esc_html_e( 'Turn Products into Shoppable Pins', 'wpcalibrate-product-catalog-connector' ); ?>
					</div>
					<p class="wpcal-pcc-method-desc">
						<?php esc_html_e( 'Publish real-time pricing, stock availability, titles, and high-resolution gallery images directly to Pinterest for organic shopping search and Pinterest Shop tabs.', 'wpcalibrate-product-catalog-connector' ); ?>
					</p>
				</div>

				<div class="wpcal-pcc-method-card">
					<div class="wpcal-pcc-method-title">
						<span class="dashicons dashicons-chart-area" style="color: var(--wpcal-primary);"></span>
						<?php esc_html_e( 'Pinterest Shopping Ads & Retargeting', 'wpcalibrate-product-catalog-connector' ); ?>
					</div>
					<p class="wpcal-pcc-method-desc">
						<?php esc_html_e( 'Powers dynamic product ads, retargeting campaigns, and custom product groups with rich taxonomy, brand, and custom label mapping.', 'wpcalibrate-product-catalog-connector' ); ?>
					</p>
				</div>

				<div class="wpcal-pcc-method-card">
					<div class="wpcal-pcc-method-title">
						<span class="dashicons dashicons-performance" style="color: var(--wpcal-primary);"></span>
						<?php esc_html_e( 'Built for Large Stores (50,000+ Items)', 'wpcalibrate-product-catalog-connector' ); ?>
					</div>
					<p class="wpcal-pcc-method-desc">
						<?php esc_html_e( 'No more 504 timeouts, memory crashes, or slowed checkout pages. Uses deterministic chunked background streaming and atomic cache replacement.', 'wpcalibrate-product-catalog-connector' ); ?>
					</p>
				</div>
			</div>
		</div>

		<!-- How to Use Guide -->
		<div class="wpcal-pcc-card">
			<h2 class="wpcal-pcc-card-title"><?php esc_html_e( 'How to Use: 5-Step Setup Workflow', 'wpcalibrate-product-catalog-connector' ); ?></h2>
			<p style="margin-bottom: 20px; font-size: 13px; color: var(--wpcal-muted);">
				<?php esc_html_e( 'Follow this streamlined step-by-step setup sequence to configure your catalog feed and connect it to your Pinterest Business account:', 'wpcalibrate-product-catalog-connector' ); ?>
			</p>

			<!-- Interactive Visual Progress Stepper -->
			<div class="wpcal-pcc-pipeline-bar" style="display: flex; align-items: center; justify-content: space-between; background: #f8f9fa; border: 1px solid #dcdcde; border-radius: 6px; padding: 12px 18px; margin-bottom: 20px; overflow-x: auto; gap: 8px;">
				<div class="wpcal-pcc-pipeline-step" style="display: flex; align-items: center; gap: 8px; white-space: nowrap;">
					<span class="wpcal-pcc-pipeline-dot" style="width: 24px; height: 24px; border-radius: 50%; background: #2271b1; color: #ffffff; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center;">1</span>
					<span class="wpcal-pcc-pipeline-label" style="font-size: 12px; font-weight: 600; color: #2c3338;"><?php esc_html_e( 'Store & Regional', 'wpcalibrate-product-catalog-connector' ); ?></span>
				</div>
				<span class="wpcal-pcc-pipeline-arrow" style="color: #c3c4c7; font-size: 14px;">&rarr;</span>

				<div class="wpcal-pcc-pipeline-step" style="display: flex; align-items: center; gap: 8px; white-space: nowrap;">
					<span class="wpcal-pcc-pipeline-dot" style="width: 24px; height: 24px; border-radius: 50%; background: #2271b1; color: #ffffff; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center;">2</span>
					<span class="wpcal-pcc-pipeline-label" style="font-size: 12px; font-weight: 600; color: #2c3338;"><?php esc_html_e( 'Filter Rules', 'wpcalibrate-product-catalog-connector' ); ?></span>
				</div>
				<span class="wpcal-pcc-pipeline-arrow" style="color: #c3c4c7; font-size: 14px;">&rarr;</span>

				<div class="wpcal-pcc-pipeline-step" style="display: flex; align-items: center; gap: 8px; white-space: nowrap;">
					<span class="wpcal-pcc-pipeline-dot" style="width: 24px; height: 24px; border-radius: 50%; background: #2271b1; color: #ffffff; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center;">3</span>
					<span class="wpcal-pcc-pipeline-label" style="font-size: 12px; font-weight: 600; color: #2c3338;"><?php esc_html_e( 'Field Mapping', 'wpcalibrate-product-catalog-connector' ); ?></span>
				</div>
				<span class="wpcal-pcc-pipeline-arrow" style="color: #c3c4c7; font-size: 14px;">&rarr;</span>

				<div class="wpcal-pcc-pipeline-step" style="display: flex; align-items: center; gap: 8px; white-space: nowrap;">
					<span class="wpcal-pcc-pipeline-dot" style="width: 24px; height: 24px; border-radius: 50%; background: #2271b1; color: #ffffff; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center;">4</span>
					<span class="wpcal-pcc-pipeline-label" style="font-size: 12px; font-weight: 600; color: #2c3338;"><?php esc_html_e( 'Test & Preview', 'wpcalibrate-product-catalog-connector' ); ?></span>
				</div>
				<span class="wpcal-pcc-pipeline-arrow" style="color: #c3c4c7; font-size: 14px;">&rarr;</span>

				<div class="wpcal-pcc-pipeline-step" style="display: flex; align-items: center; gap: 8px; white-space: nowrap;">
					<span class="wpcal-pcc-pipeline-dot" style="width: 24px; height: 24px; border-radius: 50%; background: #2271b1; color: #ffffff; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center;">5</span>
					<span class="wpcal-pcc-pipeline-label" style="font-size: 12px; font-weight: 600; color: #2c3338;"><?php esc_html_e( 'Connect Pinterest', 'wpcalibrate-product-catalog-connector' ); ?></span>
				</div>
			</div>

			<!-- 5 Cards Responsive Grid -->
			<div class="wpcal-pcc-workflow-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; margin-bottom: 8px;">
				<!-- Step 1 -->
				<div class="wpcal-pcc-workflow-card" style="background: #ffffff; border: 1px solid #dcdcde; border-top: 3px solid #2271b1; border-radius: 6px; padding: 16px; display: flex; flex-direction: column; min-width: 0; box-sizing: border-box;">
					<div class="wpcal-pcc-workflow-badge-row" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
						<span class="wpcal-pcc-workflow-num" style="font-size: 16px; font-weight: 700; color: #2271b1; font-family: monospace;">01</span>
						<span class="wpcal-pcc-workflow-tag" style="font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; background: #f0f6fc; color: #2271b1; padding: 2px 7px; border-radius: 10px;"><?php esc_html_e( 'Step 1', 'wpcalibrate-product-catalog-connector' ); ?></span>
					</div>
					<h4 class="wpcal-pcc-workflow-title" style="font-size: 13px; font-weight: 600; color: #1d2327; margin: 0 0 8px 0; line-height: 1.35;"><?php esc_html_e( 'Store & Regional Settings', 'wpcalibrate-product-catalog-connector' ); ?></h4>
					<p class="wpcal-pcc-workflow-text" style="font-size: 12px; color: #646970; line-height: 1.45; margin: 0 0 14px 0; flex: 1;">
						<?php esc_html_e( 'Choose your feed format (CSV/TSV), ISO target country code (e.g. US, GB), default locale, and currency.', 'wpcalibrate-product-catalog-connector' ); ?>
					</p>
					<div class="wpcal-pcc-workflow-footer" style="margin-top: auto;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_SLUG . '&tab=catalog' ) ); ?>" class="button button-secondary" style="width: 100%; text-align: center; justify-content: center; font-size: 12px;">
							<?php esc_html_e( 'Catalog Settings', 'wpcalibrate-product-catalog-connector' ); ?> &rarr;
						</a>
					</div>
				</div>

				<!-- Step 2 -->
				<div class="wpcal-pcc-workflow-card" style="background: #ffffff; border: 1px solid #dcdcde; border-top: 3px solid #2271b1; border-radius: 6px; padding: 16px; display: flex; flex-direction: column; min-width: 0; box-sizing: border-box;">
					<div class="wpcal-pcc-workflow-badge-row" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
						<span class="wpcal-pcc-workflow-num" style="font-size: 16px; font-weight: 700; color: #2271b1; font-family: monospace;">02</span>
						<span class="wpcal-pcc-workflow-tag" style="font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; background: #f0f6fc; color: #2271b1; padding: 2px 7px; border-radius: 10px;"><?php esc_html_e( 'Step 2', 'wpcalibrate-product-catalog-connector' ); ?></span>
					</div>
					<h4 class="wpcal-pcc-workflow-title" style="font-size: 13px; font-weight: 600; color: #1d2327; margin: 0 0 8px 0; line-height: 1.35;"><?php esc_html_e( 'Product Filter Rules', 'wpcalibrate-product-catalog-connector' ); ?></h4>
					<p class="wpcal-pcc-workflow-text" style="font-size: 12px; color: #646970; line-height: 1.45; margin: 0 0 14px 0; flex: 1;">
						<?php esc_html_e( 'Select which product types to export (simple, variable children), exclude out-of-stock items, and set price limits.', 'wpcalibrate-product-catalog-connector' ); ?>
					</p>
					<div class="wpcal-pcc-workflow-footer" style="margin-top: auto;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_SLUG . '&tab=product_rules' ) ); ?>" class="button button-secondary" style="width: 100%; text-align: center; justify-content: center; font-size: 12px;">
							<?php esc_html_e( 'Product Rules', 'wpcalibrate-product-catalog-connector' ); ?> &rarr;
						</a>
					</div>
				</div>

				<!-- Step 3 -->
				<div class="wpcal-pcc-workflow-card" style="background: #ffffff; border: 1px solid #dcdcde; border-top: 3px solid #2271b1; border-radius: 6px; padding: 16px; display: flex; flex-direction: column; min-width: 0; box-sizing: border-box;">
					<div class="wpcal-pcc-workflow-badge-row" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
						<span class="wpcal-pcc-workflow-num" style="font-size: 16px; font-weight: 700; color: #2271b1; font-family: monospace;">03</span>
						<span class="wpcal-pcc-workflow-tag" style="font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; background: #f0f6fc; color: #2271b1; padding: 2px 7px; border-radius: 10px;"><?php esc_html_e( 'Step 3', 'wpcalibrate-product-catalog-connector' ); ?></span>
					</div>
					<h4 class="wpcal-pcc-workflow-title" style="font-size: 13px; font-weight: 600; color: #1d2327; margin: 0 0 8px 0; line-height: 1.35;"><?php esc_html_e( 'Field & Category Mapping', 'wpcalibrate-product-catalog-connector' ); ?></h4>
					<p class="wpcal-pcc-workflow-text" style="font-size: 12px; color: #646970; line-height: 1.45; margin: 0 0 14px 0; flex: 1;">
						<?php esc_html_e( 'Map default Google Product Category, Brand, GTIN/MPN identifiers, and custom labels (0-4) for targeted ads.', 'wpcalibrate-product-catalog-connector' ); ?>
					</p>
					<div class="wpcal-pcc-workflow-footer" style="margin-top: auto;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_SLUG . '&tab=field_mapping' ) ); ?>" class="button button-secondary" style="width: 100%; text-align: center; justify-content: center; font-size: 12px;">
							<?php esc_html_e( 'Field Mapping', 'wpcalibrate-product-catalog-connector' ); ?> &rarr;
						</a>
					</div>
				</div>

				<!-- Step 4 -->
				<div class="wpcal-pcc-workflow-card" style="background: #ffffff; border: 1px solid #dcdcde; border-top: 3px solid #2271b1; border-radius: 6px; padding: 16px; display: flex; flex-direction: column; min-width: 0; box-sizing: border-box;">
					<div class="wpcal-pcc-workflow-badge-row" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
						<span class="wpcal-pcc-workflow-num" style="font-size: 16px; font-weight: 700; color: #2271b1; font-family: monospace;">04</span>
						<span class="wpcal-pcc-workflow-tag" style="font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; background: #f0f6fc; color: #2271b1; padding: 2px 7px; border-radius: 10px;"><?php esc_html_e( 'Step 4', 'wpcalibrate-product-catalog-connector' ); ?></span>
					</div>
					<h4 class="wpcal-pcc-workflow-title" style="font-size: 13px; font-weight: 600; color: #1d2327; margin: 0 0 8px 0; line-height: 1.35;"><?php esc_html_e( 'Regenerate & Test Feed', 'wpcalibrate-product-catalog-connector' ); ?></h4>
					<p class="wpcal-pcc-workflow-text" style="font-size: 12px; color: #646970; line-height: 1.45; margin: 0 0 14px 0; flex: 1;">
						<?php esc_html_e( 'Trigger feed generation to build your retail catalog file. Check Diagnostics to preview exported rows and verify headers.', 'wpcalibrate-product-catalog-connector' ); ?>
					</p>
					<div class="wpcal-pcc-workflow-footer" style="margin-top: auto;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_SLUG . '&tab=diagnostics' ) ); ?>" class="button button-secondary" style="width: 100%; text-align: center; justify-content: center; font-size: 12px;">
							<?php esc_html_e( 'Diagnostics & Preview', 'wpcalibrate-product-catalog-connector' ); ?> &rarr;
						</a>
					</div>
				</div>

				<!-- Step 5 -->
				<div class="wpcal-pcc-workflow-card" style="background: #ffffff; border: 1px solid #dcdcde; border-top: 3px solid #2271b1; border-radius: 6px; padding: 16px; display: flex; flex-direction: column; min-width: 0; box-sizing: border-box;">
					<div class="wpcal-pcc-workflow-badge-row" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
						<span class="wpcal-pcc-workflow-num" style="font-size: 16px; font-weight: 700; color: #2271b1; font-family: monospace;">05</span>
						<span class="wpcal-pcc-workflow-tag" style="font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; background: #f0f6fc; color: #2271b1; padding: 2px 7px; border-radius: 10px;"><?php esc_html_e( 'Step 5', 'wpcalibrate-product-catalog-connector' ); ?></span>
					</div>
					<h4 class="wpcal-pcc-workflow-title" style="font-size: 13px; font-weight: 600; color: #1d2327; margin: 0 0 8px 0; line-height: 1.35;"><?php esc_html_e( 'Connect with Pinterest', 'wpcalibrate-product-catalog-connector' ); ?></h4>
					<p class="wpcal-pcc-workflow-text" style="font-size: 12px; color: #646970; line-height: 1.45; margin: 0 0 14px 0; flex: 1;">
						<?php esc_html_e( 'In Pinterest Business Hub > Catalogs > Data Sources, paste your Hosted Feed URL above for automated syncing.', 'wpcalibrate-product-catalog-connector' ); ?>
					</p>
					<div class="wpcal-pcc-workflow-footer" style="margin-top: auto;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_SLUG . '&tab=pinterest' ) ); ?>" class="button button-secondary" style="width: 100%; text-align: center; justify-content: center; font-size: 12px;">
							<?php esc_html_e( 'Pinterest Options', 'wpcalibrate-product-catalog-connector' ); ?> &rarr;
						</a>
					</div>
				</div>
			</div>
		</div>

		<!-- Method & Architecture Card -->
		<div class="wpcal-pcc-card">
			<h2 class="wpcal-pcc-card-title"><?php esc_html_e( 'Synchronization Method & Architecture', 'wpcalibrate-product-catalog-connector' ); ?></h2>
			<p>
				<?php esc_html_e( 'WPCalibrate utilizes an enterprise-grade background synchronization architecture designed to guarantee catalog reliability without taxing your WordPress front-end or database:', 'wpcalibrate-product-catalog-connector' ); ?>
			</p>

			<div class="wpcal-pcc-method-grid">
				<div class="wpcal-pcc-method-card">
					<div class="wpcal-pcc-method-title">
						<span class="dashicons dashicons-randomize" style="color: var(--wpcal-primary);"></span>
						<?php esc_html_e( 'Action Scheduler Batch Streaming', 'wpcalibrate-product-catalog-connector' ); ?>
					</div>
					<p class="wpcal-pcc-method-desc">
						<?php esc_html_e( 'Products are queried in controlled batches (default 250 items) using Action Scheduler queueing. This divides large catalogs into light, self-contained micro-tasks that prevent memory spikes and timeouts.', 'wpcalibrate-product-catalog-connector' ); ?>
					</p>
				</div>

				<div class="wpcal-pcc-method-card">
					<div class="wpcal-pcc-method-title">
						<span class="dashicons dashicons-shield-alt" style="color: var(--wpcal-primary);"></span>
						<?php esc_html_e( 'Atomic File Swapping', 'wpcalibrate-product-catalog-connector' ); ?>
					</div>
					<p class="wpcal-pcc-method-desc">
						<?php esc_html_e( 'The catalog is generated into a temporary staging file (.tmp). Only after all batches succeed and validation passes is the file atomically swapped into production. Pinterest never sees incomplete or corrupt feeds.', 'wpcalibrate-product-catalog-connector' ); ?>
					</p>
				</div>

				<div class="wpcal-pcc-method-card">
					<div class="wpcal-pcc-method-title">
						<span class="dashicons dashicons-update" style="color: var(--wpcal-primary);"></span>
						<?php esc_html_e( 'Smart Dirty-State Invalidation', 'wpcalibrate-product-catalog-connector' ); ?>
					</div>
					<p class="wpcal-pcc-method-desc">
						<?php esc_html_e( 'When you edit products or process WooCommerce orders, the plugin marks the catalog as dirty and schedules a debounced regeneration instead of repeatedly taxing your server on every individual product save.', 'wpcalibrate-product-catalog-connector' ); ?>
					</p>
				</div>

				<div class="wpcal-pcc-method-card">
					<div class="wpcal-pcc-method-title">
						<span class="dashicons dashicons-lock" style="color: var(--wpcal-primary);"></span>
						<?php esc_html_e( 'Protected Token Endpoints', 'wpcalibrate-product-catalog-connector' ); ?>
					</div>
					<p class="wpcal-pcc-method-desc">
						<?php esc_html_e( 'Your feed URL is protected by a 64-character cryptographic token to prevent unauthorized third-party scraping. If ever compromised, you can rotate the token instantly with one click.', 'wpcalibrate-product-catalog-connector' ); ?>
					</p>
				</div>
			</div>
		</div>

		<!-- Quick Navigation Hub -->
		<div class="wpcal-pcc-card">
			<h2 class="wpcal-pcc-card-title"><?php esc_html_e( 'Plugin Settings & Modules Hub', 'wpcalibrate-product-catalog-connector' ); ?></h2>
			<div class="wpcal-pcc-quicknav-grid">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_SLUG . '&tab=catalog' ) ); ?>" class="wpcal-pcc-quicknav-card">
					<span class="wpcal-pcc-quicknav-title"><?php esc_html_e( 'Catalog Settings', 'wpcalibrate-product-catalog-connector' ); ?></span>
					<span class="wpcal-pcc-quicknav-desc"><?php esc_html_e( 'Format, country, currency, schedule, and sync mode.', 'wpcalibrate-product-catalog-connector' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_SLUG . '&tab=product_rules' ) ); ?>" class="wpcal-pcc-quicknav-card">
					<span class="wpcal-pcc-quicknav-title"><?php esc_html_e( 'Product Rules', 'wpcalibrate-product-catalog-connector' ); ?></span>
					<span class="wpcal-pcc-quicknav-desc"><?php esc_html_e( 'Filter out-of-stock items, categories, and price thresholds.', 'wpcalibrate-product-catalog-connector' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_SLUG . '&tab=field_mapping' ) ); ?>" class="wpcal-pcc-quicknav-card">
					<span class="wpcal-pcc-quicknav-title"><?php esc_html_e( 'Field Mapping', 'wpcalibrate-product-catalog-connector' ); ?></span>
					<span class="wpcal-pcc-quicknav-desc"><?php esc_html_e( 'Map Google Product Categories, brand, and custom labels.', 'wpcalibrate-product-catalog-connector' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_SLUG . '&tab=pinterest' ) ); ?>" class="wpcal-pcc-quicknav-card">
					<span class="wpcal-pcc-quicknav-title"><?php esc_html_e( 'Pinterest API', 'wpcalibrate-product-catalog-connector' ); ?></span>
					<span class="wpcal-pcc-quicknav-desc"><?php esc_html_e( 'OAuth connection and remote feed registration.', 'wpcalibrate-product-catalog-connector' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_SLUG . '&tab=sync' ) ); ?>" class="wpcal-pcc-quicknav-card">
					<span class="wpcal-pcc-quicknav-title"><?php esc_html_e( 'Sync History', 'wpcalibrate-product-catalog-connector' ); ?></span>
					<span class="wpcal-pcc-quicknav-desc"><?php esc_html_e( 'View past execution runs, durations, and lock controls.', 'wpcalibrate-product-catalog-connector' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_SLUG . '&tab=diagnostics' ) ); ?>" class="wpcal-pcc-quicknav-card">
					<span class="wpcal-pcc-quicknav-title"><?php esc_html_e( 'Diagnostics', 'wpcalibrate-product-catalog-connector' ); ?></span>
					<span class="wpcal-pcc-quicknav-desc"><?php esc_html_e( 'System health checks and 10-row feed preview table.', 'wpcalibrate-product-catalog-connector' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_SLUG . '&tab=license' ) ); ?>" class="wpcal-pcc-quicknav-card">
					<span class="wpcal-pcc-quicknav-title"><?php esc_html_e( 'License Manager', 'wpcalibrate-product-catalog-connector' ); ?></span>
					<span class="wpcal-pcc-quicknav-desc"><?php esc_html_e( 'Manage plugin activation and software update license.', 'wpcalibrate-product-catalog-connector' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PAGE_SLUG . '&tab=support' ) ); ?>" class="wpcal-pcc-quicknav-card">
					<span class="wpcal-pcc-quicknav-title"><?php esc_html_e( 'Customer Support', 'wpcalibrate-product-catalog-connector' ); ?></span>
					<span class="wpcal-pcc-quicknav-desc"><?php esc_html_e( 'Email, WhatsApp, and sanitized system diagnostic report.', 'wpcalibrate-product-catalog-connector' ); ?></span>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Tab 2: Catalog Settings.
	 *
	 * @param array<string, mixed> $settings
	 */
	private function render_catalog_tab( array $settings ): void {
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'wpcal_pcc_save_settings_nonce', 'wpcal_pcc_nonce' ); ?>
			<input type="hidden" name="wpcal_pcc_action" value="save_settings" />
			<input type="hidden" name="current_tab" value="catalog" />

			<div class="wpcal-pcc-card">
				<h2 class="wpcal-pcc-card-title"><?php esc_html_e( 'Catalog Basics & Regional Settings', 'wpcalibrate-product-catalog-connector' ); ?></h2>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_mode"><?php esc_html_e( 'Integration Mode', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<select name="wpcal_pcc[mode]" id="wpcal_mode" class="regular-text">
						<option value="feed_only" <?php selected( $settings['mode'], 'feed_only' ); ?>><?php esc_html_e( 'Feed Only (Primary & dependable hosted URL)', 'wpcalibrate-product-catalog-connector' ); ?></option>
						<option value="api_feed" <?php selected( $settings['mode'], 'api_feed' ); ?>><?php esc_html_e( 'Pinterest API + Feed (Advanced registration & status)', 'wpcalibrate-product-catalog-connector' ); ?></option>
					</select>
					<p class="wpcal-pcc-help"><?php esc_html_e( 'The hosted catalog feed is always active and does not require Pinterest API credentials.', 'wpcalibrate-product-catalog-connector' ); ?></p>
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_format"><?php esc_html_e( 'Feed File Format', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<select name="wpcal_pcc[feed_format]" id="wpcal_format" class="regular-text">
						<option value="csv" <?php selected( $settings['feed_format'], 'csv' ); ?>><?php esc_html_e( 'CSV (Comma Separated Values - Recommended)', 'wpcalibrate-product-catalog-connector' ); ?></option>
						<option value="tsv" <?php selected( $settings['feed_format'], 'tsv' ); ?>><?php esc_html_e( 'TSV (Tab Separated Values)', 'wpcalibrate-product-catalog-connector' ); ?></option>
					</select>
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_country"><?php esc_html_e( 'Target Country (ISO 3166-1 alpha-2)', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<input type="text" name="wpcal_pcc[country]" id="wpcal_country" value="<?php echo esc_attr( (string) $settings['country'] ); ?>" class="regular-text" maxlength="2" />
					<p class="wpcal-pcc-help"><?php esc_html_e( 'Two-letter uppercase country code (e.g. US, GB, CA, AU).', 'wpcalibrate-product-catalog-connector' ); ?></p>
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_locale"><?php esc_html_e( 'Default Locale', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<input type="text" name="wpcal_pcc[locale]" id="wpcal_locale" value="<?php echo esc_attr( (string) $settings['locale'] ); ?>" class="regular-text" />
					<p class="wpcal-pcc-help"><?php esc_html_e( 'Catalog language locale (e.g. en_US, en_GB, de_DE).', 'wpcalibrate-product-catalog-connector' ); ?></p>
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_currency"><?php esc_html_e( 'Currency (ISO 4217)', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<input type="text" name="wpcal_pcc[currency]" id="wpcal_currency" value="<?php echo esc_attr( (string) $settings['currency'] ); ?>" class="regular-text" maxlength="3" />
					<p class="wpcal-pcc-help"><?php esc_html_e( 'Three-letter currency code (e.g. USD, GBP, EUR). Must match your store and Pinterest catalog currency.', 'wpcalibrate-product-catalog-connector' ); ?></p>
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_schedule"><?php esc_html_e( 'Generation Schedule', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<select name="wpcal_pcc[schedule]" id="wpcal_schedule" class="regular-text">
						<option value="daily" <?php selected( $settings['schedule'], 'daily' ); ?>><?php esc_html_e( 'Daily (Recommended)', 'wpcalibrate-product-catalog-connector' ); ?></option>
						<option value="twelve_hours" <?php selected( $settings['schedule'], 'twelve_hours' ); ?>><?php esc_html_e( 'Every 12 Hours', 'wpcalibrate-product-catalog-connector' ); ?></option>
						<option value="twice_weekly" <?php selected( $settings['schedule'], 'twice_weekly' ); ?>><?php esc_html_e( 'Twice Weekly', 'wpcalibrate-product-catalog-connector' ); ?></option>
						<option value="weekly" <?php selected( $settings['schedule'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'wpcalibrate-product-catalog-connector' ); ?></option>
						<option value="manual" <?php selected( $settings['schedule'], 'manual' ); ?>><?php esc_html_e( 'Manual Only', 'wpcalibrate-product-catalog-connector' ); ?></option>
					</select>
					<p class="wpcal-pcc-help"><?php esc_html_e( 'Automatic background regeneration schedule. Product updates also flag the catalog for debounced regeneration.', 'wpcalibrate-product-catalog-connector' ); ?></p>
				</div>
			</div>

			<div class="wpcal-pcc-card">
				<h2 class="wpcal-pcc-card-title"><?php esc_html_e( 'HTTP Basic Authentication (Optional Feed Protection)', 'wpcalibrate-product-catalog-connector' ); ?></h2>
				<div class="wpcal-pcc-form-row">
					<label>
						<input type="checkbox" name="wpcal_pcc[http_basic_auth_enabled]" value="1" <?php checked( ! empty( $settings['http_basic_auth_enabled'] ) ); ?> />
						<strong><?php esc_html_e( 'Require HTTP Basic Authentication for Feed Requests', 'wpcalibrate-product-catalog-connector' ); ?></strong>
					</label>
					<p class="wpcal-pcc-help"><?php esc_html_e( 'If enabled, Pinterest must be configured with these credentials to download your catalog.', 'wpcalibrate-product-catalog-connector' ); ?></p>
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_auth_user"><?php esc_html_e( 'Feed HTTP Username', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<input type="text" name="wpcal_pcc[http_basic_auth_user]" id="wpcal_auth_user" value="<?php echo esc_attr( (string) $settings['http_basic_auth_user'] ); ?>" class="regular-text" />
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_auth_pass"><?php esc_html_e( 'Feed HTTP Password', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<input type="password" name="wpcal_pcc[http_basic_auth_pass]" id="wpcal_auth_pass" value="<?php echo ! empty( $settings['http_basic_auth_pass'] ) ? '••••••••' : ''; ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Leave empty to keep existing password', 'wpcalibrate-product-catalog-connector' ); ?>" />
				</div>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary wpcal-btn-primary"><?php esc_html_e( 'Save Catalog Settings', 'wpcalibrate-product-catalog-connector' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * Tab 3: Product Rules.
	 *
	 * @param array<string, mixed> $settings
	 */
	private function render_product_rules_tab( array $settings ): void {
		$categories = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'wpcal_pcc_save_settings_nonce', 'wpcal_pcc_nonce' ); ?>
			<input type="hidden" name="wpcal_pcc_action" value="save_settings" />
			<input type="hidden" name="current_tab" value="product_rules" />

			<div class="wpcal-pcc-card">
				<h2 class="wpcal-pcc-card-title"><?php esc_html_e( 'Product Eligibility & Selection Rules', 'wpcalibrate-product-catalog-connector' ); ?></h2>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label"><?php esc_html_e( 'Supported Product Types', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<?php foreach ( [ 'simple' => __( 'Simple Products', 'wpcalibrate-product-catalog-connector' ), 'variable' => __( 'Variable Products', 'wpcalibrate-product-catalog-connector' ), 'external' => __( 'External/Affiliate Products', 'wpcalibrate-product-catalog-connector' ), 'grouped' => __( 'Grouped Products', 'wpcalibrate-product-catalog-connector' ) ] as $type_key => $type_label ) : ?>
						<label style="display:block; margin-bottom:4px;">
							<input type="checkbox" name="wpcal_pcc[included_types][]" value="<?php echo esc_attr( $type_key ); ?>" <?php checked( in_array( $type_key, (array) $settings['included_types'], true ) ); ?> />
							<?php echo esc_html( $type_label ); ?>
						</label>
					<?php endforeach; ?>
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_stock_action"><?php esc_html_e( 'Out of Stock Products', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<select name="wpcal_pcc[out_of_stock_action]" id="wpcal_stock_action" class="regular-text">
						<option value="include" <?php selected( $settings['out_of_stock_action'], 'include' ); ?>><?php esc_html_e( 'Include in feed with availability: out_of_stock (Recommended)', 'wpcalibrate-product-catalog-connector' ); ?></option>
						<option value="exclude" <?php selected( $settings['out_of_stock_action'], 'exclude' ); ?>><?php esc_html_e( 'Exclude completely from feed', 'wpcalibrate-product-catalog-connector' ); ?></option>
					</select>
				</div>

				<div class="wpcal-pcc-form-row">
					<label>
						<input type="checkbox" name="wpcal_pcc[include_variations]" value="1" <?php checked( ! empty( $settings['include_variations'] ) ); ?> />
						<strong><?php esc_html_e( 'Export product variations as individual items with shared item_group_id', 'wpcalibrate-product-catalog-connector' ); ?></strong>
					</label>
				</div>

				<div class="wpcal-pcc-form-row">
					<label>
						<input type="checkbox" name="wpcal_pcc[exclude_missing_image]" value="1" <?php checked( ! empty( $settings['exclude_missing_image'] ) ); ?> />
						<strong><?php esc_html_e( 'Exclude products without a primary image (Required by Pinterest)', 'wpcalibrate-product-catalog-connector' ); ?></strong>
					</label>
				</div>

				<div class="wpcal-pcc-form-row">
					<label>
						<input type="checkbox" name="wpcal_pcc[exclude_missing_price]" value="1" <?php checked( ! empty( $settings['exclude_missing_price'] ) ); ?> />
						<strong><?php esc_html_e( 'Exclude products without a valid positive price', 'wpcalibrate-product-catalog-connector' ); ?></strong>
					</label>
				</div>

				<div class="wpcal-pcc-form-row">
					<label>
						<input type="checkbox" name="wpcal_pcc[include_virtual]" value="1" <?php checked( ! empty( $settings['include_virtual'] ) ); ?> />
						<strong><?php esc_html_e( 'Include Virtual Products', 'wpcalibrate-product-catalog-connector' ); ?></strong>
					</label>
				</div>

				<div class="wpcal-pcc-form-row">
					<label>
						<input type="checkbox" name="wpcal_pcc[include_downloadable]" value="1" <?php checked( ! empty( $settings['include_downloadable'] ) ); ?> />
						<strong><?php esc_html_e( 'Include Downloadable Products', 'wpcalibrate-product-catalog-connector' ); ?></strong>
					</label>
				</div>
			</div>

			<div class="wpcal-pcc-card">
				<h2 class="wpcal-pcc-card-title"><?php esc_html_e( 'Category & Product Filters', 'wpcalibrate-product-catalog-connector' ); ?></h2>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_inc_cat"><?php esc_html_e( 'Include Only Specific Categories (Leave empty to include all)', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<select name="wpcal_pcc[included_categories][]" id="wpcal_inc_cat" class="regular-text" multiple style="height: 120px;">
						<?php if ( is_array( $categories ) && ! is_wp_error( $categories ) ) : ?>
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo esc_attr( (string) $cat->term_id ); ?>" <?php selected( in_array( $cat->term_id, (array) $settings['included_categories'], true ) ); ?>>
									<?php echo esc_html( $cat->name ); ?> (ID: <?php echo esc_html( (string) $cat->term_id ); ?>)
								</option>
							<?php endforeach; ?>
						<?php endif; ?>
					</select>
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_exc_cat"><?php esc_html_e( 'Exclude Specific Categories', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<select name="wpcal_pcc[excluded_categories][]" id="wpcal_exc_cat" class="regular-text" multiple style="height: 120px;">
						<?php if ( is_array( $categories ) && ! is_wp_error( $categories ) ) : ?>
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo esc_attr( (string) $cat->term_id ); ?>" <?php selected( in_array( $cat->term_id, (array) $settings['excluded_categories'], true ) ); ?>>
									<?php echo esc_html( $cat->name ); ?> (ID: <?php echo esc_html( (string) $cat->term_id ); ?>)
								</option>
							<?php endforeach; ?>
						<?php endif; ?>
					</select>
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_exc_prod"><?php esc_html_e( 'Exclude Product IDs (Comma separated)', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<input type="text" name="wpcal_pcc[excluded_products]" id="wpcal_exc_prod" value="<?php echo esc_attr( implode( ', ', (array) $settings['excluded_products'] ) ); ?>" class="regular-text" />
				</div>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary wpcal-btn-primary"><?php esc_html_e( 'Save Product Rules', 'wpcalibrate-product-catalog-connector' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * Tab 4: Field Mapping.
	 *
	 * @param array<string, mixed> $settings
	 */
	private function render_field_mapping_tab( array $settings ): void {
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'wpcal_pcc_save_settings_nonce', 'wpcal_pcc_nonce' ); ?>
			<input type="hidden" name="wpcal_pcc_action" value="save_settings" />
			<input type="hidden" name="current_tab" value="field_mapping" />

			<div class="wpcal-pcc-card">
				<h2 class="wpcal-pcc-card-title"><?php esc_html_e( 'Field Mapping & Identifier Fallbacks', 'wpcalibrate-product-catalog-connector' ); ?></h2>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_desc_src"><?php esc_html_e( 'Description Source', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<select name="wpcal_pcc[description_source]" id="wpcal_desc_src" class="regular-text">
						<option value="short_first" <?php selected( $settings['description_source'], 'short_first' ); ?>><?php esc_html_e( 'Short description first (fallback to full)', 'wpcalibrate-product-catalog-connector' ); ?></option>
						<option value="full_first" <?php selected( $settings['description_source'], 'full_first' ); ?>><?php esc_html_e( 'Full description first (fallback to short)', 'wpcalibrate-product-catalog-connector' ); ?></option>
						<option value="short_only" <?php selected( $settings['description_source'], 'short_only' ); ?>><?php esc_html_e( 'Short description only', 'wpcalibrate-product-catalog-connector' ); ?></option>
						<option value="full_only" <?php selected( $settings['description_source'], 'full_only' ); ?>><?php esc_html_e( 'Full description only', 'wpcalibrate-product-catalog-connector' ); ?></option>
					</select>
					<p class="wpcal-pcc-help"><?php esc_html_e( 'Descriptions are cleaned of shortcodes and HTML tags and limited to safe Pinterest lengths.', 'wpcalibrate-product-catalog-connector' ); ?></p>
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_brand_src"><?php esc_html_e( 'Brand Mapping Source', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<select name="wpcal_pcc[brand_source]" id="wpcal_brand_src" class="regular-text">
						<option value="none" <?php selected( $settings['brand_source'], 'none' ); ?>><?php esc_html_e( 'None / Default taxonomy', 'wpcalibrate-product-catalog-connector' ); ?></option>
						<option value="attribute" <?php selected( $settings['brand_source'], 'attribute' ); ?>><?php esc_html_e( 'Product Attribute', 'wpcalibrate-product-catalog-connector' ); ?></option>
						<option value="custom_field" <?php selected( $settings['brand_source'], 'custom_field' ); ?>><?php esc_html_e( 'Custom Meta Field', 'wpcalibrate-product-catalog-connector' ); ?></option>
					</select>
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_brand_attr"><?php esc_html_e( 'Brand Attribute Name (e.g. pa_brand, brand)', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<input type="text" name="wpcal_pcc[brand_attribute]" id="wpcal_brand_attr" value="<?php echo esc_attr( (string) $settings['brand_attribute'] ); ?>" class="regular-text" />
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_brand_cf"><?php esc_html_e( 'Brand Custom Field Meta Key', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<input type="text" name="wpcal_pcc[brand_custom_field]" id="wpcal_brand_cf" value="<?php echo esc_attr( (string) $settings['brand_custom_field'] ); ?>" class="regular-text" />
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_cond"><?php esc_html_e( 'Default Condition', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<select name="wpcal_pcc[condition_default]" id="wpcal_cond" class="regular-text">
						<option value="new" <?php selected( $settings['condition_default'], 'new' ); ?>><?php esc_html_e( 'New', 'wpcalibrate-product-catalog-connector' ); ?></option>
						<option value="refurbished" <?php selected( $settings['condition_default'], 'refurbished' ); ?>><?php esc_html_e( 'Refurbished', 'wpcalibrate-product-catalog-connector' ); ?></option>
						<option value="used" <?php selected( $settings['condition_default'], 'used' ); ?>><?php esc_html_e( 'Used', 'wpcalibrate-product-catalog-connector' ); ?></option>
					</select>
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_gtin_src"><?php esc_html_e( 'GTIN Source', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<select name="wpcal_pcc[gtin_source]" id="wpcal_gtin_src" class="regular-text">
						<option value="sku" <?php selected( $settings['gtin_source'], 'sku' ); ?>><?php esc_html_e( 'Product SKU', 'wpcalibrate-product-catalog-connector' ); ?></option>
						<option value="custom_field" <?php selected( $settings['gtin_source'], 'custom_field' ); ?>><?php esc_html_e( 'Custom Meta Field', 'wpcalibrate-product-catalog-connector' ); ?></option>
						<option value="none" <?php selected( $settings['gtin_source'], 'none' ); ?>><?php esc_html_e( 'None', 'wpcalibrate-product-catalog-connector' ); ?></option>
					</select>
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_gtin_cf"><?php esc_html_e( 'GTIN Custom Meta Key', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<input type="text" name="wpcal_pcc[gtin_custom_field]" id="wpcal_gtin_cf" value="<?php echo esc_attr( (string) $settings['gtin_custom_field'] ); ?>" class="regular-text" />
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_gal_count"><?php esc_html_e( 'Max Additional Gallery Images', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<input type="number" name="wpcal_pcc[additional_images_count]" id="wpcal_gal_count" value="<?php echo esc_attr( (string) $settings['additional_images_count'] ); ?>" min="0" max="10" class="small-text" />
					<p class="wpcal-pcc-help"><?php esc_html_e( 'Number of product gallery images included in additional_image_link (max 10).', 'wpcalibrate-product-catalog-connector' ); ?></p>
				</div>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary wpcal-btn-primary"><?php esc_html_e( 'Save Field Mappings', 'wpcalibrate-product-catalog-connector' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * Tab 5: Pinterest API Settings.
	 *
	 * @param array<string, mixed> $settings
	 */
	private function render_pinterest_tab( array $settings ): void {
		$auth_url     = $this->oauth_service->get_authorization_url();
		$redirect_uri = OAuthService::get_redirect_uri();
		$is_connected = $this->token_store->is_connected();
		$masked_token = $this->token_store->get_masked_access_token();
		?>
		<div class="wpcal-pcc-card">
			<h2 class="wpcal-pcc-card-title">
				<span><?php esc_html_e( 'Pinterest API Connection (OAuth 2.0)', 'wpcalibrate-product-catalog-connector' ); ?></span>
				<?php if ( $is_connected ) : ?>
					<span class="wpcal-pcc-badge wpcal-pcc-badge-connected">● <?php esc_html_e( 'Connected', 'wpcalibrate-product-catalog-connector' ); ?></span>
				<?php else : ?>
					<span class="wpcal-pcc-badge wpcal-pcc-badge-disconnected">○ <?php esc_html_e( 'Not Connected', 'wpcalibrate-product-catalog-connector' ); ?></span>
				<?php endif; ?>
			</h2>

			<p>
				<?php esc_html_e( 'Connect your approved Pinterest Business Developer application to register your hosted catalog feed directly and monitor ingestion results via the Pinterest API v5.', 'wpcalibrate-product-catalog-connector' ); ?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( 'wpcal_pcc_save_settings_nonce', 'wpcal_pcc_nonce' ); ?>
				<input type="hidden" name="wpcal_pcc_action" value="save_settings" />
				<input type="hidden" name="current_tab" value="pinterest" />

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_app_id"><?php esc_html_e( 'Pinterest App ID / Client ID', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<input type="text" name="wpcal_pcc[pinterest_app_id]" id="wpcal_app_id" value="<?php echo esc_attr( (string) $settings['pinterest_app_id'] ); ?>" class="regular-text" />
					<p class="wpcal-pcc-help"><?php esc_html_e( 'Found in your Pinterest Developers Portal App Dashboard.', 'wpcalibrate-product-catalog-connector' ); ?></p>
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_app_sec"><?php esc_html_e( 'Pinterest App Secret', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<input type="password" name="wpcal_pcc[pinterest_app_secret]" id="wpcal_app_sec" value="<?php echo ! empty( $settings['pinterest_app_secret'] ) ? '••••••••' : ''; ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Leave empty to preserve existing secret', 'wpcalibrate-product-catalog-connector' ); ?>" />
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label"><?php esc_html_e( 'Authorized Redirect URI', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<div class="wpcal-pcc-url-box">
						<input type="text" readonly id="wpcal-pcc-redirect-uri" class="wpcal-pcc-url-input" value="<?php echo esc_attr( $redirect_uri ); ?>" />
						<button type="button" class="button wpcal-pcc-copy-btn" data-target="wpcal-pcc-redirect-uri">
							<?php esc_html_e( 'Copy URI', 'wpcalibrate-product-catalog-connector' ); ?>
						</button>
					</div>
					<p class="wpcal-pcc-help"><?php esc_html_e( 'Add this exact URL to your Pinterest Developer App Redirect URIs whitelist.', 'wpcalibrate-product-catalog-connector' ); ?></p>
				</div>

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_ad_acc"><?php esc_html_e( 'Pinterest Ad Account ID (Optional)', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<input type="text" name="wpcal_pcc[pinterest_ad_account_id]" id="wpcal_ad_acc" value="<?php echo esc_attr( (string) $settings['pinterest_ad_account_id'] ); ?>" class="regular-text" />
					<p class="wpcal-pcc-help"><?php esc_html_e( 'Required only if managing catalogs under a specific Pinterest Ad Account.', 'wpcalibrate-product-catalog-connector' ); ?></p>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary wpcal-btn-primary"><?php esc_html_e( 'Save Credentials', 'wpcalibrate-product-catalog-connector' ); ?></button>
				</p>
			</form>
		</div>

		<?php if ( ! empty( $settings['pinterest_app_id'] ) ) : ?>
			<div class="wpcal-pcc-card">
				<h3 class="wpcal-pcc-card-title"><?php esc_html_e( 'Connection Actions', 'wpcalibrate-product-catalog-connector' ); ?></h3>
				<?php if ( ! $is_connected ) : ?>
					<p><?php esc_html_e( 'Credentials are saved. Click below to authorize this store with Pinterest:', 'wpcalibrate-product-catalog-connector' ); ?></p>
					<a href="<?php echo esc_url( $auth_url ); ?>" class="button button-primary wpcal-btn-primary">
						<?php esc_html_e( 'Connect with Pinterest', 'wpcalibrate-product-catalog-connector' ); ?>
					</a>
				<?php else : ?>
					<p><?php esc_html_e( 'Account is currently connected to Pinterest.', 'wpcalibrate-product-catalog-connector' ); ?></p>
					<div class="wpcal-pcc-form-row">
						<strong><?php esc_html_e( 'Active Token:', 'wpcalibrate-product-catalog-connector' ); ?></strong>
						<code><?php echo esc_html( $masked_token ); ?></code>
					</div>

					<!-- Register Feed button if not registered -->
					<?php if ( empty( $settings['pinterest_feed_id'] ) ) : ?>
						<form method="post" style="display:inline-block; margin-right: 12px;">
							<?php wp_nonce_field( 'wpcal_pcc_register_feed_nonce', 'wpcal_pcc_nonce' ); ?>
							<input type="hidden" name="wpcal_pcc_action" value="pinterest_register_feed" />
							<button type="submit" class="button button-primary wpcal-btn-primary">
								<?php esc_html_e( 'Register Hosted Feed with Pinterest API', 'wpcalibrate-product-catalog-connector' ); ?>
							</button>
						</form>
					<?php else : ?>
						<div class="wpcal-pcc-form-row">
							<strong><?php esc_html_e( 'Registered Remote Feed ID:', 'wpcalibrate-product-catalog-connector' ); ?></strong>
							<code><?php echo esc_html( (string) $settings['pinterest_feed_id'] ); ?></code>
						</div>
					<?php endif; ?>

					<form method="post" style="display:inline-block;">
						<?php wp_nonce_field( 'wpcal_pcc_pinterest_disconnect_nonce', 'wpcal_pcc_nonce' ); ?>
						<input type="hidden" name="wpcal_pcc_action" value="pinterest_disconnect" />
						<button type="submit" class="button button-secondary">
							<?php esc_html_e( 'Disconnect Pinterest', 'wpcalibrate-product-catalog-connector' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Tab 6: Synchronization History.
	 *
	 * @param array<string, mixed> $settings
	 */
	private function render_sync_tab( array $settings ): void {
		$runs = SyncRunRepository::get_recent_runs( 20 );
		$is_locked = Lock::is_locked();
		$lock_info = Lock::get_lock_info();
		?>
		<div class="wpcal-pcc-card">
			<h2 class="wpcal-pcc-card-title"><?php esc_html_e( 'Feed Synchronization Controls', 'wpcalibrate-product-catalog-connector' ); ?></h2>
			<div class="wpcal-pcc-actions-bar" style="margin-top: 0; padding-top: 0; border-top: none;">
				<form method="post" style="display:inline;">
					<?php wp_nonce_field( 'wpcal_pcc_sync_nonce', 'wpcal_pcc_nonce' ); ?>
					<input type="hidden" name="wpcal_pcc_action" value="trigger_sync" />
					<button type="submit" class="button button-primary wpcal-btn-primary wpcal-pcc-confirm-sync">
						<?php esc_html_e( 'Start Full Feed Regeneration', 'wpcalibrate-product-catalog-connector' ); ?>
					</button>
				</form>

				<?php if ( $is_locked ) : ?>
					<form method="post" style="display:inline;">
						<?php wp_nonce_field( 'wpcal_pcc_lock_nonce', 'wpcal_pcc_nonce' ); ?>
						<input type="hidden" name="wpcal_pcc_action" value="clear_lock" />
						<button type="submit" class="button button-secondary">
							<?php esc_html_e( 'Force Clear Concurrency Lock', 'wpcalibrate-product-catalog-connector' ); ?>
						</button>
					</form>
					<span style="color: var(--wpcal-warning); font-size: 13px;">
						⚠️ <?php printf( /* translators: %s: run ID */ esc_html__( 'Active lock held by %s', 'wpcalibrate-product-catalog-connector' ), esc_html( (string) ( $lock_info['run_id'] ?? 'unknown' ) ) ); ?>
					</span>
				<?php endif; ?>
			</div>
		</div>

		<div class="wpcal-pcc-card">
			<h3 class="wpcal-pcc-card-title"><?php esc_html_e( 'Recent Synchronization Runs', 'wpcalibrate-product-catalog-connector' ); ?></h3>
			<?php if ( empty( $runs ) ) : ?>
				<p><?php esc_html_e( 'No synchronization runs recorded yet.', 'wpcalibrate-product-catalog-connector' ); ?></p>
			<?php else : ?>
				<div style="overflow-x: auto;">
					<table class="wpcal-pcc-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Run ID', 'wpcalibrate-product-catalog-connector' ); ?></th>
								<th><?php esc_html_e( 'Type', 'wpcalibrate-product-catalog-connector' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wpcalibrate-product-catalog-connector' ); ?></th>
								<th><?php esc_html_e( 'Scanned', 'wpcalibrate-product-catalog-connector' ); ?></th>
								<th><?php esc_html_e( 'Exported', 'wpcalibrate-product-catalog-connector' ); ?></th>
								<th><?php esc_html_e( 'Skipped', 'wpcalibrate-product-catalog-connector' ); ?></th>
								<th><?php esc_html_e( 'Errors', 'wpcalibrate-product-catalog-connector' ); ?></th>
								<th><?php esc_html_e( 'Started', 'wpcalibrate-product-catalog-connector' ); ?></th>
								<th><?php esc_html_e( 'Finished', 'wpcalibrate-product-catalog-connector' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $runs as $run ) : ?>
								<tr>
									<td><code><?php echo esc_html( (string) $run['run_id'] ); ?></code></td>
									<td><?php echo esc_html( ucfirst( (string) $run['run_type'] ) ); ?></td>
									<td>
										<span style="font-weight: 600; color: <?php echo 'completed' === $run['status'] ? 'var(--wpcal-success)' : ( 'failed' === $run['status'] ? 'var(--wpcal-error)' : 'var(--wpcal-warning)' ); ?>;">
											● <?php echo esc_html( ucfirst( (string) $run['status'] ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( (string) $run['items_scanned'] ); ?></td>
									<td><strong><?php echo esc_html( (string) $run['items_exported'] ); ?></strong></td>
									<td><?php echo esc_html( (string) $run['items_skipped'] ); ?></td>
									<td><?php echo esc_html( (string) $run['errors_count'] ); ?></td>
									<td><?php echo esc_html( (string) $run['started_at'] ); ?></td>
									<td><?php echo esc_html( (string) ( $run['finished_at'] ?: '—' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Tab 8: License Management.
	 */
	private function render_license_tab(): void {
		$masked_key = $this->license_manager->get_masked_key();
		$status     = $this->license_manager->get_status();
		?>
		<div class="wpcal-pcc-card">
			<h2 class="wpcal-pcc-card-title"><?php esc_html_e( 'License Management', 'wpcalibrate-product-catalog-connector' ); ?></h2>

			<div class="notice notice-info inline" style="margin: 0 0 20px 0;">
				<p>
					<?php esc_html_e( 'Note: Commercial licensing server details for WPCalibrate are pending configuration. The hosted catalog feed is fully functional without a license in this build.', 'wpcalibrate-product-catalog-connector' ); ?>
				</p>
			</div>

			<form method="post" action="">
				<?php wp_nonce_field( 'wpcal_pcc_license_nonce', 'wpcal_pcc_nonce' ); ?>
				<input type="hidden" name="wpcal_pcc_action" value="save_license" />

				<div class="wpcal-pcc-form-row">
					<label class="wpcal-pcc-label" for="wpcal_lic_key"><?php esc_html_e( 'WPCalibrate License Key', 'wpcalibrate-product-catalog-connector' ); ?></label>
					<input type="text" name="license_key" id="wpcal_lic_key" value="<?php echo esc_attr( $masked_key ); ?>" class="regular-text" placeholder="WPCAL-XXXX-XXXX-XXXX" />
				</div>

				<div class="wpcal-pcc-form-row">
					<strong><?php esc_html_e( 'License Status:', 'wpcalibrate-product-catalog-connector' ); ?></strong>
					<span style="color: var(--wpcal-muted);"><?php echo esc_html( ucfirst( (string) $status['status'] ) ); ?></span>
					<div class="wpcal-pcc-help"><?php echo esc_html( (string) $status['message'] ); ?></div>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary wpcal-btn-primary"><?php esc_html_e( 'Save License', 'wpcalibrate-product-catalog-connector' ); ?></button>
					<?php if ( ! empty( $masked_key ) ) : ?>
						<button type="submit" name="deactivate_license" value="1" class="button button-secondary"><?php esc_html_e( 'Clear License', 'wpcalibrate-product-catalog-connector' ); ?></button>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}
}

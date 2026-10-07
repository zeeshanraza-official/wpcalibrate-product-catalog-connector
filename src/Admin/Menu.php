<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles shared WPCalibrate top-level admin menu and plugin submenu registration.
 */
class Menu {
	public const PARENT_SLUG = 'wpcalibrate';
	public const PAGE_SLUG   = 'wpcalibrate-product-catalog-connector';

	private SettingsPage $settings_page;

	public function __construct( SettingsPage $settings_page ) {
		$this->settings_page = $settings_page;
	}

	/**
	 * Register admin menu hooks.
	 */
	public function register(): void {
		// Priority 9 ensures the top-level parent menu exists before child items are attached.
		add_action( 'admin_menu', [ $this, 'register_shared_parent_menu' ], 9 );
		add_action( 'admin_menu', [ $this, 'register_submenu' ], 10 );
		add_action( 'admin_head', [ $this, 'render_menu_icon_styles' ] );
		add_filter( 'plugin_action_links_' . WPCAL_PCC_BASENAME, [ $this, 'add_plugin_action_links' ] );
	}

	/**
	 * Enforce standard WordPress 20x20px menu icon dimensions across all admin screens.
	 */
	public function render_menu_icon_styles(): void {
		?>
		<style id="wpcal-pcc-menu-icon-css">
			#adminmenu .toplevel_page_wpcalibrate .wp-menu-image img,
			#adminmenu #toplevel_page_wpcalibrate .wp-menu-image img {
				width: 20px !important;
				height: 20px !important;
				max-width: 20px !important;
				max-height: 20px !important;
				padding: 7px 0 0 0 !important;
				margin: 0 auto !important;
				display: block !important;
				box-sizing: content-box !important;
				object-fit: contain !important;
				opacity: 0.85 !important;
			}
			#adminmenu .toplevel_page_wpcalibrate:hover .wp-menu-image img,
			#adminmenu .toplevel_page_wpcalibrate.wp-has-current-submenu .wp-menu-image img,
			#adminmenu .toplevel_page_wpcalibrate.current .wp-menu-image img {
				opacity: 1 !important;
			}
			#adminmenu .toplevel_page_wpcalibrate .wp-menu-image,
			#adminmenu #toplevel_page_wpcalibrate .wp-menu-image {
				width: 36px !important;
				height: 34px !important;
				overflow: hidden !important;
			}
		</style>
		<?php
	}

	/**
	 * Registers the shared WPCalibrate top-level menu safely if not already registered.
	 */
	public function register_shared_parent_menu(): void {
		global $menu;

		// Check if top-level 'wpcalibrate' menu is already present in $menu array.
		$parent_exists = false;
		if ( is_array( $menu ) ) {
			foreach ( $menu as $item ) {
				if ( isset( $item[2] ) && self::PARENT_SLUG === $item[2] ) {
					$parent_exists = true;
					break;
				}
			}
		}

		$capability = self::get_capability();

		if ( ! $parent_exists ) {
			// Use dedicated 20x20 menu icon
			$icon_url = WPCAL_PCC_URL . 'assets/branding/icon-menu.png';

			add_menu_page(
				'WPCalibrate',
				'WPCalibrate',
				$capability,
				self::PARENT_SLUG,
				static function (): void {
					// Fallback if top-level menu clicked directly.
					wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
					exit;
				},
				$icon_url,
				58.5
			);
		}
	}

	/**
	 * Determine the required capability, preferring manage_woocommerce with manage_options fallback.
	 */
	public static function get_capability(): string {
		return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Registers this plugin's dedicated submenu.
	 */
	public function register_submenu(): void {
		$hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'WPCalibrate Product Catalog Connector', 'wpcalibrate-product-catalog-connector' ),
			__( 'Catalog Connector', 'wpcalibrate-product-catalog-connector' ),
			self::get_capability(),
			self::PAGE_SLUG,
			[ $this->settings_page, 'render' ]
		);

		// If this is the first item under WPCalibrate and the parent item has slug 'wpcalibrate',
		// normalize the first submenu link title if needed.
		add_action(
			'admin_head',
			static function (): void {
				global $submenu;
				if ( isset( $submenu[ self::PARENT_SLUG ][0] ) && self::PARENT_SLUG === $submenu[ self::PARENT_SLUG ][0][2] ) {
					$submenu[ self::PARENT_SLUG ][0][0] = __( 'Dashboard', 'wpcalibrate-product-catalog-connector' );
				}
			}
		);
	}

	/**
	 * Add plugin action links on the Plugins administration screen.
	 *
	 * @param array<string, string> $links
	 * @return array<string, string>
	 */
	public function add_plugin_action_links( array $links ): array {
		$plugin_links = [
			'settings'    => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
				esc_html__( 'Settings', 'wpcalibrate-product-catalog-connector' )
			),
			'diagnostics' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=diagnostics' ) ),
				esc_html__( 'Diagnostics', 'wpcalibrate-product-catalog-connector' )
			),
			'support'     => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=support' ) ),
				esc_html__( 'Support', 'wpcalibrate-product-catalog-connector' )
			),
		];

		return array_merge( $plugin_links, $links );
	}
}

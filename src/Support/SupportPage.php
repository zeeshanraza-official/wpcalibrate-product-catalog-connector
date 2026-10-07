<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Support;

use WPCalibrate\ProductCatalogConnector\Compatibility\HPOS;
use WPCalibrate\ProductCatalogConnector\Compatibility\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles WPCalibrate support information and sanitized system report generation.
 */
class SupportPage {
	public const EMAIL       = 'support@wpcalibrate.com';
	public const WEBSITE     = 'https://wpcalibrate.com';
	public const MARKETPLACE = 'https://marketplace.wpcalibrate.com/';
	public const PHONE_WHATSAPP = '+447474795976';

	/**
	 * Render the Support tab content.
	 */
	public function render(): void {
		$system_info = self::generate_system_report();
		?>
		<div class="wpcal-pcc-card">
			<h2 class="wpcal-pcc-card-title"><?php esc_html_e( 'WPCalibrate Customer Support', 'wpcalibrate-product-catalog-connector' ); ?></h2>
			<p>
				<?php esc_html_e( 'Our dedicated engineering support team is available to assist you with catalog synchronization, feed optimization, or Pinterest integration issues.', 'wpcalibrate-product-catalog-connector' ); ?>
			</p>

			<div class="wpcal-pcc-grid wpcal-pcc-support-grid" style="margin-top: 20px;">
				<div class="wpcal-pcc-stat-box">
					<div class="wpcal-pcc-stat-label"><?php esc_html_e( 'Email Support', 'wpcalibrate-product-catalog-connector' ); ?></div>
					<div class="wpcal-pcc-support-value">
						<a href="<?php echo esc_url( 'mailto:' . self::EMAIL ); ?>"><?php echo esc_html( self::EMAIL ); ?></a>
					</div>
					<div class="wpcal-pcc-stat-desc"><?php esc_html_e( 'Standard 24-hour turnaround', 'wpcalibrate-product-catalog-connector' ); ?></div>
				</div>

				<div class="wpcal-pcc-stat-box">
					<div class="wpcal-pcc-stat-label"><?php esc_html_e( 'Phone / WhatsApp', 'wpcalibrate-product-catalog-connector' ); ?></div>
					<div class="wpcal-pcc-support-value">
						<a href="<?php echo esc_url( 'https://wa.me/' . str_replace( '+', '', self::PHONE_WHATSAPP ) ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( self::PHONE_WHATSAPP ); ?>
						</a>
					</div>
					<div class="wpcal-pcc-stat-desc"><?php esc_html_e( 'Direct WhatsApp assistance', 'wpcalibrate-product-catalog-connector' ); ?></div>
				</div>

				<div class="wpcal-pcc-stat-box">
					<div class="wpcal-pcc-stat-label"><?php esc_html_e( 'Official Website', 'wpcalibrate-product-catalog-connector' ); ?></div>
					<div class="wpcal-pcc-support-value">
						<a href="<?php echo esc_url( self::WEBSITE ); ?>" target="_blank" rel="noopener noreferrer">
							wpcalibrate.com
						</a>
					</div>
					<div class="wpcal-pcc-stat-desc"><?php esc_html_e( 'Guides, updates & products', 'wpcalibrate-product-catalog-connector' ); ?></div>
				</div>

				<div class="wpcal-pcc-stat-box">
					<div class="wpcal-pcc-stat-label"><?php esc_html_e( 'Marketplace & Licenses', 'wpcalibrate-product-catalog-connector' ); ?></div>
					<div class="wpcal-pcc-support-value">
						<a href="<?php echo esc_url( self::MARKETPLACE ); ?>" target="_blank" rel="noopener noreferrer">
							marketplace.wpcalibrate.com
						</a>
					</div>
					<div class="wpcal-pcc-stat-desc"><?php esc_html_e( 'Account & license portal', 'wpcalibrate-product-catalog-connector' ); ?></div>
				</div>
			</div>
		</div>

		<div class="wpcal-pcc-card">
			<h3 class="wpcal-pcc-card-title"><?php esc_html_e( 'System Diagnostic Report', 'wpcalibrate-product-catalog-connector' ); ?></h3>
			<p class="wpcal-pcc-help">
				<?php esc_html_e( 'When contacting technical support, include this sanitized environment summary to expedite debugging. No passwords, license keys, or sensitive customer details are included.', 'wpcalibrate-product-catalog-connector' ); ?>
			</p>
			<textarea readonly rows="12" style="width: 100%; font-family: monospace; font-size: 12px; background: #f6f7f7; padding: 12px; border-radius: 4px;" id="wpcal-pcc-sysinfo"><?php echo esc_textarea( $system_info ); ?></textarea>
			<div style="margin-top: 10px;">
				<button type="button" class="button wpcal-pcc-copy-btn" data-target="wpcal-pcc-sysinfo">
					<?php esc_html_e( 'Copy System Report', 'wpcalibrate-product-catalog-connector' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Generate sanitized system diagnostic report text.
	 */
	public static function generate_system_report(): string {
		global $wp_version;

		$lines = [];
		$lines[] = '### WPCalibrate Product Catalog Connector Environment ###';
		$lines[] = 'Plugin Version: ' . WPCAL_PCC_VERSION;
		$lines[] = 'PHP Version: ' . PHP_VERSION;
		$lines[] = 'WordPress Version: ' . ( $wp_version ?? 'Unknown' );
		$lines[] = 'WooCommerce Active: ' . ( WooCommerce::is_active() ? 'Yes' : 'No' );
		$lines[] = 'WooCommerce Version: ' . WooCommerce::get_version();
		$lines[] = 'HPOS Status: ' . HPOS::get_status_label();
		$lines[] = 'Server Software: ' . sanitize_text_field( $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' );
		$lines[] = 'Memory Limit: ' . ini_get( 'memory_limit' );
		$lines[] = 'Max Execution Time: ' . ini_get( 'max_execution_time' );
		$lines[] = 'Site URL: ' . ( function_exists( 'get_site_url' ) ? get_site_url() : '' );
		$lines[] = 'Home URL: ' . ( function_exists( 'home_url' ) ? home_url() : '' );
		$lines[] = 'WP-Cron Disabled: ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'Yes' : 'No' );
		$lines[] = 'Action Scheduler Active: ' . ( function_exists( 'as_enqueue_async_action' ) ? 'Yes' : 'No' );
		$lines[] = 'Report Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';

		return implode( "\n", $lines );
	}
}

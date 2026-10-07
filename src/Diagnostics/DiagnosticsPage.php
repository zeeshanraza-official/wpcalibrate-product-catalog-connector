<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Diagnostics;

use WPCalibrate\ProductCatalogConnector\Compatibility\HPOS;
use WPCalibrate\ProductCatalogConnector\Compatibility\WooCommerce;
use WPCalibrate\ProductCatalogConnector\Feed\FeedStorage;
use WPCalibrate\ProductCatalogConnector\Pinterest\FeedService;
use WPCalibrate\ProductCatalogConnector\Pinterest\TokenStore;
use WPCalibrate\ProductCatalogConnector\Settings\Settings;
use WPCalibrate\ProductCatalogConnector\Sync\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Diagnostics dashboard tab displaying environment status, sync history, and item issues.
 */
class DiagnosticsPage {
	/**
	 * Render Diagnostics tab.
	 */
	public function render(): void {
		global $wp_version;

		$settings   = Settings::get_all();
		$format     = (string) ( $settings['feed_format'] ?? 'csv' );
		$feed_info  = FeedStorage::get_feed_info( $format );
		$latest_run = SyncRunRepository::get_latest_run();
		$issues     = SyncRunRepository::get_issues( null, 30 );
		$token_store = new TokenStore();
		$next_sched  = Scheduler::get_next_scheduled_time();

		// Pinterest Ingestion result check if connected.
		$pinterest_results = null;
		if ( $token_store->is_connected() && ! empty( $settings['pinterest_feed_id'] ) ) {
			$feed_service = new FeedService();
			$remote_res   = $feed_service->get_processing_results( (string) $settings['pinterest_feed_id'] );
			if ( ! is_wp_error( $remote_res ) ) {
				$pinterest_results = $remote_res;
			}
		}
		?>
		<div class="wpcal-pcc-grid">
			<div class="wpcal-pcc-stat-box">
				<div class="wpcal-pcc-stat-label"><?php esc_html_e( 'Feed File Status', 'wpcalibrate-product-catalog-connector' ); ?></div>
				<div class="wpcal-pcc-stat-value" style="font-size: 18px;">
					<?php if ( $feed_info['exists'] ) : ?>
						<span style="color: var(--wpcal-success);">● <?php esc_html_e( 'Active & Available', 'wpcalibrate-product-catalog-connector' ); ?></span>
					<?php else : ?>
						<span style="color: var(--wpcal-error);">○ <?php esc_html_e( 'Not Generated', 'wpcalibrate-product-catalog-connector' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="wpcal-pcc-stat-desc">
					<?php
					if ( $feed_info['exists'] ) {
						printf(
							/* translators: 1: size in KB, 2: age in minutes */
							esc_html__( '%1$s KB • Updated %2$d min ago', 'wpcalibrate-product-catalog-connector' ),
							esc_html( (string) round( $feed_info['size'] / 1024, 1 ) ),
							esc_html( (string) round( $feed_info['age'] / 60 ) )
						);
					} else {
						esc_html_e( 'Awaiting initial catalog generation', 'wpcalibrate-product-catalog-connector' );
					}
					?>
				</div>
			</div>

			<div class="wpcal-pcc-stat-box">
				<div class="wpcal-pcc-stat-label"><?php esc_html_e( 'Scheduler Health', 'wpcalibrate-product-catalog-connector' ); ?></div>
				<div class="wpcal-pcc-stat-value" style="font-size: 18px;">
					<?php echo function_exists( 'as_enqueue_async_action' ) ? esc_html__( 'Action Scheduler', 'wpcalibrate-product-catalog-connector' ) : esc_html__( 'WP-Cron', 'wpcalibrate-product-catalog-connector' ); ?>
				</div>
				<div class="wpcal-pcc-stat-desc">
					<?php
					if ( $next_sched ) {
						printf(
							/* translators: %s: formatted date and time */
							esc_html__( 'Next job: %s', 'wpcalibrate-product-catalog-connector' ),
							esc_html( wp_date( 'Y-m-d H:i:s', $next_sched ) ?: '' )
						);
					} else {
						esc_html_e( 'No recurring jobs scheduled', 'wpcalibrate-product-catalog-connector' );
					}
					?>
				</div>
			</div>

			<div class="wpcal-pcc-stat-box">
				<div class="wpcal-pcc-stat-label"><?php esc_html_e( 'WooCommerce & HPOS', 'wpcalibrate-product-catalog-connector' ); ?></div>
				<div class="wpcal-pcc-stat-value" style="font-size: 18px;">
					<?php echo esc_html( WooCommerce::get_version() ); ?>
				</div>
				<div class="wpcal-pcc-stat-desc">
					<?php echo esc_html( HPOS::get_status_label() ); ?>
				</div>
			</div>

			<div class="wpcal-pcc-stat-box">
				<div class="wpcal-pcc-stat-label"><?php esc_html_e( 'Pinterest API Mode', 'wpcalibrate-product-catalog-connector' ); ?></div>
				<div class="wpcal-pcc-stat-value" style="font-size: 18px;">
					<?php if ( $token_store->is_connected() ) : ?>
						<span style="color: var(--wpcal-success);"><?php esc_html_e( 'Connected', 'wpcalibrate-product-catalog-connector' ); ?></span>
					<?php else : ?>
						<span style="color: var(--wpcal-muted);"><?php esc_html_e( 'Disconnected', 'wpcalibrate-product-catalog-connector' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="wpcal-pcc-stat-desc">
					<?php
					if ( ! empty( $settings['pinterest_feed_id'] ) ) {
						printf( /* translators: %s: feed ID */ esc_html__( 'Feed ID: %s', 'wpcalibrate-product-catalog-connector' ), esc_html( (string) $settings['pinterest_feed_id'] ) );
					} else {
						esc_html_e( 'Remote feed not registered', 'wpcalibrate-product-catalog-connector' );
					}
					?>
				</div>
			</div>
		</div>

		<?php if ( $pinterest_results && ! empty( $pinterest_results['items'] ) ) : ?>
			<div class="wpcal-pcc-card">
				<h3 class="wpcal-pcc-card-title"><?php esc_html_e( 'Pinterest Ingestion Diagnostics', 'wpcalibrate-product-catalog-connector' ); ?></h3>
				<table class="wpcal-pcc-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Processing ID', 'wpcalibrate-product-catalog-connector' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wpcalibrate-product-catalog-connector' ); ?></th>
							<th><?php esc_html_e( 'Created At', 'wpcalibrate-product-catalog-connector' ); ?></th>
							<th><?php esc_html_e( 'Ingested Count', 'wpcalibrate-product-catalog-connector' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( (array) $pinterest_results['items'], 0, 5 ) as $res ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) ( $res['id'] ?? 'N/A' ) ); ?></code></td>
								<td><span class="wpcal-pcc-badge wpcal-pcc-badge-v1"><?php echo esc_html( (string) ( $res['status'] ?? 'UNKNOWN' ) ); ?></span></td>
								<td><?php echo esc_html( (string) ( $res['created_at'] ?? 'N/A' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $res['product_counts']['ingested'] ?? '0' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<div class="wpcal-pcc-card">
			<h3 class="wpcal-pcc-card-title"><?php esc_html_e( 'Recent Product Diagnostic Issues & Exclusions', 'wpcalibrate-product-catalog-connector' ); ?></h3>
			<p class="wpcal-pcc-help">
				<?php esc_html_e( 'Specific warnings and exclusions recorded during catalog generation (e.g. missing images, excluded by category rule, invalid URLs).', 'wpcalibrate-product-catalog-connector' ); ?>
			</p>
			<?php if ( empty( $issues ) ) : ?>
				<p style="color: var(--wpcal-success); font-weight: 500;">✓ <?php esc_html_e( 'No product issues or exclusions currently recorded.', 'wpcalibrate-product-catalog-connector' ); ?></p>
			<?php else : ?>
				<div style="overflow-x: auto;">
					<table class="wpcal-pcc-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Time', 'wpcalibrate-product-catalog-connector' ); ?></th>
								<th><?php esc_html_e( 'Product ID', 'wpcalibrate-product-catalog-connector' ); ?></th>
								<th><?php esc_html_e( 'SKU', 'wpcalibrate-product-catalog-connector' ); ?></th>
								<th><?php esc_html_e( 'Issue Type', 'wpcalibrate-product-catalog-connector' ); ?></th>
								<th><?php esc_html_e( 'Severity', 'wpcalibrate-product-catalog-connector' ); ?></th>
								<th><?php esc_html_e( 'Message', 'wpcalibrate-product-catalog-connector' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $issues as $issue ) : ?>
								<tr>
									<td><?php echo esc_html( (string) $issue['created_at'] ); ?></td>
									<td>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $issue['product_id'] . '&action=edit' ) ); ?>" target="_blank">
											#<?php echo esc_html( (string) $issue['product_id'] ); ?>
										</a>
									</td>
									<td><?php echo esc_html( (string) ( $issue['product_sku'] ?: '—' ) ); ?></td>
									<td><code><?php echo esc_html( (string) $issue['issue_type'] ); ?></code></td>
									<td>
										<span style="font-weight: 600; color: <?php echo 'warning' === $issue['severity'] ? 'var(--wpcal-warning)' : 'var(--wpcal-muted)'; ?>;">
											<?php echo esc_html( strtoupper( (string) $issue['severity'] ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( (string) $issue['message'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}

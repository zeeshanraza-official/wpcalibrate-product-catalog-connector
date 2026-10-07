<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Sync;

use WPCalibrate\ProductCatalogConnector\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages recurring scheduled feed regeneration via Action Scheduler or WP-Cron.
 */
class Scheduler {
	public const HOOK_NAME = 'wpcal_pcc_scheduled_sync';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'register_cron_intervals' ] );
		add_action( self::HOOK_NAME, [ $this, 'run_scheduled_sync' ] );
	}

	/**
	 * Register custom WP-Cron intervals.
	 *
	 * @param array<string, array<string, mixed>> $schedules
	 * @return array<string, array<string, mixed>>
	 */
	public function register_cron_intervals( array $schedules ): array {
		if ( ! isset( $schedules['twelve_hours'] ) ) {
			$schedules['twelve_hours'] = [
				'interval' => 12 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 12 Hours', 'wpcalibrate-product-catalog-connector' ),
			];
		}

		if ( ! isset( $schedules['twice_weekly'] ) ) {
			$schedules['twice_weekly'] = [
				'interval' => 3.5 * DAY_IN_SECONDS,
				'display'  => __( 'Twice Weekly', 'wpcalibrate-product-catalog-connector' ),
			];
		}

		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = [
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'wpcalibrate-product-catalog-connector' ),
			];
		}

		return $schedules;
	}

	/**
	 * Reconfigure scheduled recurring generation based on settings.
	 */
	public static function reschedule(): void {
		$schedule = (string) Settings::get( 'schedule', 'daily' );

		// Clear existing scheduled actions.
		wp_clear_scheduled_hook( self::HOOK_NAME );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_NAME, [], 'wpcalibrate-pcc' );
		}

		if ( 'manual' === $schedule ) {
			return;
		}

		$interval_seconds = match ( $schedule ) {
			'twelve_hours' => 12 * HOUR_IN_SECONDS,
			'twice_weekly' => (int) ( 3.5 * DAY_IN_SECONDS ),
			'weekly'       => 7 * DAY_IN_SECONDS,
			default        => 24 * HOUR_IN_SECONDS, // daily
		};

		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			as_schedule_recurring_action(
				time() + $interval_seconds,
				$interval_seconds,
				self::HOOK_NAME,
				[],
				'wpcalibrate-pcc'
			);
		} else {
			$cron_recurrence = match ( $schedule ) {
				'twelve_hours' => 'twelve_hours',
				'twice_weekly' => 'twice_weekly',
				'weekly'       => 'weekly',
				default        => 'daily',
			};
			wp_schedule_event( time() + $interval_seconds, $cron_recurrence, self::HOOK_NAME );
		}
	}

	/**
	 * Callback executed when scheduled time arrives.
	 */
	public function run_scheduled_sync(): void {
		$processor = new BatchProcessor();
		$processor->start_sync( 'scheduled' );
	}

	/**
	 * Get next scheduled execution timestamp.
	 */
	public static function get_next_scheduled_time(): ?int {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( self::HOOK_NAME, [], 'wpcalibrate-pcc' );
			if ( $next ) {
				return (int) $next;
			}
		}

		$wp_next = wp_next_scheduled( self::HOOK_NAME );
		return $wp_next ? (int) $wp_next : null;
	}
}

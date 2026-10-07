<?php
declare(strict_types=1);

namespace WPCalibrate\ProductCatalogConnector\Activation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles database table installation and versioned schema migrations.
 */
class Migrator {
	public const DB_VERSION = '1.0.0';
	public const DB_VERSION_OPTION = 'wpcal_pcc_db_version';

	/**
	 * Run schema migrations if required.
	 */
	public static function migrate(): void {
		$current_version = get_option( self::DB_VERSION_OPTION, '0.0.0' );

		if ( version_compare( (string) $current_version, self::DB_VERSION, '<' ) ) {
			self::install_tables();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		}
	}

	/**
	 * Create or update custom database tables using dbDelta().
	 */
	public static function install_tables(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade_path = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( file_exists( $upgrade_path ) ) {
				require_once $upgrade_path;
			}
		}

		$charset_collate = $wpdb->get_charset_collate();

		$sync_runs_table = $wpdb->prefix . 'wpcal_pcc_sync_runs';
		$item_issues_table = $wpdb->prefix . 'wpcal_pcc_item_issues';

		$sql_sync_runs = "CREATE TABLE {$sync_runs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id varchar(64) NOT NULL,
			run_type varchar(32) NOT NULL DEFAULT 'manual',
			status varchar(32) NOT NULL DEFAULT 'running',
			items_scanned int(11) unsigned NOT NULL DEFAULT 0,
			items_exported int(11) unsigned NOT NULL DEFAULT 0,
			items_skipped int(11) unsigned NOT NULL DEFAULT 0,
			warnings_count int(11) unsigned NOT NULL DEFAULT 0,
			errors_count int(11) unsigned NOT NULL DEFAULT 0,
			checksum varchar(64) NOT NULL DEFAULT '',
			file_format varchar(16) NOT NULL DEFAULT 'csv',
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			message text NULL,
			started_at datetime NOT NULL,
			finished_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY run_id (run_id),
			KEY status (status),
			KEY started_at (started_at)
		) {$charset_collate};";

		$sql_item_issues = "CREATE TABLE {$item_issues_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id varchar(64) NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			product_sku varchar(100) NOT NULL DEFAULT '',
			issue_type varchar(64) NOT NULL,
			severity varchar(16) NOT NULL DEFAULT 'warning',
			message text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY product_id (product_id),
			KEY issue_type (issue_type)
		) {$charset_collate};";

		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql_sync_runs );
			dbDelta( $sql_item_issues );
		}
	}

	/**
	 * Drop tables if requested on uninstall.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		$sync_runs_table = $wpdb->prefix . 'wpcal_pcc_sync_runs';
		$item_issues_table = $wpdb->prefix . 'wpcal_pcc_item_issues';

		$wpdb->query( "DROP TABLE IF EXISTS {$sync_runs_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$item_issues_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( self::DB_VERSION_OPTION );
	}
}

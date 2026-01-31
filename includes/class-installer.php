<?php
/**
 * Installation and database schema
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

class Installer {

	/**
	 * Run on plugin activation
	 */
	public static function activate() {
		self::create_tables();
		self::create_options();
		self::maybe_migrate_database();

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Run on plugin deactivation
	 */
	public static function deactivate() {
		// Cleanup if needed
		flush_rewrite_rules();
	}

	/**
	 * Create database tables
	 */
	private static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Table for email events (SNS notifications)
		$table_events = $wpdb->prefix . 'ses_email_events';

		$sql_events = "CREATE TABLE IF NOT EXISTS $table_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			message_id varchar(255) NOT NULL,
			notification_type varchar(50) NOT NULL,
			event_type varchar(50) NOT NULL,
			event_source varchar(50) DEFAULT 'sns_notification',
			recipient varchar(255) NOT NULL,
			sender varchar(255) NOT NULL,
			subject varchar(500) DEFAULT NULL,
			bounce_type varchar(50) DEFAULT NULL,
			bounce_subtype varchar(50) DEFAULT NULL,
			complaint_type varchar(50) DEFAULT NULL,
			diagnostic_code text DEFAULT NULL,
			smtp_response text DEFAULT NULL,
			event_metadata longtext DEFAULT NULL,
			timestamp datetime NOT NULL,
			raw_payload longtext DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY message_id (message_id),
			KEY notification_type (notification_type),
			KEY event_type (event_type),
			KEY event_source (event_source),
			KEY recipient (recipient),
			KEY timestamp (timestamp)
		) $charset_collate;";

		// Table for email tracking (opens, clicks)
		$table_tracking = $wpdb->prefix . 'ses_email_tracking';

		$sql_tracking = "CREATE TABLE IF NOT EXISTS $table_tracking (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			message_id varchar(255) NOT NULL,
			tracking_type varchar(20) NOT NULL,
			recipient varchar(255) NOT NULL,
			url varchar(1000) DEFAULT NULL,
			user_agent varchar(500) DEFAULT NULL,
			ip_address varchar(45) DEFAULT NULL,
			timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY message_id (message_id),
			KEY tracking_type (tracking_type),
			KEY recipient (recipient),
			KEY timestamp (timestamp)
		) $charset_collate;";

		// Table for global unsubscribe list
		$table_unsubscribes = $wpdb->prefix . 'ses_unsubscribes';

		$sql_unsubscribes = "CREATE TABLE IF NOT EXISTS $table_unsubscribes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(255) NOT NULL,
			reason varchar(500) DEFAULT NULL,
			unsubscribed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY email (email),
			KEY unsubscribed_at (unsubscribed_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_events );
		dbDelta( $sql_tracking );
		dbDelta( $sql_unsubscribes );

		// Store schema version
		update_option( 'ses_sns_tracker_db_version', '1.2.0' );
	}

	/**
	 * Create default options
	 */
	private static function create_options() {
		// Check if old settings exist
		$old_settings = get_option( 'ses_sns_tracker_settings' );
		
		if ( $old_settings ) {
			// Migrate old settings to new option name
			$migrated_settings = $old_settings;
			
			// Add new Day 3 settings with defaults
			if ( ! isset( $migrated_settings['enable_manual_tracking'] ) ) {
				$migrated_settings['enable_manual_tracking'] = '1';
			}
			if ( ! isset( $migrated_settings['use_ses_native_tracking'] ) ) {
				$migrated_settings['use_ses_native_tracking'] = '0';
			}
			if ( ! isset( $migrated_settings['tracking_strategy'] ) ) {
				$migrated_settings['tracking_strategy'] = 'prefer_ses';
			}
			if ( ! isset( $migrated_settings['enable_unsubscribe_filter'] ) ) {
				$migrated_settings['enable_unsubscribe_filter'] = '1';
			}
			
			update_option( 'sessypress_settings', $migrated_settings );
		} else {
			// Create new settings
			$defaults = array(
				'sns_secret_key'            => wp_generate_password( 32, false ),
				'track_opens'               => '1',
				'track_clicks'              => '1',
				'sns_endpoint_slug'         => 'ses-sns-webhook',
				'retention_days'            => 90,
				'enable_manual_tracking'    => '1',
				'use_ses_native_tracking'   => '0',
				'tracking_strategy'         => 'prefer_ses',
				'enable_unsubscribe_filter' => '1',
			);

			add_option( 'sessypress_settings', $defaults );
		}
	}

	/**
	 * Migrate database schema if needed
	 */
	private static function maybe_migrate_database() {
		$current_version = get_option( 'ses_sns_tracker_db_version', '0.0.0' );
		$target_version  = '1.2.0';

		if ( version_compare( $current_version, '1.1.0', '<' ) ) {
			self::migrate_to_1_1_0();
		}

		if ( version_compare( $current_version, '1.2.0', '<' ) ) {
			self::migrate_to_1_2_0();
		}

		update_option( 'ses_sns_tracker_db_version', $target_version );
	}

	/**
	 * Migrate to version 1.1.0
	 * Add event_source and event_metadata columns
	 */
	private static function migrate_to_1_1_0() {
		global $wpdb;

		$table = $wpdb->prefix . 'ses_email_events';

		// Check if columns already exist
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_col( "DESCRIBE $table" );

		if ( ! in_array( 'event_source', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE $table ADD COLUMN event_source VARCHAR(50) DEFAULT 'sns_notification' AFTER event_type" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE $table ADD INDEX idx_event_source (event_source)" );

			// Set event_source for existing records
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "UPDATE $table SET event_source = 'sns_notification' WHERE event_source IS NULL" );
		}

		if ( ! in_array( 'event_metadata', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE $table ADD COLUMN event_metadata LONGTEXT DEFAULT NULL AFTER smtp_response" );
		}
	}

	/**
	 * Migrate to version 1.2.0
	 * Add unsubscribe table and update settings
	 */
	private static function migrate_to_1_2_0() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Create unsubscribe table if it doesn't exist
		$table_unsubscribes = $wpdb->prefix . 'ses_unsubscribes';

		$sql = "CREATE TABLE IF NOT EXISTS $table_unsubscribes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(255) NOT NULL,
			reason varchar(500) DEFAULT NULL,
			unsubscribed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY email (email),
			KEY unsubscribed_at (unsubscribed_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Update settings with new Day 3 options
		$settings = get_option( 'sessypress_settings', array() );
		
		if ( ! isset( $settings['enable_manual_tracking'] ) ) {
			$settings['enable_manual_tracking'] = '1';
		}
		if ( ! isset( $settings['use_ses_native_tracking'] ) ) {
			$settings['use_ses_native_tracking'] = '0';
		}
		if ( ! isset( $settings['tracking_strategy'] ) ) {
			$settings['tracking_strategy'] = 'prefer_ses';
		}
		if ( ! isset( $settings['enable_unsubscribe_filter'] ) ) {
			$settings['enable_unsubscribe_filter'] = '1';
		}

		update_option( 'sessypress_settings', $settings );
	}
}

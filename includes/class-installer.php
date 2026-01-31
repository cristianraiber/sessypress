<?php
/**
 * Installation and database schema
 */

namespace SES_SNS_Tracker;

defined( 'ABSPATH' ) || exit;

class Installer {

	/**
	 * Run on plugin activation
	 */
	public static function activate() {
		self::create_tables();
		self::create_options();

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
			recipient varchar(255) NOT NULL,
			sender varchar(255) NOT NULL,
			subject varchar(500) DEFAULT NULL,
			bounce_type varchar(50) DEFAULT NULL,
			bounce_subtype varchar(50) DEFAULT NULL,
			complaint_type varchar(50) DEFAULT NULL,
			diagnostic_code text DEFAULT NULL,
			smtp_response text DEFAULT NULL,
			timestamp datetime NOT NULL,
			raw_payload longtext DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY message_id (message_id),
			KEY notification_type (notification_type),
			KEY event_type (event_type),
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

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_events );
		dbDelta( $sql_tracking );

		// Store schema version
		update_option( 'ses_sns_tracker_db_version', '1.0.0' );
	}

	/**
	 * Create default options
	 */
	private static function create_options() {
		$defaults = array(
			'sns_secret_key'     => wp_generate_password( 32, false ),
			'track_opens'        => '1',
			'track_clicks'       => '1',
			'sns_endpoint_slug'  => 'ses-sns-webhook',
			'retention_days'     => 90,
		);

		add_option( 'ses_sns_tracker_settings', $defaults );
	}
}

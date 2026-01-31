<?php
/**
 * Handle email opens and link clicks tracking
 *
 * @package SESSYPress
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

/**
 * Email tracking handler
 */
class Tracker {

	/**
	 * Handle tracking request
	 */
	public function handle_tracking_request() {
		$action = isset( $_GET['ses_action'] ) ? sanitize_text_field( wp_unslash( $_GET['ses_action'] ) ) : '';

		switch ( $action ) {
			case 'open':
				$this->track_open();
				break;

			case 'click':
				$this->track_click();
				break;

			// Unsubscribe is now handled separately by Unsubscribe_Manager
		}
	}

	/**
	 * Track email open
	 */
	private function track_open() {
		$message_id = isset( $_GET['mid'] ) ? sanitize_text_field( wp_unslash( $_GET['mid'] ) ) : '';
		$recipient  = isset( $_GET['r'] ) ? sanitize_email( wp_unslash( $_GET['r'] ) ) : '';

		if ( ! $message_id || ! $recipient ) {
			$this->send_pixel();
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ses_email_tracking';

		// Check if already tracked
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table WHERE message_id = %s AND recipient = %s AND tracking_type = 'open' LIMIT 1",
			$message_id,
			$recipient
		) );

		if ( ! $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$table,
				array(
					'message_id'    => $message_id,
					'tracking_type' => 'open',
					'recipient'     => $recipient,
					'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
					'ip_address'    => $this->get_ip_address(),
				),
				array( '%s', '%s', '%s', '%s', '%s' )
			);
		}

		$this->send_pixel();
	}

	/**
	 * Track link click with campaign analytics
	 */
	private function track_click() {
		$message_id = isset( $_GET['mid'] ) ? sanitize_text_field( wp_unslash( $_GET['mid'] ) ) : '';
		$recipient  = isset( $_GET['r'] ) ? sanitize_email( wp_unslash( $_GET['r'] ) ) : '';
		$url        = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : '';

		if ( ! $message_id || ! $recipient || ! $url ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		// Use Link_Analytics to store click with UTM tracking
		$link_analytics = new Link_Analytics();
		$metadata       = array(
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'ip_address' => $this->get_ip_address(),
		);

		$link_analytics->store_click_with_campaign( $message_id, $recipient, $url, $metadata );

		// Redirect to the original URL
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Send 1x1 transparent pixel
	 */
	private function send_pixel() {
		header( 'Content-Type: image/gif' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );

		// 1x1 transparent GIF
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
		exit;
	}

	/**
	 * Get client IP address
	 */
	private function get_ip_address() {
		$ip = '';

		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return $ip;
	}
}

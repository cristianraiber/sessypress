<?php
/**
 * Link Analytics - UTM Tracking
 *
 * @package SESSYPress
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

/**
 * Track and analyze link clicks with UTM parameters
 */
class Link_Analytics {

	/**
	 * Extract UTM parameters from URL
	 *
	 * @param string $url URL to extract UTM parameters from.
	 * @return array Array of UTM parameters.
	 */
	public function extract_utm_params( $url ) {
		$utm_params = array(
			'utm_source'   => '',
			'utm_medium'   => '',
			'utm_campaign' => '',
			'utm_term'     => '',
			'utm_content'  => '',
		);

		$parsed_url = wp_parse_url( $url );

		if ( ! isset( $parsed_url['query'] ) ) {
			return $utm_params;
		}

		parse_str( $parsed_url['query'], $query_params );

		foreach ( $utm_params as $key => $value ) {
			if ( isset( $query_params[ $key ] ) ) {
				$utm_params[ $key ] = sanitize_text_field( $query_params[ $key ] );
			}
		}

		return $utm_params;
	}

	/**
	 * Store click event with campaign metadata
	 *
	 * @param string $message_id Message ID.
	 * @param string $recipient  Recipient email.
	 * @param string $url        Clicked URL.
	 * @param array  $metadata   Additional metadata.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public function store_click_with_campaign( $message_id, $recipient, $url, $metadata = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ses_email_tracking';

		// Extract UTM parameters.
		$utm_params = $this->extract_utm_params( $url );

		// Merge with existing metadata.
		$metadata = array_merge( $metadata, $utm_params );

		// Store in tracking table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert(
			$table,
			array(
				'message_id'    => sanitize_text_field( $message_id ),
				'tracking_type' => 'click',
				'recipient'     => sanitize_email( $recipient ),
				'url'           => esc_url_raw( $url ),
				'user_agent'    => isset( $metadata['user_agent'] ) ? sanitize_text_field( $metadata['user_agent'] ) : '',
				'ip_address'    => isset( $metadata['ip_address'] ) ? sanitize_text_field( $metadata['ip_address'] ) : $this->get_client_ip(),
				'timestamp'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $result ) {
			return false;
		}

		$insert_id = $wpdb->insert_id;

		// Store UTM metadata if present.
		if ( ! empty( array_filter( $utm_params ) ) ) {
			$this->store_campaign_metadata( $insert_id, $utm_params );
		}

		return $insert_id;
	}

	/**
	 * Store campaign metadata in separate meta table
	 *
	 * @param int   $tracking_id Tracking record ID.
	 * @param array $utm_params  UTM parameters.
	 * @return bool True on success.
	 */
	private function store_campaign_metadata( $tracking_id, $utm_params ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ses_email_tracking';

		// For now, we'll store as JSON in a comment field.
		// In future, we could create a separate meta table.
		// This is a simplified approach for Day 3.

		// Update the record with campaign data in a custom field.
		// Since we don't have a meta field yet, we'll use wp_options as a workaround.
		update_option( "ses_campaign_meta_{$tracking_id}", $utm_params, false );

		return true;
	}

	/**
	 * Get clicks by campaign
	 *
	 * @param string $campaign Campaign name.
	 * @return int Number of clicks for the campaign.
	 */
	public function get_clicks_by_campaign( $campaign ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ses_email_tracking';

		// Search for campaign in URL.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE tracking_type = 'click' AND url LIKE %s",
				'%utm_campaign=' . $wpdb->esc_like( $campaign ) . '%'
			)
		);

		return (int) $count;
	}

	/**
	 * Get top campaigns by click count
	 *
	 * @param int $limit Number of campaigns to retrieve.
	 * @return array Array of campaigns with click counts.
	 */
	public function get_top_campaigns( $limit = 10 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ses_email_tracking';
		$limit = absint( $limit );

		// Extract campaign from URL and group by it.
		// This is a simplified query - in production you'd want a dedicated campaign table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					url,
					COUNT(*) as click_count
				FROM $table
				WHERE tracking_type = 'click' 
				  AND url LIKE %s
				GROUP BY url
				ORDER BY click_count DESC
				LIMIT %d",
				'%utm_campaign=%',
				$limit
			)
		);

		// Extract campaign names.
		$campaigns = array();

		foreach ( $results as $row ) {
			$utm_params = $this->extract_utm_params( $row->url );

			if ( ! empty( $utm_params['utm_campaign'] ) ) {
				$campaign_name = $utm_params['utm_campaign'];

				if ( ! isset( $campaigns[ $campaign_name ] ) ) {
					$campaigns[ $campaign_name ] = array(
						'campaign'    => $campaign_name,
						'clicks'      => 0,
						'utm_source'  => $utm_params['utm_source'],
						'utm_medium'  => $utm_params['utm_medium'],
						'utm_content' => $utm_params['utm_content'],
					);
				}

				$campaigns[ $campaign_name ]['clicks'] += (int) $row->click_count;
			}
		}

		// Sort by clicks descending.
		uasort( $campaigns, function ( $a, $b ) {
			return $b['clicks'] - $a['clicks'];
		} );

		return array_slice( $campaigns, 0, $limit );
	}

	/**
	 * Get campaign performance overview
	 *
	 * @param string $campaign Campaign name.
	 * @return array Campaign statistics.
	 */
	public function get_campaign_stats( $campaign ) {
		global $wpdb;

		$tracking_table = $wpdb->prefix . 'ses_email_tracking';

		// Get total clicks.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$clicks = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $tracking_table 
				WHERE tracking_type = 'click' 
				  AND url LIKE %s",
				'%utm_campaign=' . $wpdb->esc_like( $campaign ) . '%'
			)
		);

		// Get unique clickers.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$unique_clickers = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT recipient) FROM $tracking_table 
				WHERE tracking_type = 'click' 
				  AND url LIKE %s",
				'%utm_campaign=' . $wpdb->esc_like( $campaign ) . '%'
			)
		);

		// Get most clicked links.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$top_links = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url, COUNT(*) as click_count 
				FROM $tracking_table 
				WHERE tracking_type = 'click' 
				  AND url LIKE %s
				GROUP BY url 
				ORDER BY click_count DESC 
				LIMIT 5",
				'%utm_campaign=' . $wpdb->esc_like( $campaign ) . '%'
			)
		);

		return array(
			'campaign'        => $campaign,
			'total_clicks'    => (int) $clicks,
			'unique_clickers' => (int) $unique_clickers,
			'top_links'       => $top_links,
		);
	}

	/**
	 * Get client IP address
	 *
	 * @return string IP address.
	 */
	private function get_client_ip() {
		$ip_keys = array(
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		foreach ( $ip_keys as $key ) {
			if ( isset( $_SERVER[ $key ] ) && filter_var( wp_unslash( $_SERVER[ $key ] ), FILTER_VALIDATE_IP ) ) {
				return sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			}
		}

		return '';
	}

	/**
	 * Get click timeline for campaign
	 *
	 * @param string $campaign Campaign name.
	 * @param int    $days     Number of days to analyze.
	 * @return array Timeline data.
	 */
	public function get_campaign_timeline( $campaign, $days = 7 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ses_email_tracking';
		$days  = absint( $days );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					DATE(timestamp) as date,
					COUNT(*) as clicks
				FROM $table
				WHERE tracking_type = 'click'
				  AND url LIKE %s
				  AND timestamp >= DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY DATE(timestamp)
				ORDER BY date ASC",
				'%utm_campaign=' . $wpdb->esc_like( $campaign ) . '%',
				$days
			)
		);

		return $results;
	}
}

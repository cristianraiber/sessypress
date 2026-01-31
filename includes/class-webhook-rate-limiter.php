<?php
/**
 * Webhook Rate Limiter - Protects against webhook abuse
 *
 * @package SESSYPress
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

class Webhook_Rate_Limiter {

	/**
	 * Rate limit: max requests per minute per IP
	 */
	const MAX_REQUESTS_PER_MINUTE = 300;

	/**
	 * Rate limit: max requests per hour per IP
	 */
	const MAX_REQUESTS_PER_HOUR = 3000;

	/**
	 * Cache key prefix
	 */
	const CACHE_PREFIX = 'sessypress_webhook_rate_';

	/**
	 * Check if request should be rate limited
	 *
	 * @param string $ip IP address
	 * @return bool True if request should be allowed, false if rate limited
	 */
	public function check( $ip ) {
		// Sanitize IP
		$ip = sanitize_text_field( $ip );

		// Check minute limit
		if ( ! $this->check_limit( $ip, 'minute', self::MAX_REQUESTS_PER_MINUTE, 60 ) ) {
			error_log( 'SESSYPress: Rate limit exceeded (minute) for IP: ' . $ip );
			return false;
		}

		// Check hour limit
		if ( ! $this->check_limit( $ip, 'hour', self::MAX_REQUESTS_PER_HOUR, 3600 ) ) {
			error_log( 'SESSYPress: Rate limit exceeded (hour) for IP: ' . $ip );
			return false;
		}

		return true;
	}

	/**
	 * Check specific rate limit
	 *
	 * @param string $ip IP address
	 * @param string $window Time window (minute/hour)
	 * @param int    $max_requests Maximum requests allowed
	 * @param int    $window_seconds Window duration in seconds
	 * @return bool True if under limit
	 */
	private function check_limit( $ip, $window, $max_requests, $window_seconds ) {
		$cache_key = self::CACHE_PREFIX . md5( $ip . $window );

		// Get current count
		$count = get_transient( $cache_key );

		if ( false === $count ) {
			// First request in this window
			set_transient( $cache_key, 1, $window_seconds );
			return true;
		}

		// Check if over limit
		if ( $count >= $max_requests ) {
			return false;
		}

		// Increment counter
		set_transient( $cache_key, $count + 1, $window_seconds );
		return true;
	}

	/**
	 * Get current rate limit status for an IP
	 *
	 * @param string $ip IP address
	 * @return array Status with minute/hour counts
	 */
	public function get_status( $ip ) {
		$ip = sanitize_text_field( $ip );

		return array(
			'minute' => array(
				'count' => (int) get_transient( self::CACHE_PREFIX . md5( $ip . 'minute' ) ),
				'limit' => self::MAX_REQUESTS_PER_MINUTE,
			),
			'hour'   => array(
				'count' => (int) get_transient( self::CACHE_PREFIX . md5( $ip . 'hour' ) ),
				'limit' => self::MAX_REQUESTS_PER_HOUR,
			),
		);
	}

	/**
	 * Clear rate limits for an IP (admin override)
	 *
	 * @param string $ip IP address
	 */
	public function clear( $ip ) {
		$ip = sanitize_text_field( $ip );
		delete_transient( self::CACHE_PREFIX . md5( $ip . 'minute' ) );
		delete_transient( self::CACHE_PREFIX . md5( $ip . 'hour' ) );
	}
}

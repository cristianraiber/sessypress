<?php
/**
 * AWS IP Validator - Validates requests come from AWS IP ranges
 *
 * @package SESSYPress
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

class AWS_IP_Validator {

	/**
	 * AWS IP ranges URL
	 */
	const IP_RANGES_URL = 'https://ip-ranges.amazonaws.com/ip-ranges.json';

	/**
	 * Cache key for IP ranges
	 */
	const CACHE_KEY = 'sessypress_aws_ip_ranges';

	/**
	 * Cache expiration (24 hours)
	 */
	const CACHE_EXPIRATION = DAY_IN_SECONDS;

	/**
	 * Validate if request IP is from AWS
	 *
	 * @param string $ip IP address to validate
	 * @param string $service AWS service (default: 'AMAZON' for all AWS IPs)
	 * @return bool True if IP is from AWS
	 */
	public function is_aws_ip( $ip, $service = 'AMAZON' ) {
		// Validate IP format
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			error_log( 'SESSYPress: Invalid IP format: ' . $ip );
			return false;
		}

		// Get AWS IP ranges
		$ranges = $this->get_ip_ranges();
		if ( ! $ranges ) {
			// If we can't get ranges, log warning but allow request (fail open)
			error_log( 'SESSYPress: Failed to load AWS IP ranges, allowing request' );
			return true;
		}

		// Check IPv4
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return $this->check_ipv4( $ip, $ranges['ipv4'], $service );
		}

		// Check IPv6
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $this->check_ipv6( $ip, $ranges['ipv6'], $service );
		}

		return false;
	}

	/**
	 * Get AWS IP ranges (with caching)
	 *
	 * @return array|false Array with ipv4/ipv6 ranges or false on failure
	 */
	private function get_ip_ranges() {
		// Try cache first
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		// Download fresh ranges
		$response = wp_remote_get(
			self::IP_RANGES_URL,
			array(
				'timeout'    => 10,
				'user-agent' => 'SESSYPress/' . SESSYPRESS_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'SESSYPress: Failed to download AWS IP ranges: ' . $response->get_error_message() );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! $data || ! isset( $data['prefixes'] ) ) {
			error_log( 'SESSYPress: Invalid AWS IP ranges format' );
			return false;
		}

		// Parse ranges
		$ranges = array(
			'ipv4' => array(),
			'ipv6' => array(),
		);

		// IPv4 prefixes
		if ( isset( $data['prefixes'] ) ) {
			foreach ( $data['prefixes'] as $prefix ) {
				$ranges['ipv4'][] = array(
					'cidr'    => $prefix['ip_prefix'],
					'service' => isset( $prefix['service'] ) ? $prefix['service'] : 'AMAZON',
					'region'  => isset( $prefix['region'] ) ? $prefix['region'] : 'GLOBAL',
				);
			}
		}

		// IPv6 prefixes
		if ( isset( $data['ipv6_prefixes'] ) ) {
			foreach ( $data['ipv6_prefixes'] as $prefix ) {
				$ranges['ipv6'][] = array(
					'cidr'    => $prefix['ipv6_prefix'],
					'service' => isset( $prefix['service'] ) ? $prefix['service'] : 'AMAZON',
					'region'  => isset( $prefix['region'] ) ? $prefix['region'] : 'GLOBAL',
				);
			}
		}

		// Cache for 24 hours
		set_transient( self::CACHE_KEY, $ranges, self::CACHE_EXPIRATION );

		return $ranges;
	}

	/**
	 * Check if IPv4 address is in ranges
	 *
	 * @param string $ip IPv4 address
	 * @param array  $ranges IPv4 ranges
	 * @param string $service Service filter
	 * @return bool True if IP is in ranges
	 */
	private function check_ipv4( $ip, $ranges, $service ) {
		$ip_long = ip2long( $ip );
		if ( false === $ip_long ) {
			return false;
		}

		foreach ( $ranges as $range ) {
			// Filter by service if not AMAZON (all)
			if ( 'AMAZON' !== $service && $range['service'] !== $service ) {
				continue;
			}

			// Parse CIDR
			list( $subnet, $mask ) = explode( '/', $range['cidr'] );
			$subnet_long = ip2long( $subnet );
			$mask_long   = -1 << ( 32 - (int) $mask );

			// Check if IP is in subnet
			if ( ( $ip_long & $mask_long ) === ( $subnet_long & $mask_long ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if IPv6 address is in ranges
	 *
	 * @param string $ip IPv6 address
	 * @param array  $ranges IPv6 ranges
	 * @param string $service Service filter
	 * @return bool True if IP is in ranges
	 */
	private function check_ipv6( $ip, $ranges, $service ) {
		$ip_bin = inet_pton( $ip );
		if ( false === $ip_bin ) {
			return false;
		}

		foreach ( $ranges as $range ) {
			// Filter by service if not AMAZON (all)
			if ( 'AMAZON' !== $service && $range['service'] !== $service ) {
				continue;
			}

			// Parse CIDR
			list( $subnet, $prefix_length ) = explode( '/', $range['cidr'] );
			$subnet_bin = inet_pton( $subnet );

			if ( false === $subnet_bin ) {
				continue;
			}

			// Create mask
			$mask_bin = str_repeat( "\xff", (int) floor( $prefix_length / 8 ) );
			$remainder = $prefix_length % 8;
			if ( $remainder ) {
				$mask_bin .= chr( 0xff << ( 8 - $remainder ) );
			}
			$mask_bin = str_pad( $mask_bin, 16, "\x00" );

			// Check if IP is in subnet
			if ( ( $ip_bin & $mask_bin ) === ( $subnet_bin & $mask_bin ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Clear cached IP ranges (useful for debugging)
	 */
	public function clear_cache() {
		delete_transient( self::CACHE_KEY );
	}
}

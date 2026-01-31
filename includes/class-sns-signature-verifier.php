<?php
/**
 * SNS Signature Verifier - Validates AWS SNS signatures
 *
 * @package SESSYPress
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

class SNS_Signature_Verifier {

	/**
	 * Verify SNS message signature
	 *
	 * @param array $message SNS message data
	 * @return bool True if signature is valid
	 */
	public function verify( $message ) {
		// Required fields for signature verification
		$required_fields = array( 'Signature', 'SigningCertURL', 'SignatureVersion' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $message[ $field ] ) ) {
				error_log( 'SESSYPress: Missing required field for signature verification: ' . $field );
				return false;
			}
		}

		// Only support SignatureVersion 1
		if ( '1' !== $message['SignatureVersion'] ) {
			error_log( 'SESSYPress: Unsupported SignatureVersion: ' . $message['SignatureVersion'] );
			return false;
		}

		// Validate signing certificate URL (must be from AWS)
		if ( ! $this->is_valid_cert_url( $message['SigningCertURL'] ) ) {
			error_log( 'SESSYPress: Invalid SigningCertURL: ' . $message['SigningCertURL'] );
			return false;
		}

		// Download and cache certificate
		$certificate = $this->get_certificate( $message['SigningCertURL'] );
		if ( ! $certificate ) {
			error_log( 'SESSYPress: Failed to download certificate from: ' . $message['SigningCertURL'] );
			return false;
		}

		// Build string to sign based on message type
		$string_to_sign = $this->build_string_to_sign( $message );
		if ( ! $string_to_sign ) {
			error_log( 'SESSYPress: Failed to build string to sign' );
			return false;
		}

		// Verify signature
		$signature = base64_decode( $message['Signature'] );
		$public_key = openssl_pkey_get_public( $certificate );

		if ( ! $public_key ) {
			error_log( 'SESSYPress: Failed to extract public key from certificate' );
			return false;
		}

		$result = openssl_verify( $string_to_sign, $signature, $public_key, OPENSSL_ALGO_SHA1 );

		openssl_free_key( $public_key );

		if ( 1 === $result ) {
			return true;
		}

		error_log( 'SESSYPress: Signature verification failed' );
		return false;
	}

	/**
	 * Validate signing certificate URL
	 *
	 * @param string $url Certificate URL
	 * @return bool True if URL is valid
	 */
	private function is_valid_cert_url( $url ) {
		// Must be HTTPS
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return false;
		}

		// Must be from amazonaws.com domain
		$parsed = wp_parse_url( $url );
		if ( ! isset( $parsed['host'] ) ) {
			return false;
		}

		$host = $parsed['host'];

		// Allow sns.*.amazonaws.com or *.sns.amazonaws.com
		return (
			preg_match( '/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host ) ||
			preg_match( '/^[a-z0-9-]+\.sns\.amazonaws\.com$/', $host )
		);
	}

	/**
	 * Get certificate (with caching)
	 *
	 * @param string $url Certificate URL
	 * @return string|false Certificate content or false on failure
	 */
	private function get_certificate( $url ) {
		// Cache key based on URL hash
		$cache_key = 'sessypress_sns_cert_' . md5( $url );

		// Try to get from cache (24h expiration)
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// Download certificate
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'SESSYPress/' . SESSYPRESS_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'SESSYPress: Failed to download certificate: ' . $response->get_error_message() );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			error_log( 'SESSYPress: Empty certificate response' );
			return false;
		}

		// Validate certificate format
		if ( false === strpos( $body, '-----BEGIN CERTIFICATE-----' ) ) {
			error_log( 'SESSYPress: Invalid certificate format' );
			return false;
		}

		// Cache for 24 hours
		set_transient( $cache_key, $body, DAY_IN_SECONDS );

		return $body;
	}

	/**
	 * Build string to sign based on message type
	 *
	 * @param array $message SNS message
	 * @return string|false String to sign or false on failure
	 */
	private function build_string_to_sign( $message ) {
		// Determine message type
		$type = isset( $message['Type'] ) ? $message['Type'] : '';

		// Fields to include in signature (order matters!)
		$fields = array();

		if ( 'Notification' === $type ) {
			$fields = array( 'Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type' );
		} elseif ( 'SubscriptionConfirmation' === $type || 'UnsubscribeConfirmation' === $type ) {
			$fields = array( 'Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type' );
		} else {
			return false;
		}

		// Build string
		$string_to_sign = '';
		foreach ( $fields as $field ) {
			// Skip if field not present (Subject is optional)
			if ( ! isset( $message[ $field ] ) ) {
				if ( 'Subject' !== $field ) {
					error_log( 'SESSYPress: Missing required field for signature: ' . $field );
					return false;
				}
				continue;
			}

			$string_to_sign .= $field . "\n";
			$string_to_sign .= $message[ $field ] . "\n";
		}

		return $string_to_sign;
	}
}

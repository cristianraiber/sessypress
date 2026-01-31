<?php
/**
 * SNS Handler - Router for SNS messages (Notification vs Event Publishing)
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

class SNS_Handler {

	/**
	 * Event detector instance
	 *
	 * @var Event_Detector
	 */
	private $detector;

	/**
	 * SNS Notification handler instance
	 *
	 * @var SNS_Notification_Handler
	 */
	private $sns_notification_handler;

	/**
	 * Event Publishing handler instance
	 *
	 * @var Event_Publishing_Handler
	 */
	private $event_publishing_handler;

	/**
	 * SNS Signature verifier instance
	 *
	 * @var SNS_Signature_Verifier
	 */
	private $signature_verifier;

	/**
	 * AWS IP validator instance
	 *
	 * @var AWS_IP_Validator
	 */
	private $ip_validator;

	/**
	 * Rate limiter instance
	 *
	 * @var Webhook_Rate_Limiter
	 */
	private $rate_limiter;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->detector                   = new Event_Detector();
		$this->sns_notification_handler   = new SNS_Notification_Handler();
		$this->event_publishing_handler   = new Event_Publishing_Handler();
		$this->signature_verifier         = new SNS_Signature_Verifier();
		$this->ip_validator               = new AWS_IP_Validator();
		$this->rate_limiter               = new Webhook_Rate_Limiter();
	}

	/**
	 * Process SNS webhook request
	 *
	 * @param \WP_REST_Request $request REST request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function process( $request ) {
		// 1. Rate limiting (first line of defense)
		$client_ip = $this->get_client_ip( $request );
		if ( ! $this->rate_limiter->check( $client_ip ) ) {
			return new \WP_Error( 'rate_limit_exceeded', 'Too many requests', array( 'status' => 429 ) );
		}

		// 2. Validate secret key
		if ( ! $this->validate_secret( $request ) ) {
			return new \WP_Error( 'invalid_secret', 'Invalid secret key', array( 'status' => 403 ) );
		}

		// 3. AWS IP validation (optional, can be disabled in settings)
		if ( $this->is_ip_validation_enabled() ) {
			if ( ! $this->ip_validator->is_aws_ip( $client_ip ) ) {
				error_log( 'SESSYPress: Request from non-AWS IP: ' . $client_ip );
				return new \WP_Error( 'invalid_source', 'Request must come from AWS IP', array( 'status' => 403 ) );
			}
		}

		$body = $request->get_body();
		$data = json_decode( $body, true );

		if ( ! $data ) {
			return new \WP_Error( 'invalid_json', 'Invalid JSON payload', array( 'status' => 400 ) );
		}

		// 4. SNS signature verification (for SNS notifications only)
		if ( $this->is_sns_message( $data ) ) {
			if ( ! $this->signature_verifier->verify( $data ) ) {
				error_log( 'SESSYPress: SNS signature verification failed' );
				return new \WP_Error( 'invalid_signature', 'Invalid SNS signature', array( 'status' => 403 ) );
			}
		}

		// Detect message type
		$message_type = $this->detector->detect_message_type( $data );

		// Handle SNS subscription confirmation
		if ( 'subscription_confirmation' === $message_type ) {
			return $this->confirm_subscription( $data );
		}

		// Handle SNS unsubscribe confirmation
		if ( 'unsubscribe_confirmation' === $message_type ) {
			return rest_ensure_response( array( 'message' => 'Unsubscribe confirmed' ) );
		}

		// Handle SNS notification
		if ( 'notification' === $message_type ) {
			return $this->handle_notification( $data );
		}

		return new \WP_Error( 'unknown_type', 'Unknown SNS message type', array( 'status' => 400 ) );
	}

	/**
	 * Validate secret key
	 *
	 * @param \WP_REST_Request $request REST request object
	 * @return bool
	 */
	private function validate_secret( $request ) {
		$settings = get_option( 'sessypress_settings', array() );
		$secret   = isset( $settings['sns_secret_key'] ) ? $settings['sns_secret_key'] : '';

		// Fallback to old option name for backwards compatibility
		if ( empty( $secret ) ) {
			$old_settings = get_option( 'ses_sns_tracker_settings', array() );
			$secret       = isset( $old_settings['sns_secret_key'] ) ? $old_settings['sns_secret_key'] : '';
		}

		$provided = sanitize_text_field( (string) $request->get_param( 'key' ) );

		return hash_equals( $secret, $provided );
	}

	/**
	 * Confirm SNS subscription
	 *
	 * @param array $data SNS subscription confirmation data
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function confirm_subscription( $data ) {
		if ( ! isset( $data['SubscribeURL'] ) ) {
			return new \WP_Error( 'missing_subscribe_url', 'Missing SubscribeURL', array( 'status' => 400 ) );
		}

		// Call the subscribe URL to confirm
		$response = wp_remote_get( $data['SubscribeURL'] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'subscription_failed', $response->get_error_message(), array( 'status' => 500 ) );
		}

		// Log subscription confirmation
		error_log( 'SESSYPress: SNS Subscription confirmed for topic: ' . ( $data['TopicArn'] ?? 'unknown' ) );

		return rest_ensure_response( array( 'message' => 'Subscription confirmed' ) );
	}

	/**
	 * Handle SNS notification
	 *
	 * @param array $data SNS notification data
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function handle_notification( $data ) {
		if ( ! isset( $data['Message'] ) ) {
			return new \WP_Error( 'missing_message', 'Missing Message field', array( 'status' => 400 ) );
		}

		$message = $this->detector->parse_notification( $data );

		if ( ! $message ) {
			return new \WP_Error( 'invalid_message', 'Invalid Message JSON', array( 'status' => 400 ) );
		}

		// Route to appropriate handler
		if ( $this->detector->is_sns_notification( $message ) ) {
			$this->sns_notification_handler->handle_event( $message );
			return rest_ensure_response( array( 'message' => 'SNS Notification processed' ) );
		}

		if ( $this->detector->is_event_publishing( $message ) ) {
			$this->event_publishing_handler->handle_event( $message );
			return rest_ensure_response( array( 'message' => 'Event Publishing message processed' ) );
		}

		return new \WP_Error( 'unknown_message_format', 'Unknown message format', array( 'status' => 400 ) );
	}

	/**
	 * Get client IP address
	 *
	 * @param \WP_REST_Request $request REST request object
	 * @return string Client IP address
	 */
	private function get_client_ip( $request ) {
		// Try various headers (in order of preference)
		$headers = array(
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'HTTP_CLIENT_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			$ip = $request->get_header( strtolower( str_replace( 'HTTP_', '', $header ) ) );
			if ( ! empty( $ip ) ) {
				// X-Forwarded-For can contain multiple IPs (client, proxy1, proxy2)
				// Use first one (original client)
				if ( strpos( $ip, ',' ) !== false ) {
					$ips = explode( ',', $ip );
					$ip  = trim( $ips[0] );
				}

				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		// Fallback to $_SERVER['REMOTE_ADDR']
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
	}

	/**
	 * Check if IP validation is enabled
	 *
	 * @return bool True if enabled
	 */
	private function is_ip_validation_enabled() {
		$settings = get_option( 'sessypress_settings', array() );
		return isset( $settings['validate_aws_ip'] ) ? (bool) $settings['validate_aws_ip'] : true;
	}

	/**
	 * Check if message is an SNS message (has Signature field)
	 *
	 * @param array $data Message data
	 * @return bool True if SNS message
	 */
	private function is_sns_message( $data ) {
		return isset( $data['Type'] ) && isset( $data['Signature'] );
	}
}

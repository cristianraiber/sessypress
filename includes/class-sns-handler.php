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
	 * Constructor
	 */
	public function __construct() {
		$this->detector                   = new Event_Detector();
		$this->sns_notification_handler   = new SNS_Notification_Handler();
		$this->event_publishing_handler   = new Event_Publishing_Handler();
	}

	/**
	 * Process SNS webhook request
	 *
	 * @param \WP_REST_Request $request REST request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function process( $request ) {
		// Validate secret key
		if ( ! $this->validate_secret( $request ) ) {
			return new \WP_Error( 'invalid_secret', 'Invalid secret key', array( 'status' => 403 ) );
		}

		$body = $request->get_body();
		$data = json_decode( $body, true );

		if ( ! $data ) {
			return new \WP_Error( 'invalid_json', 'Invalid JSON payload', array( 'status' => 400 ) );
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

		$provided = $request->get_param( 'key' );

		return hash_equals( $secret, (string) $provided );
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
}

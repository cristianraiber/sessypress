<?php
/**
 * Event Detector - Detect SNS message type and route to appropriate handler
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

class Event_Detector {

	/**
	 * Detect SNS message type
	 *
	 * @param array $data SNS payload
	 * @return string|null Type: 'subscription_confirmation', 'notification', 'unsubscribe_confirmation', or null
	 */
	public function detect_message_type( $data ) {
		if ( ! isset( $data['Type'] ) ) {
			return null;
		}

		switch ( $data['Type'] ) {
			case 'SubscriptionConfirmation':
				return 'subscription_confirmation';

			case 'Notification':
				return 'notification';

			case 'UnsubscribeConfirmation':
				return 'unsubscribe_confirmation';

			default:
				return null;
		}
	}

	/**
	 * Parse SNS notification message
	 *
	 * @param array $data SNS payload
	 * @return array|null Parsed message or null
	 */
	public function parse_notification( $data ) {
		if ( ! isset( $data['Message'] ) ) {
			return null;
		}

		$message = json_decode( $data['Message'], true );

		if ( ! $message || ! is_array( $message ) ) {
			return null;
		}

		return $message;
	}

	/**
	 * Check if message is SNS Notification (legacy)
	 *
	 * @param array $message Parsed message
	 * @return bool
	 */
	public function is_sns_notification( $message ) {
		return isset( $message['notificationType'] );
	}

	/**
	 * Check if message is Event Publishing
	 *
	 * @param array $message Parsed message
	 * @return bool
	 */
	public function is_event_publishing( $message ) {
		return isset( $message['eventType'] );
	}

	/**
	 * Get handler class name for event
	 *
	 * @param array $message Parsed message
	 * @return string Handler class name
	 */
	public function get_handler_for_event( $message ) {
		if ( $this->is_sns_notification( $message ) ) {
			return 'SNS_Notification_Handler';
		}

		if ( $this->is_event_publishing( $message ) ) {
			return 'Event_Publishing_Handler';
		}

		return 'Unknown_Handler';
	}

	/**
	 * Get event type from message
	 *
	 * @param array $message Parsed message
	 * @return string|null
	 */
	public function get_event_type( $message ) {
		if ( $this->is_sns_notification( $message ) ) {
			return $message['notificationType'];
		}

		if ( $this->is_event_publishing( $message ) ) {
			return $message['eventType'];
		}

		return null;
	}
}

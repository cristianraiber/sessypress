<?php
/**
 * Handle SNS notifications
 */

namespace SES_SNS_Tracker;

defined( 'ABSPATH' ) || exit;

class SNS_Handler {

	/**
	 * Process SNS notification
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

		// Handle SNS subscription confirmation
		if ( isset( $data['Type'] ) && 'SubscriptionConfirmation' === $data['Type'] ) {
			return $this->confirm_subscription( $data );
		}

		// Handle SNS notification
		if ( isset( $data['Type'] ) && 'Notification' === $data['Type'] ) {
			return $this->handle_notification( $data );
		}

		return new \WP_Error( 'unknown_type', 'Unknown SNS message type', array( 'status' => 400 ) );
	}

	/**
	 * Validate secret key
	 */
	private function validate_secret( $request ) {
		$settings = get_option( 'ses_sns_tracker_settings', array() );
		$secret   = isset( $settings['sns_secret_key'] ) ? $settings['sns_secret_key'] : '';

		$provided = $request->get_param( 'key' );

		return hash_equals( $secret, (string) $provided );
	}

	/**
	 * Confirm SNS subscription
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
		error_log( 'SES SNS Tracker: Subscription confirmed for topic: ' . ( $data['TopicArn'] ?? 'unknown' ) );

		return rest_ensure_response( array( 'message' => 'Subscription confirmed' ) );
	}

	/**
	 * Handle SNS notification
	 */
	private function handle_notification( $data ) {
		if ( ! isset( $data['Message'] ) ) {
			return new \WP_Error( 'missing_message', 'Missing Message field', array( 'status' => 400 ) );
		}

		$message = json_decode( $data['Message'], true );

		if ( ! $message ) {
			return new \WP_Error( 'invalid_message', 'Invalid Message JSON', array( 'status' => 400 ) );
		}

		// Store the event
		$this->store_event( $message );

		return rest_ensure_response( array( 'message' => 'Notification processed' ) );
	}

	/**
	 * Store event in database
	 */
	private function store_event( $message ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ses_email_events';

		$notification_type = isset( $message['notificationType'] ) ? $message['notificationType'] : '';
		$mail              = isset( $message['mail'] ) ? $message['mail'] : array();
		$message_id        = isset( $mail['messageId'] ) ? $mail['messageId'] : '';
		$source            = isset( $mail['source'] ) ? $mail['source'] : '';
		$destination       = isset( $mail['destination'] ) ? $mail['destination'] : array();
		$timestamp         = isset( $mail['timestamp'] ) ? $mail['timestamp'] : current_time( 'mysql' );

		// Parse subject from headers if available
		$subject = '';
		if ( isset( $mail['commonHeaders']['subject'] ) ) {
			$subject = $mail['commonHeaders']['subject'];
		}

		// Process based on notification type
		switch ( $notification_type ) {
			case 'Bounce':
				$this->store_bounce( $message_id, $message, $source, $destination, $subject, $timestamp );
				break;

			case 'Complaint':
				$this->store_complaint( $message_id, $message, $source, $destination, $subject, $timestamp );
				break;

			case 'Delivery':
				$this->store_delivery( $message_id, $message, $source, $destination, $subject, $timestamp );
				break;
		}
	}

	/**
	 * Store bounce event
	 */
	private function store_bounce( $message_id, $message, $source, $destination, $subject, $timestamp ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'ses_email_events';
		$bounce = isset( $message['bounce'] ) ? $message['bounce'] : array();

		$bounce_type     = isset( $bounce['bounceType'] ) ? $bounce['bounceType'] : '';
		$bounce_subtype  = isset( $bounce['bounceSubType'] ) ? $bounce['bounceSubType'] : '';
		$bounced_recips  = isset( $bounce['bouncedRecipients'] ) ? $bounce['bouncedRecipients'] : array();

		foreach ( $bounced_recips as $recipient ) {
			$email           = isset( $recipient['emailAddress'] ) ? $recipient['emailAddress'] : '';
			$diagnostic_code = isset( $recipient['diagnosticCode'] ) ? $recipient['diagnosticCode'] : '';

			$wpdb->insert(
				$table,
				array(
					'message_id'        => $message_id,
					'notification_type' => 'Bounce',
					'event_type'        => 'bounce',
					'recipient'         => $email,
					'sender'            => $source,
					'subject'           => $subject,
					'bounce_type'       => $bounce_type,
					'bounce_subtype'    => $bounce_subtype,
					'diagnostic_code'   => $diagnostic_code,
					'timestamp'         => gmdate( 'Y-m-d H:i:s', strtotime( $timestamp ) ),
					'raw_payload'       => wp_json_encode( $message ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Store complaint event
	 */
	private function store_complaint( $message_id, $message, $source, $destination, $subject, $timestamp ) {
		global $wpdb;

		$table     = $wpdb->prefix . 'ses_email_events';
		$complaint = isset( $message['complaint'] ) ? $message['complaint'] : array();

		$complaint_type       = isset( $complaint['complaintFeedbackType'] ) ? $complaint['complaintFeedbackType'] : '';
		$complained_recips    = isset( $complaint['complainedRecipients'] ) ? $complaint['complainedRecipients'] : array();

		foreach ( $complained_recips as $recipient ) {
			$email = isset( $recipient['emailAddress'] ) ? $recipient['emailAddress'] : '';

			$wpdb->insert(
				$table,
				array(
					'message_id'        => $message_id,
					'notification_type' => 'Complaint',
					'event_type'        => 'complaint',
					'recipient'         => $email,
					'sender'            => $source,
					'subject'           => $subject,
					'complaint_type'    => $complaint_type,
					'timestamp'         => gmdate( 'Y-m-d H:i:s', strtotime( $timestamp ) ),
					'raw_payload'       => wp_json_encode( $message ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Store delivery event
	 */
	private function store_delivery( $message_id, $message, $source, $destination, $subject, $timestamp ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'ses_email_events';
		$delivery = isset( $message['delivery'] ) ? $message['delivery'] : array();

		$smtp_response = isset( $delivery['smtpResponse'] ) ? $delivery['smtpResponse'] : '';
		$recipients    = isset( $delivery['recipients'] ) ? $delivery['recipients'] : $destination;

		foreach ( $recipients as $email ) {
			$wpdb->insert(
				$table,
				array(
					'message_id'        => $message_id,
					'notification_type' => 'Delivery',
					'event_type'        => 'delivery',
					'recipient'         => $email,
					'sender'            => $source,
					'subject'           => $subject,
					'smtp_response'     => $smtp_response,
					'timestamp'         => gmdate( 'Y-m-d H:i:s', strtotime( $timestamp ) ),
					'raw_payload'       => wp_json_encode( $message ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}
}

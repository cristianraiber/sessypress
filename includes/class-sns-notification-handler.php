<?php
/**
 * SNS Notification Handler - Process legacy SNS notifications (Bounce, Complaint, Delivery)
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

class SNS_Notification_Handler {

	/**
	 * Handle SNS notification message
	 *
	 * @param array $message Parsed SNS notification
	 */
	public function handle_event( $message ) {
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
	 *
	 * @param string $message_id SES message ID
	 * @param array  $message Full notification message
	 * @param string $source Sender email
	 * @param array  $destination Recipients
	 * @param string $subject Email subject
	 * @param string $timestamp Event timestamp
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
					'event_source'      => 'sns_notification',
					'recipient'         => $email,
					'sender'            => $source,
					'subject'           => $subject,
					'bounce_type'       => $bounce_type,
					'bounce_subtype'    => $bounce_subtype,
					'diagnostic_code'   => $diagnostic_code,
					'timestamp'         => gmdate( 'Y-m-d H:i:s', strtotime( $timestamp ) ),
					'raw_payload'       => wp_json_encode( $message ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Store complaint event
	 *
	 * @param string $message_id SES message ID
	 * @param array  $message Full notification message
	 * @param string $source Sender email
	 * @param array  $destination Recipients
	 * @param string $subject Email subject
	 * @param string $timestamp Event timestamp
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
					'event_source'      => 'sns_notification',
					'recipient'         => $email,
					'sender'            => $source,
					'subject'           => $subject,
					'complaint_type'    => $complaint_type,
					'timestamp'         => gmdate( 'Y-m-d H:i:s', strtotime( $timestamp ) ),
					'raw_payload'       => wp_json_encode( $message ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Store delivery event
	 *
	 * @param string $message_id SES message ID
	 * @param array  $message Full notification message
	 * @param string $source Sender email
	 * @param array  $destination Recipients
	 * @param string $subject Email subject
	 * @param string $timestamp Event timestamp
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
					'event_source'      => 'sns_notification',
					'recipient'         => $email,
					'sender'            => $source,
					'subject'           => $subject,
					'smtp_response'     => $smtp_response,
					'timestamp'         => gmdate( 'Y-m-d H:i:s', strtotime( $timestamp ) ),
					'raw_payload'       => wp_json_encode( $message ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}
}

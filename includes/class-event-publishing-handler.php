<?php
/**
 * Event Publishing Handler - Process SES Event Publishing events
 * (Send, Reject, Open, Click, Bounce, Complaint, Delivery, DeliveryDelay, RenderingFailure, Subscription)
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

class Event_Publishing_Handler {

	/**
	 * Handle Event Publishing message
	 *
	 * @param array $message Parsed Event Publishing message
	 */
	public function handle_event( $message ) {
		$event_type = isset( $message['eventType'] ) ? $message['eventType'] : '';

		switch ( $event_type ) {
			case 'Send':
				$this->store_send_event( $message );
				break;

			case 'Reject':
				$this->store_reject_event( $message );
				break;

			case 'Open':
				$this->store_open_event( $message );
				break;

			case 'Click':
				$this->store_click_event( $message );
				break;

			case 'Bounce':
				$this->store_bounce_event( $message );
				break;

			case 'Complaint':
				$this->store_complaint_event( $message );
				break;

			case 'Delivery':
				$this->store_delivery_event( $message );
				break;

			case 'DeliveryDelay':
				$this->store_delivery_delay_event( $message );
				break;

			case 'RenderingFailure':
				$this->store_rendering_failure_event( $message );
				break;

			case 'Subscription':
				$this->store_subscription_event( $message );
				break;

			default:
				error_log( 'SESSYPress: Unknown Event Publishing event type: ' . $event_type );
		}
	}

	/**
	 * Store Send event
	 *
	 * @param array $message Event message
	 */
	private function store_send_event( $message ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ses_email_events';
		$mail  = isset( $message['mail'] ) ? $message['mail'] : array();

		$message_id  = isset( $mail['messageId'] ) ? $mail['messageId'] : '';
		$source      = isset( $mail['source'] ) ? $mail['source'] : '';
		$destination = isset( $mail['destination'] ) ? $mail['destination'] : array();
		$subject     = isset( $mail['commonHeaders']['subject'] ) ? $mail['commonHeaders']['subject'] : '';
		$timestamp   = isset( $mail['timestamp'] ) ? $mail['timestamp'] : current_time( 'mysql' );

		foreach ( $destination as $recipient ) {
			$wpdb->insert(
				$table,
				array(
					'message_id'        => $message_id,
					'notification_type' => 'Send',
					'event_type'        => 'Send',
					'event_source'      => 'event_publishing',
					'recipient'         => $recipient,
					'sender'            => $source,
					'subject'           => $subject,
					'timestamp'         => gmdate( 'Y-m-d H:i:s', strtotime( $timestamp ) ),
					'raw_payload'       => wp_json_encode( $message ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Store Reject event
	 *
	 * @param array $message Event message
	 */
	private function store_reject_event( $message ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'ses_email_events';
		$mail   = isset( $message['mail'] ) ? $message['mail'] : array();
		$reject = isset( $message['reject'] ) ? $message['reject'] : array();

		$message_id  = isset( $mail['messageId'] ) ? $mail['messageId'] : '';
		$source      = isset( $mail['source'] ) ? $mail['source'] : '';
		$destination = isset( $mail['destination'] ) ? $mail['destination'] : array();
		$subject     = isset( $mail['commonHeaders']['subject'] ) ? $mail['commonHeaders']['subject'] : '';
		$reason      = isset( $reject['reason'] ) ? $reject['reason'] : '';

		$metadata = wp_json_encode(
			array(
				'reason' => $reason,
			)
		);

		foreach ( $destination as $recipient ) {
			$wpdb->insert(
				$table,
				array(
					'message_id'        => $message_id,
					'notification_type' => 'Reject',
					'event_type'        => 'Reject',
					'event_source'      => 'event_publishing',
					'recipient'         => $recipient,
					'sender'            => $source,
					'subject'           => $subject,
					'event_metadata'    => $metadata,
					'timestamp'         => current_time( 'mysql' ),
					'raw_payload'       => wp_json_encode( $message ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Store Open event
	 *
	 * @param array $message Event message
	 */
	private function store_open_event( $message ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ses_email_events';
		$mail  = isset( $message['mail'] ) ? $message['mail'] : array();
		$open  = isset( $message['open'] ) ? $message['open'] : array();

		$message_id  = isset( $mail['messageId'] ) ? $mail['messageId'] : '';
		$source      = isset( $mail['source'] ) ? $mail['source'] : '';
		$destination = isset( $mail['destination'] ) ? $mail['destination'] : array();
		$subject     = isset( $mail['commonHeaders']['subject'] ) ? $mail['commonHeaders']['subject'] : '';

		$metadata = $this->extract_metadata( $open );

		foreach ( $destination as $recipient ) {
			$wpdb->insert(
				$table,
				array(
					'message_id'        => $message_id,
					'notification_type' => 'Open',
					'event_type'        => 'Open',
					'event_source'      => 'event_publishing',
					'recipient'         => $recipient,
					'sender'            => $source,
					'subject'           => $subject,
					'event_metadata'    => $metadata,
					'timestamp'         => isset( $open['timestamp'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $open['timestamp'] ) ) : current_time( 'mysql' ),
					'raw_payload'       => wp_json_encode( $message ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Store Click event
	 *
	 * @param array $message Event message
	 */
	private function store_click_event( $message ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ses_email_events';
		$mail  = isset( $message['mail'] ) ? $message['mail'] : array();
		$click = isset( $message['click'] ) ? $message['click'] : array();

		$message_id  = isset( $mail['messageId'] ) ? $mail['messageId'] : '';
		$source      = isset( $mail['source'] ) ? $mail['source'] : '';
		$destination = isset( $mail['destination'] ) ? $mail['destination'] : array();
		$subject     = isset( $mail['commonHeaders']['subject'] ) ? $mail['commonHeaders']['subject'] : '';
		$link        = isset( $click['link'] ) ? $click['link'] : '';

		$metadata = $this->extract_metadata( $click );

		foreach ( $destination as $recipient ) {
			$wpdb->insert(
				$table,
				array(
					'message_id'        => $message_id,
					'notification_type' => 'Click',
					'event_type'        => 'Click',
					'event_source'      => 'event_publishing',
					'recipient'         => $recipient,
					'sender'            => $source,
					'subject'           => $subject,
					'event_metadata'    => $metadata,
					'timestamp'         => isset( $click['timestamp'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $click['timestamp'] ) ) : current_time( 'mysql' ),
					'raw_payload'       => wp_json_encode( $message ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Store Bounce event (from Event Publishing)
	 *
	 * @param array $message Event message
	 */
	private function store_bounce_event( $message ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'ses_email_events';
		$mail   = isset( $message['mail'] ) ? $message['mail'] : array();
		$bounce = isset( $message['bounce'] ) ? $message['bounce'] : array();

		$message_id      = isset( $mail['messageId'] ) ? $mail['messageId'] : '';
		$source          = isset( $mail['source'] ) ? $mail['source'] : '';
		$subject         = isset( $mail['commonHeaders']['subject'] ) ? $mail['commonHeaders']['subject'] : '';
		$bounce_type     = isset( $bounce['bounceType'] ) ? $bounce['bounceType'] : '';
		$bounce_subtype  = isset( $bounce['bounceSubType'] ) ? $bounce['bounceSubType'] : '';
		$bounced_recips  = isset( $bounce['bouncedRecipients'] ) ? $bounce['bouncedRecipients'] : array();

		foreach ( $bounced_recips as $recipient_data ) {
			$email           = isset( $recipient_data['emailAddress'] ) ? $recipient_data['emailAddress'] : '';
			$diagnostic_code = isset( $recipient_data['diagnosticCode'] ) ? $recipient_data['diagnosticCode'] : '';

			$wpdb->insert(
				$table,
				array(
					'message_id'        => $message_id,
					'notification_type' => 'Bounce',
					'event_type'        => 'Bounce',
					'event_source'      => 'event_publishing',
					'recipient'         => $email,
					'sender'            => $source,
					'subject'           => $subject,
					'bounce_type'       => $bounce_type,
					'bounce_subtype'    => $bounce_subtype,
					'diagnostic_code'   => $diagnostic_code,
					'timestamp'         => isset( $bounce['timestamp'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $bounce['timestamp'] ) ) : current_time( 'mysql' ),
					'raw_payload'       => wp_json_encode( $message ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Store Complaint event (from Event Publishing)
	 *
	 * @param array $message Event message
	 */
	private function store_complaint_event( $message ) {
		global $wpdb;

		$table     = $wpdb->prefix . 'ses_email_events';
		$mail      = isset( $message['mail'] ) ? $message['mail'] : array();
		$complaint = isset( $message['complaint'] ) ? $message['complaint'] : array();

		$message_id           = isset( $mail['messageId'] ) ? $mail['messageId'] : '';
		$source               = isset( $mail['source'] ) ? $mail['source'] : '';
		$subject              = isset( $mail['commonHeaders']['subject'] ) ? $mail['commonHeaders']['subject'] : '';
		$complaint_type       = isset( $complaint['complaintFeedbackType'] ) ? $complaint['complaintFeedbackType'] : '';
		$complained_recips    = isset( $complaint['complainedRecipients'] ) ? $complaint['complainedRecipients'] : array();

		foreach ( $complained_recips as $recipient_data ) {
			$email = isset( $recipient_data['emailAddress'] ) ? $recipient_data['emailAddress'] : '';

			$wpdb->insert(
				$table,
				array(
					'message_id'        => $message_id,
					'notification_type' => 'Complaint',
					'event_type'        => 'Complaint',
					'event_source'      => 'event_publishing',
					'recipient'         => $email,
					'sender'            => $source,
					'subject'           => $subject,
					'complaint_type'    => $complaint_type,
					'timestamp'         => isset( $complaint['timestamp'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $complaint['timestamp'] ) ) : current_time( 'mysql' ),
					'raw_payload'       => wp_json_encode( $message ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Store Delivery event (from Event Publishing)
	 *
	 * @param array $message Event message
	 */
	private function store_delivery_event( $message ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'ses_email_events';
		$mail     = isset( $message['mail'] ) ? $message['mail'] : array();
		$delivery = isset( $message['delivery'] ) ? $message['delivery'] : array();

		$message_id    = isset( $mail['messageId'] ) ? $mail['messageId'] : '';
		$source        = isset( $mail['source'] ) ? $mail['source'] : '';
		$subject       = isset( $mail['commonHeaders']['subject'] ) ? $mail['commonHeaders']['subject'] : '';
		$smtp_response = isset( $delivery['smtpResponse'] ) ? $delivery['smtpResponse'] : '';
		$recipients    = isset( $delivery['recipients'] ) ? $delivery['recipients'] : array();

		foreach ( $recipients as $email ) {
			$wpdb->insert(
				$table,
				array(
					'message_id'        => $message_id,
					'notification_type' => 'Delivery',
					'event_type'        => 'Delivery',
					'event_source'      => 'event_publishing',
					'recipient'         => $email,
					'sender'            => $source,
					'subject'           => $subject,
					'smtp_response'     => $smtp_response,
					'timestamp'         => isset( $delivery['timestamp'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $delivery['timestamp'] ) ) : current_time( 'mysql' ),
					'raw_payload'       => wp_json_encode( $message ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Store Delivery Delay event
	 *
	 * @param array $message Event message
	 */
	private function store_delivery_delay_event( $message ) {
		global $wpdb;

		$table         = $wpdb->prefix . 'ses_email_events';
		$mail          = isset( $message['mail'] ) ? $message['mail'] : array();
		$delivery_delay = isset( $message['deliveryDelay'] ) ? $message['deliveryDelay'] : array();

		$message_id         = isset( $mail['messageId'] ) ? $mail['messageId'] : '';
		$source             = isset( $mail['source'] ) ? $mail['source'] : '';
		$subject            = isset( $mail['commonHeaders']['subject'] ) ? $mail['commonHeaders']['subject'] : '';
		$delayed_recipients = isset( $delivery_delay['delayedRecipients'] ) ? $delivery_delay['delayedRecipients'] : array();

		$metadata = wp_json_encode(
			array(
				'delay_type'       => isset( $delivery_delay['delayType'] ) ? $delivery_delay['delayType'] : '',
				'expiration_time'  => isset( $delivery_delay['expirationTime'] ) ? $delivery_delay['expirationTime'] : '',
				'reporting_mta'    => isset( $delivery_delay['reportingMTA'] ) ? $delivery_delay['reportingMTA'] : '',
			)
		);

		foreach ( $delayed_recipients as $recipient_data ) {
			$email  = isset( $recipient_data['emailAddress'] ) ? $recipient_data['emailAddress'] : '';
			$status = isset( $recipient_data['status'] ) ? $recipient_data['status'] : '';

			$wpdb->insert(
				$table,
				array(
					'message_id'        => $message_id,
					'notification_type' => 'DeliveryDelay',
					'event_type'        => 'DeliveryDelay',
					'event_source'      => 'event_publishing',
					'recipient'         => $email,
					'sender'            => $source,
					'subject'           => $subject,
					'event_metadata'    => $metadata,
					'timestamp'         => isset( $delivery_delay['timestamp'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $delivery_delay['timestamp'] ) ) : current_time( 'mysql' ),
					'raw_payload'       => wp_json_encode( $message ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Store Rendering Failure event
	 *
	 * @param array $message Event message
	 */
	private function store_rendering_failure_event( $message ) {
		global $wpdb;

		$table   = $wpdb->prefix . 'ses_email_events';
		$mail    = isset( $message['mail'] ) ? $message['mail'] : array();
		$failure = isset( $message['failure'] ) ? $message['failure'] : array();

		$message_id  = isset( $mail['messageId'] ) ? $mail['messageId'] : '';
		$source      = isset( $mail['source'] ) ? $mail['source'] : '';
		$destination = isset( $mail['destination'] ) ? $mail['destination'] : array();

		$metadata = wp_json_encode(
			array(
				'template_name'  => isset( $failure['templateName'] ) ? $failure['templateName'] : '',
				'error_message'  => isset( $failure['errorMessage'] ) ? $failure['errorMessage'] : '',
			)
		);

		foreach ( $destination as $recipient ) {
			$wpdb->insert(
				$table,
				array(
					'message_id'        => $message_id,
					'notification_type' => 'RenderingFailure',
					'event_type'        => 'RenderingFailure',
					'event_source'      => 'event_publishing',
					'recipient'         => $recipient,
					'sender'            => $source,
					'subject'           => '',
					'event_metadata'    => $metadata,
					'timestamp'         => current_time( 'mysql' ),
					'raw_payload'       => wp_json_encode( $message ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Store Subscription event
	 *
	 * @param array $message Event message
	 */
	private function store_subscription_event( $message ) {
		global $wpdb;

		$table        = $wpdb->prefix . 'ses_email_events';
		$mail         = isset( $message['mail'] ) ? $message['mail'] : array();
		$subscription = isset( $message['subscription'] ) ? $message['subscription'] : array();

		$message_id  = isset( $mail['messageId'] ) ? $mail['messageId'] : '';
		$source      = isset( $mail['source'] ) ? $mail['source'] : '';
		$destination = isset( $mail['destination'] ) ? $mail['destination'] : array();

		$metadata = wp_json_encode(
			array(
				'contact_list'   => isset( $subscription['contactList'] ) ? $subscription['contactList'] : '',
				'timestamp'      => isset( $subscription['timestamp'] ) ? $subscription['timestamp'] : '',
			)
		);

		foreach ( $destination as $recipient ) {
			$wpdb->insert(
				$table,
				array(
					'message_id'        => $message_id,
					'notification_type' => 'Subscription',
					'event_type'        => 'Subscription',
					'event_source'      => 'event_publishing',
					'recipient'         => $recipient,
					'sender'            => $source,
					'subject'           => '',
					'event_metadata'    => $metadata,
					'timestamp'         => isset( $subscription['timestamp'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $subscription['timestamp'] ) ) : current_time( 'mysql' ),
					'raw_payload'       => wp_json_encode( $message ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Extract metadata from event data (IP, User Agent, Link, etc.)
	 *
	 * @param array $event Event data
	 * @return string JSON-encoded metadata
	 */
	private function extract_metadata( $event ) {
		$metadata = array();

		if ( isset( $event['ipAddress'] ) ) {
			$metadata['ip_address'] = $event['ipAddress'];
		}

		if ( isset( $event['userAgent'] ) ) {
			$metadata['user_agent'] = $event['userAgent'];
		}

		if ( isset( $event['link'] ) ) {
			$metadata['link'] = $event['link'];
		}

		if ( isset( $event['linkTags'] ) ) {
			$metadata['link_tags'] = $event['linkTags'];
		}

		if ( isset( $event['timestamp'] ) ) {
			$metadata['timestamp'] = $event['timestamp'];
		}

		return wp_json_encode( $metadata );
	}
}

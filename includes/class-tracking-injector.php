<?php
/**
 * Inject tracking into outgoing emails
 */

namespace SES_SNS_Tracker;

defined( 'ABSPATH' ) || exit;

class Tracking_Injector {

	/**
	 * Inject tracking into email
	 */
	public function inject( $args ) {
		$settings = get_option( 'ses_sns_tracker_settings', array() );

		$track_opens  = isset( $settings['track_opens'] ) && '1' === $settings['track_opens'];
		$track_clicks = isset( $settings['track_clicks'] ) && '1' === $settings['track_clicks'];

		if ( ! $track_opens && ! $track_clicks ) {
			return $args;
		}

		// Extract message ID from headers
		$message_id = $this->extract_message_id( $args );

		if ( ! $message_id ) {
			return $args;
		}

		// Get recipient email
		$recipient = $this->get_recipient( $args );

		if ( ! $recipient ) {
			return $args;
		}

		// Modify HTML message
		if ( isset( $args['message'] ) && is_string( $args['message'] ) ) {
			$message = $args['message'];

			// Check if HTML
			if ( strpos( $message, '<html' ) !== false || strpos( $message, '<body' ) !== false ) {
				if ( $track_opens ) {
					$message = $this->inject_open_pixel( $message, $message_id, $recipient );
				}

				if ( $track_clicks ) {
					$message = $this->inject_click_tracking( $message, $message_id, $recipient );
				}

				$args['message'] = $message;
			}
		}

		return $args;
	}

	/**
	 * Extract message ID from headers
	 */
	private function extract_message_id( $args ) {
		if ( ! isset( $args['headers'] ) ) {
			return null;
		}

		$headers = $args['headers'];

		if ( is_string( $headers ) ) {
			$headers = explode( "\n", $headers );
		}

		foreach ( $headers as $header ) {
			if ( stripos( $header, 'Message-ID:' ) === 0 ) {
				return trim( str_replace( 'Message-ID:', '', $header ) );
			}
		}

		// Generate a unique ID if not found
		return 'wp-' . wp_generate_uuid4();
	}

	/**
	 * Get recipient email
	 */
	private function get_recipient( $args ) {
		if ( ! isset( $args['to'] ) ) {
			return null;
		}

		$to = $args['to'];

		if ( is_array( $to ) ) {
			$to = reset( $to );
		}

		// Extract email from "Name <email@example.com>" format
		if ( preg_match( '/<(.+)>/', $to, $matches ) ) {
			return $matches[1];
		}

		return sanitize_email( $to );
	}

	/**
	 * Inject open tracking pixel
	 */
	private function inject_open_pixel( $message, $message_id, $recipient ) {
		$tracking_url = add_query_arg(
			array(
				'ses_track'  => '1',
				'ses_action' => 'open',
				'mid'        => rawurlencode( $message_id ),
				'r'          => rawurlencode( $recipient ),
			),
			home_url()
		);

		$pixel = '<img src="' . esc_url( $tracking_url ) . '" alt="" width="1" height="1" style="display:none;" />';

		// Insert before closing </body> tag or at the end
		if ( stripos( $message, '</body>' ) !== false ) {
			$message = str_ireplace( '</body>', $pixel . '</body>', $message );
		} else {
			$message .= $pixel;
		}

		return $message;
	}

	/**
	 * Inject click tracking
	 */
	private function inject_click_tracking( $message, $message_id, $recipient ) {
		// Match all <a href="..."> tags
		$message = preg_replace_callback(
			'/<a\s+(?:[^>]*?\s+)?href=(["\'])(.*?)\1/i',
			function ( $matches ) use ( $message_id, $recipient ) {
				$original_url = $matches[2];

				// Skip if already a tracking URL or unsubscribe link
				if ( strpos( $original_url, 'ses_track=' ) !== false ) {
					return $matches[0];
				}

				// Skip anchor links and special URLs
				if ( strpos( $original_url, '#' ) === 0 || strpos( $original_url, 'mailto:' ) === 0 ) {
					return $matches[0];
				}

				// Check if this is an unsubscribe link
				if ( stripos( $original_url, 'unsubscribe' ) !== false ) {
					$tracking_url = add_query_arg(
						array(
							'ses_track'  => '1',
							'ses_action' => 'unsubscribe',
							'mid'        => rawurlencode( $message_id ),
							'r'          => rawurlencode( $recipient ),
						),
						home_url()
					);
				} else {
					$tracking_url = add_query_arg(
						array(
							'ses_track'  => '1',
							'ses_action' => 'click',
							'mid'        => rawurlencode( $message_id ),
							'r'          => rawurlencode( $recipient ),
							'url'        => rawurlencode( $original_url ),
						),
						home_url()
					);
				}

				return str_replace( $matches[2], $tracking_url, $matches[0] );
			},
			$message
		);

		return $message;
	}
}

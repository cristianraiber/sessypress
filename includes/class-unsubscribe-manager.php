<?php
/**
 * Global Unsubscribe List Manager
 *
 * @package SESSYPress
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

/**
 * Manage global unsubscribe list
 */
class Unsubscribe_Manager {

	/**
	 * Check if email is unsubscribed
	 *
	 * @param string $email Email address to check.
	 * @return bool True if unsubscribed, false otherwise.
	 */
	public function is_unsubscribed( $email ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ses_unsubscribes';
		$email = sanitize_email( $email );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE email = %s",
				$email
			)
		);

		return $count > 0;
	}

	/**
	 * Add email to unsubscribe list
	 *
	 * @param string $email  Email address.
	 * @param string $reason Unsubscribe reason.
	 * @return bool True on success, false on failure.
	 */
	public function add_to_unsubscribe_list( $email, $reason = '' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ses_unsubscribes';
		$email = sanitize_email( $email );

		if ( empty( $email ) || ! is_email( $email ) ) {
			return false;
		}

		// Check if already unsubscribed.
		if ( $this->is_unsubscribed( $email ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert(
			$table,
			array(
				'email'           => $email,
				'reason'          => sanitize_text_field( $reason ),
				'unsubscribed_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s' )
		);

		if ( $result ) {
			/**
			 * Fires after an email is added to the unsubscribe list.
			 *
			 * @param string $email  The email address.
			 * @param string $reason The unsubscribe reason.
			 */
			do_action( 'sessypress_email_unsubscribed', $email, $reason );
		}

		return (bool) $result;
	}

	/**
	 * Remove email from unsubscribe list
	 *
	 * @param string $email Email address.
	 * @return bool True on success, false on failure.
	 */
	public function remove_from_unsubscribe_list( $email ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ses_unsubscribes';
		$email = sanitize_email( $email );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'email' => $email ),
			array( '%s' )
		);

		if ( $result ) {
			/**
			 * Fires after an email is removed from the unsubscribe list.
			 *
			 * @param string $email The email address.
			 */
			do_action( 'sessypress_email_resubscribed', $email );
		}

		return (bool) $result;
	}

	/**
	 * Get total unsubscribe count
	 *
	 * @return int Total number of unsubscribed emails.
	 */
	public function get_unsubscribe_count() {
		global $wpdb;

		$table = $wpdb->prefix . 'ses_unsubscribes';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM $table" );

		return (int) $count;
	}

	/**
	 * Get all unsubscribed emails
	 *
	 * @param int $limit  Number of records to retrieve.
	 * @param int $offset Offset for pagination.
	 * @return array Array of unsubscribe records.
	 */
	public function get_unsubscribed_emails( $limit = 100, $offset = 0 ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'ses_unsubscribes';
		$limit  = absint( $limit );
		$offset = absint( $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table ORDER BY unsubscribed_at DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		return $results;
	}

	/**
	 * Export unsubscribe list to CSV
	 *
	 * @return string CSV content.
	 */
	public function export_to_csv() {
		global $wpdb;

		$table = $wpdb->prefix . 'ses_unsubscribes';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( "SELECT * FROM $table ORDER BY unsubscribed_at DESC", ARRAY_A );

		$csv = "Email,Reason,Unsubscribed At\n";

		foreach ( $results as $row ) {
			$csv .= sprintf(
				'"%s","%s","%s"' . "\n",
				str_replace( '"', '""', $row['email'] ),
				str_replace( '"', '""', $row['reason'] ),
				$row['unsubscribed_at']
			);
		}

		return $csv;
	}

	/**
	 * Filter wp_mail to block unsubscribed emails
	 *
	 * @param array $args wp_mail arguments.
	 * @return array Modified wp_mail arguments.
	 */
	public function filter_wp_mail( $args ) {
		// Get recipient(s).
		$recipients = isset( $args['to'] ) ? $args['to'] : array();

		if ( ! is_array( $recipients ) ) {
			$recipients = explode( ',', $recipients );
		}

		// Filter out unsubscribed emails.
		$filtered_recipients = array();

		foreach ( $recipients as $recipient ) {
			// Extract email from "Name <email@example.com>" format.
			$email = $recipient;
			if ( preg_match( '/<(.+)>/', $recipient, $matches ) ) {
				$email = $matches[1];
			}

			$email = sanitize_email( trim( $email ) );

			// Skip if unsubscribed.
			if ( $this->is_unsubscribed( $email ) ) {
				/**
				 * Fires when an email is blocked due to unsubscribe.
				 *
				 * @param string $email The blocked email address.
				 * @param array  $args  The wp_mail arguments.
				 */
				do_action( 'sessypress_email_blocked', $email, $args );
				continue;
			}

			$filtered_recipients[] = $recipient;
		}

		// If all recipients are unsubscribed, return false to cancel email.
		if ( empty( $filtered_recipients ) ) {
			/**
			 * Fires when an email is completely blocked.
			 *
			 * @param array $args The wp_mail arguments.
			 */
			do_action( 'sessypress_email_cancelled', $args );

			// Return empty array to prevent email from sending.
			add_filter( 'wp_mail', '__return_false', 99999 );
			return $args;
		}

		// Update recipients.
		$args['to'] = $filtered_recipients;

		return $args;
	}

	/**
	 * Handle unsubscribe tracking request
	 */
	public function handle_unsubscribe_request() {
		if ( ! isset( $_GET['ses_action'] ) || 'unsubscribe' !== $_GET['ses_action'] ) {
			return;
		}

		$recipient = isset( $_GET['r'] ) ? sanitize_email( wp_unslash( $_GET['r'] ) ) : '';

		if ( empty( $recipient ) || ! is_email( $recipient ) ) {
			wp_die( esc_html__( 'Invalid unsubscribe request.', 'sessypress' ) );
		}

		// Add to unsubscribe list.
		$this->add_to_unsubscribe_list( $recipient, __( 'User clicked unsubscribe link', 'sessypress' ) );

		// Display unsubscribe confirmation page.
		$this->display_unsubscribe_page( $recipient );

		exit;
	}

	/**
	 * Display unsubscribe confirmation page
	 *
	 * @param string $email Email address that was unsubscribed.
	 */
	private function display_unsubscribe_page( $email ) {
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Unsubscribed', 'sessypress' ); ?></title>
			<style>
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
					background: #f0f0f1;
					padding: 40px 20px;
					margin: 0;
				}
				.container {
					max-width: 500px;
					margin: 0 auto;
					background: #fff;
					padding: 40px;
					border-radius: 8px;
					box-shadow: 0 2px 4px rgba(0,0,0,0.1);
					text-align: center;
				}
				h1 {
					color: #2c3338;
					font-size: 24px;
					margin: 0 0 20px;
				}
				p {
					color: #50575e;
					line-height: 1.6;
					margin: 0 0 20px;
				}
				.email {
					background: #f6f7f7;
					padding: 10px 15px;
					border-radius: 4px;
					font-family: monospace;
					word-break: break-all;
				}
				.success-icon {
					font-size: 48px;
					color: #00a32a;
					margin-bottom: 20px;
				}
			</style>
		</head>
		<body>
			<div class="container">
				<div class="success-icon">✓</div>
				<h1><?php esc_html_e( 'You have been unsubscribed', 'sessypress' ); ?></h1>
				<p><?php esc_html_e( 'The following email address has been removed from our mailing list:', 'sessypress' ); ?></p>
				<p class="email"><?php echo esc_html( $email ); ?></p>
				<p><?php esc_html_e( 'You will no longer receive emails from us.', 'sessypress' ); ?></p>
			</div>
		</body>
		</html>
		<?php
	}
}

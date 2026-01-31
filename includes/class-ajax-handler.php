<?php
/**
 * AJAX Handler for Dashboard
 *
 * @package SESSYPress
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

/**
 * Class AJAX_Handler
 */
class AJAX_Handler {

	/**
	 * Register AJAX hooks
	 */
	public static function init() {
		add_action( 'wp_ajax_sessypress_load_timeline', array( __CLASS__, 'load_timeline' ) );
	}

	/**
	 * Load timeline events via AJAX
	 */
	public static function load_timeline() {
		// Verify nonce
		if ( ! check_ajax_referer( 'sessypress_timeline', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'sessypress' ) ) );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to access this data.', 'sessypress' ) ) );
		}

		global $wpdb;

		$events_table = $wpdb->prefix . 'ses_email_events';

		// Get filters
		$event_type   = isset( $_POST['event_type'] ) ? sanitize_text_field( wp_unslash( $_POST['event_type'] ) ) : '';
		$event_source = isset( $_POST['event_source'] ) ? sanitize_text_field( wp_unslash( $_POST['event_source'] ) ) : '';
		$recipient    = isset( $_POST['recipient'] ) ? sanitize_email( wp_unslash( $_POST['recipient'] ) ) : '';
		$message_id   = isset( $_POST['message_id'] ) ? sanitize_text_field( wp_unslash( $_POST['message_id'] ) ) : '';
		$date_from    = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$date_to      = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : gmdate( 'Y-m-d' );

		// Build WHERE clauses
		$where_clauses = array( '1=1' );
		$where_values  = array();

		if ( ! empty( $event_type ) ) {
			$where_clauses[] = 'event_type = %s';
			$where_values[]  = $event_type;
		}

		if ( ! empty( $event_source ) ) {
			$where_clauses[] = 'event_source = %s';
			$where_values[]  = $event_source;
		}

		if ( ! empty( $recipient ) ) {
			$where_clauses[] = 'recipient LIKE %s';
			$where_values[]  = '%' . $wpdb->esc_like( $recipient ) . '%';
		}

		if ( ! empty( $message_id ) ) {
			$where_clauses[] = 'message_id LIKE %s';
			$where_values[]  = '%' . $wpdb->esc_like( $message_id ) . '%';
		}

		$where_clauses[] = 'DATE(timestamp) BETWEEN %s AND %s';
		$where_values[]  = $date_from;
		$where_values[]  = $date_to;

		$where_sql = implode( ' AND ', $where_clauses );

		// Get events with pagination
		$limit  = 50;
		$offset = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$events = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM $events_table WHERE $where_sql ORDER BY timestamp DESC LIMIT %d OFFSET %d",
				array_merge( $where_values, array( $limit, $offset ) )
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_events = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM $events_table WHERE $where_sql",
				$where_values
			)
		);

		if ( empty( $events ) ) {
			wp_send_json_success(
				array(
					'html' => '<div class="notice notice-info inline" style="margin: 0;"><p>' . esc_html__( 'No events found matching your filters.', 'sessypress' ) . '</p></div>',
				)
			);
		}

		// Generate HTML
		ob_start();
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width: 150px;"><?php esc_html_e( 'Timestamp', 'sessypress' ); ?></th>
					<th style="width: 120px;"><?php esc_html_e( 'Event Type', 'sessypress' ); ?></th>
					<th style="width: 140px;"><?php esc_html_e( 'Event Source', 'sessypress' ); ?></th>
					<th><?php esc_html_e( 'Recipient', 'sessypress' ); ?></th>
					<th><?php esc_html_e( 'Subject', 'sessypress' ); ?></th>
					<th style="width: 100px;"><?php esc_html_e( 'Actions', 'sessypress' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $events as $event ) : ?>
					<?php
					// Determine badge color
					$badge_style  = self::get_event_badge_style( $event['event_type'] );
					$source_badge = self::get_source_badge_style( $event['event_source'] );
					?>
					<tr>
						<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', strtotime( $event['timestamp'] ) ) ); ?></td>
						<td>
							<span style="<?php echo esc_attr( $badge_style ); ?> color: #fff; padding: 4px 10px; border-radius: 3px; font-size: 11px; font-weight: 600; display: inline-block;">
								<?php echo esc_html( $event['event_type'] ); ?>
							</span>
						</td>
						<td>
							<span style="<?php echo esc_attr( $source_badge ); ?> padding: 4px 10px; border-radius: 3px; font-size: 11px; font-weight: 600; display: inline-block;">
								<?php echo esc_html( self::get_source_label( $event['event_source'] ) ); ?>
							</span>
						</td>
						<td>
							<code style="font-size: 11px;"><?php echo esc_html( $event['recipient'] ); ?></code>
						</td>
						<td><?php echo esc_html( $event['subject'] ? $event['subject'] : '—' ); ?></td>
						<td>
							<a href="#" class="sessypress-toggle-details" data-event-id="<?php echo esc_attr( $event['id'] ); ?>">
								<?php esc_html_e( 'Show Details', 'sessypress' ); ?>
							</a>
						</td>
					</tr>
					<tr class="sessypress-event-details">
						<td colspan="6">
							<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
								<div>
									<h4 style="margin: 0 0 10px; font-size: 13px;"><?php esc_html_e( 'Event Information', 'sessypress' ); ?></h4>
									<table class="widefat" style="background: #fff;">
										<tr>
											<td style="width: 150px; font-weight: 600;"><?php esc_html_e( 'Message ID:', 'sessypress' ); ?></td>
											<td><code style="font-size: 11px;"><?php echo esc_html( $event['message_id'] ); ?></code></td>
										</tr>
										<tr>
											<td style="font-weight: 600;"><?php esc_html_e( 'Sender:', 'sessypress' ); ?></td>
											<td><?php echo esc_html( $event['sender'] ); ?></td>
										</tr>
										<?php if ( $event['bounce_type'] ) : ?>
										<tr>
											<td style="font-weight: 600;"><?php esc_html_e( 'Bounce Type:', 'sessypress' ); ?></td>
											<td><?php echo esc_html( $event['bounce_type'] . ' / ' . $event['bounce_subtype'] ); ?></td>
										</tr>
										<?php endif; ?>
										<?php if ( $event['complaint_type'] ) : ?>
										<tr>
											<td style="font-weight: 600;"><?php esc_html_e( 'Complaint Type:', 'sessypress' ); ?></td>
											<td><?php echo esc_html( $event['complaint_type'] ); ?></td>
										</tr>
										<?php endif; ?>
										<?php if ( $event['smtp_response'] ) : ?>
										<tr>
											<td style="font-weight: 600;"><?php esc_html_e( 'SMTP Response:', 'sessypress' ); ?></td>
											<td><?php echo esc_html( $event['smtp_response'] ); ?></td>
										</tr>
										<?php endif; ?>
										<?php if ( $event['diagnostic_code'] ) : ?>
										<tr>
											<td style="font-weight: 600;"><?php esc_html_e( 'Diagnostic Code:', 'sessypress' ); ?></td>
											<td><?php echo esc_html( $event['diagnostic_code'] ); ?></td>
										</tr>
										<?php endif; ?>
									</table>
								</div>
								<div>
									<h4 style="margin: 0 0 10px; font-size: 13px;"><?php esc_html_e( 'Event Metadata', 'sessypress' ); ?></h4>
									<?php if ( $event['event_metadata'] ) : ?>
										<?php
										$metadata = json_decode( $event['event_metadata'], true );
										if ( $metadata ) :
											?>
											<table class="widefat" style="background: #fff;">
												<?php foreach ( $metadata as $key => $value ) : ?>
													<tr>
														<td style="width: 150px; font-weight: 600;"><?php echo esc_html( ucwords( str_replace( '_', ' ', $key ) ) ); ?>:</td>
														<td>
															<?php
															if ( is_array( $value ) ) {
																echo '<pre style="margin: 0; font-size: 11px;">' . esc_html( wp_json_encode( $value, JSON_PRETTY_PRINT ) ) . '</pre>';
															} else {
																echo esc_html( $value );
															}
															?>
														</td>
													</tr>
												<?php endforeach; ?>
											</table>
										<?php else : ?>
											<p class="description"><?php esc_html_e( 'Invalid metadata format', 'sessypress' ); ?></p>
										<?php endif; ?>
									<?php else : ?>
										<p class="description"><?php esc_html_e( 'No metadata available', 'sessypress' ); ?></p>
									<?php endif; ?>
								</div>
							</div>
							<?php if ( $event['raw_payload'] ) : ?>
							<div style="margin-top: 15px;">
								<h4 style="margin: 0 0 10px; font-size: 13px;"><?php esc_html_e( 'Raw Payload', 'sessypress' ); ?></h4>
								<pre style="background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 3px; overflow-x: auto; max-height: 300px; font-size: 11px;"><?php echo esc_html( $event['raw_payload'] ); ?></pre>
							</div>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<div style="margin-top: 15px; text-align: center; color: #646970;">
			<?php
			printf(
				/* translators: %1$d: number of events shown, %2$d: total events */
				esc_html__( 'Showing %1$d of %2$d events', 'sessypress' ),
				count( $events ),
				(int) $total_events
			);
			?>
		</div>
		<?php
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Get badge style for event type
	 *
	 * @param string $event_type Event type.
	 * @return string Badge style CSS.
	 */
	private static function get_event_badge_style( $event_type ) {
		$type = strtolower( $event_type );

		$styles = array(
			'send'             => 'background: #2271b1;',
			'delivery'         => 'background: #00a32a;',
			'reject'           => 'background: #dba617;',
			'bounce'           => 'background: #d63638;',
			'complaint'        => 'background: #cc1818;',
			'open'             => 'background: #00a32a;',
			'click'            => 'background: #00a32a;',
			'deliverydelay'    => 'background: #f0b849;',
			'renderingfailure' => 'background: #8c1bab;',
			'subscription'     => 'background: #50575e;',
		);

		return isset( $styles[ $type ] ) ? $styles[ $type ] : 'background: #646970;';
	}

	/**
	 * Get badge style for event source
	 *
	 * @param string $event_source Event source.
	 * @return string Badge style CSS.
	 */
	private static function get_source_badge_style( $event_source ) {
		$styles = array(
			'sns_notification' => 'background: #e7f3ff; color: #2271b1; border: 1px solid #2271b1;',
			'event_publishing' => 'background: #ecf7ed; color: #00a32a; border: 1px solid #00a32a;',
			'manual'           => 'background: #f0f0f1; color: #50575e; border: 1px solid #646970;',
		);

		return isset( $styles[ $event_source ] ) ? $styles[ $event_source ] : 'background: #f0f0f1; color: #646970;';
	}

	/**
	 * Get label for event source
	 *
	 * @param string $event_source Event source.
	 * @return string Human-readable label.
	 */
	private static function get_source_label( $event_source ) {
		$labels = array(
			'sns_notification' => __( 'SNS Notification', 'sessypress' ),
			'event_publishing' => __( 'Event Publishing', 'sessypress' ),
			'manual'           => __( 'Manual Tracking', 'sessypress' ),
		);

		return isset( $labels[ $event_source ] ) ? $labels[ $event_source ] : $event_source;
	}
}

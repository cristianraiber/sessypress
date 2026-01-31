<?php
/**
 * Admin dashboard page
 */

namespace SES_SNS_Tracker;

defined( 'ABSPATH' ) || exit;

// Get stats
global $wpdb;

$events_table   = $wpdb->prefix . 'ses_email_events';
$tracking_table = $wpdb->prefix . 'ses_email_tracking';

// Last 30 days
$date_from = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );

$total_delivered = $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $events_table WHERE event_type = 'delivery' AND timestamp >= %s",
	$date_from
) );

$total_bounced = $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $events_table WHERE event_type = 'bounce' AND timestamp >= %s",
	$date_from
) );

$total_complaints = $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $events_table WHERE event_type = 'complaint' AND timestamp >= %s",
	$date_from
) );

$total_opens = $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $tracking_table WHERE tracking_type = 'open' AND timestamp >= %s",
	$date_from
) );

$total_clicks = $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $tracking_table WHERE tracking_type = 'click' AND timestamp >= %s",
	$date_from
) );

$total_unsubscribes = $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM $tracking_table WHERE tracking_type = 'unsubscribe' AND timestamp >= %s",
	$date_from
) );

// Calculate rates
$bounce_rate    = $total_delivered > 0 ? round( ( $total_bounced / $total_delivered ) * 100, 2 ) : 0;
$complaint_rate = $total_delivered > 0 ? round( ( $total_complaints / $total_delivered ) * 100, 2 ) : 0;
$open_rate      = $total_delivered > 0 ? round( ( $total_opens / $total_delivered ) * 100, 2 ) : 0;
$click_rate     = $total_delivered > 0 ? round( ( $total_clicks / $total_delivered ) * 100, 2 ) : 0;

// Recent events
$recent_events = $wpdb->get_results( $wpdb->prepare(
	"SELECT * FROM $events_table ORDER BY timestamp DESC LIMIT %d",
	20
), ARRAY_A );

$settings = get_option( 'ses_sns_tracker_settings', array() );
$endpoint_url = rest_url( 'ses-sns-tracker/v1/' . ( $settings['sns_endpoint_slug'] ?? 'ses-sns-webhook' ) );
$secret_key = $settings['sns_secret_key'] ?? '';
?>

<div class="wrap">
	<h1><?php esc_html_e( 'SES Email Tracking Dashboard', 'ses-sns-tracker' ); ?></h1>

	<div class="card" style="max-width: 800px;">
		<h2><?php esc_html_e( 'SNS Endpoint Configuration', 'ses-sns-tracker' ); ?></h2>
		<p><?php esc_html_e( 'Configure your Amazon SNS subscriptions to send notifications to this endpoint:', 'ses-sns-tracker' ); ?></p>
		<p>
			<strong><?php esc_html_e( 'Endpoint URL:', 'ses-sns-tracker' ); ?></strong><br>
			<code style="font-size: 12px; background: #f0f0f0; padding: 8px; display: inline-block; margin-top: 5px;">
				<?php echo esc_html( $endpoint_url ); ?>?key=<?php echo esc_html( $secret_key ); ?>
			</code>
		</p>
		<p class="description">
			<?php esc_html_e( 'Add this URL as an HTTPS subscription endpoint in your SNS topics for bounce, complaint, and delivery notifications.', 'ses-sns-tracker' ); ?>
		</p>
	</div>

	<h2><?php esc_html_e( 'Email Statistics (Last 30 Days)', 'ses-sns-tracker' ); ?></h2>

	<div class="ses-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
		<div class="ses-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #2271b1; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Delivered', 'ses-sns-tracker' ); ?></h3>
			<p style="margin: 0; font-size: 32px; font-weight: 600; color: #2271b1;"><?php echo number_format_i18n( $total_delivered ); ?></p>
		</div>

		<div class="ses-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #d63638; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Bounced', 'ses-sns-tracker' ); ?></h3>
			<p style="margin: 0; font-size: 32px; font-weight: 600; color: #d63638;"><?php echo number_format_i18n( $total_bounced ); ?></p>
			<p style="margin: 5px 0 0; font-size: 12px; color: #646970;"><?php echo esc_html( $bounce_rate ); ?>% bounce rate</p>
		</div>

		<div class="ses-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #dba617; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Complaints', 'ses-sns-tracker' ); ?></h3>
			<p style="margin: 0; font-size: 32px; font-weight: 600; color: #dba617;"><?php echo number_format_i18n( $total_complaints ); ?></p>
			<p style="margin: 5px 0 0; font-size: 12px; color: #646970;"><?php echo esc_html( $complaint_rate ); ?>% complaint rate</p>
		</div>

		<div class="ses-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #00a32a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Opens', 'ses-sns-tracker' ); ?></h3>
			<p style="margin: 0; font-size: 32px; font-weight: 600; color: #00a32a;"><?php echo number_format_i18n( $total_opens ); ?></p>
			<p style="margin: 5px 0 0; font-size: 12px; color: #646970;"><?php echo esc_html( $open_rate ); ?>% open rate</p>
		</div>

		<div class="ses-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #00a32a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Clicks', 'ses-sns-tracker' ); ?></h3>
			<p style="margin: 0; font-size: 32px; font-weight: 600; color: #00a32a;"><?php echo number_format_i18n( $total_clicks ); ?></p>
			<p style="margin: 5px 0 0; font-size: 12px; color: #646970;"><?php echo esc_html( $click_rate ); ?>% click rate</p>
		</div>

		<div class="ses-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #646970; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Unsubscribes', 'ses-sns-tracker' ); ?></h3>
			<p style="margin: 0; font-size: 32px; font-weight: 600; color: #646970;"><?php echo number_format_i18n( $total_unsubscribes ); ?></p>
		</div>
	</div>

	<h2><?php esc_html_e( 'Recent Events', 'ses-sns-tracker' ); ?></h2>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Timestamp', 'ses-sns-tracker' ); ?></th>
				<th><?php esc_html_e( 'Type', 'ses-sns-tracker' ); ?></th>
				<th><?php esc_html_e( 'Recipient', 'ses-sns-tracker' ); ?></th>
				<th><?php esc_html_e( 'Subject', 'ses-sns-tracker' ); ?></th>
				<th><?php esc_html_e( 'Details', 'ses-sns-tracker' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! empty( $recent_events ) ) : ?>
				<?php foreach ( $recent_events as $event ) : ?>
					<tr>
						<td><?php echo esc_html( $event['timestamp'] ); ?></td>
						<td>
							<?php
							$badge_color = 'background: #646970;';
							if ( 'delivery' === $event['event_type'] ) {
								$badge_color = 'background: #00a32a;';
							} elseif ( 'bounce' === $event['event_type'] ) {
								$badge_color = 'background: #d63638;';
							} elseif ( 'complaint' === $event['event_type'] ) {
								$badge_color = 'background: #dba617;';
							}
							?>
							<span style="<?php echo esc_attr( $badge_color ); ?> color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600;">
								<?php echo esc_html( ucfirst( $event['event_type'] ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $event['recipient'] ); ?></td>
						<td><?php echo esc_html( $event['subject'] ); ?></td>
						<td>
							<?php if ( 'bounce' === $event['event_type'] ) : ?>
								<?php echo esc_html( $event['bounce_type'] . ' - ' . $event['bounce_subtype'] ); ?>
							<?php elseif ( 'complaint' === $event['event_type'] ) : ?>
								<?php echo esc_html( $event['complaint_type'] ); ?>
							<?php elseif ( 'delivery' === $event['event_type'] ) : ?>
								<?php echo esc_html( $event['smtp_response'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="5"><?php esc_html_e( 'No events found.', 'ses-sns-tracker' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
</div>

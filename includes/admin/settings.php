<?php
/**
 * Admin settings page - WordPress Settings API implementation
 *
 * @package SESSYPress
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'sessypress' ) );
}

$settings = get_option( 'sessypress_settings', array() );

// Backwards compatibility.
if ( empty( $settings ) ) {
	$settings = get_option( 'ses_sns_tracker_settings', array() );
}

// Defaults.
$settings = wp_parse_args(
	$settings,
	array(
		'sns_secret_key'         => wp_generate_password( 32, false ),
		'sns_endpoint_slug'      => 'ses-sns-webhook',
		'track_opens'            => '1',
		'track_clicks'           => '1',
		'retention_days'         => 90,
		'enable_manual_tracking' => '1',
	)
);

$endpoint_url = rest_url( 'sessypress/v1/' . $settings['sns_endpoint_slug'] );

// Get quick stats for sidebar.
global $wpdb;
$events_table = $wpdb->prefix . 'ses_email_events';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$total_events = $wpdb->get_var( "SELECT COUNT(*) FROM $events_table" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$event_sources = $wpdb->get_results(
	"SELECT event_source, COUNT(*) as count 
	FROM $events_table 
	GROUP BY event_source",
	ARRAY_A
);

$source_counts = array(
	'sns_notification' => 0,
	'event_publishing' => 0,
	'manual'           => 0,
);

foreach ( $event_sources as $row ) {
	if ( isset( $source_counts[ $row['event_source'] ] ) ) {
		$source_counts[ $row['event_source'] ] = (int) $row['count'];
	}
}
?>

<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'sessypress_messages' ); ?>

	<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
		
		<!-- Main Settings Form -->
		<div>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'sessypress_settings_group' );
				do_settings_sections( 'sessypress-settings' );
				submit_button();
				?>
			</form>

			<!-- Setup Instructions -->
			<div class="card">
				<h2><?php esc_html_e( 'Setup Instructions', 'sessypress' ); ?></h2>
				
				<div class="sessypress-setup-tabs">
					<h3><?php esc_html_e( 'Option 1: SNS Notifications (Legacy)', 'sessypress' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Go to AWS SNS Console > Topics', 'sessypress' ); ?></li>
						<li><?php esc_html_e( 'Create topics for: Bounces, Complaints, Deliveries', 'sessypress' ); ?></li>
						<li><?php esc_html_e( 'Create HTTPS subscriptions pointing to your endpoint URL', 'sessypress' ); ?></li>
						<li><?php esc_html_e( 'Go to SES > Identities > Select domain > Notifications', 'sessypress' ); ?></li>
						<li><?php esc_html_e( 'Link SNS topics to notification types', 'sessypress' ); ?></li>
					</ol>
					<p class="description">
						<strong><?php esc_html_e( 'Pros:', 'sessypress' ); ?></strong> <?php esc_html_e( 'Simple setup, reliable', 'sessypress' ); ?><br>
						<strong><?php esc_html_e( 'Cons:', 'sessypress' ); ?></strong> <?php esc_html_e( 'No open/click tracking, no delivery delays', 'sessypress' ); ?>
					</p>

					<h3><?php esc_html_e( 'Option 2: Event Publishing (Recommended)', 'sessypress' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Go to SES > Configuration Sets', 'sessypress' ); ?></li>
						<li><?php esc_html_e( 'Create a new configuration set', 'sessypress' ); ?></li>
						<li><?php esc_html_e( 'Add Event Destination > SNS', 'sessypress' ); ?></li>
						<li><?php esc_html_e( 'Create or select an SNS topic', 'sessypress' ); ?></li>
						<li><?php esc_html_e( 'Select all event types (Send, Reject, Bounce, etc.)', 'sessypress' ); ?></li>
						<li><?php esc_html_e( 'Subscribe to the SNS topic with your endpoint URL', 'sessypress' ); ?></li>
						<li><?php esc_html_e( 'Use this Configuration Set when sending emails', 'sessypress' ); ?></li>
					</ol>
					<p class="description">
						<strong><?php esc_html_e( 'Pros:', 'sessypress' ); ?></strong> <?php esc_html_e( 'Complete tracking, opens, clicks, delivery delays', 'sessypress' ); ?><br>
						<strong><?php esc_html_e( 'Cons:', 'sessypress' ); ?></strong> <?php esc_html_e( 'Requires configuration set in email headers', 'sessypress' ); ?>
					</p>
				</div>
			</div>

			<!-- Testing Guide -->
			<div class="card">
				<h2><?php esc_html_e( 'Testing Your Setup', 'sessypress' ); ?></h2>
				<p><?php esc_html_e( 'Use the AWS SES Mailbox Simulator to test different scenarios:', 'sessypress' ); ?></p>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Email Address', 'sessypress' ); ?></th>
							<th><?php esc_html_e( 'Result', 'sessypress' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>success@simulator.amazonses.com</code></td>
							<td><?php esc_html_e( 'Successful delivery', 'sessypress' ); ?></td>
						</tr>
						<tr>
							<td><code>bounce@simulator.amazonses.com</code></td>
							<td><?php esc_html_e( 'Hard bounce', 'sessypress' ); ?></td>
						</tr>
						<tr>
							<td><code>ooto@simulator.amazonses.com</code></td>
							<td><?php esc_html_e( 'Out of office (soft bounce)', 'sessypress' ); ?></td>
						</tr>
						<tr>
							<td><code>complaint@simulator.amazonses.com</code></td>
							<td><?php esc_html_e( 'Spam complaint', 'sessypress' ); ?></td>
						</tr>
						<tr>
							<td><code>suppressionlist@simulator.amazonses.com</code></td>
							<td><?php esc_html_e( 'Suppression list', 'sessypress' ); ?></td>
						</tr>
					</tbody>
				</table>
				<p class="description">
					<?php esc_html_e( 'Send test emails to these addresses and check the Dashboard for events within a few seconds.', 'sessypress' ); ?>
				</p>
			</div>
		</div>

		<!-- Sidebar -->
		<div>
			<!-- Quick Stats -->
			<div class="postbox">
				<h2 class="hndle"><?php esc_html_e( 'Quick Stats', 'sessypress' ); ?></h2>
				<div class="inside">
					<div class="sessypress-quick-stat">
						<div class="sessypress-stat-label"><?php esc_html_e( 'Total Events:', 'sessypress' ); ?></div>
						<div class="sessypress-stat-number"><?php echo esc_html( number_format_i18n( (int) $total_events ) ); ?></div>
					</div>
					<hr>
					<table class="widefat striped">
						<tbody>
							<tr>
								<td><?php esc_html_e( 'SNS Notifications:', 'sessypress' ); ?></td>
								<td style="text-align: right;"><strong><?php echo esc_html( number_format_i18n( $source_counts['sns_notification'] ) ); ?></strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Event Publishing:', 'sessypress' ); ?></td>
								<td style="text-align: right;"><strong><?php echo esc_html( number_format_i18n( $source_counts['event_publishing'] ) ); ?></strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Manual Tracking:', 'sessypress' ); ?></td>
								<td style="text-align: right;"><strong><?php echo esc_html( number_format_i18n( $source_counts['manual'] ) ); ?></strong></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Tracking Modes Info -->
			<div class="postbox">
				<h2 class="hndle"><?php esc_html_e( 'Tracking Modes', 'sessypress' ); ?></h2>
				<div class="inside">
					<div class="sessypress-info-box sessypress-info-sns">
						<strong><?php esc_html_e( 'SNS Notifications', 'sessypress' ); ?></strong>
						<p><?php esc_html_e( 'Tracks bounces, complaints, and deliveries. Reliable but limited event types.', 'sessypress' ); ?></p>
					</div>
					<div class="sessypress-info-box sessypress-info-event">
						<strong><?php esc_html_e( 'Event Publishing', 'sessypress' ); ?></strong>
						<p><?php esc_html_e( 'Tracks all events including opens, clicks, delivery delays. Requires Configuration Set.', 'sessypress' ); ?></p>
					</div>
					<div class="sessypress-info-box sessypress-info-manual">
						<strong><?php esc_html_e( 'Manual Tracking', 'sessypress' ); ?></strong>
						<p><?php esc_html_e( 'Fallback tracking via pixel and URL rewriting. Works without SES configuration.', 'sessypress' ); ?></p>
					</div>
				</div>
			</div>

			<!-- Resources -->
			<div class="postbox">
				<h2 class="hndle"><?php esc_html_e( 'Resources', 'sessypress' ); ?></h2>
				<div class="inside">
					<ul>
						<li>
							<span class="dashicons dashicons-external"></span>
							<a href="https://docs.aws.amazon.com/ses/latest/dg/event-publishing-setting-up.html" target="_blank">
								<?php esc_html_e( 'AWS Event Publishing Guide', 'sessypress' ); ?>
							</a>
						</li>
						<li>
							<span class="dashicons dashicons-external"></span>
							<a href="https://docs.aws.amazon.com/ses/latest/dg/configure-sns-notifications.html" target="_blank">
								<?php esc_html_e( 'AWS SNS Notifications Guide', 'sessypress' ); ?>
							</a>
						</li>
						<li>
							<span class="dashicons dashicons-external"></span>
							<a href="https://docs.aws.amazon.com/ses/latest/dg/send-email-simulator.html" target="_blank">
								<?php esc_html_e( 'SES Mailbox Simulator', 'sessypress' ); ?>
							</a>
						</li>
					</ul>
				</div>
			</div>
		</div>
	</div>
</div>

<style>
.sessypress-quick-stat {
	padding: 15px 0;
	text-align: center;
}
.sessypress-stat-label {
	font-size: 12px;
	color: #646970;
	text-transform: uppercase;
	margin-bottom: 10px;
}
.sessypress-stat-number {
	font-size: 32px;
	font-weight: 600;
	color: #2271b1;
}
.sessypress-info-box {
	padding: 12px;
	margin-bottom: 10px;
	border-left: 3px solid #ccc;
}
.sessypress-info-box:last-child {
	margin-bottom: 0;
}
.sessypress-info-box strong {
	display: block;
	margin-bottom: 5px;
}
.sessypress-info-box p {
	margin: 0;
	font-size: 12px;
	color: #646970;
}
.sessypress-info-sns {
	background: #e7f3ff;
	border-left-color: #2271b1;
}
.sessypress-info-sns strong {
	color: #2271b1;
}
.sessypress-info-event {
	background: #ecf7ed;
	border-left-color: #00a32a;
}
.sessypress-info-event strong {
	color: #00a32a;
}
.sessypress-info-manual {
	background: #f0f0f1;
	border-left-color: #646970;
}
.sessypress-info-manual strong {
	color: #50575e;
}
.postbox .inside ul {
	margin: 0;
}
.postbox .inside ul li {
	padding: 5px 0;
}
.postbox .inside ul li .dashicons {
	font-size: 16px;
	width: 16px;
	height: 16px;
	vertical-align: middle;
	color: #2271b1;
}
</style>

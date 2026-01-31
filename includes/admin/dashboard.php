<?php
/**
 * Admin dashboard page - Day 2: Dual-mode tracking UI
 *
 * @package SESSYPress
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'sessypress' ) );
}

global $wpdb;

$events_table   = $wpdb->prefix . 'ses_email_events';
$tracking_table = $wpdb->prefix . 'ses_email_tracking';

// Get filter parameters
$event_source_filter = isset( $_GET['event_source'] ) ? sanitize_text_field( wp_unslash( $_GET['event_source'] ) ) : 'all';
$date_from           = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
$date_to             = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : gmdate( 'Y-m-d' );

// Build WHERE clause for event_source filter
$where_source = '';
if ( 'sns' === $event_source_filter ) {
	$where_source = " AND event_source = 'sns_notification'";
} elseif ( 'event_publishing' === $event_source_filter ) {
	$where_source = " AND event_source = 'event_publishing'";
} elseif ( 'manual' === $event_source_filter ) {
	$where_source = " AND event_source = 'manual'";
}

$date_range_sql = $wpdb->prepare( ' AND DATE(timestamp) BETWEEN %s AND %s', $date_from, $date_to );

// Get comprehensive stats
$stats = array(
	'total_sent'             => 0,
	'total_delivered'        => 0,
	'total_rejected'         => 0,
	'total_bounced'          => 0,
	'total_complaints'       => 0,
	'total_opens'            => 0,
	'total_clicks'           => 0,
	'delivery_delays'        => 0,
	'rendering_failures'     => 0,
	'subscription_events'    => 0,
	'sns_events'             => 0,
	'event_publishing_count' => 0,
	'manual_events'          => 0,
);

// Count by event types
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$event_counts = $wpdb->get_results(
	"SELECT event_type, event_source, COUNT(*) as count 
	FROM $events_table 
	WHERE 1=1 $date_range_sql $where_source
	GROUP BY event_type, event_source",
	ARRAY_A
);

foreach ( $event_counts as $row ) {
	$type   = strtolower( $row['event_type'] );
	$source = $row['event_source'];
	$count  = (int) $row['count'];

	// Count by source
	if ( 'sns_notification' === $source ) {
		$stats['sns_events'] += $count;
	} elseif ( 'event_publishing' === $source ) {
		$stats['event_publishing_count'] += $count;
	} elseif ( 'manual' === $source ) {
		$stats['manual_events'] += $count;
	}

	// Count by type
	switch ( $type ) {
		case 'send':
			$stats['total_sent'] += $count;
			break;
		case 'delivery':
			$stats['total_delivered'] += $count;
			break;
		case 'reject':
			$stats['total_rejected'] += $count;
			break;
		case 'bounce':
			$stats['total_bounced'] += $count;
			break;
		case 'complaint':
			$stats['total_complaints'] += $count;
			break;
		case 'open':
			$stats['total_opens'] += $count;
			break;
		case 'click':
			$stats['total_clicks'] += $count;
			break;
		case 'deliverydelay':
			$stats['delivery_delays'] += $count;
			break;
		case 'renderingfailure':
			$stats['rendering_failures'] += $count;
			break;
		case 'subscription':
			$stats['subscription_events'] += $count;
			break;
	}
}

// Get click heatmap data
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$click_heatmap = $wpdb->get_results(
	"SELECT 
		JSON_UNQUOTE(JSON_EXTRACT(event_metadata, '$.link')) as link,
		COUNT(*) as click_count
	FROM $events_table
	WHERE event_type = 'Click' 
		AND event_source = 'event_publishing'
		$date_range_sql
		AND event_metadata IS NOT NULL
		AND JSON_EXTRACT(event_metadata, '$.link') IS NOT NULL
	GROUP BY link
	ORDER BY click_count DESC
	LIMIT 10",
	ARRAY_A
);

// Get open rate by hour
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$opens_by_hour = $wpdb->get_results(
	"SELECT 
		HOUR(timestamp) as hour,
		COUNT(*) as open_count
	FROM $events_table
	WHERE event_type = 'Open'
		AND event_source = 'event_publishing'
		$date_range_sql
	GROUP BY hour
	ORDER BY hour",
	ARRAY_A
);

// Format data for Chart.js
$hours_data = array_fill( 0, 24, 0 );
foreach ( $opens_by_hour as $row ) {
	$hours_data[ (int) $row['hour'] ] = (int) $row['open_count'];
}

// Calculate rates
$bounce_rate    = $stats['total_delivered'] > 0 ? round( ( $stats['total_bounced'] / $stats['total_delivered'] ) * 100, 2 ) : 0;
$complaint_rate = $stats['total_delivered'] > 0 ? round( ( $stats['total_complaints'] / $stats['total_delivered'] ) * 100, 2 ) : 0;
$open_rate      = $stats['total_delivered'] > 0 ? round( ( $stats['total_opens'] / $stats['total_delivered'] ) * 100, 2 ) : 0;
$click_rate     = $stats['total_delivered'] > 0 ? round( ( $stats['total_clicks'] / $stats['total_delivered'] ) * 100, 2 ) : 0;

$settings     = get_option( 'sessypress_settings', array() );
$endpoint_url = rest_url( 'sessypress/v1/' . ( $settings['sns_endpoint_slug'] ?? 'ses-sns-webhook' ) );
$secret_key   = $settings['sns_secret_key'] ?? '';
?>

<div class="wrap sessypress-dashboard">
	<h1><?php esc_html_e( 'SESSYPress Dashboard', 'sessypress' ); ?></h1>

	<!-- SNS Endpoint Configuration -->
	<div class="card" style="max-width: 800px; margin-bottom: 20px;">
		<h2><?php esc_html_e( 'SNS Endpoint Configuration', 'sessypress' ); ?></h2>
		<p><?php esc_html_e( 'Configure your Amazon SNS subscriptions to send notifications to this endpoint:', 'sessypress' ); ?></p>
		<p>
			<strong><?php esc_html_e( 'Endpoint URL:', 'sessypress' ); ?></strong><br>
			<code style="font-size: 12px; background: #f0f0f0; padding: 8px; display: inline-block; margin-top: 5px;">
				<?php echo esc_html( $endpoint_url ); ?>?key=<?php echo esc_html( $secret_key ); ?>
			</code>
		</p>
		<p class="description">
			<?php esc_html_e( 'Add this URL as an HTTPS subscription endpoint in your SNS topics for bounce, complaint, and delivery notifications.', 'sessypress' ); ?>
		</p>
	</div>

	<!-- Filters -->
	<div class="sessypress-filters" style="background: #fff; padding: 15px; margin-bottom: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
		<form method="get" id="sessypress-filter-form">
			<input type="hidden" name="page" value="sessypress-dashboard" />
			
			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
				<div>
					<label for="event_source" style="font-weight: 600; display: block; margin-bottom: 5px;">
						<?php esc_html_e( 'Event Source:', 'sessypress' ); ?>
					</label>
					<select name="event_source" id="event_source" class="widefat">
						<option value="all" <?php selected( $event_source_filter, 'all' ); ?>><?php esc_html_e( 'All Events', 'sessypress' ); ?></option>
						<option value="sns" <?php selected( $event_source_filter, 'sns' ); ?>><?php esc_html_e( 'SNS Only', 'sessypress' ); ?></option>
						<option value="event_publishing" <?php selected( $event_source_filter, 'event_publishing' ); ?>><?php esc_html_e( 'Event Publishing Only', 'sessypress' ); ?></option>
						<option value="manual" <?php selected( $event_source_filter, 'manual' ); ?>><?php esc_html_e( 'Manual Tracking Only', 'sessypress' ); ?></option>
					</select>
				</div>

				<div>
					<label for="date_from" style="font-weight: 600; display: block; margin-bottom: 5px;">
						<?php esc_html_e( 'From:', 'sessypress' ); ?>
					</label>
					<input type="date" name="date_from" id="date_from" value="<?php echo esc_attr( $date_from ); ?>" class="widefat" />
				</div>

				<div>
					<label for="date_to" style="font-weight: 600; display: block; margin-bottom: 5px;">
						<?php esc_html_e( 'To:', 'sessypress' ); ?>
					</label>
					<input type="date" name="date_to" id="date_to" value="<?php echo esc_attr( $date_to ); ?>" class="widefat" />
				</div>

				<div>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Filters', 'sessypress' ); ?></button>
					<a href="?page=sessypress-dashboard" class="button"><?php esc_html_e( 'Reset', 'sessypress' ); ?></a>
				</div>
			</div>
		</form>
	</div>

	<!-- Statistics Grid -->
	<h2><?php esc_html_e( 'Email Statistics', 'sessypress' ); ?></h2>
	<p class="description" style="margin-bottom: 15px;">
		<?php
		printf(
			/* translators: %1$s: start date, %2$s: end date */
			esc_html__( 'Showing data from %1$s to %2$s', 'sessypress' ),
			esc_html( gmdate( 'F j, Y', strtotime( $date_from ) ) ),
			esc_html( gmdate( 'F j, Y', strtotime( $date_to ) ) )
		);
		?>
	</p>

	<div class="sessypress-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px;">
		
		<!-- Total Sent -->
		<div class="sessypress-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #2271b1; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Total Sent', 'sessypress' ); ?></h3>
			<p style="margin: 0; font-size: 32px; font-weight: 600; color: #2271b1;"><?php echo number_format_i18n( $stats['total_sent'] ); ?></p>
			<span class="sessypress-badge" style="background: #e7f3ff; color: #2271b1; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; display: inline-block; margin-top: 5px;">
				<?php esc_html_e( 'Send Events', 'sessypress' ); ?>
			</span>
		</div>

		<!-- Delivered -->
		<div class="sessypress-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #00a32a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Delivered', 'sessypress' ); ?></h3>
			<p style="margin: 0; font-size: 32px; font-weight: 600; color: #00a32a;"><?php echo number_format_i18n( $stats['total_delivered'] ); ?></p>
			<span class="sessypress-badge" style="background: #ecf7ed; color: #00a32a; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; display: inline-block; margin-top: 5px;">
				<?php esc_html_e( 'Delivery Events', 'sessypress' ); ?>
			</span>
		</div>

		<!-- Rejected -->
		<div class="sessypress-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #dba617; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Rejected', 'sessypress' ); ?></h3>
			<p style="margin: 0; font-size: 32px; font-weight: 600; color: #dba617;"><?php echo number_format_i18n( $stats['total_rejected'] ); ?></p>
			<span class="sessypress-badge" style="background: #fcf3e3; color: #dba617; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; display: inline-block; margin-top: 5px;">
				<?php esc_html_e( 'Reject Events', 'sessypress' ); ?>
			</span>
		</div>

		<!-- Bounced -->
		<div class="sessypress-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #d63638; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Bounced', 'sessypress' ); ?></h3>
			<p style="margin: 0; font-size: 32px; font-weight: 600; color: #d63638;"><?php echo number_format_i18n( $stats['total_bounced'] ); ?></p>
			<p style="margin: 5px 0 0; font-size: 12px; color: #646970;"><?php echo esc_html( $bounce_rate ); ?>% bounce rate</p>
		</div>

		<!-- Complaints -->
		<div class="sessypress-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #cc1818; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Complaints', 'sessypress' ); ?></h3>
			<p style="margin: 0; font-size: 32px; font-weight: 600; color: #cc1818;"><?php echo number_format_i18n( $stats['total_complaints'] ); ?></p>
			<p style="margin: 5px 0 0; font-size: 12px; color: #646970;"><?php echo esc_html( $complaint_rate ); ?>% complaint rate</p>
		</div>

		<!-- Opens -->
		<div class="sessypress-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #00a32a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Opens', 'sessypress' ); ?></h3>
			<p style="margin: 0; font-size: 32px; font-weight: 600; color: #00a32a;"><?php echo number_format_i18n( $stats['total_opens'] ); ?></p>
			<p style="margin: 5px 0 0; font-size: 12px; color: #646970;"><?php echo esc_html( $open_rate ); ?>% open rate</p>
		</div>

		<!-- Clicks -->
		<div class="sessypress-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #00a32a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Clicks', 'sessypress' ); ?></h3>
			<p style="margin: 0; font-size: 32px; font-weight: 600; color: #00a32a;"><?php echo number_format_i18n( $stats['total_clicks'] ); ?></p>
			<p style="margin: 5px 0 0; font-size: 12px; color: #646970;"><?php echo esc_html( $click_rate ); ?>% click rate</p>
		</div>

		<!-- Delivery Delays -->
		<div class="sessypress-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #f0b849; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Delivery Delays', 'sessypress' ); ?></h3>
			<p style="margin: 0; font-size: 32px; font-weight: 600; color: #f0b849;"><?php echo number_format_i18n( $stats['delivery_delays'] ); ?></p>
			<span class="sessypress-badge" style="background: #fef8ee; color: #f0b849; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; display: inline-block; margin-top: 5px;">
				<?php esc_html_e( 'Delayed', 'sessypress' ); ?>
			</span>
		</div>

		<!-- Rendering Failures -->
		<div class="sessypress-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #8c1bab; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Rendering Failures', 'sessypress' ); ?></h3>
			<p style="margin: 0; font-size: 32px; font-weight: 600; color: #8c1bab;"><?php echo number_format_i18n( $stats['rendering_failures'] ); ?></p>
			<span class="sessypress-badge" style="background: #f3e5f7; color: #8c1bab; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; display: inline-block; margin-top: 5px;">
				<?php esc_html_e( 'Template Errors', 'sessypress' ); ?>
			</span>
		</div>

		<!-- Subscription Events -->
		<div class="sessypress-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #50575e; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Subscription Events', 'sessypress' ); ?></h3>
			<p style="margin: 0; font-size: 32px; font-weight: 600; color: #50575e;"><?php echo number_format_i18n( $stats['subscription_events'] ); ?></p>
			<span class="sessypress-badge" style="background: #f0f0f1; color: #50575e; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; display: inline-block; margin-top: 5px;">
				<?php esc_html_e( 'SNS Confirmations', 'sessypress' ); ?>
			</span>
		</div>

		<!-- Event Sources Breakdown -->
		<?php if ( 'all' === $event_source_filter ) : ?>
		<div class="sessypress-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #2271b1; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
			<h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;"><?php esc_html_e( 'Event Sources', 'sessypress' ); ?></h3>
			<div style="margin-top: 10px;">
				<div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
					<span style="font-size: 12px;"><?php esc_html_e( 'SNS:', 'sessypress' ); ?></span>
					<strong style="color: #2271b1;"><?php echo number_format_i18n( $stats['sns_events'] ); ?></strong>
				</div>
				<div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
					<span style="font-size: 12px;"><?php esc_html_e( 'Event Pub:', 'sessypress' ); ?></span>
					<strong style="color: #00a32a;"><?php echo number_format_i18n( $stats['event_publishing_count'] ); ?></strong>
				</div>
				<div style="display: flex; justify-content: space-between;">
					<span style="font-size: 12px;"><?php esc_html_e( 'Manual:', 'sessypress' ); ?></span>
					<strong style="color: #646970;"><?php echo number_format_i18n( $stats['manual_events'] ); ?></strong>
				</div>
			</div>
		</div>
		<?php endif; ?>
	</div>

	<!-- Charts Section -->
	<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
		
		<!-- Click Heatmap -->
		<div class="card">
			<h2><?php esc_html_e( 'Click Heatmap - Top 10 Links', 'sessypress' ); ?></h2>
			<?php if ( ! empty( $click_heatmap ) ) : ?>
				<div class="sessypress-click-heatmap">
					<?php
					$max_clicks = max( array_column( $click_heatmap, 'click_count' ) );
					foreach ( $click_heatmap as $item ) :
						$link        = $item['link'] ? $item['link'] : __( '(Unknown)', 'sessypress' );
						$click_count = (int) $item['click_count'];
						$percentage  = $max_clicks > 0 ? ( $click_count / $max_clicks ) * 100 : 0;
						?>
						<div style="margin-bottom: 15px;">
							<div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
								<span style="font-size: 12px; color: #50575e; word-break: break-all; max-width: 70%;" title="<?php echo esc_attr( $link ); ?>">
									<?php echo esc_html( strlen( $link ) > 50 ? substr( $link, 0, 50 ) . '...' : $link ); ?>
								</span>
								<strong style="color: #2271b1;"><?php echo number_format_i18n( $click_count ); ?></strong>
							</div>
							<div style="background: #f0f0f1; height: 20px; border-radius: 3px; overflow: hidden;">
								<div style="background: linear-gradient(90deg, #2271b1, #00a32a); height: 100%; width: <?php echo esc_attr( $percentage ); ?>%; transition: width 0.3s;"></div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No click data available for the selected period.', 'sessypress' ); ?></p>
			<?php endif; ?>
		</div>

		<!-- Open Rate by Hour -->
		<div class="card">
			<h2><?php esc_html_e( 'Open Rate by Hour of Day', 'sessypress' ); ?></h2>
			<canvas id="sessypress-opens-chart" width="400" height="250"></canvas>
			<p class="description" style="margin-top: 10px;">
				<?php esc_html_e( 'Shows when recipients are most likely to open emails. Use this to optimize your send times.', 'sessypress' ); ?>
			</p>
		</div>
	</div>

	<!-- Event Timeline with AJAX Filtering -->
	<h2><?php esc_html_e( 'Event Timeline', 'sessypress' ); ?></h2>
	
	<div id="sessypress-timeline-filters" style="background: #fff; padding: 15px; margin-bottom: 10px; border: 1px solid #ccd0d4;">
		<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px;">
			<div>
				<label for="timeline-event-type" style="display: block; margin-bottom: 3px; font-size: 12px; font-weight: 600;">
					<?php esc_html_e( 'Event Type:', 'sessypress' ); ?>
				</label>
				<select id="timeline-event-type" class="widefat">
					<option value=""><?php esc_html_e( 'All Types', 'sessypress' ); ?></option>
					<option value="Send"><?php esc_html_e( 'Send', 'sessypress' ); ?></option>
					<option value="Delivery"><?php esc_html_e( 'Delivery', 'sessypress' ); ?></option>
					<option value="Reject"><?php esc_html_e( 'Reject', 'sessypress' ); ?></option>
					<option value="Bounce"><?php esc_html_e( 'Bounce', 'sessypress' ); ?></option>
					<option value="Complaint"><?php esc_html_e( 'Complaint', 'sessypress' ); ?></option>
					<option value="Open"><?php esc_html_e( 'Open', 'sessypress' ); ?></option>
					<option value="Click"><?php esc_html_e( 'Click', 'sessypress' ); ?></option>
					<option value="DeliveryDelay"><?php esc_html_e( 'Delivery Delay', 'sessypress' ); ?></option>
					<option value="RenderingFailure"><?php esc_html_e( 'Rendering Failure', 'sessypress' ); ?></option>
					<option value="Subscription"><?php esc_html_e( 'Subscription', 'sessypress' ); ?></option>
				</select>
			</div>

			<div>
				<label for="timeline-event-source" style="display: block; margin-bottom: 3px; font-size: 12px; font-weight: 600;">
					<?php esc_html_e( 'Event Source:', 'sessypress' ); ?>
				</label>
				<select id="timeline-event-source" class="widefat">
					<option value=""><?php esc_html_e( 'All Sources', 'sessypress' ); ?></option>
					<option value="sns_notification"><?php esc_html_e( 'SNS Notification', 'sessypress' ); ?></option>
					<option value="event_publishing"><?php esc_html_e( 'Event Publishing', 'sessypress' ); ?></option>
					<option value="manual"><?php esc_html_e( 'Manual Tracking', 'sessypress' ); ?></option>
				</select>
			</div>

			<div>
				<label for="timeline-recipient" style="display: block; margin-bottom: 3px; font-size: 12px; font-weight: 600;">
					<?php esc_html_e( 'Recipient:', 'sessypress' ); ?>
				</label>
				<input type="text" id="timeline-recipient" class="widefat" placeholder="<?php esc_attr_e( 'Search by email...', 'sessypress' ); ?>" />
			</div>

			<div>
				<label for="timeline-message-id" style="display: block; margin-bottom: 3px; font-size: 12px; font-weight: 600;">
					<?php esc_html_e( 'Message ID:', 'sessypress' ); ?>
				</label>
				<input type="text" id="timeline-message-id" class="widefat" placeholder="<?php esc_attr_e( 'Search by message ID...', 'sessypress' ); ?>" />
			</div>

			<div>
				<label style="display: block; margin-bottom: 3px; font-size: 12px; font-weight: 600;">&nbsp;</label>
				<button type="button" id="timeline-apply-filters" class="button button-primary widefat">
					<?php esc_html_e( 'Apply Filters', 'sessypress' ); ?>
				</button>
			</div>

			<div>
				<label style="display: block; margin-bottom: 3px; font-size: 12px; font-weight: 600;">&nbsp;</label>
				<button type="button" id="timeline-reset-filters" class="button widefat">
					<?php esc_html_e( 'Reset', 'sessypress' ); ?>
				</button>
			</div>
		</div>
	</div>

	<div id="sessypress-timeline-container">
		<div id="sessypress-timeline-loading" style="display: none; text-align: center; padding: 40px;">
			<span class="spinner is-active" style="float: none; margin: 0;"></span>
			<p><?php esc_html_e( 'Loading events...', 'sessypress' ); ?></p>
		</div>
		<div id="sessypress-timeline-results"></div>
	</div>
</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
jQuery(document).ready(function($) {
	
	// Initialize Open Rate by Hour Chart
	var ctx = document.getElementById('sessypress-opens-chart');
	if (ctx) {
		var hoursData = <?php echo wp_json_encode( $hours_data ); ?>;
		
		new Chart(ctx, {
			type: 'line',
			data: {
				labels: [
					'12am', '1am', '2am', '3am', '4am', '5am', '6am', '7am', '8am', '9am', '10am', '11am',
					'12pm', '1pm', '2pm', '3pm', '4pm', '5pm', '6pm', '7pm', '8pm', '9pm', '10pm', '11pm'
				],
				datasets: [{
					label: '<?php echo esc_js( __( 'Email Opens', 'sessypress' ) ); ?>',
					data: hoursData,
					borderColor: '#2271b1',
					backgroundColor: 'rgba(34, 113, 177, 0.1)',
					tension: 0.4,
					fill: true
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: true,
				plugins: {
					legend: {
						display: false
					},
					tooltip: {
						callbacks: {
							label: function(context) {
								return context.parsed.y + ' opens';
							}
						}
					}
				},
				scales: {
					y: {
						beginAtZero: true,
						ticks: {
							precision: 0
						}
					}
				}
			}
		});
	}

	// AJAX Timeline Functionality
	function loadTimeline() {
		var filters = {
			action: 'sessypress_load_timeline',
			nonce: '<?php echo esc_js( wp_create_nonce( 'sessypress_timeline' ) ); ?>',
			event_type: $('#timeline-event-type').val(),
			event_source: $('#timeline-event-source').val(),
			recipient: $('#timeline-recipient').val(),
			message_id: $('#timeline-message-id').val(),
			date_from: '<?php echo esc_js( $date_from ); ?>',
			date_to: '<?php echo esc_js( $date_to ); ?>'
		};

		$('#sessypress-timeline-loading').show();
		$('#sessypress-timeline-results').html('');

		$.post(ajaxurl, filters, function(response) {
			$('#sessypress-timeline-loading').hide();
			if (response.success) {
				$('#sessypress-timeline-results').html(response.data.html);
			} else {
				$('#sessypress-timeline-results').html('<p class="description">' + response.data.message + '</p>');
			}
		});
	}

	// Load timeline on page load
	loadTimeline();

	// Apply filters button
	$('#timeline-apply-filters').on('click', function() {
		loadTimeline();
	});

	// Reset filters button
	$('#timeline-reset-filters').on('click', function() {
		$('#timeline-event-type').val('');
		$('#timeline-event-source').val('');
		$('#timeline-recipient').val('');
		$('#timeline-message-id').val('');
		loadTimeline();
	});

	// Enter key support
	$('#timeline-recipient, #timeline-message-id').on('keypress', function(e) {
		if (e.which === 13) {
			loadTimeline();
		}
	});

	// Toggle event details
	$(document).on('click', '.sessypress-toggle-details', function(e) {
		e.preventDefault();
		$(this).closest('tr').next('.sessypress-event-details').toggle();
		$(this).text(function(i, text) {
			return text === '<?php echo esc_js( __( 'Show Details', 'sessypress' ) ); ?>' ? 
				'<?php echo esc_js( __( 'Hide Details', 'sessypress' ) ); ?>' : 
				'<?php echo esc_js( __( 'Show Details', 'sessypress' ) ); ?>';
		});
	});
});
</script>

<style>
.sessypress-event-details {
	background: #f9f9f9;
	display: none;
}
.sessypress-event-details td {
	padding: 15px !important;
}
.sessypress-event-details pre {
	background: #fff;
	padding: 10px;
	border: 1px solid #ddd;
	border-radius: 3px;
	overflow-x: auto;
	max-height: 300px;
	font-size: 11px;
	line-height: 1.4;
}
</style>

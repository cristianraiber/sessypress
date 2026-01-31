<?php
/**
 * Admin dashboard page - WordPress standard dataForm implementation
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

// Get filter parameters.
$event_source_filter = isset( $_GET['event_source'] ) ? sanitize_text_field( wp_unslash( $_GET['event_source'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$date_from           = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$date_to             = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : gmdate( 'Y-m-d' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Build WHERE clauses using proper prepared statements.
$where_clauses = array( '1=1' );
$where_clauses[] = $wpdb->prepare( 'DATE(timestamp) BETWEEN %s AND %s', $date_from, $date_to );

// Add event_source filter using prepared statement.
if ( 'all' !== $event_source_filter ) {
	$source_map = array(
		'sns'              => 'sns_notification',
		'event_publishing' => 'event_publishing',
		'manual'           => 'manual',
	);
	
	if ( isset( $source_map[ $event_source_filter ] ) ) {
		$where_clauses[] = $wpdb->prepare( 'event_source = %s', $source_map[ $event_source_filter ] );
	}
}

$where_sql = implode( ' AND ', $where_clauses );

// Get comprehensive stats.
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

// Count by event types.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$event_counts = $wpdb->get_results(
	"SELECT event_type, event_source, COUNT(*) as count 
	FROM $events_table 
	WHERE $where_sql
	GROUP BY event_type, event_source",
	ARRAY_A
);

foreach ( $event_counts as $row ) {
	$event_type = strtolower( $row['event_type'] );
	$source     = $row['event_source'];
	$count      = (int) $row['count'];

	// Count by source.
	if ( 'sns_notification' === $source ) {
		$stats['sns_events'] += $count;
	} elseif ( 'event_publishing' === $source ) {
		$stats['event_publishing_count'] += $count;
	} elseif ( 'manual' === $source ) {
		$stats['manual_events'] += $count;
	}

	// Count by type.
	switch ( $event_type ) {
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

// Calculate rates.
$bounce_rate    = $stats['total_delivered'] > 0 ? round( ( $stats['total_bounced'] / $stats['total_delivered'] ) * 100, 2 ) : 0;
$complaint_rate = $stats['total_delivered'] > 0 ? round( ( $stats['total_complaints'] / $stats['total_delivered'] ) * 100, 2 ) : 0;
$open_rate      = $stats['total_delivered'] > 0 ? round( ( $stats['total_opens'] / $stats['total_delivered'] ) * 100, 2 ) : 0;
$click_rate     = $stats['total_delivered'] > 0 ? round( ( $stats['total_clicks'] / $stats['total_delivered'] ) * 100, 2 ) : 0;

// Get click heatmap data.
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

// Get open rate by hour.
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

// Format data for Chart.js.
$hours_data = array_fill( 0, 24, 0 );
foreach ( $opens_by_hour as $row ) {
	$hours_data[ (int) $row['hour'] ] = (int) $row['open_count'];
}

$settings     = get_option( 'sessypress_settings', array() );
$endpoint_url = rest_url( 'sessypress/v1/' . ( $settings['sns_endpoint_slug'] ?? 'ses-sns-webhook' ) );
$secret_key   = $settings['sns_secret_key'] ?? '';

// Initialize Events List Table.
$events_table_obj = new Events_List_Table();
$events_table_obj->prepare_items();
?>

<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'sessypress_messages' ); ?>

	<!-- SNS Endpoint Configuration -->
	<div class="card">
		<h2><?php esc_html_e( 'SNS Endpoint Configuration', 'sessypress' ); ?></h2>
		<p><?php esc_html_e( 'Configure your Amazon SNS subscriptions to send notifications to this endpoint:', 'sessypress' ); ?></p>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Endpoint URL:', 'sessypress' ); ?></th>
				<td>
					<input type="text" readonly class="large-text code" value="<?php echo esc_attr( $endpoint_url . '?key=' . $secret_key ); ?>" onclick="this.select();" />
					<p class="description">
						<?php esc_html_e( 'Add this URL as an HTTPS subscription endpoint in your SNS topics. Click to select and copy.', 'sessypress' ); ?>
					</p>
				</td>
			</tr>
		</table>
	</div>

	<!-- Filters Form -->
	<form method="get" class="sessypress-filters">
		<input type="hidden" name="page" value="sessypress-dashboard" />

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="event_source"><?php esc_html_e( 'Event Source:', 'sessypress' ); ?></label>
				</th>
				<td>
					<select name="event_source" id="event_source">
						<option value="all" <?php selected( $event_source_filter, 'all' ); ?>><?php esc_html_e( 'All Events', 'sessypress' ); ?></option>
						<option value="sns" <?php selected( $event_source_filter, 'sns' ); ?>><?php esc_html_e( 'SNS Only', 'sessypress' ); ?></option>
						<option value="event_publishing" <?php selected( $event_source_filter, 'event_publishing' ); ?>><?php esc_html_e( 'Event Publishing Only', 'sessypress' ); ?></option>
						<option value="manual" <?php selected( $event_source_filter, 'manual' ); ?>><?php esc_html_e( 'Manual Tracking Only', 'sessypress' ); ?></option>
					</select>
				</td>
				<th scope="row">
					<label for="date_from"><?php esc_html_e( 'Date Range:', 'sessypress' ); ?></label>
				</th>
				<td>
					<input type="text" name="date_from" id="date_from" class="sessypress-datepicker" value="<?php echo esc_attr( $date_from ); ?>" />
					<span> — </span>
					<input type="text" name="date_to" id="date_to" class="sessypress-datepicker" value="<?php echo esc_attr( $date_to ); ?>" />
				</td>
				<td>
					<?php submit_button( __( 'Apply Filters', 'sessypress' ), 'primary', 'submit', false ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=sessypress-dashboard' ) ); ?>" class="button">
						<?php esc_html_e( 'Reset', 'sessypress' ); ?>
					</a>
				</td>
			</tr>
		</table>
	</form>

	<!-- Statistics Dashboard -->
	<h2><?php esc_html_e( 'Email Statistics', 'sessypress' ); ?></h2>
	<p class="description">
		<?php
		printf(
			/* translators: %1$s: start date, %2$s: end date */
			esc_html__( 'Showing data from %1$s to %2$s', 'sessypress' ),
			esc_html( gmdate( 'F j, Y', strtotime( $date_from ) ) ),
			esc_html( gmdate( 'F j, Y', strtotime( $date_to ) ) )
		);
		?>
	</p>

	<div class="sessypress-stats-grid">
		<?php
		// Stats boxes using WordPress admin styles.
		$stats_boxes = array(
			array(
				'title' => __( 'Total Sent', 'sessypress' ),
				'value' => $stats['total_sent'],
				'class' => 'send',
				'icon'  => 'dashicons-email',
			),
			array(
				'title' => __( 'Delivered', 'sessypress' ),
				'value' => $stats['total_delivered'],
				'class' => 'success',
				'icon'  => 'dashicons-yes-alt',
			),
			array(
				'title' => __( 'Rejected', 'sessypress' ),
				'value' => $stats['total_rejected'],
				'class' => 'warning',
				'icon'  => 'dashicons-warning',
			),
			array(
				'title'    => __( 'Bounced', 'sessypress' ),
				'value'    => $stats['total_bounced'],
				'class'    => 'error',
				'icon'     => 'dashicons-dismiss',
				'subvalue' => sprintf( '%s%% bounce rate', $bounce_rate ),
			),
			array(
				'title'    => __( 'Complaints', 'sessypress' ),
				'value'    => $stats['total_complaints'],
				'class'    => 'error',
				'icon'     => 'dashicons-flag',
				'subvalue' => sprintf( '%s%% complaint rate', $complaint_rate ),
			),
			array(
				'title'    => __( 'Opens', 'sessypress' ),
				'value'    => $stats['total_opens'],
				'class'    => 'success',
				'icon'     => 'dashicons-visibility',
				'subvalue' => sprintf( '%s%% open rate', $open_rate ),
			),
			array(
				'title'    => __( 'Clicks', 'sessypress' ),
				'value'    => $stats['total_clicks'],
				'class'    => 'success',
				'icon'     => 'dashicons-admin-links',
				'subvalue' => sprintf( '%s%% click rate', $click_rate ),
			),
			array(
				'title' => __( 'Delivery Delays', 'sessypress' ),
				'value' => $stats['delivery_delays'],
				'class' => 'warning',
				'icon'  => 'dashicons-clock',
			),
		);

		foreach ( $stats_boxes as $box ) {
			?>
			<div class="sessypress-stat-box sessypress-stat-<?php echo esc_attr( $box['class'] ); ?>">
				<span class="dashicons <?php echo esc_attr( $box['icon'] ); ?>"></span>
				<div class="sessypress-stat-content">
					<div class="sessypress-stat-title"><?php echo esc_html( $box['title'] ); ?></div>
					<div class="sessypress-stat-value"><?php echo esc_html( number_format_i18n( $box['value'] ) ); ?></div>
					<?php if ( isset( $box['subvalue'] ) ) : ?>
						<div class="sessypress-stat-subvalue"><?php echo esc_html( $box['subvalue'] ); ?></div>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}
		?>
	</div>

	<!-- Charts Row -->
	<div class="sessypress-charts-row">
		<div class="postbox">
			<h2 class="hndle"><?php esc_html_e( 'Click Heatmap - Top 10 Links', 'sessypress' ); ?></h2>
			<div class="inside">
				<?php if ( ! empty( $click_heatmap ) ) : ?>
					<table class="widefat">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Link', 'sessypress' ); ?></th>
								<th style="width: 100px; text-align: right;"><?php esc_html_e( 'Clicks', 'sessypress' ); ?></th>
								<th style="width: 200px;"><?php esc_html_e( 'Popularity', 'sessypress' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$max_clicks = max( array_column( $click_heatmap, 'click_count' ) );
							foreach ( $click_heatmap as $item ) :
								$link        = $item['link'] ? $item['link'] : __( '(Unknown)', 'sessypress' );
								$click_count = (int) $item['click_count'];
								$percentage  = $max_clicks > 0 ? ( $click_count / $max_clicks ) * 100 : 0;
								?>
								<tr>
									<td>
										<code title="<?php echo esc_attr( $link ); ?>">
											<?php echo esc_html( strlen( $link ) > 60 ? substr( $link, 0, 60 ) . '...' : $link ); ?>
										</code>
									</td>
									<td style="text-align: right;">
										<strong><?php echo esc_html( number_format_i18n( $click_count ) ); ?></strong>
									</td>
									<td>
										<div class="sessypress-progress-bar">
											<div class="sessypress-progress-fill" style="width: <?php echo esc_attr( $percentage ); ?>%;"></div>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No click data available for the selected period.', 'sessypress' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<div class="postbox">
			<h2 class="hndle"><?php esc_html_e( 'Open Rate by Hour of Day', 'sessypress' ); ?></h2>
			<div class="inside">
				<canvas id="sessypress-opens-chart" width="400" height="200"></canvas>
				<p class="description">
					<?php esc_html_e( 'Shows when recipients are most likely to open emails. Use this to optimize your send times.', 'sessypress' ); ?>
				</p>
			</div>
		</div>
	</div>

	<!-- Event Timeline (WP_List_Table) -->
	<h2><?php esc_html_e( 'Event Timeline', 'sessypress' ); ?></h2>
	
	<form method="get">
		<input type="hidden" name="page" value="sessypress-dashboard" />
		<?php
		$events_table_obj->search_box( __( 'Search Events', 'sessypress' ), 'sessypress-events' );
		$events_table_obj->display();
		?>
	</form>
</div>

<?php
// Enqueue Chart.js.
wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', array(), '4.4.0', true );

// Inline script for chart initialization.
$chart_data = wp_json_encode( $hours_data );
wp_add_inline_script(
	'chart-js',
	"
	document.addEventListener('DOMContentLoaded', function() {
		var ctx = document.getElementById('sessypress-opens-chart');
		if (ctx) {
			new Chart(ctx, {
				type: 'line',
				data: {
					labels: [
						'12am', '1am', '2am', '3am', '4am', '5am', '6am', '7am', '8am', '9am', '10am', '11am',
						'12pm', '1pm', '2pm', '3pm', '4pm', '5pm', '6pm', '7pm', '8pm', '9pm', '10pm', '11pm'
					],
					datasets: [{
						label: '" . esc_js( __( 'Email Opens', 'sessypress' ) ) . "',
						data: $chart_data,
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
						legend: { display: false }
					},
					scales: {
						y: {
							beginAtZero: true,
							ticks: { precision: 0 }
						}
					}
				}
			});
		}
	});
	"
);

// Enqueue jQuery UI Datepicker.
wp_enqueue_script( 'jquery-ui-datepicker' );
wp_enqueue_style( 'jquery-ui-css', 'https://code.jquery.com/ui/1.13.2/themes/smoothness/jquery-ui.min.css', array(), '1.13.2' );

// Inline script for datepicker.
wp_add_inline_script(
	'jquery-ui-datepicker',
	"
	jQuery(document).ready(function($) {
		$('.sessypress-datepicker').datepicker({
			dateFormat: 'yy-mm-dd',
			maxDate: 0
		});
	});
	"
);
?>

<style>
.sessypress-stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 15px;
	margin: 20px 0;
}
.sessypress-stat-box {
	background: #fff;
	padding: 20px;
	border-left: 4px solid #ccc;
	display: flex;
	align-items: center;
	gap: 15px;
}
.sessypress-stat-box .dashicons {
	font-size: 40px;
	width: 40px;
	height: 40px;
}
.sessypress-stat-send { border-left-color: #2271b1; }
.sessypress-stat-send .dashicons { color: #2271b1; }
.sessypress-stat-success { border-left-color: #00a32a; }
.sessypress-stat-success .dashicons { color: #00a32a; }
.sessypress-stat-warning { border-left-color: #dba617; }
.sessypress-stat-warning .dashicons { color: #dba617; }
.sessypress-stat-error { border-left-color: #d63638; }
.sessypress-stat-error .dashicons { color: #d63638; }
.sessypress-stat-title {
	font-size: 12px;
	color: #646970;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}
.sessypress-stat-value {
	font-size: 28px;
	font-weight: 600;
	line-height: 1;
}
.sessypress-stat-subvalue {
	font-size: 11px;
	color: #646970;
	margin-top: 2px;
}
.sessypress-charts-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 20px;
	margin: 20px 0;
}
.sessypress-progress-bar {
	background: #f0f0f1;
	height: 24px;
	border-radius: 3px;
	overflow: hidden;
}
.sessypress-progress-fill {
	background: linear-gradient(90deg, #2271b1, #00a32a);
	height: 100%;
	transition: width 0.3s;
}
.sessypress-badge {
	display: inline-block;
	padding: 4px 10px;
	border-radius: 3px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}
.sessypress-badge-send { background: #2271b1; color: #fff; }
.sessypress-badge-success { background: #00a32a; color: #fff; }
.sessypress-badge-warning { background: #dba617; color: #fff; }
.sessypress-badge-error { background: #d63638; color: #fff; }
.sessypress-badge-info { background: #646970; color: #fff; }
.sessypress-badge-source-sns { background: #e7f3ff; color: #2271b1; border: 1px solid #2271b1; }
.sessypress-badge-source-event { background: #ecf7ed; color: #00a32a; border: 1px solid #00a32a; }
.sessypress-badge-source-manual { background: #f0f0f1; color: #50575e; border: 1px solid #646970; }
</style>

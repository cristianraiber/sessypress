<?php
/**
 * Admin settings page
 *
 * @package SESSYPress
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'sessypress' ) );
}

// Save settings
if ( isset( $_POST['sessypress_save_settings'] ) && check_admin_referer( 'sessypress_settings' ) ) {
	$settings = isset( $_POST['sessypress_settings'] ) ? (array) wp_unslash( $_POST['sessypress_settings'] ) : array();

	// Sanitize settings
	$sanitized = array(
		'sns_secret_key'         => isset( $settings['sns_secret_key'] ) ? sanitize_text_field( $settings['sns_secret_key'] ) : '',
		'sns_endpoint_slug'      => isset( $settings['sns_endpoint_slug'] ) ? sanitize_title( $settings['sns_endpoint_slug'] ) : 'ses-sns-webhook',
		'track_opens'            => isset( $settings['track_opens'] ) ? '1' : '0',
		'track_clicks'           => isset( $settings['track_clicks'] ) ? '1' : '0',
		'retention_days'         => isset( $settings['retention_days'] ) ? absint( $settings['retention_days'] ) : 90,
		'enable_manual_tracking' => isset( $settings['enable_manual_tracking'] ) ? '1' : '0',
	);

	update_option( 'sessypress_settings', $sanitized );

	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully.', 'sessypress' ) . '</p></div>';
}

$settings = get_option( 'sessypress_settings', array() );

// Backwards compatibility
if ( empty( $settings ) ) {
	$settings = get_option( 'ses_sns_tracker_settings', array() );
}

// Defaults
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
?>

<div class="wrap">
	<h1><?php esc_html_e( 'SESSYPress Settings', 'sessypress' ); ?></h1>

	<form method="post" action="">
		<?php wp_nonce_field( 'sessypress_settings' ); ?>

		<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
			
			<!-- Main Settings -->
			<div>
				
				<!-- SNS Configuration -->
				<div class="card">
					<h2><?php esc_html_e( 'SNS Endpoint Configuration', 'sessypress' ); ?></h2>
					
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="sns_secret_key"><?php esc_html_e( 'SNS Secret Key', 'sessypress' ); ?></label>
								</th>
								<td>
									<input
										type="text"
										id="sns_secret_key"
										name="sessypress_settings[sns_secret_key]"
										value="<?php echo esc_attr( $settings['sns_secret_key'] ); ?>"
										class="regular-text code"
										readonly
										onclick="this.select();"
									/>
									<p class="description">
										<?php esc_html_e( 'This secret key validates SNS requests. Click to select and copy. Do not share publicly.', 'sessypress' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="sns_endpoint_slug"><?php esc_html_e( 'Endpoint Slug', 'sessypress' ); ?></label>
								</th>
								<td>
									<input
										type="text"
										id="sns_endpoint_slug"
										name="sessypress_settings[sns_endpoint_slug]"
										value="<?php echo esc_attr( $settings['sns_endpoint_slug'] ); ?>"
										class="regular-text"
										pattern="[a-z0-9\-]+"
									/>
									<p class="description">
										<?php esc_html_e( 'Custom slug for the SNS webhook endpoint. Use lowercase letters, numbers, and hyphens only.', 'sessypress' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Full Endpoint URL', 'sessypress' ); ?></th>
								<td>
									<code class="sessypress-endpoint-url" style="display: block; padding: 10px; background: #f0f0f0; border-radius: 3px; margin-bottom: 5px; word-break: break-all;">
										<?php echo esc_html( $endpoint_url ); ?>?key=<?php echo esc_html( $settings['sns_secret_key'] ); ?>
									</code>
									<button type="button" class="button button-small" onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent); this.textContent='<?php echo esc_js( __( 'Copied!', 'sessypress' ) ); ?>'; setTimeout(() => this.textContent='<?php echo esc_js( __( 'Copy URL', 'sessypress' ) ); ?>', 2000);">
										<?php esc_html_e( 'Copy URL', 'sessypress' ); ?>
									</button>
									<p class="description">
										<?php esc_html_e( 'Use this URL as your SNS subscription endpoint for both SNS Notifications and Event Publishing.', 'sessypress' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Tracking Settings -->
				<div class="card" style="margin-top: 20px;">
					<h2><?php esc_html_e( 'Email Tracking Settings', 'sessypress' ); ?></h2>
					
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Manual Tracking', 'sessypress' ); ?></th>
								<td>
									<fieldset>
										<label>
											<input
												type="checkbox"
												name="sessypress_settings[enable_manual_tracking]"
												value="1"
												<?php checked( '1', $settings['enable_manual_tracking'] ); ?>
											/>
											<?php esc_html_e( 'Enable manual tracking injection', 'sessypress' ); ?>
										</label>
										<p class="description">
											<?php esc_html_e( 'Automatically inject tracking pixels and rewrite links in outgoing emails. Disable this if you\'re using SES Configuration Sets with Event Publishing.', 'sessypress' ); ?>
										</p>
									</fieldset>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Tracking Features', 'sessypress' ); ?></th>
								<td>
									<fieldset>
										<label>
											<input
												type="checkbox"
												name="sessypress_settings[track_opens]"
												value="1"
												<?php checked( '1', $settings['track_opens'] ); ?>
											/>
											<?php esc_html_e( 'Track email opens (1x1 pixel)', 'sessypress' ); ?>
										</label>
										<br><br>
										<label>
											<input
												type="checkbox"
												name="sessypress_settings[track_clicks]"
												value="1"
												<?php checked( '1', $settings['track_clicks'] ); ?>
											/>
											<?php esc_html_e( 'Track link clicks (URL rewrites)', 'sessypress' ); ?>
										</label>
										<p class="description">
											<?php esc_html_e( 'These options only apply when manual tracking is enabled. Event Publishing tracks opens/clicks automatically.', 'sessypress' ); ?>
										</p>
									</fieldset>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="retention_days"><?php esc_html_e( 'Data Retention', 'sessypress' ); ?></label>
								</th>
								<td>
									<input
										type="number"
										id="retention_days"
										name="sessypress_settings[retention_days]"
										value="<?php echo esc_attr( $settings['retention_days'] ); ?>"
										class="small-text"
										min="0"
									/> <?php esc_html_e( 'days', 'sessypress' ); ?>
									<p class="description">
										<?php esc_html_e( 'Automatically delete tracking data older than this many days. Set to 0 to keep data forever.', 'sessypress' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<?php submit_button( __( 'Save Settings', 'sessypress' ), 'primary', 'sessypress_save_settings', true, array( 'style' => 'margin-top: 20px;' ) ); ?>
			</div>

			<!-- Sidebar -->
			<div>
				
				<!-- Quick Stats -->
				<div class="card">
					<h3 style="margin-top: 0;"><?php esc_html_e( 'Quick Stats', 'sessypress' ); ?></h3>
					<?php
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
						$source_counts[ $row['event_source'] ] = (int) $row['count'];
					}
					?>
					<div style="margin-bottom: 15px;">
						<strong style="display: block; margin-bottom: 5px;"><?php esc_html_e( 'Total Events:', 'sessypress' ); ?></strong>
						<span style="font-size: 24px; color: #2271b1; font-weight: 600;"><?php echo number_format_i18n( (int) $total_events ); ?></span>
					</div>
					
					<div style="border-top: 1px solid #ddd; padding-top: 10px;">
						<div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
							<span><?php esc_html_e( 'SNS Notifications:', 'sessypress' ); ?></span>
							<strong><?php echo number_format_i18n( $source_counts['sns_notification'] ); ?></strong>
						</div>
						<div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
							<span><?php esc_html_e( 'Event Publishing:', 'sessypress' ); ?></span>
							<strong><?php echo number_format_i18n( $source_counts['event_publishing'] ); ?></strong>
						</div>
						<div style="display: flex; justify-content: space-between;">
							<span><?php esc_html_e( 'Manual Tracking:', 'sessypress' ); ?></span>
							<strong><?php echo number_format_i18n( $source_counts['manual'] ); ?></strong>
						</div>
					</div>
				</div>

				<!-- Tracking Mode Info -->
				<div class="card" style="margin-top: 20px;">
					<h3 style="margin-top: 0;"><?php esc_html_e( 'Tracking Modes', 'sessypress' ); ?></h3>
					
					<div style="margin-bottom: 15px; padding: 10px; background: #e7f3ff; border-left: 3px solid #2271b1;">
						<strong style="color: #2271b1;"><?php esc_html_e( 'SNS Notifications', 'sessypress' ); ?></strong>
						<p style="margin: 5px 0 0; font-size: 12px;">
							<?php esc_html_e( 'Tracks bounces, complaints, and deliveries. Reliable but limited event types.', 'sessypress' ); ?>
						</p>
					</div>
					
					<div style="margin-bottom: 15px; padding: 10px; background: #ecf7ed; border-left: 3px solid #00a32a;">
						<strong style="color: #00a32a;"><?php esc_html_e( 'Event Publishing', 'sessypress' ); ?></strong>
						<p style="margin: 5px 0 0; font-size: 12px;">
							<?php esc_html_e( 'Tracks all events including opens, clicks, delivery delays. Requires Configuration Set.', 'sessypress' ); ?>
						</p>
					</div>
					
					<div style="padding: 10px; background: #f0f0f1; border-left: 3px solid #646970;">
						<strong style="color: #50575e;"><?php esc_html_e( 'Manual Tracking', 'sessypress' ); ?></strong>
						<p style="margin: 5px 0 0; font-size: 12px;">
							<?php esc_html_e( 'Fallback tracking via pixel and URL rewriting. Works without SES configuration.', 'sessypress' ); ?>
						</p>
					</div>
				</div>

				<!-- Help Links -->
				<div class="card" style="margin-top: 20px;">
					<h3 style="margin-top: 0;"><?php esc_html_e( 'Resources', 'sessypress' ); ?></h3>
					<ul style="margin: 0;">
						<li><a href="https://docs.aws.amazon.com/ses/latest/dg/event-publishing-setting-up.html" target="_blank"><?php esc_html_e( 'AWS Event Publishing Guide', 'sessypress' ); ?></a></li>
						<li><a href="https://docs.aws.amazon.com/ses/latest/dg/configure-sns-notifications.html" target="_blank"><?php esc_html_e( 'AWS SNS Notifications Guide', 'sessypress' ); ?></a></li>
						<li><a href="https://docs.aws.amazon.com/ses/latest/dg/send-email-simulator.html" target="_blank"><?php esc_html_e( 'SES Mailbox Simulator', 'sessypress' ); ?></a></li>
					</ul>
				</div>
			</div>
		</div>
	</form>

	<!-- Setup Instructions -->
	<div style="margin-top: 40px;">
		<h2><?php esc_html_e( 'Setup Instructions', 'sessypress' ); ?></h2>

		<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px;">
			
			<!-- SNS Notifications Setup -->
			<div class="card">
				<h3><?php esc_html_e( 'Option 1: SNS Notifications (Legacy)', 'sessypress' ); ?></h3>
				<ol style="font-size: 13px; line-height: 1.6;">
					<li><?php esc_html_e( 'Go to AWS SNS Console > Topics', 'sessypress' ); ?></li>
					<li><?php esc_html_e( 'Create topics for: Bounces, Complaints, Deliveries', 'sessypress' ); ?></li>
					<li><?php esc_html_e( 'Create HTTPS subscriptions pointing to your endpoint URL above', 'sessypress' ); ?></li>
					<li><?php esc_html_e( 'Go to SES > Identities > Select domain > Notifications', 'sessypress' ); ?></li>
					<li><?php esc_html_e( 'Link SNS topics to notification types', 'sessypress' ); ?></li>
				</ol>
				<p class="description">
					<strong><?php esc_html_e( 'Pros:', 'sessypress' ); ?></strong> <?php esc_html_e( 'Simple setup, reliable', 'sessypress' ); ?><br>
					<strong><?php esc_html_e( 'Cons:', 'sessypress' ); ?></strong> <?php esc_html_e( 'No open/click tracking, no delivery delays', 'sessypress' ); ?>
				</p>
			</div>

			<!-- Event Publishing Setup -->
			<div class="card">
				<h3><?php esc_html_e( 'Option 2: Event Publishing (Recommended)', 'sessypress' ); ?></h3>
				<ol style="font-size: 13px; line-height: 1.6;">
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

		<!-- Testing -->
		<div class="card" style="margin-top: 20px;">
			<h3><?php esc_html_e( 'Testing Your Setup', 'sessypress' ); ?></h3>
			<p><?php esc_html_e( 'Use the AWS SES Mailbox Simulator to test different scenarios:', 'sessypress' ); ?></p>
			<ul style="font-size: 13px; line-height: 1.8;">
				<li><code>success@simulator.amazonses.com</code> - <?php esc_html_e( 'Successful delivery', 'sessypress' ); ?></li>
				<li><code>bounce@simulator.amazonses.com</code> - <?php esc_html_e( 'Hard bounce', 'sessypress' ); ?></li>
				<li><code>ooto@simulator.amazonses.com</code> - <?php esc_html_e( 'Out of office (soft bounce)', 'sessypress' ); ?></li>
				<li><code>complaint@simulator.amazonses.com</code> - <?php esc_html_e( 'Spam complaint', 'sessypress' ); ?></li>
				<li><code>suppressionlist@simulator.amazonses.com</code> - <?php esc_html_e( 'Suppression list', 'sessypress' ); ?></li>
			</ul>
			<p class="description">
				<?php esc_html_e( 'Send test emails to these addresses and check the Dashboard for events within a few seconds.', 'sessypress' ); ?>
			</p>
		</div>
	</div>
</div>

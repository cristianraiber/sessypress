<?php
/**
 * Admin settings page
 */

namespace SES_SNS_Tracker;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

// Save settings
if ( isset( $_POST['ses_sns_tracker_save'] ) && check_admin_referer( 'ses_sns_tracker_settings' ) ) {
	update_option( 'ses_sns_tracker_settings', $_POST['ses_sns_tracker_settings'] );
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'ses-sns-tracker' ) . '</p></div>';
}

$settings = get_option( 'ses_sns_tracker_settings', array() );
?>

<div class="wrap">
	<h1><?php esc_html_e( 'SES SNS Tracker Settings', 'ses-sns-tracker' ); ?></h1>

	<form method="post" action="">
		<?php wp_nonce_field( 'ses_sns_tracker_settings' ); ?>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="sns_secret_key"><?php esc_html_e( 'SNS Secret Key', 'ses-sns-tracker' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="sns_secret_key"
							name="ses_sns_tracker_settings[sns_secret_key]"
							value="<?php echo esc_attr( $settings['sns_secret_key'] ?? '' ); ?>"
							class="regular-text"
							readonly
						/>
						<p class="description">
							<?php esc_html_e( 'This key validates SNS requests. Add it to your SNS subscription URL as ?key=YOUR_KEY', 'ses-sns-tracker' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="sns_endpoint_slug"><?php esc_html_e( 'Endpoint Slug', 'ses-sns-tracker' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="sns_endpoint_slug"
							name="ses_sns_tracker_settings[sns_endpoint_slug]"
							value="<?php echo esc_attr( $settings['sns_endpoint_slug'] ?? 'ses-sns-webhook' ); ?>"
							class="regular-text"
						/>
						<p class="description">
							<?php esc_html_e( 'Custom slug for the SNS webhook endpoint.', 'ses-sns-tracker' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<?php esc_html_e( 'Email Tracking', 'ses-sns-tracker' ); ?>
					</th>
					<td>
						<fieldset>
							<label>
								<input
									type="checkbox"
									name="ses_sns_tracker_settings[track_opens]"
									value="1"
									<?php checked( isset( $settings['track_opens'] ) && '1' === $settings['track_opens'] ); ?>
								/>
								<?php esc_html_e( 'Track email opens (1x1 pixel)', 'ses-sns-tracker' ); ?>
							</label>
							<br>
							<label>
								<input
									type="checkbox"
									name="ses_sns_tracker_settings[track_clicks]"
									value="1"
									<?php checked( isset( $settings['track_clicks'] ) && '1' === $settings['track_clicks'] ); ?>
								/>
								<?php esc_html_e( 'Track link clicks (URL rewrites)', 'ses-sns-tracker' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="retention_days"><?php esc_html_e( 'Data Retention', 'ses-sns-tracker' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="retention_days"
							name="ses_sns_tracker_settings[retention_days]"
							value="<?php echo esc_attr( $settings['retention_days'] ?? 90 ); ?>"
							class="small-text"
							min="1"
						/> <?php esc_html_e( 'days', 'ses-sns-tracker' ); ?>
						<p class="description">
							<?php esc_html_e( 'Delete tracking data older than this many days. Set to 0 to keep forever.', 'ses-sns-tracker' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( __( 'Save Settings', 'ses-sns-tracker' ), 'primary', 'ses_sns_tracker_save' ); ?>
	</form>

	<hr>

	<h2><?php esc_html_e( 'Setup Instructions', 'ses-sns-tracker' ); ?></h2>

	<div class="card">
		<h3><?php esc_html_e( '1. Configure AWS SES Domain Identity', 'ses-sns-tracker' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Go to AWS SES Console > Configuration > Identities', 'ses-sns-tracker' ); ?></li>
			<li><?php esc_html_e( 'Select your verified domain or email identity', 'ses-sns-tracker' ); ?></li>
			<li><?php esc_html_e( 'Go to Notifications tab', 'ses-sns-tracker' ); ?></li>
			<li><?php esc_html_e( 'Click "Edit" on Configuration set', 'ses-sns-tracker' ); ?></li>
		</ol>
	</div>

	<div class="card">
		<h3><?php esc_html_e( '2. Create SNS Topics', 'ses-sns-tracker' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Go to AWS SNS Console > Topics', 'ses-sns-tracker' ); ?></li>
			<li><?php esc_html_e( 'Create topics for: Bounces, Complaints, Deliveries', 'ses-sns-tracker' ); ?></li>
			<li><?php esc_html_e( 'Or use a single topic for all notification types', 'ses-sns-tracker' ); ?></li>
		</ol>
	</div>

	<div class="card">
		<h3><?php esc_html_e( '3. Create SNS Subscriptions', 'ses-sns-tracker' ); ?></h3>
		<p><?php esc_html_e( 'For each SNS topic:', 'ses-sns-tracker' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'Click "Create subscription"', 'ses-sns-tracker' ); ?></li>
			<li><?php esc_html_e( 'Protocol: HTTPS', 'ses-sns-tracker' ); ?></li>
			<li><?php esc_html_e( 'Endpoint: Copy the endpoint URL from the Dashboard page', 'ses-sns-tracker' ); ?></li>
			<li><?php esc_html_e( 'Enable raw message delivery: NO (leave unchecked)', 'ses-sns-tracker' ); ?></li>
			<li><?php esc_html_e( 'Click "Create subscription"', 'ses-sns-tracker' ); ?></li>
			<li><?php esc_html_e( 'The subscription will be automatically confirmed by this plugin', 'ses-sns-tracker' ); ?></li>
		</ol>
	</div>

	<div class="card">
		<h3><?php esc_html_e( '4. Link SNS Topics to SES Identity', 'ses-sns-tracker' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Go back to SES Identity > Notifications', 'ses-sns-tracker' ); ?></li>
			<li><?php esc_html_e( 'Select the SNS topics you created for each notification type', 'ses-sns-tracker' ); ?></li>
			<li><?php esc_html_e( 'Save configuration', 'ses-sns-tracker' ); ?></li>
		</ol>
	</div>

	<div class="card">
		<h3><?php esc_html_e( '5. Test Your Setup', 'ses-sns-tracker' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Send a test email using SES', 'ses-sns-tracker' ); ?></li>
			<li><?php esc_html_e( 'Check the Dashboard for delivery notifications', 'ses-sns-tracker' ); ?></li>
			<li><?php esc_html_e( 'Test bounce by sending to bounce@simulator.amazonses.com', 'ses-sns-tracker' ); ?></li>
			<li><?php esc_html_e( 'Test complaint by sending to complaint@simulator.amazonses.com', 'ses-sns-tracker' ); ?></li>
		</ol>
	</div>
</div>

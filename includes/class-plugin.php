<?php
/**
 * Main plugin class
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

class Plugin {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	private function init_hooks() {
		// REST API endpoint for SNS notifications
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Admin menu and settings
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			
			// Initialize AJAX handlers
			AJAX_Handler::init();
		}

		// Tracking endpoints (open pixel, click tracking, unsubscribe)
		add_action( 'template_redirect', array( $this, 'handle_tracking' ) );

		// Unsubscribe filter (early priority to block before sending)
		$settings = get_option( 'sessypress_settings', array() );
		if ( isset( $settings['enable_unsubscribe_filter'] ) && '1' === $settings['enable_unsubscribe_filter'] ) {
			add_filter( 'wp_mail', array( $this, 'filter_unsubscribed_emails' ), 1 );
		}

		// Email filter to inject tracking (late priority after other modifications)
		add_filter( 'wp_mail', array( $this, 'inject_tracking' ), 999 );
	}

	/**
	 * Register REST API routes for SNS notifications
	 */
	public function register_rest_routes() {
		$settings = get_option( 'sessypress_settings', array() );
		
		// Fallback to old option name for backwards compatibility
		if ( empty( $settings ) ) {
			$settings = get_option( 'ses_sns_tracker_settings', array() );
		}
		
		$slug = isset( $settings['sns_endpoint_slug'] ) ? $settings['sns_endpoint_slug'] : 'ses-sns-webhook';

		register_rest_route( 'sessypress/v1', '/' . $slug, array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_sns_notification' ),
			'permission_callback' => '__return_true', // SNS validates via secret
		) );
	}

	/**
	 * Handle SNS notification
	 */
	public function handle_sns_notification( $request ) {
		$handler = new SNS_Handler();
		return $handler->process( $request );
	}

	/**
	 * Handle tracking requests (open pixel, click tracking, unsubscribe)
	 */
	public function handle_tracking() {
		if ( ! isset( $_GET['ses_track'] ) ) {
			return;
		}

		// Handle unsubscribe requests separately
		if ( isset( $_GET['ses_action'] ) && 'unsubscribe' === $_GET['ses_action'] ) {
			$unsubscribe_manager = new Unsubscribe_Manager();
			$unsubscribe_manager->handle_unsubscribe_request();
			return;
		}

		// Handle regular tracking (opens, clicks)
		$tracker = new Tracker();
		$tracker->handle_tracking_request();
	}

	/**
	 * Filter out unsubscribed emails
	 *
	 * @param array $args wp_mail arguments.
	 * @return array Modified wp_mail arguments.
	 */
	public function filter_unsubscribed_emails( $args ) {
		$unsubscribe_manager = new Unsubscribe_Manager();
		return $unsubscribe_manager->filter_wp_mail( $args );
	}

	/**
	 * Inject tracking into outgoing emails
	 */
	public function inject_tracking( $args ) {
		$injector = new Tracking_Injector();
		return $injector->inject( $args );
	}

	/**
	 * Register admin menu
	 */
	public function admin_menu() {
		add_menu_page(
			__( 'SESSYPress Dashboard', 'sessypress' ),
			__( 'SESSYPress', 'sessypress' ),
			'manage_options',
			'sessypress-dashboard',
			array( $this, 'admin_dashboard' ),
			'dashicons-email-alt',
			30
		);

		add_submenu_page(
			'sessypress-dashboard',
			__( 'Dashboard', 'sessypress' ),
			__( 'Dashboard', 'sessypress' ),
			'manage_options',
			'sessypress-dashboard',
			array( $this, 'admin_dashboard' )
		);

		add_submenu_page(
			'sessypress-dashboard',
			__( 'Settings', 'sessypress' ),
			__( 'Settings', 'sessypress' ),
			'manage_options',
			'sessypress-settings',
			array( $this, 'admin_settings' )
		);
	}

	/**
	 * Render admin dashboard
	 */
	public function admin_dashboard() {
		// Load WP_List_Table if not already loaded.
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		
		// Load our custom Events_List_Table.
		require_once SESSYPRESS_PATH . 'includes/admin/class-events-list-table.php';
		
		require_once SESSYPRESS_PATH . 'includes/admin/dashboard.php';
	}

	/**
	 * Render admin settings
	 */
	public function admin_settings() {
		require_once SESSYPRESS_PATH . 'includes/admin/settings.php';
	}

	/**
	 * Register settings using WordPress Settings API
	 */
	public function register_settings() {
		// Register setting.
		register_setting(
			'sessypress_settings_group',
			'sessypress_settings',
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		// SNS Configuration Section.
		add_settings_section(
			'sessypress_sns_section',
			__( 'SNS Endpoint Configuration', 'sessypress' ),
			array( $this, 'render_sns_section_callback' ),
			'sessypress-settings'
		);

		add_settings_field(
			'sns_secret_key',
			__( 'SNS Secret Key', 'sessypress' ),
			array( $this, 'render_secret_key_field' ),
			'sessypress-settings',
			'sessypress_sns_section'
		);

		add_settings_field(
			'sns_endpoint_slug',
			__( 'Endpoint Slug', 'sessypress' ),
			array( $this, 'render_endpoint_slug_field' ),
			'sessypress-settings',
			'sessypress_sns_section'
		);

		// Tracking Settings Section.
		add_settings_section(
			'sessypress_tracking_section',
			__( 'Email Tracking Settings', 'sessypress' ),
			array( $this, 'render_tracking_section_callback' ),
			'sessypress-settings'
		);

		add_settings_field(
			'enable_manual_tracking',
			__( 'Manual Tracking', 'sessypress' ),
			array( $this, 'render_manual_tracking_field' ),
			'sessypress-settings',
			'sessypress_tracking_section'
		);

		add_settings_field(
			'tracking_features',
			__( 'Tracking Features', 'sessypress' ),
			array( $this, 'render_tracking_features_field' ),
			'sessypress-settings',
			'sessypress_tracking_section'
		);

		add_settings_field(
			'retention_days',
			__( 'Data Retention', 'sessypress' ),
			array( $this, 'render_retention_field' ),
			'sessypress-settings',
			'sessypress_tracking_section'
		);
	}

	/**
	 * Sanitize settings
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		if ( isset( $input['sns_secret_key'] ) ) {
			$sanitized['sns_secret_key'] = sanitize_text_field( $input['sns_secret_key'] );
		}

		if ( isset( $input['track_opens'] ) ) {
			$sanitized['track_opens'] = '1';
		} else {
			$sanitized['track_opens'] = '0';
		}

		if ( isset( $input['track_clicks'] ) ) {
			$sanitized['track_clicks'] = '1';
		} else {
			$sanitized['track_clicks'] = '0';
		}

		if ( isset( $input['sns_endpoint_slug'] ) ) {
			$sanitized['sns_endpoint_slug'] = sanitize_title( $input['sns_endpoint_slug'] );
		}

		if ( isset( $input['retention_days'] ) ) {
			$sanitized['retention_days'] = absint( $input['retention_days'] );
		}

		// Day 3: Manual tracking settings
		if ( isset( $input['enable_manual_tracking'] ) ) {
			$sanitized['enable_manual_tracking'] = '1';
		} else {
			$sanitized['enable_manual_tracking'] = '0';
		}

		if ( isset( $input['use_ses_native_tracking'] ) ) {
			$sanitized['use_ses_native_tracking'] = '1';
		} else {
			$sanitized['use_ses_native_tracking'] = '0';
		}

		if ( isset( $input['tracking_strategy'] ) ) {
			$allowed_strategies = array( 'prefer_ses', 'prefer_manual', 'use_both', 'manual_only' );
			if ( in_array( $input['tracking_strategy'], $allowed_strategies, true ) ) {
				$sanitized['tracking_strategy'] = $input['tracking_strategy'];
			} else {
				$sanitized['tracking_strategy'] = 'prefer_ses';
			}
		}

		if ( isset( $input['enable_unsubscribe_filter'] ) ) {
			$sanitized['enable_unsubscribe_filter'] = '1';
		} else {
			$sanitized['enable_unsubscribe_filter'] = '0';
		}

		return $sanitized;
	}

	/**
	 * Render settings fields using WordPress form helpers
	 */
	public function render_secret_key_field() {
		$settings = get_option( 'sessypress_settings', array() );
		$value    = $settings['sns_secret_key'] ?? wp_generate_password( 32, false );
		?>
		<input 
			type="text" 
			name="sessypress_settings[sns_secret_key]" 
			value="<?php echo esc_attr( $value ); ?>" 
			class="regular-text code" 
			readonly 
			onclick="this.select();" 
		/>
		<p class="description">
			<?php esc_html_e( 'This secret key validates SNS requests. Click to select and copy. Do not share publicly.', 'sessypress' ); ?>
		</p>
		<?php
	}

	public function render_track_opens_field() {
		$settings = get_option( 'sessypress_settings', array() );
		$checked  = isset( $settings['track_opens'] ) && '1' === $settings['track_opens'];
		?>
		<label>
			<input type="checkbox" name="sessypress_settings[track_opens]" value="1" <?php checked( $checked ); ?> />
			<?php esc_html_e( 'Enable email open tracking (1x1 pixel)', 'sessypress' ); ?>
		</label>
		<?php
	}

	public function render_track_clicks_field() {
		$settings = get_option( 'sessypress_settings', array() );
		$checked  = isset( $settings['track_clicks'] ) && '1' === $settings['track_clicks'];
		?>
		<label>
			<input type="checkbox" name="sessypress_settings[track_clicks]" value="1" <?php checked( $checked ); ?> />
			<?php esc_html_e( 'Enable link click tracking (URL rewrites)', 'sessypress' ); ?>
		</label>
		<?php
	}

	/**
	 * Section callbacks using WordPress admin UI
	 */
	public function render_sns_section_callback() {
		echo '<p>' . esc_html__( 'Configure your SNS webhook endpoint for receiving SES notifications.', 'sessypress' ) . '</p>';
	}

	public function render_tracking_section_callback() {
		echo '<p>' . esc_html__( 'Configure email tracking features and data retention.', 'sessypress' ) . '</p>';
	}

	public function render_endpoint_slug_field() {
		$settings = get_option( 'sessypress_settings', array() );
		$value    = $settings['sns_endpoint_slug'] ?? 'ses-sns-webhook';
		?>
		<input 
			type="text" 
			name="sessypress_settings[sns_endpoint_slug]" 
			value="<?php echo esc_attr( $value ); ?>" 
			class="regular-text" 
			pattern="[a-z0-9\-]+" 
		/>
		<p class="description">
			<?php esc_html_e( 'Custom slug for the SNS webhook endpoint. Use lowercase letters, numbers, and hyphens only.', 'sessypress' ); ?>
		</p>
		<?php
		$endpoint_url = rest_url( 'sessypress/v1/' . $value );
		?>
		<p>
			<strong><?php esc_html_e( 'Full Endpoint URL:', 'sessypress' ); ?></strong><br>
			<input 
				type="text" 
				readonly 
				class="large-text code" 
				value="<?php echo esc_attr( $endpoint_url . '?key=' . ( $settings['sns_secret_key'] ?? '' ) ); ?>" 
				onclick="this.select();" 
			/>
		</p>
		<?php
	}

	public function render_manual_tracking_field() {
		$settings = get_option( 'sessypress_settings', array() );
		$checked  = isset( $settings['enable_manual_tracking'] ) && '1' === $settings['enable_manual_tracking'];
		?>
		<fieldset>
			<label>
				<input 
					type="checkbox" 
					name="sessypress_settings[enable_manual_tracking]" 
					value="1" 
					<?php checked( $checked ); ?> 
				/>
				<?php esc_html_e( 'Enable manual tracking injection', 'sessypress' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'Automatically inject tracking pixels and rewrite links in outgoing emails. Disable this if you\'re using SES Configuration Sets with Event Publishing.', 'sessypress' ); ?>
			</p>
		</fieldset>
		<?php
	}

	public function render_tracking_features_field() {
		$settings = get_option( 'sessypress_settings', array() );
		$track_opens  = isset( $settings['track_opens'] ) && '1' === $settings['track_opens'];
		$track_clicks = isset( $settings['track_clicks'] ) && '1' === $settings['track_clicks'];
		?>
		<fieldset>
			<label>
				<input 
					type="checkbox" 
					name="sessypress_settings[track_opens]" 
					value="1" 
					<?php checked( $track_opens ); ?> 
				/>
				<?php esc_html_e( 'Track email opens (1x1 pixel)', 'sessypress' ); ?>
			</label>
			<br><br>
			<label>
				<input 
					type="checkbox" 
					name="sessypress_settings[track_clicks]" 
					value="1" 
					<?php checked( $track_clicks ); ?> 
				/>
				<?php esc_html_e( 'Track link clicks (URL rewrites)', 'sessypress' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'These options only apply when manual tracking is enabled. Event Publishing tracks opens/clicks automatically.', 'sessypress' ); ?>
			</p>
		</fieldset>
		<?php
	}

	public function render_retention_field() {
		$settings = get_option( 'sessypress_settings', array() );
		$value    = $settings['retention_days'] ?? 90;
		?>
		<input 
			type="number" 
			name="sessypress_settings[retention_days]" 
			value="<?php echo esc_attr( $value ); ?>" 
			class="small-text" 
			min="0" 
		/> 
		<?php esc_html_e( 'days', 'sessypress' ); ?>
		<p class="description">
			<?php esc_html_e( 'Automatically delete tracking data older than this many days. Set to 0 to keep data forever.', 'sessypress' ); ?>
		</p>
		<?php
	}
}

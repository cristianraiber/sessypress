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
		}

		// Tracking endpoints (open pixel, click tracking)
		add_action( 'template_redirect', array( $this, 'handle_tracking' ) );

		// Email filter to inject tracking
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
	 * Handle tracking requests (open pixel, click tracking)
	 */
	public function handle_tracking() {
		if ( ! isset( $_GET['ses_track'] ) ) {
			return;
		}

		$tracker = new Tracker();
		$tracker->handle_tracking_request();
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
			__( 'SES Email Tracking', 'ses-sns-tracker' ),
			__( 'Email Tracking', 'ses-sns-tracker' ),
			'manage_options',
			'ses-sns-tracker',
			array( $this, 'admin_dashboard' ),
			'dashicons-email-alt',
			30
		);

		add_submenu_page(
			'ses-sns-tracker',
			__( 'Dashboard', 'ses-sns-tracker' ),
			__( 'Dashboard', 'ses-sns-tracker' ),
			'manage_options',
			'ses-sns-tracker',
			array( $this, 'admin_dashboard' )
		);

		add_submenu_page(
			'ses-sns-tracker',
			__( 'Settings', 'ses-sns-tracker' ),
			__( 'Settings', 'ses-sns-tracker' ),
			'manage_options',
			'ses-sns-tracker-settings',
			array( $this, 'admin_settings' )
		);
	}

	/**
	 * Render admin dashboard
	 */
	public function admin_dashboard() {
		require_once SESSYPRESS_PATH . 'includes/admin/dashboard.php';
	}

	/**
	 * Render admin settings
	 */
	public function admin_settings() {
		require_once SESSYPRESS_PATH . 'includes/admin/settings.php';
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting(
			'ses_sns_tracker_settings_group',
			'ses_sns_tracker_settings',
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		add_settings_section(
			'ses_sns_tracker_general',
			__( 'General Settings', 'ses-sns-tracker' ),
			null,
			'ses-sns-tracker-settings'
		);

		add_settings_field(
			'sns_secret_key',
			__( 'SNS Secret Key', 'ses-sns-tracker' ),
			array( $this, 'render_secret_key_field' ),
			'ses-sns-tracker-settings',
			'ses_sns_tracker_general'
		);

		add_settings_field(
			'track_opens',
			__( 'Track Email Opens', 'ses-sns-tracker' ),
			array( $this, 'render_track_opens_field' ),
			'ses-sns-tracker-settings',
			'ses_sns_tracker_general'
		);

		add_settings_field(
			'track_clicks',
			__( 'Track Link Clicks', 'ses-sns-tracker' ),
			array( $this, 'render_track_clicks_field' ),
			'ses-sns-tracker-settings',
			'ses_sns_tracker_general'
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

		return $sanitized;
	}

	/**
	 * Render settings fields
	 */
	public function render_secret_key_field() {
		$settings = get_option( 'ses_sns_tracker_settings', array() );
		$value    = isset( $settings['sns_secret_key'] ) ? $settings['sns_secret_key'] : '';
		?>
		<input type="text" name="ses_sns_tracker_settings[sns_secret_key]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" readonly />
		<p class="description"><?php esc_html_e( 'Use this key to validate SNS requests. Add it to your SNS subscription URL as ?key=YOUR_KEY', 'ses-sns-tracker' ); ?></p>
		<?php
	}

	public function render_track_opens_field() {
		$settings = get_option( 'ses_sns_tracker_settings', array() );
		$checked  = isset( $settings['track_opens'] ) && '1' === $settings['track_opens'];
		?>
		<label>
			<input type="checkbox" name="ses_sns_tracker_settings[track_opens]" value="1" <?php checked( $checked ); ?> />
			<?php esc_html_e( 'Enable email open tracking (1x1 pixel)', 'ses-sns-tracker' ); ?>
		</label>
		<?php
	}

	public function render_track_clicks_field() {
		$settings = get_option( 'ses_sns_tracker_settings', array() );
		$checked  = isset( $settings['track_clicks'] ) && '1' === $settings['track_clicks'];
		?>
		<label>
			<input type="checkbox" name="ses_sns_tracker_settings[track_clicks]" value="1" <?php checked( $checked ); ?> />
			<?php esc_html_e( 'Enable link click tracking (URL rewrites)', 'ses-sns-tracker' ); ?>
		</label>
		<?php
	}
}

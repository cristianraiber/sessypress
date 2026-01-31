<?php
/**
 * Plugin Name: SESSYPress
 * Plugin URI: https://github.com/wpchill/sessypress
 * Description: Complete email tracking for Amazon SES - supports both SNS Notifications and Event Publishing (bounces, complaints, deliveries, opens, clicks, rendering failures, delivery delays)
 * Version: 1.0.0
 * Author: WPChill
 * Author URI: https://wpchill.com
 * Text Domain: sessypress
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace SESSYPress;

defined( 'ABSPATH' ) || exit;

define( 'SESSYPRESS_VERSION', '1.0.0' );
define( 'SESSYPRESS_FILE', __FILE__ );
define( 'SESSYPRESS_PATH', plugin_dir_path( __FILE__ ) );
define( 'SESSYPRESS_URL', plugin_dir_url( __FILE__ ) );

// Activation hook
register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Installer', 'activate' ) );

// Deactivation hook
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\\Installer', 'deactivate' ) );

// Autoloader
spl_autoload_register( function ( $class ) {
	$prefix   = 'SESSYPress\\';
	$base_dir = __DIR__ . '/includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file           = $base_dir . 'class-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
} );

// Initialize plugin
add_action( 'plugins_loaded', function () {
	Plugin::instance();
} );

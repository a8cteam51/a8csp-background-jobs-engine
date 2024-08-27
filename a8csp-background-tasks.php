<?php
/**
 * The a8csp-background-tasks bootstrap file.
 *
 * @since       1.0.0
 * @version     1.0.0
 * @author      WordPress.com Special Projects
 * @license     GPL-3.0-or-later
 *
 * @noinspection    ALL
 *
 * @wordpress-plugin
 * Plugin Name:             a8csp-background-tasks
 * Plugin URI:              https://wpspecialprojects.wordpress.com
 * Description:             
 * Version:                 1.0.0
 * Requires at least:       6.5
 * Tested up to:            6.5
 * Requires PHP:            8.2
 * Author:                  WordPress.com Special Projects
 * Author URI:              https://wpspecialprojects.wordpress.com
 * License:                 GPL v3 or later
 * License URI:             https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:             a8csp-background-tasks
 * Domain Path:             /languages
 * WC requires at least:    8.8
 * WC tested up to:         8.8
 **/

defined( 'ABSPATH' ) || exit;

// Define plugin constants.
function_exists( 'get_plugin_data' ) || require_once ABSPATH . 'wp-admin/includes/plugin.php';
define( 'A8CSP_BACKGROUND_TASKS_METADATA', get_plugin_data( __FILE__, false, false ) );

define( 'A8CSP_BACKGROUND_TASKS_BASENAME', plugin_basename( __FILE__ ) );
define( 'A8CSP_BACKGROUND_TASKS_PATH', plugin_dir_path( __FILE__ ) );
define( 'A8CSP_BACKGROUND_TASKS_URL', plugin_dir_url( __FILE__ ) );

// Load plugin translations so they are available even for the error admin notices.
add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			A8CSP_BACKGROUND_TASKS_METADATA['TextDomain'],
			false,
			dirname( A8CSP_BACKGROUND_TASKS_BASENAME ) . A8CSP_BACKGROUND_TASKS_METADATA['DomainPath']
		);
	}
);

// Load the autoloader.
if ( ! is_file( A8CSP_BACKGROUND_TASKS_PATH . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			$message      = __( 'It seems like <strong>a8csp-background-tasks</strong> is corrupted. Please reinstall!', 'a8csp-background-tasks' );
			$html_message = wp_sprintf( '<div class="error notice a8csp-background-tasks-error">%s</div>', wpautop( $message ) );
			echo wp_kses_post( $html_message );
		}
	);
	return;
}
require_once A8CSP_BACKGROUND_TASKS_PATH . '/vendor/autoload.php';

// Initialize the plugin if system requirements check out.
$a8csp_background_tasks_requirements = validate_plugin_requirements( A8CSP_BACKGROUND_TASKS_BASENAME );
define( 'A8CSP_BACKGROUND_TASKS_REQUIREMENTS', $a8csp_background_tasks_requirements );

if ( $a8csp_background_tasks_requirements instanceof WP_Error ) {
	add_action(
		'admin_notices',
		static function () use ( $a8csp_background_tasks_requirements ) {
			$html_message = wp_sprintf( '<div class="error notice a8csp-background-tasks-error">%s</div>', $a8csp_background_tasks_requirements->get_error_message() );
			echo wp_kses_post( $html_message );
		}
	);
} else {
	require_once A8CSP_BACKGROUND_TASKS_PATH . 'functions.php';
	add_action( 'plugins_loaded', array( a8csp_background_tasks_get_plugin_instance(), 'maybe_initialize' ) );
}

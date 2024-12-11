<?php
/**
 * The A8C Special Projects Background Tasks bootstrap file.
 *
 * @since       1.0.0
 * @version     1.0.0
 * @package     Automattic\SpecialProjects\BackgroundTasks
 * @author      Automattic Special Projects
 * @license     GPL-2.0-or-later
 *
 * @noinspection    ALL
 *
 * @wordpress-plugin
 * Plugin Name:             A8CSP Background Tasks
 * Plugin URI:              https://wpspecialprojects.wordpress.com
 * Description:             Provides a framework for running background tasks in WordPress.
 * Version:                 1.0.0
 * Requires at least:       6.7
 * Tested up to:            6.7
 * Requires PHP:            8.3
 * Author:                  Automattic Special Projects
 * Author URI:              https://wpspecialprojects.wordpress.com
 * License:                 GPL v3 or later
 * License URI:             https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:             a8csp-background-tasks
 * Domain Path:             /languages
 **/

defined( 'ABSPATH' ) || exit;

// Define plugin constants.
function_exists( 'get_plugin_data' ) || require_once ABSPATH . 'wp-admin/includes/plugin.php';
define( 'A8CSP_BGT_METADATA', get_plugin_data( __FILE__, false, false ) );

define( 'A8CSP_BGT_BASENAME', plugin_basename( __FILE__ ) );
define( 'A8CSP_BGT_PATH', plugin_dir_path( __FILE__ ) );
define( 'A8CSP_BGT_URL', plugin_dir_url( __FILE__ ) );

// Load plugin translations, so they are available even for the error admin notices.
add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			A8CSP_BGT_METADATA['TextDomain'],
			false,
			dirname( A8CSP_BGT_BASENAME ) . A8CSP_BGT_METADATA['DomainPath']
		);
	}
);

// Load the autoloader.
if ( ! is_file( A8CSP_BGT_PATH . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			wp_admin_notice(
				wp_sprintf(
					/* translators: %s: Plugin name */
					__( 'It seems like <strong>%s</strong> is corrupted. Please reinstall!', 'a8csp-background-tasks' ),
					A8CSP_BGT_METADATA['Name']
				),
				array( 'type' => 'error' )
			);
		}
	);
	return;
}
require_once A8CSP_BGT_PATH . '/vendor/autoload.php';

return;

// Initialize the plugin if system requirements check out.
$a8csp_bgt_requirements = validate_plugin_requirements( A8CSP_BGT_BASENAME );
define( 'A8CSP_BGT_REQUIREMENTS', $a8csp_bgt_requirements );

if ( $a8csp_bgt_requirements instanceof WP_Error ) {
	add_action(
		'admin_notices',
		static function () use ( $a8csp_bgt_requirements ) {
			wp_admin_notice(
				$a8csp_bgt_requirements->get_error_message(),
				array( 'type' => 'error' )
			);
		}
	);
} else {
	require_once A8CSP_BGT_PATH . 'functions.php';
	add_action( 'plugins_loaded', array( a8csp_bgt_get_plugin_instance(), 'initialize' ) );
}

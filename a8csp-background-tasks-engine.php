<?php declare( strict_types=1 );
/**
 * The A8CSP Background Tasks Engine bootstrap file.
 *
 * @since       1.0.0
 * @version     1.0.0
 * @package     A8C\SpecialProjects\BackgroundTasksEngine
 * @author      A8C Special Projects
 * @license     GPL-2.0-or-later
 *
 * @noinspection    ALL
 *
 * @wordpress-plugin
 * Plugin Name:             A8CSP Background Tasks Engine
 * Plugin URI:              https://specialprojects.automattic.com
 * Update URI:              https://github.com/a8cteam51/a8csp-background-tasks-engine
 * Description:             A background-work engine for WordPress sites: Tasks, Schedules, and Batches using Action Scheduler when available, with a documented best-effort WP-Cron fallback.
 * Version:                 1.0.0-beta.1
 * Requires at least:       7.0
 * Tested up to:            7.0
 * Requires PHP:            8.5
 * Author:                  A8C Special Projects
 * Author URI:              https://specialprojects.automattic.com
 * License:                 GPL v2 or later
 * License URI:             https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:             a8csp-background-tasks-engine
 * Domain Path:             /languages
 */

\defined( 'ABSPATH' ) || exit;

\define( 'A8CSP_BGTE_BASENAME', plugin_basename( __FILE__ ) );
\define( 'A8CSP_BGTE_DIR_PATH', plugin_dir_path( __FILE__ ) );

require_once A8CSP_BGTE_DIR_PATH . '/functions-bootstrap.php';

add_filter( 'update_plugins_github.com', 'a8csp_bgte_check_github_release_update', 10, 3 );

// Core registers header Domain Paths for site-active plugins only, so a network-activated copy
// registers its own translations path; loading stays just-in-time either way.
load_plugin_textdomain( 'a8csp-background-tasks-engine', false, dirname( A8CSP_BGTE_BASENAME ) . '/languages' );

if ( ! \is_file( A8CSP_BGTE_DIR_PATH . '/vendor/autoload.php' ) ) {
	a8csp_bgte_output_requirements_error( new WP_Error( 'missing_autoloader' ) );
	return;
}
require_once A8CSP_BGTE_DIR_PATH . '/vendor/autoload.php';

\define( 'A8CSP_BGTE_REQUIREMENTS', a8csp_bgte_validate_requirements() );
if ( is_wp_error( A8CSP_BGTE_REQUIREMENTS ) ) {
	a8csp_bgte_output_requirements_error( A8CSP_BGTE_REQUIREMENTS );
} else {
	require_once A8CSP_BGTE_DIR_PATH . '/functions.php';
	// Activation includes this file after plugins_loaded has fired, so boot immediately on that request.
	if ( 0 < did_action( 'plugins_loaded' ) ) {
		a8csp_bgte_plugin();
	} else {
		add_action( 'plugins_loaded', 'a8csp_bgte_plugin', 0 ); // @phpstan-ignore return.void
	}
}

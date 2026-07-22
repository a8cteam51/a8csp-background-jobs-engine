<?php declare( strict_types=1 );
/**
 * The A8CSP Background Jobs Engine bootstrap file.
 *
 * This file must remain parsable on PHP versions below the plugin's declared floor, since it
 * runs before the requirements check can report a friendly error; a dedicated CI job lints it
 * directly against the older PHP versions.
 *
 * @since       1.0.0
 * @version     1.0.0
 * @package     A8C\SpecialProjects\BackgroundJobsEngine
 * @author      A8C Special Projects
 * @license     GPL-2.0-or-later
 *
 * @noinspection    ALL
 *
 * @wordpress-plugin
 * Plugin Name:             A8CSP Background Jobs Engine
 * Plugin URI:              https://specialprojects.automattic.com
 * Update URI:              https://github.com/a8cteam51/a8csp-background-jobs-engine
 * Description:             A background-work engine for WordPress sites: Jobs, Schedules, and Chunked Jobs using Action Scheduler when available, with a documented best-effort WP-Cron fallback.
 * Version:                 1.0.0-beta.1
 * Requires at least:       7.0
 * Tested up to:            7.0
 * Requires PHP:            8.5
 * Author:                  A8C Special Projects
 * Author URI:              https://specialprojects.automattic.com
 * License:                 GPL v2 or later
 * License URI:             https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:             a8csp-background-jobs-engine
 * Domain Path:             /languages
 */

\defined( 'ABSPATH' ) || exit;

\define( 'A8CSP_BGJE_BASENAME', plugin_basename( __FILE__ ) );
\define( 'A8CSP_BGJE_DIR_PATH', plugin_dir_path( __FILE__ ) );

// The bootstrap's helper functions live in functions-bootstrap.php, which shares this file's
// below-floor parse constraint; they must exist before the updater registration and the
// requirements gate below can reference them.
require_once A8CSP_BGJE_DIR_PATH . 'functions-bootstrap.php';

// The self-updater registers before the requirements gates below: an incompatible install is
// the one that most needs to be offered the corrective update.
add_filter( 'update_plugins_github.com', 'a8csp_bgje_check_github_release_update', 10, 3 );

// Registration-only since WP 6.7, so include time is safe — and required: core registers the
// header path only for site-active plugins (wp-settings.php skips it in the network-activated
// loop), so network-activated copies lose their bundled translations without this line.
// Gettext calls still wait for `init` (JIT).
load_plugin_textdomain( 'a8csp-background-jobs-engine', false, dirname( A8CSP_BGJE_BASENAME ) . '/languages' );

if ( ! \is_file( A8CSP_BGJE_DIR_PATH . 'vendor/autoload.php' ) ) {
	a8csp_bgje_output_requirements_error( new WP_Error( 'missing_autoloader' ) );
	return;
}
require_once A8CSP_BGJE_DIR_PATH . 'vendor/autoload.php';

\define( 'A8CSP_BGJE_REQUIREMENTS_RESULT', a8csp_bgje_validate_requirements() );
if ( is_wp_error( A8CSP_BGJE_REQUIREMENTS_RESULT ) ) {
	a8csp_bgje_output_requirements_error( A8CSP_BGJE_REQUIREMENTS_RESULT );
} else {
	require_once A8CSP_BGJE_DIR_PATH . 'functions.php';
	add_action( 'plugins_loaded', array( a8csp_bgje_plugin(), 'boot' ) );
}

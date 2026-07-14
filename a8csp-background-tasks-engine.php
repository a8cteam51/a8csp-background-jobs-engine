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
 * Description:             A background-work engine for WordPress sites: Tasks, Schedules, and Batches on pluggable scheduling backends.
 * Version:                 1.0.0
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

add_filter(
	'update_plugins_github.com',
	static function ( $update, $plugin_data, $plugin_file ) {
		if ( A8CSP_BGTE_BASENAME !== $plugin_file || false !== $update ) {
			return $update;
		}

		$transient_key       = 'a8csp_bgte_github_latest_release';
		$latest_release_info = get_transient( $transient_key );
		if ( false === $latest_release_info ) {
			$response            = wp_remote_get( 'https://api.github.com/repos/a8cteam51/a8csp-background-tasks-engine/releases/latest' );
			$latest_release_info = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ? array() : \json_decode( wp_remote_retrieve_body( $response ), true );
		}
		if ( ! \is_array( $latest_release_info ) ) {
			$latest_release_info = array();
		}

		$release_tag    = $latest_release_info['tag_name'] ?? null;
		$release_url    = $latest_release_info['html_url'] ?? null;
		$release_assets = $latest_release_info['assets'] ?? null;

		$release_asset = null;
		foreach ( \is_array( $release_assets ) ? $release_assets : array() as $asset ) {
			if ( ! \is_array( $asset ) ) {
				continue;
			}

			$asset_name = $asset['name'] ?? null;
			if ( 'a8csp-background-tasks-engine.zip' !== $asset_name ) {
				continue;
			}

			$release_asset = $asset['browser_download_url'] ?? null;
			break;
		}

		$release_is_usable = \is_string( $release_tag ) && \is_string( $release_url ) && \is_string( $release_asset );
		if ( isset( $response ) ) {
			set_transient(
				$transient_key,
				$release_is_usable ? $latest_release_info : array(),
				$release_is_usable ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS
			);
		}
		if ( ! $release_is_usable ) {
			return $update;
		}

		$latest_release_version = \ltrim( $release_tag, 'v' );
		if ( \version_compare( $plugin_data['Version'], $latest_release_version, '<' ) ) {
			$update = array(
				'slug'    => $plugin_data['TextDomain'],
				'version' => $latest_release_version,
				'url'     => $release_url,
				'package' => $release_asset,
			);
		} else {
			$update = false;
		}

		return $update;
	},
	10,
	3
);

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
	add_action( 'plugins_loaded', 'a8csp_bgte_plugin' ); // @phpstan-ignore return.void
}

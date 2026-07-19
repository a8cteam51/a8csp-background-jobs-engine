<?php declare( strict_types=1 );
/**
 * BELOW-FLOOR BOOTSTRAP: LEGACY PHP PARSABILITY IS REQUIRED.
 *
 * These are the bootstrap's helper functions: the GitHub release updater, plugin metadata,
 * version compatibility checks, the requirements gate, and its admin-notice reporter.
 *
 * This file loads before the requirements check can run, so it MUST remain parsable on PHP
 * versions below the plugin's declared floor. No modern syntax beyond what it already carries
 * belongs in this file, and a dedicated CI job lints it directly against the older PHP versions.
 *
 * @since       1.0.0
 * @version     1.0.0
 * @package     A8C\SpecialProjects\BackgroundJobsEngine
 * @author      A8C Special Projects
 * @license     GPL-2.0-or-later
 */

\defined( 'ABSPATH' ) || exit;

/**
 * Returns the plugin's metadata.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @template PluginMetaKey of key-of<PluginMetaData>
 *
 * @param   PluginMetaKey|null $property Optional. The property to return. Default all.
 *
 * @return  ($property is null ? PluginMetaData : ($property is PluginMetaKey ? PluginMetaData[PluginMetaKey] : null))
 */
function a8csp_bgje_get_plugin_metadata( $property = null ) {
	/**
	 * Metadata cache, keyed raw vs translated.
	 *
	 * @var array<string, PluginMetaData> $plugin_data
	 */
	static $plugin_data = array();

	$can_translate = 0 < did_action( 'init' );
	$cache_key     = $can_translate ? 'translated' : 'raw';

	if ( isset( $plugin_data[ $cache_key ] ) ) {
		$metadata = $plugin_data[ $cache_key ];
	} else {
		if ( ! \function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file = trailingslashit( WP_PLUGIN_DIR ) . A8CSP_BGJE_BASENAME;
		$metadata    = get_plugin_data( $plugin_file, false, $can_translate );

		// Extra plugin headers registered by other plugins exist only once those plugins have
		// loaded, and this plugin can load first. A read is therefore cached only from
		// `plugins_loaded` onward, so an include-time read cannot poison later header reads.
		if ( 0 < did_action( 'plugins_loaded' ) ) {
			$plugin_data[ $cache_key ] = $metadata;
		}
	}

	if ( null === $property ) {
		return $metadata;
	}

	if ( \is_string( $property ) && isset( $metadata[ $property ] ) ) {
		return $metadata[ $property ];
	}

	return null;
}

/**
 * Returns the plugin's slug.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  string
 */
function a8csp_bgje_get_plugin_slug() {
	$text_domain = a8csp_bgje_get_plugin_metadata( 'TextDomain' );
	return sanitize_key( $text_domain );
}

/**
 * Returns the plugin's name.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  string
 */
function a8csp_bgje_get_plugin_name() {
	return a8csp_bgje_get_plugin_metadata( 'Name' );
}

/**
 * Returns the plugin's version.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  string
 */
function a8csp_bgje_get_plugin_version() {
	return a8csp_bgje_get_plugin_metadata( 'Version' );
}

/**
 * Filters the update-check result for this plugin against its GitHub releases.
 *
 * A prerelease installation follows every published release; a stable installation follows only
 * the stable channel, which the latest-release endpoint provides by definition.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   array<string, mixed>|false                 $update      The pending update data, or false when none is known yet.
 * @param   array{Version: string, TextDomain: string} $plugin_data The plugin's header data.
 * @param   string                                     $plugin_file The plugin file being checked.
 *
 * @return  array<string, mixed>|false
 */
function a8csp_bgje_check_github_release_update( $update, $plugin_data, $plugin_file ) {
	if ( \constant( 'A8CSP_BGJE_BASENAME' ) !== $plugin_file || false !== $update ) {
		return $update;
	}

	$prerelease_channel = \str_contains( (string) ( $plugin_data['Version'] ?? '' ), '-' );
	$transient_key      = 'a8csp_bgje_github_latest_release_' . ( $prerelease_channel ? 'prerelease' : 'stable' );

	$latest_release_info = get_transient( $transient_key );
	if ( false === $latest_release_info ) {
		$release_url_path    = $prerelease_channel ? 'releases?per_page=10' : 'releases/latest';
		$response            = wp_remote_get( 'https://api.github.com/repos/a8cteam51/a8csp-background-tasks-engine/' . $release_url_path );
		$latest_release_info = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ? array() : \json_decode( wp_remote_retrieve_body( $response ), true );
	}

	if ( ! \is_array( $latest_release_info ) ) {
		$latest_release_info = array();
	}

	if ( array() !== $latest_release_info && \array_is_list( $latest_release_info ) ) {
		// The GitHub /releases list is ordered by publish time, not version, so keep the highest-versioned non-draft entry rather than the first.
		$channel_latest         = null;
		$channel_latest_version = null;
		foreach ( $latest_release_info as $release_candidate ) {
			if ( ! \is_array( $release_candidate ) || true === ( $release_candidate['draft'] ?? null ) ) {
				continue;
			}

			$candidate_tag = $release_candidate['tag_name'] ?? null;
			if ( ! \is_string( $candidate_tag ) ) {
				continue;
			}

			$candidate_version = \ltrim( $candidate_tag, 'v' );
			if ( null === $channel_latest_version || \version_compare( $candidate_version, $channel_latest_version, '>' ) ) {
				$channel_latest         = $release_candidate;
				$channel_latest_version = $candidate_version;
			}
		}

		$latest_release_info = \is_array( $channel_latest ) ? $channel_latest : array();
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
		if ( 'a8csp-background-jobs-engine.zip' !== $asset_name ) {
			continue;
		}

		$release_asset = $asset['browser_download_url'] ?? null;
		break;
	}

	$release_is_usable = \is_string( $release_tag ) && \is_string( $release_url ) && \is_string( $release_asset );
	if ( isset( $response ) ) {
		set_transient( $transient_key, $release_is_usable ? $latest_release_info : array(), $release_is_usable ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
	}

	if ( ! $release_is_usable ) {
		return false;
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
}

/**
 * Checks compatibility with the current WordPress version.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $min_wp_version The minimum WP version required to run.
 *
 * @return  bool
 */
function a8csp_bgje_is_wp_version_compatible( $min_wp_version ) {
	if ( ! \function_exists( 'is_wp_version_compatible' ) ) {
		return false;
	}

	return is_wp_version_compatible( $min_wp_version );
}

/**
 * Checks compatibility with the current PHP version.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $min_php_version The minimum PHP version required to run.
 *
 * @return  bool
 */
function a8csp_bgje_is_php_version_compatible( $min_php_version ) {
	if ( ! \function_exists( 'is_php_version_compatible' ) ) {
		return false;
	}

	return is_php_version_compatible( $min_php_version );
}

/**
 * Validates the plugin requirements.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  true|\WP_Error
 */
function a8csp_bgje_validate_requirements() {
	$plugin_metadata = a8csp_bgje_get_plugin_metadata();
	if ( ! isset( $plugin_metadata['RequiresPHP'] ) || '' === $plugin_metadata['RequiresPHP'] ) {
		$plugin_metadata['RequiresPHP'] = '8.5';
	}
	if ( ! isset( $plugin_metadata['RequiresWP'] ) || '' === $plugin_metadata['RequiresWP'] ) {
		$plugin_metadata['RequiresWP'] = '7.0';
	}

	$is_php_compatible = a8csp_bgje_is_php_version_compatible( $plugin_metadata['RequiresPHP'] );
	$is_wp_compatible  = a8csp_bgje_is_wp_version_compatible( $plugin_metadata['RequiresWP'] );

	$wp_error = new \WP_Error();
	if ( ! $is_wp_compatible ) {
		$wp_error->add( 'plugin_wp_incompatible', '', array( 'requires_wp' => $plugin_metadata['RequiresWP'] ) );
	}
	if ( ! $is_php_compatible ) {
		$wp_error->add( 'plugin_php_incompatible', '', array( 'requires_php' => $plugin_metadata['RequiresPHP'] ) );
	}

	return $wp_error->has_errors() ? $wp_error : true;
}

/**
 * Outputs an error that the system requirements weren't met.
 *
 * The notice hangs on `all_admin_notices`, which fires on site, network, and user admin screens
 * alike — so a network activation that fails the gate is explained on the network admin screen
 * where it happened, not just on per-site dashboards.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   \WP_Error $error          The error message to display.
 *
 * @return  void
 */
function a8csp_bgje_output_requirements_error( $error ) {
	add_action(
		'all_admin_notices',
		static function () use ( $error ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			$requirements_error = wp_sprintf(
				/* translators: 1: Plugin name, 2: Plugin version */
				__( '<strong>%1$s (version %2$s)</strong> could not be initialized.', 'a8csp-background-jobs-engine' ),
				a8csp_bgje_get_plugin_metadata( 'Name' ),
				a8csp_bgje_get_plugin_metadata( 'Version' )
			);

			if ( $error->has_errors() ) {
				$requirements_error .= ' ' . __( 'Your environment does not meet all the system requirements listed below:', 'a8csp-background-jobs-engine' );
				$requirements_error .= '<ul class="ul-disc">';

				/**
				 * WP_Error accepts only integer or string error codes.
				 *
				 * @var int|string $error_code
				 */
				foreach ( $error->get_error_codes() as $error_code ) {
					$error_data = $error->get_error_data( $error_code );
					if ( ! \is_array( $error_data ) ) {
						$error_data = array();
					}

					switch ( $error_code ) {
						case 'plugin_wp_incompatible':
							$error_message = wp_sprintf(
								/* translators: 1: Current WP version, 2: Minimum WP version */
								__( 'Current <em>WordPress version (%1$s)</em> does not meet minimum required version of %2$s.', 'a8csp-background-jobs-engine' ),
								get_bloginfo( 'version' ),
								$error_data['requires_wp']
							);
							break;
						case 'plugin_php_incompatible':
							$error_message = wp_sprintf(
								/* translators: 1: Current PHP version, 2: Minimum PHP version */
								__( 'Current <em>PHP version (%1$s)</em> does not meet minimum required version of %2$s.', 'a8csp-background-jobs-engine' ),
								PHP_VERSION,
								$error_data['requires_php']
							);
							break;
						case 'missing_autoloader':
							$error_message = __( 'The autoloader file is missing. Please run <code>composer install</code> to generate it.', 'a8csp-background-jobs-engine' );
							break;
						default:
							$error_message = $error->get_error_message( $error_code );
					}

					$requirements_error .= "<li>$error_message</li>";
				}

				$requirements_error .= '</ul>';
			}

			if ( \function_exists( 'wp_admin_notice' ) ) {
				wp_admin_notice( $requirements_error, array( 'type' => 'error' ) );
			} else {
				echo wp_kses_post( '<div class="notice notice-error"><p>' . $requirements_error . '</p></div>' );
			}
		}
	);
}

<?php declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

use A8C\SpecialProjects\BackgroundTasks\Plugin;

// region META

/**
 * Returns the plugin's main class instance.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  Plugin
 */
function a8csp_bgt_get_plugin_instance(): Plugin {
	return Plugin::get_instance();
}

/**
 * Returns the plugin's slug.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  string
 */
function a8csp_bgt_get_plugin_slug(): string {
	return sanitize_key( A8CSP_BACKGROUND_TASKS_METADATA['TextDomain'] );
}

// endregion

// region OTHERS

foreach ( glob( A8CSP_BACKGROUND_TASKS_PATH . 'includes/*.php' ) as $a8csp_bgt_filename ) {
	if ( preg_match( '#/includes/_#i', $a8csp_bgt_filename ) ) {
		continue; // Ignore files prefixed with an underscore.
	}

	include $a8csp_bgt_filename;
}

// endregion



// region TASKS API

/**
 * Returns the hash of the provided arguments.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   array $args The arguments to hash.
 *
 * @return  string
 */
function a8csp_bgt_hash_task_args( array $args ): string {
	return hash( 'md5', wp_json_encode( $args ) );
}

// endregion

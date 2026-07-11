<?php declare( strict_types=1 );

/**
 * Canned plugin metadata matching the plugin header for Unit tests that drive version-floor
 * branches without WordPress. The `function_exists()` guards keep this file inert wherever the
 * real bootstrap is loaded.
 *
 * @since   1.0.0
 * @version 1.0.0
 * @package A8C\SpecialProjects\BackgroundTasksEngine
 */

if ( ! \function_exists( 'a8csp_bgte_get_plugin_metadata' ) ) {
	/**
	 * Returns the canned plugin metadata value for a requested property.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $property Optional. The metadata property to return.
	 *
	 * @return  string|null
	 */
	function a8csp_bgte_get_plugin_metadata( $property = null ) {
		return 'WC requires at least' === $property ? '10.0' : null;
	}
}

if ( ! \function_exists( 'a8csp_bgte_get_plugin_name' ) ) {
	/**
	 * Returns the canned plugin name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	function a8csp_bgte_get_plugin_name() {
		return 'A8CSP Background Tasks Engine';
	}
}

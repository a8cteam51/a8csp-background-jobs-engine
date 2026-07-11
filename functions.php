<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundTasksEngine\Plugin;

\defined( 'ABSPATH' ) || exit;

// region META

/**
 * Returns the plugin instance, booting it on first access.
 *
 * Doubles as the `plugins_loaded` hook target: WordPress ignores an action callback's return
 * value, so the accessor is hooked directly. Named, rather than an anonymous closure, so
 * `remove_action( 'plugins_loaded', 'a8csp_bgte_plugin' )` can unhook the boot and
 * `has_action()` can assert the wiring by name.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  Plugin
 */
function a8csp_bgte_plugin(): Plugin {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new Plugin();
		$plugin->boot();
	}

	return $plugin;
}
// endregion

// region OTHER

$a8csp_bgte_includes = \glob( \constant( 'A8CSP_BGTE_DIR_PATH' ) . 'includes/*.php' );
if ( false !== $a8csp_bgte_includes ) {
	\sort( $a8csp_bgte_includes ); // Glob order is filesystem-dependent, so sort for a deterministic load order.
	foreach ( $a8csp_bgte_includes as $a8csp_bgte_include ) {
		if ( \str_starts_with( \basename( $a8csp_bgte_include ), '_' ) ) {
			continue; // An underscore prefix opts a file out of automatic loading.
		}

		require_once $a8csp_bgte_include;
	}
}

// endregion

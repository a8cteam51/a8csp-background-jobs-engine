<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Engine;
use A8C\SpecialProjects\BackgroundJobsEngine\Plugin;

\defined( 'ABSPATH' ) || exit;

// region META

/**
 * Returns the plugin's composition root.
 *
 * Construction only; it never boots the plugin.
 *
 * @internal Engine boot wiring; clients enter through `a8csp_bgje()`.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  Plugin
 */
function a8csp_bgje_plugin(): Plugin {
	/**
	 * Shared composition-root instance.
	 *
	 * @var Plugin|null $plugin
	 */
	static $plugin = null;

	return $plugin ??= new Plugin();
}

/**
 * Returns the owner-bound background-work engine handle.
 *
 * Construction is lazy and infallible. Owner validation and engine readiness surface as `WP_Error`
 * from the first verb call. Use the calling plugin's lowercase slug as the owner; owner exclusivity
 * is a client convention, while the `a8csp-jobs-engine` prefix is enforced as the engine's reserved
 * namespace. Call the engine on the target blog so storage uses a client graph bound to that site.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner Stable client-plugin owner.
 *
 * @return  Engine
 */
function a8csp_bgje( string $owner ): Engine {
	return new Engine( $owner );
}

// endregion

// region OTHER

$a8csp_bgje_includes = \glob( \constant( 'A8CSP_BGJE_DIR_PATH' ) . 'includes/*.php' );
if ( false !== $a8csp_bgje_includes ) {
	\sort( $a8csp_bgje_includes ); // Glob order is filesystem-dependent, so sort for a deterministic load order.
	foreach ( $a8csp_bgje_includes as $a8csp_bgje_include ) {
		if ( \str_starts_with( \basename( $a8csp_bgje_include ), '_' ) ) {
			continue; // An underscore prefix opts a file out of automatic loading.
		}

		require_once $a8csp_bgje_include;
	}
}

// endregion

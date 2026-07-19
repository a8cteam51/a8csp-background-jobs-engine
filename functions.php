<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Client;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundTasksEngine\Plugin;

\defined( 'ABSPATH' ) || exit;

// region META

/**
 * Returns the plugin's composition root.
 *
 * Construction only; it never boots the plugin.
 *
 * @internal Engine boot wiring; clients enter through `a8csp_bgte()`.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  Plugin
 */
function a8csp_bgte_plugin(): Plugin {
	/**
	 * Shared composition-root instance.
	 *
	 * @var Plugin|null $plugin
	 */
	static $plugin = null;

	return $plugin ??= new Plugin();
}

/**
 * Returns the owner-bound background-work client.
 *
 * Available from `init` or later. By `init`, the engine's `plugins_loaded` priority-zero boot has
 * run in every standard load path; the request that activates the engine is the one exception —
 * it stays dormant there until the next request. Use the calling plugin's lowercase slug as the
 * owner; owner exclusivity is a client convention, while the `a8csp-bgte` prefix is enforced as
 * the engine's reserved namespace.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner Stable client-plugin owner.
 *
 * @throws  \InvalidArgumentException When the owner violates the canonical client grammar.
 * @throws  \LogicException           When called before the earliest safe hook or engine wiring fails.
 *
 * @return  Client
 */
function a8csp_bgte( string $owner ): Client {
	if ( 0 === did_action( 'init' ) && ! doing_action( 'init' ) ) {
		throw new \LogicException( 'The background tasks client is available from the init hook; call a8csp_bgte() from an init callback or later.' );
	}

	return Component::client( $owner );
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

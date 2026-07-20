<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Client;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Component;
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
 * Returns the owner-bound background-work client.
 *
 * Available from `init` or later. By `init`, the engine's `plugins_loaded` priority-zero boot has
 * run in every standard load path; the request that activates the engine is the one exception —
 * it stays dormant there until the next request. Use the calling plugin's lowercase slug as the
 * owner; owner exclusivity is a client convention, while the `a8csp-jobs-engine` prefix is enforced as
 * the engine's reserved namespace.
 * Call the engine on the target blog; a storage operation after `switch_to_blog()` throws instead of
 * writing through a client graph bound to another site.
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
function a8csp_bgje( string $owner ): Client {
	if ( 0 === did_action( 'init' ) && ! doing_action( 'init' ) ) {
		throw new \LogicException( 'The background jobs client is available from the init hook; call a8csp_bgje() from an init callback or later.' );
	}

	return Component::client( $owner );
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

<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundTasksEngine\Plugin;

\defined( 'ABSPATH' ) || exit;

// region META

/**
 * Returns the plugin instance, booting it on first access.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  Plugin
 */
function a8csp_bgte_plugin(): Plugin {
	/**
	 * Boot-once shared instance.
	 *
	 * @var Plugin|null $plugin
	 */
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new Plugin();
		$plugin->boot();
	}

	return $plugin;
}

/**
 * Returns the owner-bound background-work consumer.
 *
 * Available from `init` or later. By `init`, the engine's `plugins_loaded` priority-zero boot has
 * always run in every standard load path. Use the calling plugin's lowercase slug as the owner;
 * owner exclusivity is a consumer convention, while the `a8csp-bgte` prefix is enforced as the
 * engine's reserved namespace.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner Stable consumer-plugin owner.
 *
 * @throws  \InvalidArgumentException When the owner violates the canonical consumer grammar.
 * @throws  \LogicException           When called before the earliest safe hook or engine wiring fails.
 *
 * @return  Consumer
 */
function a8csp_bgte( string $owner ): Consumer {
	if ( 0 === did_action( 'init' ) && ! doing_action( 'init' ) ) {
		throw new \LogicException(
			'The background tasks consumer is available from the init hook; call a8csp_bgte() from an init callback or later.'
		);
	}

	return Component::consumer( $owner );
}

// endregion

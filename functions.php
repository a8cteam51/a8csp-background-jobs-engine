<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Container;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\WorkIdentity;
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
 * Call from `plugins_loaded` or later. Resolution during any `plugins_loaded` priority initializes
 * the engine graph on demand, so consumer ordering within that hook is immaterial. Use the calling
 * plugin's lowercase slug as the owner; owner exclusivity is a consumer convention, while the
 * `a8csp-bgte` prefix is enforced as the engine's reserved namespace.
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
	WorkIdentity::validate_owner( $owner );

	if ( 0 === did_action( 'plugins_loaded' ) && ! doing_action( 'plugins_loaded' ) ) {
		throw new \LogicException(
			'The background tasks consumer is available from the plugins_loaded hook; call a8csp_bgte() from a plugins_loaded callback or later.'
		);
	}

	Container::boot();

	return Container::consumer( $owner );
}

// endregion

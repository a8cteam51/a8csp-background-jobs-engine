<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * A plugin is a list of components: `COMPONENTS` below is that list, and `boot()` runs it — a
 * component is a class with `is_needed()` and `initialize()`, and the boot is a foreach you can
 * read. This is the one file you edit to wire a component in.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class Plugin {
	// region FIELDS AND CONSTANTS

	/**
	 * Add the plugin's top-level components here; they boot in registration order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<int, class-string<Component>>
	 */
	private const COMPONENTS = array();

	/**
	 * Whether `boot()` has already run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     bool
	 */
	private bool $booted = false;

	// endregion

	// region METHODS

	/**
	 * Returns true if the plugin should boot on the current site.
	 *
	 * A plugin that is gated as a whole expresses that check here once instead of in every
	 * component.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	public function is_needed(): bool {
		return true;
	}

	// endregion

	// region HOOKS

	/**
	 * Boots every registered component whose gate is open; idempotent — only the first eligible call
	 * has any effect.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function boot(): void {
		if ( $this->booted || ! $this->is_needed() ) {
			return;
		}

		$this->booted = true;

		foreach ( self::COMPONENTS as $component_class ) {
			$component = new $component_class();
			if ( $component->is_needed() ) {
				$component->initialize();
			}
		}
	}

	// endregion
}

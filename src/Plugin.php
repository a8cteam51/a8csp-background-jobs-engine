<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine;

use A8C\SpecialProjects\BackgroundTasksEngine\CLI;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine;

\defined( 'ABSPATH' ) || exit;

/**
 * The plugin's composition root: `COMPONENTS` is the top-level component list and `boot()` runs it.
 *
 * The `plugins_loaded` boot initializes each needed `COMPONENTS` entry at most once.
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
	 * @var     array<int, class-string<ComponentInterface>>
	 */
	private const COMPONENTS = array(
		Engine\Component::class,
		CLI\Component::class,
	);

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

	// region HOOKS

	/**
	 * Boots every registered component whose gate is open; idempotent — only the first eligible call
	 * has any effect.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \Throwable When component initialization fails.
	 *
	 * @return  void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		try {
			foreach ( self::COMPONENTS as $component_class ) {
				$component = new $component_class();
				if ( $component->is_needed() ) {
					$component->initialize();
				}
			}
		} catch ( \Throwable $throwable ) {
			$this->booted = false;
			throw $throwable;
		}
	}

	// endregion
}

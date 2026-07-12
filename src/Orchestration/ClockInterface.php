<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Orchestration;

\defined( 'ABSPATH' ) || exit;

/**
 * Supplies Unix timestamps to orchestration persistence boundaries.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface ClockInterface {
	// region METHODS

	/**
	 * Returns the current Unix timestamp.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int
	 */
	public function now(): int;

	// endregion
}

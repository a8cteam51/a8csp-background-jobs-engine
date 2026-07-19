<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine;

\defined( 'ABSPATH' ) || exit;

/**
 * Supplies injectable integer randomness for orchestration decisions.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface RandomizerInterface {
	// region METHODS

	/**
	 * Returns a uniformly distributed random integer between the inclusive boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $min Inclusive lower boundary.
	 * @param   int $max Inclusive upper boundary.
	 *
	 * @return  int
	 */
	public function int( int $min, int $max ): int;

	// endregion
}

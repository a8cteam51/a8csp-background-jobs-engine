<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime;

\defined( 'ABSPATH' ) || exit;

/**
 * Supplies cryptographically secure integer randomness.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class Randomizer implements RandomizerInterface {
	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function int( int $min, int $max ): int {
		return \random_int( $min, $max );
	}

	// endregion
}

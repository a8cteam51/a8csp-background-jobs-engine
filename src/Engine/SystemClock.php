<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Engine;

use Psr\Clock\ClockInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Supplies the current system instant.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class SystemClock implements ClockInterface {
	// region INHERITED METHODS

	/**
	 * Returns the current system instant.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  \DateTimeImmutable
	 */
	#[\Override]
	public function now(): \DateTimeImmutable {
		return new \DateTimeImmutable();
	}

	// endregion
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job;

\defined( 'ABSPATH' ) || exit;

/**
 * Defines bounded exponential retry-delay ceilings.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class RetryPolicy {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $max_attempts Total permitted attempts, including the initial attempt.
	 * @param   int $base_delay   First retry's maximum delay in seconds.
	 * @param   int $multiplier   Exponential delay multiplier.
	 * @param   int $max_delay    Maximum delay in seconds.
	 *
	 * @throws  \InvalidArgumentException When an invariant does not hold.
	 */
	public function __construct(
		public int $max_attempts = 3,
		public int $base_delay = \MINUTE_IN_SECONDS,
		public int $multiplier = 2,
		public int $max_delay = \HOUR_IN_SECONDS,
	) {
		if ( 1 > $this->max_attempts || 1 > $this->base_delay ) {
			throw new \InvalidArgumentException( 'Retry policy requires at least one attempt and a positive base delay.' );
		}

		if ( 1 > $this->multiplier ) {
			throw new \InvalidArgumentException( 'Retry policy requires a multiplier of at least one.' );
		}

		if ( $this->base_delay > $this->max_delay ) {
			throw new \InvalidArgumentException( 'Retry policy requires the maximum delay to be at least the base delay.' );
		}
	}

	// endregion

	// region METHODS

	/**
	 * Returns the deterministic delay ceiling separating a failed attempt from its next attempt.
	 *
	 * Attempt one produces the first retry ceiling at `base_delay`.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $attempt One-indexed number of the just-failed attempt.
	 *
	 * @throws  \InvalidArgumentException When the attempt has no following attempt.
	 *
	 * @return  int
	 */
	public function delay_ceiling_for_attempt( int $attempt ): int {
		if ( 1 > $attempt || $attempt >= $this->max_attempts ) {
			throw new \InvalidArgumentException( 'Retry delay requires $attempt to be the one-indexed just-failed attempt number in the range 1 <= $attempt < max_attempts so a next attempt exists.' );
		}

		$ceiling = $this->base_delay;

		if ( 1 < $this->multiplier ) {
			for ( $step = 1; $step < $attempt && $ceiling < $this->max_delay; ++$step ) {
				if ( $ceiling > \intdiv( $this->max_delay, $this->multiplier ) ) {
					$ceiling = $this->max_delay;
					break;
				}

				$ceiling *= $this->multiplier;
			}
		}

		return $ceiling;
	}

	// endregion
}

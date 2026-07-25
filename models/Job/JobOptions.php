<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Declares optional execution, retry, and overlap policy for one job definition.
 *
 * Null values select the corresponding engine default.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class JobOptions {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param (\Closure(array<array-key, mixed>): ?string)|null $overlap_key
	 *
	 * @param   int|null           $max_runtime Positive seconds for one execution invocation, or null for the engine default.
	 *                                          Effective credit is clamped to 21,600 seconds (6 hours); higher declarations are accepted.
	 * @param   RetryPolicy|null   $retry       Retry policy, or null for the engine default.
	 * @param   OverlapPolicy|null $overlap     Overlap policy, or null for the engine default.
	 * @param   \Closure|null      $overlap_key Argument-aware overlap identity, or null for the canonical argument hash.
	 *
	 * @throws  \InvalidArgumentException When the maximum runtime is non-positive.
	 */
	public function __construct(
		public ?int $max_runtime = null,
		public ?RetryPolicy $retry = null,
		public ?OverlapPolicy $overlap = null,
		public ?\Closure $overlap_key = null,
	) {
		if ( null !== $this->max_runtime && 1 > $this->max_runtime ) {
			throw new \InvalidArgumentException( 'Job maximum runtime must be positive; pass null for the engine default or a value of at least one second.' );
		}
	}

	// endregion
}

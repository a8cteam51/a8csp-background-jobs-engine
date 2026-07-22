<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job;

\defined( 'ABSPATH' ) || exit;

/**
 * Declares optional execution, retry, and overlap policy for one job definition.
 *
 * Null values select the corresponding engine default.
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
	 * @param   int|null           $max_runtime Maximum seconds for one execution invocation.
	 * @param   RetryPolicy|null   $retry       Retry policy, or null for the engine default.
	 * @param   OverlapPolicy|null $overlap     Overlap policy, or null for the engine default.
	 * @param   \Closure|null      $overlap_key Argument-aware overlap identity, or null for the canonical argument hash.
	 */
	public function __construct(
		public ?int $max_runtime = null,
		public ?RetryPolicy $retry = null,
		public ?OverlapPolicy $overlap = null,
		public ?\Closure $overlap_key = null,
	) {}

	// endregion
}

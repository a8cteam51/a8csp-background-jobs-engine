<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Declares optional execution, retry, overlap, and priority policy for one job definition.
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
	 *                                          Effective credit is clamped to `Runtime\Locks\LockWindows::MAX_EXECUTION_LEASE`,
	 *                                          21,600 seconds (6 hours); higher declarations are accepted.
	 * @param   RetryPolicy|null   $retry       Retry policy, or null for the engine default.
	 * @param   OverlapPolicy|null $overlap     Overlap policy, or null for the engine default.
	 * @param   \Closure|null      $overlap_key Argument-aware overlap identity, or null for the canonical argument hash.
	 * @param   int|null           $priority    Job-default priority from 0 through 255, or null to defer to the engine default.
	 */
	public function __construct(
		public ?int $max_runtime = null,
		public ?RetryPolicy $retry = null,
		public ?OverlapPolicy $overlap = null,
		public ?\Closure $overlap_key = null,
		public ?int $priority = null,
	) {}

	// endregion
}

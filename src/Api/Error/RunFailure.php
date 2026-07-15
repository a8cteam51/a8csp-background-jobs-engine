<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Error;

\defined( 'ABSPATH' ) || exit;

/**
 * Persisted terminal-failure value delivered to consumer callbacks and lifecycle hooks.
 *
 * The terminalization stage is one of `execution`, `queue-generation`, `crash-reclaim`, or
 * `scheduling`. The summary is engine-authored and redacted; it never contains a raw consumer
 * exception message.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class RunFailure implements ErrorInterface {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                       $name         Stable task or batch identity.
	 * @param   string                       $run_id       Run identifier.
	 * @param   int                          $attempts     Attempts consumed before terminal failure.
	 * @param   string                       $stage        Terminalization stage.
	 * @param   ApiErrorCode                 $code         Machine-readable cause classification.
	 * @param   string                       $summary      Engine-authored redacted failure summary.
	 * @param   array<array-key, mixed>|null $failed_chunk Batch chunk arguments for the failing chunk, or null for a task or non-chunk failure.
	 */
	public function __construct(
		public string $name,
		public string $run_id,
		public int $attempts,
		public string $stage,
		public ApiErrorCode $code,
		public string $summary,
		public ?array $failed_chunk,
	) {}

	// endregion
}

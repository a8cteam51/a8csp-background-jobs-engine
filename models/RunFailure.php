<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Persisted terminal-failure value delivered to client callbacks and lifecycle hooks.
 *
 * The terminalization stage is a `RunFailureStage` case. The summary is engine-authored and
 * redacted; it never contains a raw client exception message.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class RunFailure {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                       $identity     Complete owner-qualified job or chunked job identity.
	 * @param   string                       $run_id       Run identifier.
	 * @param   int                          $attempts     Attempts consumed before terminal failure.
	 * @param   RunFailureStage              $stage        Terminalization stage.
	 * @param   ErrorCode                    $code         Machine-readable cause classification.
	 * @param   string                       $summary      Engine-authored redacted failure summary.
	 * @param   array<array-key, mixed>|null $failed_chunk Chunked Job chunk arguments for the failing chunk, or null for a job or non-chunk failure.
	 */
	public function __construct(
		public string $identity,
		public string $run_id,
		public int $attempts,
		public RunFailureStage $stage,
		public ErrorCode $code,
		public string $summary,
		public ?array $failed_chunk,
	) {}

	// endregion
}

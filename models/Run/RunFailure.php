<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Persisted terminal-failure value delivered to lifecycle hooks.
 *
 * The terminalization stage is a `RunFailureStage` value. The summary is engine-authored and
 * redacted; it never contains a raw client exception message. Details carry an optional generic
 * diagnostic payload.
 *
 * @api
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
	 * @param   string                       $identity Complete scope-qualified job or chunked job identity.
	 * @param   RunId                        $run_id   Run identifier.
	 * @param   int                          $attempts Attempts consumed before terminal failure.
	 * @param   RunFailureStage              $stage    Terminalization stage.
	 * @param   ErrorCode                    $code     Machine-readable cause classification.
	 * @param   string                       $summary  Engine-authored redacted failure summary.
	 * @param   array<array-key, mixed>|null $details  Generic diagnostic payload, or null when no details are available.
	 */
	public function __construct(
		public string $identity,
		public RunId $run_id,
		public int $attempts,
		public RunFailureStage $stage,
		public ErrorCode $code,
		public string $summary,
		public ?array $details,
	) {}

	// endregion
}

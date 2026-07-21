<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;

\defined( 'ABSPATH' ) || exit;

/**
 * Supplies cross-kind callback-runtime, overlap, retry, and terminal-notification defaults.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
trait JobDefaults {
	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function max_callback_runtime(): int {
		return self::DEFAULT_MAX_CALLBACK_RUNTIME;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function overlap_policy(): OverlapPolicy {
		return OverlapPolicy::Reject;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 *
	 * @return  string|null
	 */
	#[\Override]
	public function overlap_key( array $start_args ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The contract-fixed signature supplies arguments the keyless default ignores.
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Subclasses override this optional notification to observe a completed run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id                    Run identifier.
	 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
	 * @param   string|null             $previous_completed_run_id Previous completed run identifier for this identity, or null.
	 *
	 * @return  void
	 */
	#[\Override]
	public function on_completed( string $run_id, array $start_args, ?string $previous_completed_run_id ): void {}

	/**
	 * {@inheritDoc}
	 *
	 * Subclasses override this optional notification to observe a failed run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 * @param   RunFailure              $failure    Persisted terminal-failure value.
	 *
	 * @return  void
	 */
	#[\Override]
	public function on_failed( string $run_id, array $start_args, RunFailure $failure ): void {}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function get_retry_policy(): RetryPolicy {
		return new RetryPolicy();
	}

	// endregion
}

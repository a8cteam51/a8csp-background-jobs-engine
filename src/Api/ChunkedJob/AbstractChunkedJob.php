<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\RetryPolicy;

\defined( 'ABSPATH' ) || exit;

/**
 * Supplies default callback-runtime, retry, and terminal-notification behavior for chunked job implementations.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
abstract class AbstractChunkedJob implements ChunkedJobInterface {
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
	 * Subclasses override this optional notification to observe a completed run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 *
	 * @return  void
	 */
	#[\Override]
	public function on_completed( string $run_id, array $start_args ): void {}

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

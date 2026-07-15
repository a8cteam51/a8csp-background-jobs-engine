<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\WorkInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Retry\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Tasks\Exceptions\NonRetryableExceptionInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Contract for background work split into independently processed chunks.
 *
 * Queue generation defines the initial chunks, and processing handles one chunk. The engine invokes
 * at most one terminal callback for each run: `on_success()` after every chunk succeeds or
 * `on_failure()` after the run fails.
 *
 * A cancelled or superseded run ends without either callback; those outcomes surface through engine
 * hooks.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface BatchInterface extends WorkInterface {
	// region METHODS

	/**
	 * Returns the non-empty stable batch identity matching `[a-z0-9_-]+`.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	public function get_name(): string;

	/**
	 * Returns the declared ceiling in seconds for one queue-generation or chunk invocation.
	 *
	 * The ceiling applies independently to one `generate_queue()` or `process_chunk()` call, not to
	 * the whole batch run. The engine credits run liveness for this window immediately before either
	 * callback; exceeding it makes the still-executing run reclaimable as crashed after its lock
	 * staleness window elapses.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int
	 */
	public function max_runtime(): int;

	/**
	 * Generates one argument array for each initial chunk.
	 *
	 * The engine materializes the iterable, then applies
	 * `a8csp_background_tasks/queue/{batch}` with the exact signature
	 * `(list<array<array-key, mixed>> $queue, array<array-key, mixed> $start_args, string $run_id):`
	 * `list<array<array-key, mixed>>` before persistence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 *
	 * @return  iterable<array<array-key, mixed>>
	 */
	public function generate_queue( array $start_args ): iterable;

	/**
	 * Processes one queued chunk.
	 *
	 * A normal return marks the chunk successful, while throwing marks the attempt failed.
	 * Retry reschedules dispatch `a8csp_background_tasks/retrying/{name}` with the exact signature
	 * `(string $run_id, array<array-key, mixed> $start_args, int $attempt, int $delay): void`, followed
	 * by `a8csp_background_tasks/retrying` with the batch name prepended to the same payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $chunk_args Arguments for this chunk.
	 * @param   BatchContextInterface   $context    Controlled access to this chunk's run.
	 *
	 * @return  void
	 *
	 * @throws  \Throwable When the chunk attempt fails. Throwables implementing
	 *                     NonRetryableExceptionInterface bypass remaining retry attempts.
	 */
	public function process_chunk( array $chunk_args, BatchContextInterface $context ): void;

	/**
	 * Handles a run after every chunk succeeds.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 *
	 * @throws  \Throwable When success handling fails; the engine logs the throwable and the run
	 *                     still completes — every chunk has already succeeded.
	 *
	 * @return  void
	 */
	public function on_success( string $run_id, array $start_args ): void;

	/**
	 * Handles a failed run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 * @param   EngineError             $error      Persisted failure detail.
	 *
	 * @return  void
	 */
	public function on_failure( string $run_id, array $start_args, EngineError $error ): void;

	/**
	 * Returns the retry policy for failed chunks.
	 *
	 * The engine applies `a8csp_background_tasks/retry_policy/{name}` with the exact signature
	 * `(RetryPolicy $policy): RetryPolicy`; a foreign return leaves this contract policy in effect.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RetryPolicy
	 */
	public function get_retry_policy(): RetryPolicy;

	// endregion
}

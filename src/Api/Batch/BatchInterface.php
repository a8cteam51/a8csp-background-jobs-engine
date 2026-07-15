<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\NonRetryableExceptionInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Contract for background work split into independently processed chunks.
 *
 * Queue generation defines the initial chunks, and processing handles one chunk. The engine invokes
 * `on_success()` after every chunk succeeds or `on_failure()` after the run fails. Terminal callbacks
 * are at-least-once across crash recovery, replayed durably under Action Scheduler and best-effort
 * under the WP-Cron fallback, because a process can stop after the callback returns but
 * before its completion marker persists; implementations use the run identifier to converge replays.
 * A throwing `on_failure()` remains pending for a later maintenance attempt.
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
	 * Returns the 1-to-64-byte owner-local batch name matching `[a-z0-9_-]+`.
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
	 * `list<array<array-key, mixed>>` before persistence. The `{batch}` suffix is the complete
	 * `{owner}:{name}` batch identity.
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
	 * A normal return marks the chunk successful, while throwing marks the attempt failed. Chunk
	 * execution is at-least-once: queue advancement persists only after this method returns, so a
	 * process that stops between the chunk's side effects and that persistence redelivers the same
	 * chunk. Implementations MUST be idempotent per chunk and carry the stable business identifiers
	 * that let a replayed chunk converge inside the chunk arguments.
	 * Retry reschedules dispatch `a8csp_background_tasks/retrying/{name}` with the exact signature
	 * `(string $run_id, array<array-key, mixed> $start_args, int $attempt, int $delay): void`, followed
	 * by `a8csp_background_tasks/retrying` with the complete batch identity prepended to the same
	 * payload. The `{name}` suffix is the complete `{owner}:{name}` batch identity.
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
	 * @param   RunFailure              $failure    Persisted terminal-failure value.
	 *
	 * @return  void
	 */
	public function on_failure( string $run_id, array $start_args, RunFailure $failure ): void;

	/**
	 * Returns the retry policy for failed chunks.
	 *
	 * The engine applies `a8csp_background_tasks/retry_policy/{name}` with the exact signature
	 * `(RetryPolicy $policy): RetryPolicy`; a foreign return leaves this contract policy in effect.
	 * The `{name}` suffix is the complete `{owner}:{name}` batch identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RetryPolicy
	 */
	public function get_retry_policy(): RetryPolicy;

	// endregion
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\JobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\NonRetryableExceptionInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Contract for background work split into independently processed chunks.
 *
 * Queue generation defines the initial chunks, and processing handles one chunk. The engine invokes
 * `on_completed()` after every chunk succeeds or `on_failed()` after the run fails. Terminal callbacks
 * are at-least-once across crash recovery, replayed durably under Action Scheduler and best-effort
 * under the WP-Cron fallback, because a process can stop after the callback returns but
 * before its completion marker persists; implementations use the run identifier to converge replays.
 * A throwing `on_failed()` remains pending for a later maintenance attempt.
 *
 * A cancelled or superseded run ends without either callback; those outcomes surface through engine
 * hooks.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface ChunkedJobInterface extends JobInterface {
	// region METHODS

	/**
	 * Returns the 1-to-64-byte owner-local chunked job name matching `[a-z0-9_-]+`.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	public function get_name(): string;

	/**
	 * Returns the declared ceiling in seconds for one queue generation or chunk invocation.
	 *
	 * The ceiling applies independently to one `generate_queue()` or `process_chunk()` call, not to
	 * the whole chunked job run. The engine credits run liveness for this window immediately before either
	 * callback; exceeding it makes the still-executing run reclaimable as crashed after its lock
	 * staleness window elapses.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int
	 */
	public function max_callback_runtime(): int;

	/**
	 * Generates one argument array for each initial chunk.
	 *
	 * The engine materializes the iterable, then applies
	 * `a8csp_jobs_engine/queue/{identity}` with the exact signature
	 * `(list<array<array-key, mixed>> $queue, array<array-key, mixed> $start_args, string $run_id):`
	 * `list<array<array-key, mixed>>` before persistence. The `{identity}` suffix is the complete
	 * `{owner}:{name}` chunked job identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 *
	 * @throws  \Throwable When queue generation fails; the engine terminalizes the run as a
	 *                     queue generation failure.
	 *
	 * @return  iterable<array<array-key, mixed>>
	 */
	public function generate_queue( array $start_args ): iterable;

	/**
	 * Processes one queued chunk.
	 *
	 * A normal return marks the chunk successful, while throwing marks the attempt failed; process
	 * death does not automatically redeliver an executing chunk. A process death anywhere between
	 * durable admission and the queue-advancement CAS after this method returns terminally fails the
	 * run as a `CrashReclaim` failure, with the in-flight chunk preserved in the failure record;
	 * `retry_failed()` starts a fresh run from the original arguments. Automatic redelivery covers
	 * only non-executing states (pending, scheduled retry, and continue), which maintenance redelivers.
	 *
	 * After persisting a retry disposition, the engine dispatches
	 * `a8csp_jobs_engine/retry_scheduled/{identity}` with the exact signature `(string $run_id,
	 * array<array-key, mixed> $start_args, int $attempt, int $delay): void`, followed by
	 * `a8csp_jobs_engine/retry_scheduled` with the complete chunked job identity prepended to the same
	 * payload. The `{identity}` suffix is the complete `{owner}:{name}` chunked job identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $chunk_args Arguments for this chunk.
	 * @param   ChunkContextInterface   $context    Controlled access to this chunk's run.
	 *
	 * @throws  \Throwable When the chunk attempt fails. Throwables implementing
	 *                     NonRetryableExceptionInterface bypass remaining retry attempts.
	 *
	 * @return  void
	 */
	public function process_chunk( array $chunk_args, ChunkContextInterface $context ): void;

	/**
	 * Handles a run after every chunk succeeds.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 *
	 * @throws  \Throwable When `on_completed()` handling fails; the engine logs the throwable and the run
	 *                     still completes — every chunk has already succeeded.
	 *
	 * @return  void
	 */
	public function on_completed( string $run_id, array $start_args ): void;

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
	 * @throws  \Throwable When `on_failed()` handling fails; the callback effect remains pending for
	 *                     at-least-once replay by terminal maintenance.
	 *
	 * @return  void
	 */
	public function on_failed( string $run_id, array $start_args, RunFailure $failure ): void;

	/**
	 * Returns the retry policy for failed chunks.
	 *
	 * The engine applies `a8csp_jobs_engine/retry_policy/{identity}` with the exact signature
	 * `(RetryPolicy $policy): RetryPolicy`; a foreign return leaves this contract policy in effect.
	 * The `{identity}` suffix is the complete `{owner}:{name}` chunked job identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RetryPolicy
	 */
	public function get_retry_policy(): RetryPolicy;

	// endregion
}

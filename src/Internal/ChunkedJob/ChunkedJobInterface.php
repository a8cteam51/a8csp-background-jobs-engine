<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Internal\ChunkedJob;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\JobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\ChunkContext;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\NonRetryableException;

\defined( 'ABSPATH' ) || exit;

/**
 * Contract for background work split into independently processed chunks.
 *
 * Queue generation defines the initial chunks, and processing handles one chunk.
 * The `failed` lifecycle event dispatches only `a8csp_jobs_engine/failed` with the exact signature
 * `(RunFailure $failure): void`; the failure value carries the owner-qualified identity and run
 * identifier.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface ChunkedJobInterface extends JobInterface {
	// region METHODS

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
	 * @param   RunContext              $context    Controlled access to this run.
	 *
	 * @throws  \Throwable When queue generation fails. The engine applies the retry policy and
	 *                     re-enters queue generation while attempts remain; throwables extending
	 *                     NonRetryableException bypass remaining retry attempts.
	 *
	 * @return  iterable<array<array-key, mixed>>
	 */
	public function generate_queue( array $start_args, RunContext $context ): iterable;

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
	 * @param   ChunkContext            $context    Controlled access to this chunk's run.
	 *
	 * @throws  \Throwable When the chunk attempt fails. Throwables extending
	 *                     NonRetryableException bypass remaining retry attempts.
	 *
	 * @return  void
	 */
	public function process_chunk( array $chunk_args, ChunkContext $context ): void;

	// endregion
}

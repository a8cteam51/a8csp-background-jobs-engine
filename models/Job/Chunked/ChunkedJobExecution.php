<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;

\defined( 'ABSPATH' ) || exit;

/**
 * Executes background work split into independently processed chunks.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface ChunkedJobExecution {
	// region METHODS

	/**
	 * Generates one argument array for each initial chunk.
	 *
	 * The engine materializes the iterable before persisting and processing the queue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   RunContext              $context    Controlled access to this run.
	 *
	 * @throws  \Throwable When queue generation fails. {@see NonRetryableException} bypasses any
	 *                     remaining automatic attempts.
	 *
	 * @return  iterable<array<array-key, mixed>>
	 */
	public function generate_queue( array $start_args, RunContext $context ): iterable;

	/**
	 * Processes one queued chunk.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $chunk_args Arguments for this chunk.
	 * @param   ChunkContext            $context    Controlled access to this chunk's run.
	 *
	 * @throws  \Throwable When chunk processing fails. {@see NonRetryableException} bypasses any
	 *                     remaining automatic attempts.
	 *
	 * @return  void
	 */
	public function process_chunk( array $chunk_args, ChunkContext $context ): void;

	// endregion
}

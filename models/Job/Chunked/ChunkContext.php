<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;

\defined( 'ABSPATH' ) || exit;

/**
 * Gives a chunked job chunk controlled access to its own run.
 *
 * Queue mutations are transactional within an `AbstractChunkedJob::process_chunk()` attempt: they take
 * effect only when the attempt returns normally and are discarded when it throws, so retrying a
 * failed chunk cannot duplicate queued work.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface ChunkContext extends RunContext {
	// region METHODS

	/**
	 * Adds a chunk at the back of the run's queue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $chunk_args Arguments for the appended chunk.
	 *
	 * @throws  \InvalidArgumentException When the chunk is not portable or the chunk or resulting queue exceeds its persisted byte limit.
	 *
	 * @return  void
	 */
	public function enqueue( array $chunk_args ): void;

	/**
	 * Adds a chunk at the front of the run's queue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $chunk_args Arguments for the prepended chunk.
	 *
	 * @throws  \InvalidArgumentException When the chunk is not portable or the chunk or resulting queue exceeds its persisted byte limit.
	 *
	 * @return  void
	 */
	public function prepend( array $chunk_args ): void;

	// endregion
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob;

\defined( 'ABSPATH' ) || exit;

/**
 * Gives a chunked job chunk controlled access to its own run.
 *
 * Queue mutations are transactional within a `ChunkedJobInterface::process_chunk()` attempt: they take
 * effect only when the attempt returns normally and are discarded when it throws, so retrying a
 * failed chunk cannot duplicate queued work.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface ChunkContextInterface {
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

	/**
	 * Returns the identifier the engine assigns when the run starts.
	 *
	 * The engine passes the same value as `$run_id` to `ChunkedJobInterface::on_completed()` or
	 * `ChunkedJobInterface::on_failed()` when either callback is invoked.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	public function get_run_id(): string;

	/**
	 * Returns the arguments supplied when the run started.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>
	 */
	public function get_start_args(): array;

	// endregion
}

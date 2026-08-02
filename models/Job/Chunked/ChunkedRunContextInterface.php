<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Gives a chunked job chunk controlled access to its own run.
 *
 * Queue mutations are transactional within a `ChunkedJobExecutionInterface::process_chunk()` attempt: they take
 * effect only when the attempt returns normally and are discarded when it throws, so retrying a
 * failed chunk cannot duplicate queued work.
 *
 * The engine supplies the only implementation; consumers must not implement this interface; methods
 * may be added in minor versions.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface ChunkedRunContextInterface extends RunContextInterface {
	// region METHODS

	/**
	 * Adds a chunk at the back of the run's queue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $chunk_args Arguments for the appended chunk.
	 *
	 * @throws  \InvalidArgumentException When the chunk is not portable, or the resulting queue exceeds its persisted byte limit by more than the queue's index envelope. A queue over the limit only within that envelope is refused when the attempt commits instead. Either condition is deterministic, so the run fails terminally without consuming the remaining automatic attempts.
	 *
	 * @return  void
	 */
	public function append_chunk( array $chunk_args ): void;

	/**
	 * Adds a chunk at the front of the run's queue.
	 *
	 * Multiple calls during one chunk appear at the queue front in reverse call order: prepending A
	 * and then B places B before A.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $chunk_args Arguments for the prepended chunk.
	 *
	 * @throws  \InvalidArgumentException When the chunk is not portable, or the resulting queue exceeds its persisted byte limit by more than the queue's index envelope. A queue over the limit only within that envelope is refused when the attempt commits instead. Either condition is deterministic, so the run fails terminally without consuming the remaining automatic attempts.
	 *
	 * @return  void
	 */
	public function prepend_chunk( array $chunk_args ): void;

	// endregion
}

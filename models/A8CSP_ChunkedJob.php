<?php declare( strict_types=1 );

\defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- The consumer contract requires this global class name.
/**
 * Consumer-authored background work split into independently processed chunks.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
abstract class A8CSP_ChunkedJob {
	// phpcs:enable

	// region FIELDS AND CONSTANTS

	/**
	 * Default ceiling for one consumer callback invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int DEFAULT_MAX_CALLBACK_RUNTIME = 300;

	// endregion

	// region METHODS

	/**
	 * Returns the default ceiling for one consumer callback invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int
	 */
	public function max_callback_runtime(): int {
		return self::DEFAULT_MAX_CALLBACK_RUNTIME;
	}

	/**
	 * Returns the retry declaration; recognized integer keys, validated at registration, are
	 * max_attempts, base_delay, multiplier, and max_delay. Consumers override to supply their own.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-return array<array-key, mixed>
	 *
	 * @return  array
	 */
	public function retry(): array {
		return array();
	}

	/**
	 * Returns the stable owner-local chunked job name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	abstract public function get_name(): string;

	/**
	 * Generates the initial chunk queue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 *
	 * @return  iterable<array<array-key, mixed>>
	 */
	abstract public function generate_queue( array $start_args ): iterable;

	/**
	 * Processes one queued chunk.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $chunk_args Arguments for this chunk.
	 * @param   A8CSP_ChunkContext      $context    Controlled access to this chunked run.
	 *
	 * @return  void
	 */
	abstract public function process_chunk( array $chunk_args, A8CSP_ChunkContext $context ): void;

	/**
	 * Handles a completed run.
	 *
	 * Delivery is at-least-once: the engine replays terminal effects after a crash, so this may
	 * run more than once for a given run. Keep it idempotent, keyed on the run id.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id Run identifier.
	 * @param   array<array-key, mixed> $args   Arguments supplied when the run started.
	 *
	 * @return  void
	 */
	public function on_completed( string $run_id, array $args ): void {}

	/**
	 * Handles a failed run.
	 *
	 * Delivery is at-least-once: the engine replays terminal effects after a crash, so this may
	 * run more than once for a given run. Keep it idempotent, keyed on the run id.
	 *
	 * The $failure array carries run_id, attempts, stage (execution, queue_generation,
	 * crash_reclaim, or scheduling), code (a stable engine error code such as execution_failed),
	 * summary, and failed_chunk (array or null).
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{run_id: string, attempts: int, stage: string, code: string, summary: string, failed_chunk: array<array-key, mixed>|null} $failure
	 *
	 * @param   string                  $run_id Run identifier.
	 * @param   array<array-key, mixed> $args   Arguments supplied when the run started.
	 * @param   array                   $failure Public terminal-failure data.
	 *
	 * @return  void
	 */
	public function on_failed( string $run_id, array $args, array $failure ): void {}

	// endregion
}

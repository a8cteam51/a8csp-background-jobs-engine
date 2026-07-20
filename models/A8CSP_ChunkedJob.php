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
	 * An override that returns `<= 0` or throws is normalized to the 300-second default. Values above
	 * six hours are capped at six hours.
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
	 * Returns the invariant overlap policy; recognized values are allow, reject (default), and
	 * replace. Consumers override to supply their own.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	public function overlap_policy(): string {
		return 'reject';
	}

	/**
	 * Returns an argument-aware overlap identity, or null to use the canonical argument hash.
	 *
	 * A non-null key must contain 1 through 64 bytes. An out-of-range key surfaces as a
	 * PayloadRejected WP_Error when the chunked job is started.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 *
	 * @return  string|null
	 */
	public function overlap_key( array $start_args ): ?string {
		unset( $start_args );

		return null;
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
	 * @param   string                  $run_id     Run identifier.
	 *
	 * @return  iterable<array<array-key, mixed>>
	 */
	abstract public function generate_queue( array $start_args, string $run_id ): iterable;

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
	 * The predecessor is captured when this run completes and remains stable across at-least-once
	 * replays rather than following a live lookup.
	 *
	 * This callback fires only while the chunked job stays registered in the request delivering
	 * terminal effects.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id                    Run identifier.
	 * @param   array<array-key, mixed> $args                      Arguments supplied when the run started.
	 * @param   string|null             $previous_completed_run_id Previous completed run identifier for this job, or null when this is the first completed run.
	 *
	 * @return  void
	 */
	public function on_completed( string $run_id, array $args, ?string $previous_completed_run_id ): void {}

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
	 * This callback fires only while the chunked job stays registered in the request delivering
	 * terminal effects.
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

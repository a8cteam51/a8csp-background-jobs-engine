<?php declare( strict_types=1 );

\defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- The consumer contract requires this global class name.
/**
 * Consumer-authored unit of background work.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
abstract class A8CSP_Job {
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
	 * PayloadRejected WP_Error when the job is enqueued.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Invocation arguments.
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
	 * Returns the stable owner-local job name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	abstract public function get_name(): string;

	/**
	 * Handles one invocation of the job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $args   Invocation arguments.
	 * @param   string                  $run_id Run identifier.
	 *
	 * @return  void
	 */
	abstract public function handle( array $args, string $run_id ): void;

	/**
	 * Handles a completed run.
	 *
	 * Delivery is at-least-once: the engine replays terminal effects after a crash, so this may
	 * run more than once for a given run. Keep it idempotent, keyed on the run id.
	 *
	 * The predecessor is captured when this run completes and remains stable across at-least-once
	 * replays rather than following a live lookup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id                    Run identifier.
	 * @param   array<array-key, mixed> $args                      Invocation arguments.
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
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{run_id: string, attempts: int, stage: string, code: string, summary: string, failed_chunk: array<array-key, mixed>|null} $failure
	 *
	 * @param   string                  $run_id Run identifier.
	 * @param   array<array-key, mixed> $args   Invocation arguments.
	 * @param   array                   $failure Public terminal-failure data.
	 *
	 * @return  void
	 */
	public function on_failed( string $run_id, array $args, array $failure ): void {}

	// endregion
}

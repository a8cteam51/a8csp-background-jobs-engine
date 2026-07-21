<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;

\defined( 'ABSPATH' ) || exit;

/**
 * Shared contract for every registered background job.
 *
 * A stable name identifies the job, its retry policy governs failed invocations, and its
 * callback-runtime ceiling bounds one client callback invocation.
 *
 * Terminal callbacks are delivered at least once across crash recovery under Action Scheduler and
 * best-effort under the WP-Cron fallback. A process can stop after a callback returns but before its
 * completion marker persists, so implementations use the run identifier to converge replays. A
 * throwing `on_failed()` remains pending for a later terminal-maintenance attempt. `on_completed()`
 * is best-effort: a thrown callback is logged, and the run still completes. Cancelled and superseded
 * runs end without either callback; those outcomes surface through engine hooks.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface JobInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * Default ceiling for one client callback invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int DEFAULT_MAX_CALLBACK_RUNTIME = 300;

	/**
	 * Longest opaque overlap key accepted from a registered Job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int MAX_OVERLAP_KEY_BYTES = 64;

	// endregion

	// region METHODS

	/**
	 * Returns the 1-to-64-byte owner-local name matching `[a-z0-9_-]+`.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	public function get_name(): string;

	/**
	 * Returns the declared ceiling in seconds for one client callback invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int
	 */
	public function max_callback_runtime(): int;

	/**
	 * Returns the invariant policy applied when a matching run holds the overlap lock.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  OverlapPolicy
	 */
	public function overlap_policy(): OverlapPolicy;

	/**
	 * Returns an opaque argument-aware overlap identity, or null to use the canonical argument hash.
	 *
	 * A non-null key must contain 1 through 64 bytes. The engine hashes the opaque value before it
	 * enters the overlap-lock namespace.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 *
	 * @return  string|null
	 */
	public function overlap_key( array $start_args ): ?string;

	/**
	 * Returns the retry policy for failed invocations.
	 *
	 * The engine applies `a8csp_jobs_engine/retry_policy/{identity}` with the exact signature
	 * `(RetryPolicy $policy): RetryPolicy`; a foreign return leaves this contract policy in effect.
	 * The `{identity}` suffix is the complete `{owner}:{name}` job or chunked job identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RetryPolicy
	 */
	public function get_retry_policy(): RetryPolicy;

	/**
	 * Handles a completed run.
	 *
	 * The predecessor is the most recent prior completion known in retained terminal history when
	 * this run completes; its captured value remains identical across at-least-once replays rather
	 * than following a live lookup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id                    Run identifier.
	 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
	 * @param   string|null             $previous_completed_run_id Previous completed run identifier for this identity, or null.
	 *
	 * @throws  \Throwable When `on_completed()` handling fails; the engine logs the throwable and the run still completes.
	 *
	 * @return  void
	 */
	public function on_completed( string $run_id, array $start_args, ?string $previous_completed_run_id ): void;

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
	 * @throws  \Throwable When `on_failed()` handling fails; the callback effect remains pending for at-least-once replay by terminal maintenance.
	 *
	 * @return  void
	 */
	public function on_failed( string $run_id, array $start_args, RunFailure $failure ): void;

	// endregion
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Api\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\NonRetryableExceptionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\JobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\RetryPolicy;

\defined( 'ABSPATH' ) || exit;

/**
 * Contract for an instance-based unit of background work.
 *
 * A stable name identifies the job, a normal return from the handler signals success, and a
 * thrown exception signals failure.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface OneOffJobInterface extends JobInterface {
	// region METHODS

	/**
	 * Returns the 1-to-64-byte owner-local job name matching `[a-z0-9_-]+`.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	public function get_name(): string;

	/**
	 * Returns the declared ceiling in seconds for one handler invocation.
	 *
	 * The engine credits run liveness for this window immediately before invoking `handle()`.
	 * Exceeding the ceiling makes the still-executing run reclaimable as crashed after its lock
	 * staleness window elapses.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int
	 */
	public function max_callback_runtime(): int;

	/**
	 * Handles one invocation of the job.
	 *
	 * Terminal failures dispatch `a8csp_jobs_engine/failed/{identity}` with the run identifier,
	 * start arguments, and run failure, followed by `a8csp_jobs_engine/failed` with the job
	 * identity prepended to the same payload. The `{identity}` suffix is the complete `{owner}:{name}`
	 * job identity.
	 *
	 * After persisting a retry disposition, the engine dispatches
	 * `a8csp_jobs_engine/retry_scheduled/{identity}` with the exact signature `(string $run_id,
	 * array<array-key, mixed> $start_args, int $attempt, int $delay): void`, followed by
	 * `a8csp_jobs_engine/retry_scheduled` with the complete job identity prepended to the same
	 * payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $args Invocation arguments.
	 *
	 * @throws  \Throwable When job handling fails. Throwables implementing
	 *                     NonRetryableExceptionInterface bypass remaining retry attempts.
	 *
	 * @return  void
	 */
	public function handle( array $args ): void;

	/**
	 * Returns the retry policy for failed invocations.
	 *
	 * The engine applies `a8csp_jobs_engine/retry_policy/{identity}` with the exact signature
	 * `(RetryPolicy $policy): RetryPolicy`; a foreign return leaves this contract policy in effect.
	 * The `{identity}` suffix is the complete `{owner}:{name}` job identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RetryPolicy
	 */
	public function get_retry_policy(): RetryPolicy;

	// endregion
}

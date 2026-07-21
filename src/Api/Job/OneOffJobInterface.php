<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Api\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\JobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContext;

\defined( 'ABSPATH' ) || exit;

/**
 * Contract for an instance-based unit of background work.
 *
 * A stable name identifies the job, a normal return from the handler signals success, and a
 * thrown exception signals failure.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface OneOffJobInterface extends JobInterface {
	// region METHODS

	/**
	 * Handles one invocation of the job.
	 *
	 * A run can dispatch lifecycle hooks for `started`, `completed`, `failed`, `cancelled`,
	 * `superseded`, and `retry_scheduled`. Each event dispatches
	 * `a8csp_jobs_engine/{event}/{identity}` first, followed by `a8csp_jobs_engine/{event}` with the
	 * complete `{owner}:{name}` identity prepended to the payload.
	 *
	 * Admission dispatches `started`; successful terminalization dispatches `completed`; terminal
	 * failure dispatches `failed`; and cancellation or supersession dispatches `cancelled` or
	 * `superseded`, respectively. A failed attempt persisted for another automatic attempt dispatches
	 * `retry_scheduled` before the retry action is scheduled.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $args    Invocation arguments.
	 * @param   RunContext              $context Controlled access to this run.
	 *
	 * @throws  \Throwable When job handling fails. Throwables extending
	 *                     NonRetryableException bypass remaining retry attempts.
	 *
	 * @return  void
	 */
	public function handle( array $args, RunContext $context ): void;

	// endregion
}

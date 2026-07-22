<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job;

\defined( 'ABSPATH' ) || exit;

/**
 * Default base for an instance-based unit of background work.
 *
 * A stable name identifies the job, a normal return from the handler signals success, and a
 * thrown exception signals failure.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
abstract class AbstractJob implements JobInterface {
	use JobDefaults;

	// region METHODS

	/**
	 * Handles one invocation of the job.
	 *
	 * A run can dispatch lifecycle hooks for `started`, `completed`, `failed`, `cancelled`,
	 * `superseded`, and `retry_scheduled`. Every event except `failed` dispatches
	 * `a8csp_jobs_engine/{event}/{identity}` first, followed by `a8csp_jobs_engine/{event}` with the
	 * complete `{owner}:{name}` identity prepended to the payload. The `failed` event dispatches only
	 * `a8csp_jobs_engine/failed` with the exact signature `(RunFailure $failure): void`; the failure
	 * value carries the owner-qualified identity and run identifier.
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
	abstract public function handle( array $args, RunContext $context ): void;

	// endregion
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Tasks;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\WorkInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Retry\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Tasks\Exceptions\NonRetryableExceptionInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Contract for an instance-based unit of background work.
 *
 * A stable name identifies the task, a normal return from the handler signals success, and a
 * thrown exception signals failure.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface TaskInterface extends WorkInterface {
	// region METHODS

	/**
	 * Returns the non-empty stable task identity matching `[a-z0-9_-]+`.
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
	public function max_runtime(): int;

	/**
	 * Handles one invocation of the task.
	 *
	 * Terminal failures dispatch `a8csp_background_tasks/failed/{name}` with the run identifier,
	 * start arguments, and engine error, followed by `a8csp_background_tasks/failed` with the task
	 * name prepended to the same payload.
	 *
	 * Retry reschedules dispatch `a8csp_background_tasks/retrying/{name}` with the exact signature
	 * `(string $run_id, array<array-key, mixed> $start_args, int $attempt, int $delay): void`, followed
	 * by `a8csp_background_tasks/retrying` with the task name prepended to the same payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $args Invocation arguments.
	 *
	 * @return  void
	 *
	 * @throws  \Throwable When task handling fails. Throwables implementing
	 *                     NonRetryableExceptionInterface bypass remaining retry attempts.
	 */
	public function handle( array $args ): void;

	/**
	 * Returns the retry policy for failed invocations.
	 *
	 * The engine applies `a8csp_background_tasks/retry_policy/{name}` with the exact signature
	 * `(RetryPolicy $policy): RetryPolicy`; a foreign return leaves this contract policy in effect.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RetryPolicy
	 */
	public function get_retry_policy(): RetryPolicy;

	// endregion
}

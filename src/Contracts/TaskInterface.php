<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Contracts;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\RetryPolicy;

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
interface TaskInterface {
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
	 * Handles one invocation of the task.
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
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RetryPolicy
	 */
	public function get_retry_policy(): RetryPolicy;

	// endregion
}

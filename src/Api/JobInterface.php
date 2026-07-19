<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Api;

\defined( 'ABSPATH' ) || exit;

/**
 * Shared contract for every registered background job.
 *
 * A stable name identifies the job, its retry policy governs failed invocations, and its
 * callback-runtime ceiling bounds one client callback invocation.
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

	// endregion
}

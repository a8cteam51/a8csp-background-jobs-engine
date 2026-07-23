<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job;

\defined( 'ABSPATH' ) || exit;

/**
 * Executes one invocation of a standard background job.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface JobExecution {
	// region METHODS

	/**
	 * Handles one invocation of the job.
	 *
	 * A normal return signals success. Throwing signals failure, and
	 * {@see NonRetryableException} bypasses any remaining automatic attempts.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $args    Invocation arguments.
	 * @param   RunContext              $context Controlled access to this run.
	 *
	 * @throws  \Throwable When job handling fails.
	 *
	 * @return  void
	 */
	public function handle( array $args, RunContext $context ): void;

	// endregion
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job;

\defined( 'ABSPATH' ) || exit;

/**
 * Executes one invocation of a standard background job.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface JobExecutionInterface {
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
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   RunContextInterface     $context    Controlled access to this run.
	 *
	 * @throws  \Throwable When job handling fails.
	 *
	 * @return  void
	 */
	public function handle( array $start_args, RunContextInterface $context ): void;

	// endregion
}

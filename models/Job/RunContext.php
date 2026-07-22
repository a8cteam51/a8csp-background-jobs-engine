<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job;

\defined( 'ABSPATH' ) || exit;

/**
 * Gives one work invocation controlled access to its own run.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface RunContext {
	// region METHODS

	/**
	 * Returns the identifier the engine assigns when the run starts.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	public function get_run_id(): string;

	/**
	 * Returns the arguments supplied when the run started.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>
	 */
	public function get_start_args(): array;

	// endregion
}

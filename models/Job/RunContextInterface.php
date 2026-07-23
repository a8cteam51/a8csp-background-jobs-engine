<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;

\defined( 'ABSPATH' ) || exit;

/**
 * Gives one work invocation controlled access to its own run.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface RunContextInterface {
	// region METHODS

	/**
	 * Returns the identifier the engine assigns when the run starts.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RunId
	 */
	public function get_run_id(): RunId;

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

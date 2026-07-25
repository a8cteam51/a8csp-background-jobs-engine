<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound public handle exposing the jobs, schedules, and runs capability managers.
 *
 * Handle and portal construction is infallible; owner validation and engine resolution remain
 * lazy until a manager verb is invoked, where every expected failure surfaces as a `WP_Error`.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Engine {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Client plugin owner.
	 */
	public function __construct(
		private string $owner,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns the bound owner's job capabilities.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Jobs
	 */
	public function jobs(): Jobs {
		return new Jobs( $this->owner );
	}

	/**
	 * Returns the bound owner's schedule capabilities.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Schedules
	 */
	public function schedules(): Schedules {
		return new Schedules( $this->owner );
	}

	/**
	 * Returns the bound owner's run capabilities.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Runs
	 */
	public function runs(): Runs {
		return new Runs( $this->owner );
	}

	// endregion
}

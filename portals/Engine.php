<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Scope-bound public handle exposing the jobs, schedules, and runs capability managers.
 *
 * Handle and portal construction is infallible; scope validation and engine resolution remain
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
	 * @param   string $scope Client plugin scope.
	 */
	public function __construct(
		private string $scope,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns the bound scope's job capabilities.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Jobs
	 */
	public function jobs(): Jobs {
		return new Jobs( $this->scope );
	}

	/**
	 * Returns the bound scope's schedule capabilities.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Schedules
	 */
	public function schedules(): Schedules {
		return new Schedules( $this->scope );
	}

	/**
	 * Returns the bound scope's run capabilities.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Runs
	 */
	public function runs(): Runs {
		return new Runs( $this->scope );
	}

	// endregion
}

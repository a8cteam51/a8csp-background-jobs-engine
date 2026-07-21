<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Internal\Schedule;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\AbstractResult;

\defined( 'ABSPATH' ) || exit;

/**
 * Engine operations required by the owner-bound schedule facade.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface SchedulesEngineInterface {
	// region METHODS

	/**
	 * Synchronizes the bound owner's complete declared schedule set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<string, array{schedule: Schedule, job: string}> $declarations
	 *
	 * @param   array $declarations Complete schedule declaration keyed by owner-qualified identity.
	 *
	 * @return  AbstractResult<true, ApiError>
	 */
	public function sync( array $declarations ): AbstractResult;

	/**
	 * Immediately dispatches one declared schedule target without changing its recurrence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified schedule identity.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	public function dispatch_now( string $identity ): AbstractResult;

	// endregion
}

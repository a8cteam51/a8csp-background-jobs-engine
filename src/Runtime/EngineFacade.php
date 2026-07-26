<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;

\defined( 'ABSPATH' ) || exit;

/**
 * Internal facade for schedule operations and retained-run retry and cancellation.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class EngineFacade {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ScheduleOperations $schedules  Schedule API.
	 * @param   Dispatcher         $dispatcher Background-work admission coordinator.
	 */
	public function __construct(
		public ScheduleOperations $schedules,
		private Dispatcher $dispatcher,
	) {}

	// endregion

	// region METHODS

	/**
	 * Starts a fresh run from one retained failed run's original arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified job or chunked job identity.
	 * @param   string   $run_id   Retained failed-run identifier.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed( Identity $identity, string $run_id ): AbstractResult {
		return $this->dispatcher->retry_failed( $identity, $run_id );
	}

	/**
	 * Cancels one retained run that is not executing or pending chunked job cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified job or chunked job identity.
	 * @param   string   $run_id   Retained run identifier.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	public function cancel( Identity $identity, string $run_id ): AbstractResult {
		return $this->dispatcher->cancel( $identity, $run_id );
	}

	// endregion
}

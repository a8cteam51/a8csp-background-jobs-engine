<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\Schedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;

\defined( 'ABSPATH' ) || exit;

/**
 * Client facade for task, schedule, and batch background work.
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
	 * @param   Schedules  $schedules  Schedule API.
	 * @param   Dispatcher $dispatcher Background-work admission coordinator.
	 * @param   Inspection $inspection Read-only run inspection.
	 */
	public function __construct(
		public Schedules $schedules,
		private Dispatcher $dispatcher,
		private Inspection $inspection,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns the last completed run ID retained for one background-work identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified task or batch identity.
	 *
	 * @return  AbstractResult<string|null, EngineError>
	 */
	#[\NoDiscard( 'a last-completed-run result must be handled, not dropped' )]
	public function last_completed_run_id( string $identity ): AbstractResult {
		return $this->inspection->last_completed_run_id( $identity );
	}

	/**
	 * Starts a fresh run from one retained failed run's original arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified task or batch identity.
	 * @param   string $run_id   Retained failed-run identifier.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed( string $identity, string $run_id ): AbstractResult {
		return $this->dispatcher->retry_failed( $identity, $run_id );
	}

	/**
	 * Cancels one retained run that is not executing or pending batch cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified task or batch identity.
	 * @param   string $run_id   Retained run identifier.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	public function cancel( string $identity, string $run_id ): AbstractResult {
		return $this->dispatcher->cancel( $identity, $run_id );
	}

	// endregion
}

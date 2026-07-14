<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Tasks;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Errors\SchedulingError;

\defined( 'ABSPATH' ) || exit;

/**
 * Consumer facade for task, schedule, and batch background work.
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
	 * @param   Tasks      $tasks      Task API.
	 * @param   Schedules  $schedules  Schedule API.
	 * @param   Batches    $batches    Batch API.
	 * @param   Dispatcher $dispatcher Background-work admission coordinator.
	 */
	public function __construct( private Tasks $tasks, private Schedules $schedules, private Batches $batches, private Dispatcher $dispatcher ) {}

	// endregion

	// region METHODS

	/**
	 * Starts a fresh run from one retained failed run's original arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Stable task or batch name.
	 * @param   string $run_id Retained failed-run identifier.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed( string $name, string $run_id ): AbstractResult {
		return $this->dispatcher->retry_failed( $name, $run_id );
	}

	/**
	 * Cancels one retained run that is not executing or pending batch cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Stable task or batch name.
	 * @param   string $run_id Retained run identifier.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	public function cancel( string $name, string $run_id ): AbstractResult {
		return $this->dispatcher->cancel( $name, $run_id );
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the task API.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Tasks
	 */
	public function tasks(): Tasks {
		return $this->tasks;
	}

	/**
	 * Returns the schedule API.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Schedules
	 */
	public function schedules(): Schedules {
		return $this->schedules;
	}

	/**
	 * Returns the batch API.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Batches
	 */
	public function batches(): Batches {
		return $this->batches;
	}

	// endregion
}

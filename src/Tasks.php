<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine;

use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Orchestrator;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;

\defined( 'ABSPATH' ) || exit;

/**
 * Consumer API for registering and dispatching tasks.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Tasks {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   TaskRegistry $registry     Registered task instances.
	 * @param   Orchestrator $orchestrator Background-work coordinator.
	 */
	public function __construct(
		private TaskRegistry $registry,
		private Orchestrator $orchestrator,
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers one task under its stable name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   TaskInterface $task Task to register.
	 *
	 * @throws  \InvalidArgumentException When the task name is outside the stable-name grammar.
	 * @throws  \LogicException           When the task name is already registered.
	 *
	 * @return  void
	 */
	public function register( TaskInterface $task ): void {
		$this->registry->register( $task );
	}

	/**
	 * Creates and schedules one run for a registered task.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name     Stable task name.
	 * @param   array<array-key, mixed> $args     Task arguments.
	 * @param   int                     $delay    Scheduling delay in seconds.
	 * @param   bool                    $unique   Whether the backend retains an identical async action.
	 * @param   int                     $priority Advisory priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
	public function enqueue(
		string $name,
		array $args = array(),
		int $delay = 0,
		bool $unique = false,
		int $priority = 10
	): AbstractResult {
		return $this->orchestrator->enqueue( $name, $args, $delay, $unique, $priority );
	}

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
		return $this->orchestrator->retry_failed( $name, $run_id );
	}

	// endregion
}

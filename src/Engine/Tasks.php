<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\MaintenanceTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingError;

\defined( 'ABSPATH' ) || exit;

/**
 * Consumer API for registering and dispatching tasks.
 *
 * @internal
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
	 * @param   TaskRegistry $registry   Registered task instances.
	 * @param   Dispatcher   $dispatcher Background-work admission coordinator.
	 */
	public function __construct(
		private TaskRegistry $registry,
		private Dispatcher $dispatcher,
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
	public function enqueue( string $name, array $args = array(), int $delay = 0, bool $unique = false, int $priority = 10 ): AbstractResult {
		if ( MaintenanceTask::NAME === $name ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Background-work name "%s" is engine-reserved; register and dispatch consumer work under its own name.',
						MaintenanceTask::NAME
					)
				)
			);
		}

		return $this->dispatcher->enqueue( $name, $args, $delay, $unique, $priority );
	}

	// endregion
}

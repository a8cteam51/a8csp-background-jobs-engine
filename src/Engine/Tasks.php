<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
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
	 * Registers one task under its stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string        $identity Complete owner-qualified identity.
	 * @param   TaskInterface $task     Task to register.
	 *
	 * @throws  \InvalidArgumentException When the identity and task name disagree, or a batch owns the identity.
	 * @throws  \LogicException           When the task identity is already registered.
	 *
	 * @return  void
	 */
	public function register( string $identity, TaskInterface $task ): void {
		$this->registry->register( $identity, $task );
	}

	/**
	 * Creates and schedules one run for a registered task.
	 *
	 * A null deduplication key uses the task arguments as the single-flight identity. A non-null key
	 * replaces that identity for the incumbent run's lifetime and is reusable after terminal cleanup;
	 * it is an admission-level mechanism, not a durable ledger.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name      Complete owner-qualified task identity.
	 * @param   array<array-key, mixed> $args      Task arguments.
	 * @param   int                     $delay     Scheduling delay in seconds.
	 * @param   string|null             $dedup_key Consumer deduplication key whose hash replaces the argument hash.
	 * @param   int                     $priority  Advisory priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
	public function enqueue( string $name, array $args = array(), int $delay = 0, ?string $dedup_key = null, int $priority = 10 ): AbstractResult {
		return $this->dispatcher->enqueue( $name, $args, $delay, $dedup_key, $priority );
	}

	// endregion
}

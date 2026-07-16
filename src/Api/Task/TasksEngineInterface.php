<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Task;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;

\defined( 'ABSPATH' ) || exit;

/**
 * Engine operations required by the owner-bound task facade.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface TasksEngineInterface {
	// region METHODS

	/**
	 * Registers one task under its complete owner-qualified identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string        $identity Complete owner-qualified task identity.
	 * @param   TaskInterface $task     Task to register.
	 *
	 * @throws  \InvalidArgumentException When the identity and task name disagree, or a batch owns the identity.
	 * @throws  \LogicException           When the task identity is already registered.
	 *
	 * @return  void
	 */
	public function register_task( string $identity, TaskInterface $task ): void;

	/**
	 * Creates and schedules one run for a registered task.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $identity  Complete owner-qualified task identity.
	 * @param   array<array-key, mixed> $args      Task arguments.
	 * @param   int                     $delay     Scheduling delay in seconds.
	 * @param   string|null             $dedup_key Consumer deduplication key whose hash replaces the argument hash.
	 * @param   int                     $priority  Advisory priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	public function enqueue( string $identity, array $args, int $delay, ?string $dedup_key, int $priority ): AbstractResult;

	// endregion
}

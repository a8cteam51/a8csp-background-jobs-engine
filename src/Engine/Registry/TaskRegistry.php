<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\WorkIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Retains registered task instances by their stable identity.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class TaskRegistry {
	// region FIELDS AND CONSTANTS

	/**
	 * Registered tasks keyed by stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, TaskInterface>
	 */
	private array $tasks = array();

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   WorkRegistry $work Shared task-and-batch identity registry.
	 */
	public function __construct(
		private readonly WorkRegistry $work
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers one task instance under a unique identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string        $identity Complete owner-qualified identity.
	 * @param   TaskInterface $task     Task to register.
	 *
	 * @throws  \InvalidArgumentException When the identity and task name disagree, or another kind owns the identity.
	 * @throws  \LogicException           When the task identity is already registered.
	 *
	 * @return  void
	 */
	public function register( string $identity, TaskInterface $task ): void {
		$name = $task->get_name();
		WorkIdentity::validate_name( $name );
		$parts = WorkIdentity::parts( $identity );
		if ( null === $parts || $name !== $parts[1] ) {
			throw new \InvalidArgumentException( 'Task identity must be canonical and end with the task\'s declared local name.' );
		}

		$this->work->claim( $identity, 'task' );
		$this->tasks[ $identity ] = $task;
	}

	/**
	 * Returns the task registered under a stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Complete owner-qualified task identity.
	 *
	 * @return  TaskInterface|null
	 */
	public function get( string $name ): ?TaskInterface {
		return $this->tasks[ $name ] ?? null;
	}

	/**
	 * Returns the recorded work kind for one complete identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Complete owner-qualified work identity.
	 *
	 * @return  'batch'|'task'|null
	 */
	public function kind( string $name ): ?string {
		return $this->work->kind( $name );
	}

	// endregion
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Registry;

use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\TaskInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Retains registered task instances by their stable identity.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class TaskRegistry {
	// region FIELDS AND CONSTANTS

	/**
	 * Registered tasks keyed by stable name.
	 *
	 * @var array<string, TaskInterface>
	 */
	private array $tasks = array();

	// endregion

	// region METHODS

	/**
	 * Registers one uniquely named task instance.
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
		$name = $task->get_name();
		if ( 1 !== \preg_match( '/\A[a-z0-9_-]+\z/', $name ) ) {
			throw new \InvalidArgumentException(
				'Task name is invalid; return a non-empty name containing only lowercase letters, digits, underscores, and hyphens.'
			);
		}

		if ( isset( $this->tasks[ $name ] ) ) {
			throw new \LogicException(
				'Task name is already registered; register each task name exactly once.'
			);
		}

		$this->tasks[ $name ] = $task;
	}

	/**
	 * Returns the task registered under a stable name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Stable task name.
	 *
	 * @return  TaskInterface|null
	 */
	public function get( string $name ): ?TaskInterface {
		return $this->tasks[ $name ] ?? null;
	}

	// endregion
}

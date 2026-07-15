<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Task;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ErrorInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound facade for registering and dispatching tasks.
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
	 * @param   \Closure(string): string                                                                          $identity Owner-qualified identity composer.
	 * @param   \Closure(string, TaskInterface): void                                                             $register Task registration delegate.
	 * @param   \Closure(string, array<array-key, mixed>, int, bool, int): AbstractResult<string, ErrorInterface> $enqueue  Task admission delegate.
	 */
	public function __construct(
		private \Closure $identity,
		private \Closure $register,
		private \Closure $enqueue,
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers one task under the bound owner and its declared local name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   TaskInterface $task Task to register.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity is invalid or belongs to a batch.
	 * @throws  \LogicException           When the task identity is already registered.
	 *
	 * @return  void
	 */
	public function register( TaskInterface $task ): void {
		( $this->register )( $this->identity( $task->get_name() ), $task );
	}

	/**
	 * Creates and schedules one run for a registered task.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name     Owner-local task name.
	 * @param   array<array-key, mixed> $args     Task arguments.
	 * @param   int                     $delay    Scheduling delay in seconds.
	 * @param   bool                    $unique   Whether the backend retains an identical async action.
	 * @param   int                     $priority Advisory priority from 0 through 255.
	 *
	 * @throws  \InvalidArgumentException When the local name violates the canonical grammar.
	 *
	 * @return  AbstractResult<string, ErrorInterface>
	 */
	#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
	public function enqueue( string $name, array $args = array(), int $delay = 0, bool $unique = false, int $priority = 10 ): AbstractResult {
		return ( $this->enqueue )( $this->identity( $name ), $args, $delay, $unique, $priority );
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the complete identity for one owner-local name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Owner-local name.
	 *
	 * @return  string
	 */
	private function identity( string $name ): string {
		return ( $this->identity )( $name );
	}

	// endregion
}

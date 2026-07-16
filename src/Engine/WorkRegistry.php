<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\WorkIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Retains registered task and batch instances by their stable identities.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class WorkRegistry {
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

	/**
	 * Registered batches keyed by stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, BatchInterface>
	 */
	private array $batches = array();

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
	 * @throws  \InvalidArgumentException When the identity and task name disagree, or a batch owns the identity.
	 * @throws  \LogicException           When the task identity is already registered.
	 *
	 * @return  void
	 */
	public function register_task( string $identity, TaskInterface $task ): void {
		$this->guard_registration( $identity, $task->get_name(), 'task' );
		$this->tasks[ $identity ] = $task;
	}

	/**
	 * Registers one batch instance under a unique identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $identity Complete owner-qualified identity.
	 * @param   BatchInterface $batch    Batch to register.
	 *
	 * @throws  \InvalidArgumentException When the identity and batch name disagree, or a task owns the identity.
	 * @throws  \LogicException           When the batch identity is already registered.
	 *
	 * @return  void
	 */
	public function register_batch( string $identity, BatchInterface $batch ): void {
		$this->guard_registration( $identity, $batch->get_name(), 'batch' );
		$this->batches[ $identity ] = $batch;
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the task registered under a stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified task identity.
	 *
	 * @return  TaskInterface|null
	 */
	public function task( string $identity ): ?TaskInterface {
		return $this->tasks[ $identity ] ?? null;
	}

	/**
	 * Returns the batch registered under a stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified batch identity.
	 *
	 * @return  BatchInterface|null
	 */
	public function batch( string $identity ): ?BatchInterface {
		return $this->batches[ $identity ] ?? null;
	}

	/**
	 * Returns the registered kind for one complete identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified identity.
	 *
	 * @return  'batch'|'task'|null
	 */
	public function kind( string $identity ): ?string {
		if ( isset( $this->tasks[ $identity ] ) ) {
			return 'task';
		}

		return isset( $this->batches[ $identity ] ) ? 'batch' : null;
	}

	// endregion

	// region HELPERS

	/**
	 * Validates name grammar, identity agreement, and single-registration ownership.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $identity Complete owner-qualified identity.
	 * @param   string         $name     Declared local work name.
	 * @param   'batch'|'task' $kind     Incoming registration channel.
	 *
	 * @throws  \InvalidArgumentException When the identity is non-canonical or the other kind owns it.
	 * @throws  \LogicException           When the same kind already owns the identity.
	 *
	 * @return  void
	 */
	private function guard_registration( string $identity, string $name, string $kind ): void {
		WorkIdentity::validate_name( $name );
		$parts = WorkIdentity::parts( $identity );
		if ( null === $parts || $name !== $parts[1] ) {
			throw new \InvalidArgumentException( 'task' === $kind ? 'Task identity must be canonical and end with the task\'s declared local name.' : 'Batch identity must be canonical and end with the batch\'s declared local name.' );
		}

		$existing = $this->kind( $identity );
		if ( $kind === $existing ) {
			throw new \LogicException( 'task' === $kind ? 'Task name is already registered; register each task name exactly once.' : 'Batch name is already registered; register each batch name exactly once.' );
		}
		if ( null !== $existing ) {
			// Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( 'Background-work identity "%1$s" is already registered as a %2$s; it cannot also be registered as a %3$s.', $identity, $existing, $kind ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	// endregion
}

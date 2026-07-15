<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Task;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\AdmissionValidator;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound facade for registering and dispatching tasks.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Tasks {
	// region FIELDS AND CONSTANTS

	/**
	 * Longest consumer deduplication key accepted by the public command contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const MAX_DEDUP_KEY_BYTES = 64;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Closure(string): string                                                                           $identity Owner-qualified identity composer.
	 * @param   \Closure(string, TaskInterface): void                                                              $register Task registration delegate.
	 * @param   \Closure(string, array<array-key, mixed>, int, string|null, int): AbstractResult<string, ApiError> $enqueue  Task admission delegate.
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
	 * A null deduplication key uses the task arguments as the single-flight identity. A non-null opaque
	 * key of 1 through 64 bytes replaces that identity with its hash, so another enqueue with the same
	 * key is refused while the incumbent is admitted or running even when its arguments differ. The
	 * key is not a durable ledger entry and is reusable as soon as the incumbent reaches terminal
	 * cleanup. A manual failed-run retry re-admits under the argument identity and does not carry
	 * the key ({@see \A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\Runs::retry_failed()}).
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name      Owner-local task name.
	 * @param   array<array-key, mixed> $args      Task arguments.
	 * @param   int                     $delay     Scheduling delay in seconds.
	 * @param   string|null             $dedup_key Consumer deduplication key whose hash replaces the argument hash.
	 * @param   int                     $priority  Advisory priority from 0 through 255.
	 *
	 * @throws  \InvalidArgumentException When the local name, delay, deduplication key, priority, or arguments violate the command contract.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
	public function enqueue( string $name, array $args = array(), int $delay = 0, ?string $dedup_key = null, int $priority = 10 ): AbstractResult {
		$identity = $this->identity( $name );
		AdmissionValidator::assert_priority( $priority, \sprintf( 'Task "%s"', $name ) );

		if ( 0 > $delay ) {
			// Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( 'Task "%1$s" delay %2$d is invalid; pass a non-negative number of seconds.', $name, $delay ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( null !== $dedup_key && ( '' === $dedup_key || self::MAX_DEDUP_KEY_BYTES < \strlen( $dedup_key ) ) ) {
			// Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( 'Task "%1$s" deduplication key must contain 1 to %2$d bytes when provided.', $name, self::MAX_DEDUP_KEY_BYTES ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		AdmissionValidator::assert_portable_args( $args, \sprintf( 'Task "%s"', $name ) );

		return ( $this->enqueue )( $identity, $args, $delay, $dedup_key, $priority );
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

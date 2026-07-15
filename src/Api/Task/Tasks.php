<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Task;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\PortableArguments;

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
	 * Highest scheduler priority accepted by the public command contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const MAX_PRIORITY = 255;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Closure(string): string                                                                    $identity Owner-qualified identity composer.
	 * @param   \Closure(string, TaskInterface): void                                                       $register Task registration delegate.
	 * @param   \Closure(string, array<array-key, mixed>, int, bool, int): AbstractResult<string, ApiError> $enqueue  Task admission delegate.
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
	 * @throws  \InvalidArgumentException When the local name, delay, priority, or arguments violate the command contract.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
	public function enqueue( string $name, array $args = array(), int $delay = 0, bool $unique = false, int $priority = 10 ): AbstractResult {
		$identity = $this->identity( $name );

		if ( 0 > $priority || self::MAX_PRIORITY < $priority ) {
			// Exception values are diagnostic data, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \InvalidArgumentException(
				\sprintf(
					'Task "%1$s" priority %2$d is invalid; pass a value from 0 through %3$d.',
					$name,
					$priority,
					self::MAX_PRIORITY
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( 0 > $delay ) {
			// Exception values are diagnostic data, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \InvalidArgumentException(
				\sprintf(
					'Task "%1$s" delay %2$d is invalid; pass a non-negative number of seconds.',
					$name,
					$delay
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		try {
			$encoded_args = \wp_json_encode(
				$args,
				\JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION
			);
		} catch ( \JsonException ) {
			$encoded_args = false;
		}

		if ( ! \is_string( $encoded_args ) || ! PortableArguments::is_valid( $args ) ) {
			// Exception values are diagnostic data, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \InvalidArgumentException(
				\sprintf(
					'Task "%s" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.',
					$name
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return ( $this->enqueue )( $identity, $args, $delay, $unique, $priority );
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

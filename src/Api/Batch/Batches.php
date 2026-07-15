<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\PortableArguments;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound facade for registering and starting batches.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Batches {
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
	 * @param   \Closure(string): string                                                               $identity Owner-qualified identity composer.
	 * @param   \Closure(string, BatchInterface): void                                                 $register Batch registration delegate.
	 * @param   \Closure(string, array<array-key, mixed>, bool, int): AbstractResult<string, ApiError> $start    Batch admission delegate.
	 */
	public function __construct(
		private \Closure $identity,
		private \Closure $register,
		private \Closure $start,
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers one batch under the bound owner and its declared local name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   BatchInterface $batch Batch to register.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity is invalid or belongs to a task.
	 * @throws  \LogicException           When the batch identity is already registered.
	 *
	 * @return  void
	 */
	public function register( BatchInterface $batch ): void {
		( $this->register )( $this->identity( $batch->get_name() ), $batch );
	}

	/**
	 * Creates and schedules one run for a registered batch.
	 *
	 * A scheduling failure after replacement ownership transfers leaves the incumbent fenced; a
	 * caller handles the returned failure by starting the batch again.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name       Owner-local batch name.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   bool                    $unique     Whether a fresh incumbent causes Failure instead of replacement and
	 *                                               backend uniqueness is requested.
	 * @param   int                     $priority   Advisory priority from 0 through 255.
	 *
	 * @throws  \InvalidArgumentException When the local name, priority, or arguments violate the command contract.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	#[\NoDiscard( 'a batch-start failure must be handled, not dropped' )]
	public function start( string $name, array $start_args = array(), bool $unique = false, int $priority = 10 ): AbstractResult {
		$identity = $this->identity( $name );

		if ( 0 > $priority || self::MAX_PRIORITY < $priority ) {
			// Exception values are diagnostic data, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \InvalidArgumentException(
				\sprintf(
					'Batch "%1$s" priority %2$d is invalid; pass a value from 0 through %3$d.',
					$name,
					$priority,
					self::MAX_PRIORITY
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		try {
			$encoded_args = \wp_json_encode(
				$start_args,
				\JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION
			);
		} catch ( \JsonException ) {
			$encoded_args = false;
		}

		if ( ! \is_string( $encoded_args ) || ! PortableArguments::is_valid( $start_args ) ) {
			// Exception values are diagnostic data, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \InvalidArgumentException(
				\sprintf(
					'Batch "%s" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.',
					$name
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return ( $this->start )( $identity, $start_args, $unique, $priority );
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

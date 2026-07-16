<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\AdmissionValidator;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound facade for registering and starting batches.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Batches {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Closure(string): string                                                                            $identity Owner-qualified identity composer.
	 * @param   \Closure(string, BatchInterface): void                                                              $register Batch registration delegate.
	 * @param   \Closure(string, array<array-key, mixed>, ExistingRunPolicy, int): AbstractResult<string, ApiError> $start    Batch admission delegate.
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
	 * Reject refuses a fresh matching incumbent. Replace transfers its ownership fence to the new run.
	 *
	 * A scheduling failure after replacement ownership transfers leaves the incumbent fenced; a
	 * caller handles the returned failure by starting the batch again.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name       Owner-local batch name.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   ExistingRunPolicy       $existing   Behavior when a fresh matching incumbent holds the lock.
	 * @param   int                     $priority   Advisory priority from 0 through 255.
	 *
	 * @throws  \InvalidArgumentException When the local name, priority, or arguments violate the command contract.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	#[\NoDiscard( 'a batch-start failure must be handled, not dropped' )]
	public function start( string $name, array $start_args = array(), ExistingRunPolicy $existing = ExistingRunPolicy::Replace, int $priority = 10 ): AbstractResult {
		$identity = $this->identity( $name );
		AdmissionValidator::assert_priority( $priority, \sprintf( 'Batch "%s"', $name ) );
		AdmissionValidator::assert_portable_args( $start_args, \sprintf( 'Batch "%s"', $name ) );

		return ( $this->start )( $identity, $start_args, $existing, $priority );
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

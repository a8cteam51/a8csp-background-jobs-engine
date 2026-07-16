<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\AdmissionValidator;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkIdentity;

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
	 * @param   string                 $owner  Consumer plugin owner.
	 * @param   BatchesEngineInterface $engine Batch engine operations.
	 */
	public function __construct(
		private string $owner,
		private BatchesEngineInterface $engine,
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
		$this->engine->register_batch( WorkIdentity::compose( $this->owner, $batch->get_name() ), $batch );
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
	 * @throws  \InvalidArgumentException When the local name or priority is invalid, or arguments are not portable.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	#[\NoDiscard( 'a batch-start failure must be handled, not dropped' )]
	public function start( string $name, array $start_args = array(), ExistingRunPolicy $existing = ExistingRunPolicy::Replace, int $priority = 10 ): AbstractResult {
		$identity = WorkIdentity::compose( $this->owner, $name );
		AdmissionValidator::assert_priority( $priority, \sprintf( 'Batch "%s"', $name ) );
		$payload_error = AdmissionValidator::assert_portable_args( $start_args, \sprintf( 'Batch "%s"', $name ) );
		if ( null !== $payload_error ) {
			return new Failure( $payload_error );
		}

		return $this->engine->start( $identity, $start_args, $existing, $priority );
	}

	// endregion
}

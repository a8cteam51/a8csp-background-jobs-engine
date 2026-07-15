<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\MaintenanceTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingError;

\defined( 'ABSPATH' ) || exit;

/**
 * Consumer API for registering and starting batches.
 *
 * @internal
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
	 * @param   BatchRegistry $registry   Registered batch instances.
	 * @param   Dispatcher    $dispatcher Background-work admission coordinator.
	 */
	public function __construct(
		private BatchRegistry $registry,
		private Dispatcher $dispatcher,
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers one batch under its stable name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   BatchInterface $batch Batch to register.
	 *
	 * @throws  \InvalidArgumentException When the batch name is outside the stable-name grammar.
	 * @throws  \LogicException           When the batch name is engine-reserved or already registered.
	 *
	 * @return  void
	 */
	public function register( BatchInterface $batch ): void {
		if ( MaintenanceTask::NAME === $batch->get_name() ) {
			throw new \LogicException(
				'Batch name "a8csp-bgte-maintenance" is reserved for engine maintenance; choose a consumer-specific batch name.'
			);
		}

		$this->registry->register( $batch );
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
	 * @param   string                  $name       Stable batch name.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   bool                    $unique     Whether a fresh incumbent causes Failure instead of replacement and
	 *                                              backend uniqueness is requested.
	 * @param   int                     $priority   Advisory priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a batch-start failure must be handled, not dropped' )]
	public function start( string $name, array $start_args = array(), bool $unique = false, int $priority = 10 ): AbstractResult {
		if ( MaintenanceTask::NAME === $name ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Background-work name "%s" is engine-reserved; register and dispatch consumer work under its own name.',
						MaintenanceTask::NAME
					)
				)
			);
		}

		return $this->dispatcher->start_batch( $name, $start_args, $unique, $priority );
	}

	// endregion
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\ExistingRunPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
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
	 * Registers one batch under its stable identity.
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
	public function register( string $identity, BatchInterface $batch ): void {
		$this->registry->register( $identity, $batch );
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
	 * @param   string                  $name       Complete owner-qualified batch identity.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   ExistingRunPolicy       $existing   Behavior when a fresh matching incumbent holds the lock.
	 * @param   int                     $priority   Advisory priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a batch-start failure must be handled, not dropped' )]
	public function start( string $name, array $start_args = array(), ExistingRunPolicy $existing = ExistingRunPolicy::Replace, int $priority = 10 ): AbstractResult {
		return $this->dispatcher->start_batch( $name, $start_args, $existing, $priority );
	}

	// endregion
}

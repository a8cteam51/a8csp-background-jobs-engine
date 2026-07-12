<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine;

use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Orchestrator;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;

\defined( 'ABSPATH' ) || exit;

/**
 * Consumer API for registering and starting batches.
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
	 * @param   BatchRegistry $registry     Registered batch instances.
	 * @param   Orchestrator  $orchestrator Background-work coordinator.
	 */
	public function __construct(
		private BatchRegistry $registry,
		private Orchestrator $orchestrator,
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
	 * @throws  \LogicException           When the batch name is already registered.
	 *
	 * @return  void
	 */
	public function register( BatchInterface $batch ): void {
		$this->registry->register( $batch );
	}

	/**
	 * Creates and schedules one run for a registered batch.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name       Stable batch name.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   bool                    $unique     Whether the backend retains an identical start action.
	 * @param   int                     $priority   Advisory priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a batch-start failure must be handled, not dropped' )]
	public function start(
		string $name,
		array $start_args = array(),
		bool $unique = false,
		int $priority = 10
	): AbstractResult {
		return $this->orchestrator->start_batch( $name, $start_args, $unique, $priority );
	}

	// endregion
}

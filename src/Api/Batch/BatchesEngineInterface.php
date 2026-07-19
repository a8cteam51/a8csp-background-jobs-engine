<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;

\defined( 'ABSPATH' ) || exit;

/**
 * Engine operations required by the owner-bound batch facade.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface BatchesEngineInterface {
	// region METHODS

	/**
	 * Registers one batch under its complete owner-qualified identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $identity Complete owner-qualified batch identity.
	 * @param   BatchInterface $batch    Batch to register.
	 *
	 * @throws  \InvalidArgumentException When the identity and batch name disagree, or a task owns the identity.
	 * @throws  \LogicException           When the batch identity is already registered.
	 *
	 * @return  void
	 */
	public function register_batch( string $identity, BatchInterface $batch ): void;

	/**
	 * Creates and schedules one run for a registered batch.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $identity   Complete owner-qualified batch identity.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   ExistingRunPolicy       $existing   Behavior when a fresh matching incumbent holds the lock.
	 * @param   int                     $priority   Advisory priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	public function start( string $identity, array $start_args, ExistingRunPolicy $existing, int $priority ): AbstractResult;

	// endregion
}

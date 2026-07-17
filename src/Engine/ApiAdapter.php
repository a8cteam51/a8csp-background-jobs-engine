<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchesEngineInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\ExistingRunPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\RunsEngineInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\SchedulesEngineInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TasksEngineInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\ApiErrorMapper;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\Schedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound adapter from supported API ports to internal engine operations.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ApiAdapter implements TasksEngineInterface, BatchesEngineInterface, SchedulesEngineInterface, RunsEngineInterface {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string       $owner      Consumer plugin owner.
	 * @param   WorkRegistry $work       Registered task and batch instances.
	 * @param   Schedules    $schedules  Schedule engine operations.
	 * @param   Dispatcher   $dispatcher Background-work admission coordinator.
	 * @param   Inspection   $inspection Read-only run inspection.
	 */
	public function __construct(
		private string $owner,
		private WorkRegistry $work,
		private Schedules $schedules,
		private Dispatcher $dispatcher,
		private Inspection $inspection,
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers one task under its complete owner-qualified identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string        $identity Complete owner-qualified task identity.
	 * @param   TaskInterface $task     Task to register.
	 *
	 * @throws  \InvalidArgumentException When the identity and task name disagree, or a batch owns the identity.
	 * @throws  \LogicException           When the task identity is already registered.
	 *
	 * @return  void
	 */
	#[\Override]
	public function register_task( string $identity, TaskInterface $task ): void {
		$this->work->register_task( $identity, $task );
	}

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
	#[\Override]
	public function register_batch( string $identity, BatchInterface $batch ): void {
		$this->work->register_batch( $identity, $batch );
	}

	/**
	 * Creates and schedules one run for a registered task.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $identity  Complete owner-qualified task identity.
	 * @param   array<array-key, mixed> $args      Task arguments.
	 * @param   int                     $delay     Scheduling delay in seconds.
	 * @param   string|null             $dedup_key Consumer deduplication key whose hash replaces the argument hash.
	 * @param   int                     $priority  Advisory priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, \A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError>
	 */
	#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
	#[\Override]
	public function enqueue( string $identity, array $args, int $delay, ?string $dedup_key, int $priority ): AbstractResult {
		return ApiErrorMapper::map( $this->dispatcher->enqueue( $identity, $args, $delay, $dedup_key, $priority ) );
	}

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
	 * @return  AbstractResult<string, \A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError>
	 */
	#[\NoDiscard( 'a batch-start failure must be handled, not dropped' )]
	#[\Override]
	public function start( string $identity, array $start_args, ExistingRunPolicy $existing, int $priority ): AbstractResult {
		return ApiErrorMapper::map( $this->dispatcher->start_batch( $identity, $start_args, $existing, $priority ) );
	}

	/**
	 * Synchronizes the bound owner's complete declared schedule set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<string, array{schedule: \A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule, task: string}> $declarations
	 *
	 * @param   array $declarations Complete schedule declaration keyed by owner-qualified identity.
	 *
	 * @return  AbstractResult<true, \A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError>
	 */
	#[\NoDiscard( 'a schedule-sync failure must be handled, not dropped' )]
	#[\Override]
	public function sync( array $declarations ): AbstractResult {
		return ApiErrorMapper::map( $this->schedules->sync( $this->owner, $declarations ) );
	}

	/**
	 * Immediately dispatches one declared schedule target without changing its recurrence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified schedule identity.
	 *
	 * @return  AbstractResult<string, \A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError>
	 */
	#[\NoDiscard( 'a schedule dispatch-now failure must be handled, not dropped' )]
	#[\Override]
	public function dispatch_now( string $identity ): AbstractResult {
		return ApiErrorMapper::map( $this->schedules->dispatch_now( $identity ) );
	}

	/**
	 * Returns the most recently recorded completed run ID retained for one identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified task or batch identity.
	 *
	 * @return  AbstractResult<string|null, \A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError>
	 */
	#[\NoDiscard( 'a last-completed-run result must be handled, not dropped' )]
	#[\Override]
	public function last_completed_run_id( string $identity ): AbstractResult {
		return ApiErrorMapper::map( $this->inspection->last_completed_run_id( $identity ) );
	}

	/**
	 * Starts a fresh run from one retained failed run's original arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified task or batch identity.
	 * @param   string $run_id   Retained failed-run identifier.
	 *
	 * @return  AbstractResult<string, \A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError>
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	#[\Override]
	public function retry_failed( string $identity, string $run_id ): AbstractResult {
		return ApiErrorMapper::map( $this->dispatcher->retry_failed( $identity, $run_id ) );
	}

	/**
	 * Cancels one retained run that is not executing or pending batch cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified task or batch identity.
	 * @param   string $run_id   Retained run identifier.
	 *
	 * @return  AbstractResult<string, \A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError>
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	#[\Override]
	public function cancel( string $identity, string $run_id ): AbstractResult {
		return ApiErrorMapper::map( $this->dispatcher->cancel( $identity, $run_id ) );
	}

	// endregion
}

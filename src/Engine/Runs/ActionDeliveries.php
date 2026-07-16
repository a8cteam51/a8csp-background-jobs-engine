<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\BatchContext;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\WorkRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\PortableArguments;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Delivers the engine's internal task and batch actions.
 *
 * Fresh execution markers exclude same-sequence redelivery; stale crash recovery remains at-least-once
 * and relies on task and batch idempotency.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ActionDeliveries {
	// region FIELDS AND CONSTANTS

	/**
	 * Internal hook that resumes a batch after its inter-chunk delay.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string CONTINUE_HOOK = 'a8csp_background_tasks/continue';

	/**
	 * Internal hook that reconciles a terminal batch run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string CLEANUP_HOOK = 'a8csp_background_tasks/cleanup';

	/**
	 * Internal hook that executes task work or one batch chunk.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string RUN_HOOK = 'a8csp_background_tasks/run';

	/**
	 * Internal hook that generates and starts a batch queue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string START_HOOK = 'a8csp_background_tasks/start';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   TaskRegistry        $tasks                Registered task instances.
	 * @param   BatchRegistry       $batches              Registered batch instances.
	 * @param   WorkRegistry        $work                 Shared task-and-batch identity registry.
	 * @param   BackendInterface    $scheduler            Scheduling facade boundary.
	 * @param   StoreFactory        $stores               Name-bound store factory.
	 * @param   LoggerInterface     $logger               Log event sink.
	 * @param   ClockInterface      $clock                Timestamp source.
	 * @param   LockWindows         $lock_windows         Filterable run-lock timing policy.
	 * @param   TerminalTransitions $terminal_transitions Fenced terminal-write coordinator.
	 * @param   TerminalEffects     $terminal_effects     Consumer lifecycle-effect executor.
	 * @param   FailureLifecycle    $failure_lifecycle    Retry adjudication coordinator.
	 */
	public function __construct( private TaskRegistry $tasks, private BatchRegistry $batches, private WorkRegistry $work, private BackendInterface $scheduler, private StoreFactory $stores, private LoggerInterface $logger, private ClockInterface $clock, private LockWindows $lock_windows, private TerminalTransitions $terminal_transitions, private TerminalEffects $terminal_effects, private FailureLifecycle $failure_lifecycle ) {}

	// endregion

	// region METHODS

	/**
	 * Handles queue generation for one scheduled batch run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $batch_name Complete owner-qualified batch identity.
	 * @param   string $run_id    Run identifier.
	 * @param   int    $action_seq Expected lifecycle action sequence.
	 *
	 * @return  void
	 */
	public function handle_start_action( string $batch_name, string $run_id, int $action_seq ): void {
		$registered_batch = 'batch' === $this->work->kind( $batch_name )
			? $this->batches->get( $batch_name )
			: null;
		$liveness_at      = null !== $registered_batch
			? fn (): int => $this->execution_lease_at( $registered_batch )
			: null;
		$run_store        = $this->stores->run_store( $batch_name );
		$state            = $this->terminal_transitions->claim_delivery_ownership( 'Batch', $batch_name, $run_id, $action_seq, $run_store, $liveness_at );
		if ( null === $state ) {
			return;
		}

		$batch = $this->batch_for_action( $batch_name, $run_id, 'start' );
		if ( null === $batch ) {
			$this->fail_orphaned_run( 'Batch', $batch_name, $run_id, $state, $run_store );

			return;
		}

		try {
			$queue = $this->materialize_queue( $batch->generate_queue( $state->start_args ) );
		} catch ( \Throwable $throwable ) {
			$this->fail_batch_start_action( $batch, $batch_name, $run_id, $state, $run_store, EngineError::from_throwable( $throwable ), ApiErrorCode::ExecutionFailed );

			return;
		}
		if ( $queue instanceof EngineError ) {
			$this->fail_batch_start_action( $batch, $batch_name, $run_id, $state, $run_store, $queue, ApiErrorCode::PayloadRejected );

			return;
		}

		try {
			$queue = $this->materialize_filtered_queue( \apply_filters( 'a8csp_background_tasks/queue/' . $batch_name, $queue, $state->start_args, $run_id ) );
		} catch ( \Throwable $throwable ) {
			$this->fail_batch_start_action( $batch, $batch_name, $run_id, $state, $run_store, EngineError::from_throwable( $throwable ), ApiErrorCode::ExecutionFailed );

			return;
		}
		if ( $queue instanceof EngineError ) {
			$this->fail_batch_start_action( $batch, $batch_name, $run_id, $state, $run_store, $queue, ApiErrorCode::PayloadRejected );

			return;
		}

		$reset_at = $this->clock->now()->getTimestamp();
		if ( $this->terminal_transitions->enforce_delivery_fence( 'Batch', $batch_name, $run_id, $state, $run_store, $reset_at, $state->heartbeat_at ) ) {
			return;
		}

		$replacement = $state->with_queue( $queue )->with_heartbeat_at( $reset_at )->with_action_seq( $state->action_seq + 1 )->with_executing( false )->with_pending( PendingAction::async( 'continue', 10 ) );
		if ( null === $run_store->replace_if_state_matches( $run_id, $state, $replacement ) ) {
			return;
		}
		$state = $replacement;
		try {
			$this->terminal_effects->fire_started( $batch_name, $run_id, $state->start_args );
		} catch ( \Throwable $throwable ) {
			if ( $this->terminal_transitions->enforce_delivery_fence( 'Batch', $batch_name, $run_id, $state, $run_store, $state->heartbeat_at, $state->heartbeat_at ) ) {
				return;
			}

			$this->terminal_transitions->fail_batch( $batch, $batch_name, $run_id, $state, $run_store, EngineError::from_throwable( $throwable ), 'execution', ApiErrorCode::ExecutionFailed );

			return;
		}

		if ( $this->terminal_transitions->enforce_delivery_fence( 'Batch', $batch_name, $run_id, $state, $run_store, $state->heartbeat_at, $state->heartbeat_at ) ) {
			return;
		}

		$scheduled = $this->scheduler->enqueue_async( self::CONTINUE_HOOK, array( $batch_name, $run_id, $state->action_seq ), $batch_name . '|' . $run_id );
		if ( $scheduled->is_failure() ) {
			$this->terminal_transitions->fail_batch( $batch, $batch_name, $run_id, $state, $run_store, EngineError::scheduling( 'Batch', $batch_name, 'continue', $scheduled->error ), 'scheduling', EngineError::api_code_for_scheduling( $scheduled->error ) );

			return;
		}
	}

	/**
	 * Schedules the current retained queue head for a batch run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $batch_name Complete owner-qualified batch identity.
	 * @param   string $run_id    Run identifier.
	 * @param   int    $action_seq Expected lifecycle action sequence.
	 *
	 * @return  void
	 */
	public function handle_continue_action( string $batch_name, string $run_id, int $action_seq ): void {
		$run_store = $this->stores->run_store( $batch_name );
		$state     = $this->terminal_transitions->claim_delivery_ownership( 'Batch', $batch_name, $run_id, $action_seq, $run_store );
		if ( null === $state ) {
			return;
		}

		$batch = $this->batch_for_action( $batch_name, $run_id, 'continue' );
		if ( null === $batch ) {
			$this->fail_orphaned_run( 'Batch', $batch_name, $run_id, $state, $run_store );

			return;
		}

		if ( array() === $state->queue ) {
			$replacement = $state->with_action_seq( $state->action_seq + 1 )->with_executing( false )->with_pending( PendingAction::async( 'cleanup', 10 ) );
			if ( null === $run_store->replace_if_state_matches( $run_id, $state, $replacement ) ) {
				return;
			}
			$state     = $replacement;
			$scheduled = $this->scheduler->enqueue_async( self::CLEANUP_HOOK, array( $batch_name, $run_id, $state->action_seq ), $batch_name . '|' . $run_id );
			if ( $scheduled->is_failure() ) {
				$this->terminal_transitions->fail_batch( $batch, $batch_name, $run_id, $state, $run_store, EngineError::scheduling( 'Batch', $batch_name, 'cleanup', $scheduled->error ), 'scheduling', EngineError::api_code_for_scheduling( $scheduled->error ) );
			}

			return;
		}

		$chunk_args  = $state->queue[0];
		$replacement = $state->with_action_seq( $state->action_seq + 1 )->with_executing( false )->with_pending( PendingAction::async( 'run', 10 ) );
		if ( null === $run_store->replace_if_state_matches( $run_id, $state, $replacement ) ) {
			return;
		}
		$state     = $replacement;
		$scheduled = $this->scheduler->enqueue_async( self::RUN_HOOK, array( $batch_name, $run_id, $chunk_args, $state->action_seq ), $batch_name . '|' . $run_id );
		if ( $scheduled->is_failure() ) {
			$this->terminal_transitions->fail_batch( $batch, $batch_name, $run_id, $state, $run_store, EngineError::scheduling( 'Batch', $batch_name, 'run', $scheduled->error ), 'scheduling', EngineError::api_code_for_scheduling( $scheduled->error ), $chunk_args );
		}
	}

	/**
	 * Dispatches one scheduled run action to its registered task or batch.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                      $identity                 Complete owner-qualified task or batch identity.
	 * @param   string                      $run_id                   Run identifier.
	 * @param   array<array-key, mixed>|int $chunk_args_or_action_seq Batch chunk arguments or a task action sequence.
	 * @param   int|null                    $action_seq               Batch action sequence, or null for a task action.
	 *
	 * @return  void
	 */
	public function handle_run_action( string $identity, string $run_id, array|int $chunk_args_or_action_seq, ?int $action_seq = null ): void {
		$chunk_args   = \is_int( $chunk_args_or_action_seq ) ? null : $chunk_args_or_action_seq;
		$received_seq = \is_int( $chunk_args_or_action_seq ) ? $chunk_args_or_action_seq : $action_seq;
		$work_type    = null === $chunk_args ? 'Task' : 'Batch';
		$kind         = $this->work->kind( $identity );
		$task         = 'task' === $kind ? $this->tasks->get( $identity ) : null;
		$batch        = 'batch' === $kind ? $this->batches->get( $identity ) : null;
		$liveness_at  = null;
		if ( null === $chunk_args && null !== $task ) {
			$liveness_at = fn (): int => $this->execution_lease_at( $task );
		} elseif ( null !== $chunk_args && null !== $batch ) {
			$liveness_at = fn (): int => $this->execution_lease_at( $batch );
		}
		$run_store = $this->stores->run_store( $identity );
		$state     = $this->terminal_transitions->claim_delivery_ownership( $work_type, $identity, $run_id, $received_seq, $run_store, $liveness_at );
		if ( null === $state ) {
			return;
		}

		if ( null !== $task ) {
			if ( null !== $chunk_args ) {
				$this->logger->warning(
					'Task run action carries batch chunk arguments; schedule task runs with only the task name and run identifier.',
					array(
						'task_name' => $identity,
						'run_id'    => $run_id,
					)
				);
				$run_store->replace_if_state_matches( $run_id, $state, $state->with_executing( false ) );

				return;
			}

			$this->handle_task_run_action( $task, $identity, $run_id, $state, $run_store );

			return;
		}

		if ( null !== $batch ) {
			if ( null === $chunk_args ) {
				$this->logger->warning(
					'Batch run action is missing chunk arguments; schedule it with the current queue head as the third argument.',
					array(
						'batch_name' => $identity,
						'run_id'     => $run_id,
					)
				);
				$run_store->replace_if_state_matches( $run_id, $state, $state->with_executing( false ) );

				return;
			}

			$this->handle_batch_run_action( $batch, $identity, $run_id, $chunk_args, $state, $run_store );

			return;
		}

		$this->logger->warning(
			null === $chunk_args
				? 'Task run action references an unregistered task; register the task before dispatching its run action.'
				: 'Batch run action references an unregistered batch; register the batch before dispatching its run action.',
			array(
				( null === $chunk_args ? 'task_name' : 'batch_name' ) => $identity,
				'run_id' => $run_id,
			)
		);
		$this->fail_orphaned_run( $work_type, $identity, $run_id, $state, $run_store );
	}

	/**
	 * Handles terminal success for one drained batch run.
	 *
	 * Once success handling begins, remaining writes are exact-CAS or owner-guarded. The identity-shared
	 * run-history row is CAS-guarded and idempotent, and lock release self-guards against a new owner, so
	 * skipping the fence recheck remains safe. The outcome remains Completed regardless of current lock
	 * ownership; recording another outcome would lie.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $batch_name Complete owner-qualified batch identity.
	 * @param   string $run_id    Run identifier.
	 * @param   int    $action_seq Expected lifecycle action sequence.
	 *
	 * @return  void
	 */
	public function handle_cleanup_action( string $batch_name, string $run_id, int $action_seq ): void {
		$run_store = $this->stores->run_store( $batch_name );
		$state     = $this->terminal_transitions->claim_delivery_ownership( 'Batch', $batch_name, $run_id, $action_seq, $run_store );
		if ( null === $state ) {
			return;
		}

		$batch = $this->batch_for_action( $batch_name, $run_id, 'cleanup' );
		if ( null === $batch ) {
			$this->fail_orphaned_run( 'Batch', $batch_name, $run_id, $state, $run_store );

			return;
		}

		if ( array() !== $state->queue ) {
			$this->terminal_transitions->fail_batch( $batch, $batch_name, $run_id, $state, $run_store, new EngineError( \sprintf( 'Batch "%s" reached cleanup with queued chunks; schedule cleanup only after continue observes an empty queue.', $batch_name ) ), 'execution', ApiErrorCode::UnsupportedOperation );

			return;
		}

		$this->terminal_transitions->complete_batch( $batch, $batch_name, $run_id, $state, $run_store );
	}

	/**
	 * Registers the internal lifecycle actions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function register_hooks(): void {
		\add_action( self::START_HOOK, array( $this, 'handle_start_action' ), 10, 3 );
		\add_action( self::CONTINUE_HOOK, array( $this, 'handle_continue_action' ), 10, 3 );
		\add_action( self::RUN_HOOK, array( $this, 'handle_run_action' ), 10, 4 );
		\add_action( self::CLEANUP_HOOK, array( $this, 'handle_cleanup_action' ), 10, 3 );
	}

	// endregion

	// region HELPERS

	/**
	 * Fails batch startup after preserving its post-callback liveness fence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   BatchInterface $batch      Registered batch.
	 * @param   string         $batch_name Complete owner-qualified batch identity.
	 * @param   string         $run_id     Run identifier.
	 * @param   RunState       $state      Fenced running state.
	 * @param   RunStore       $run_store  Active-run store.
	 * @param   EngineError    $error      Terminal failure detail.
	 * @param   ApiErrorCode   $code       Machine-readable cause classification.
	 *
	 * @return  void
	 */
	private function fail_batch_start_action( BatchInterface $batch, string $batch_name, string $run_id, RunState $state, RunStore $run_store, EngineError $error, ApiErrorCode $code ): void {
		$reset_at = $this->clock->now()->getTimestamp();
		if ( $this->terminal_transitions->enforce_delivery_fence( 'Batch', $batch_name, $run_id, $state, $run_store, $reset_at, $state->heartbeat_at ) ) {
			return;
		}
		$state = $run_store->mark_executing_with_heartbeat( $run_id, $state, $reset_at );
		if ( null === $state ) {
			return;
		}

		$this->terminal_transitions->fail_batch( $batch, $batch_name, $run_id, $state, $run_store, $error, 'queue-generation', $code );
	}

	/**
	 * Executes one task run after shared-hook dispatch.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   TaskInterface $task      Registered task.
	 * @param   string        $task_name Complete owner-qualified task identity.
	 * @param   string        $run_id    Run identifier.
	 * @param   RunState      $state     Fenced running state.
	 * @param   RunStore      $run_store Active-run store.
	 *
	 * @return  void
	 */
	private function handle_task_run_action( TaskInterface $task, string $task_name, string $run_id, RunState $state, RunStore $run_store ): void {
		try {
			$task->handle( $state->start_args );
		} catch ( \Throwable $throwable ) {
			$this->failure_lifecycle->handle_task_failure( $task, $task_name, $run_id, $state, $run_store, $throwable );

			return;
		}

		if ( $this->terminal_transitions->enforce_delivery_fence( 'Task', $task_name, $run_id, $state, $run_store, null, $state->heartbeat_at ) ) {
			return;
		}

		$this->terminal_transitions->complete_task( $task_name, $run_id, $state, $run_store );
	}

	/**
	 * Executes one batch chunk and schedules the next continue after a normal return.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   BatchInterface          $batch      Registered batch.
	 * @param   string                  $batch_name Complete owner-qualified batch identity.
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $chunk_args Chunk arguments.
	 * @param   RunState                $state      Fenced running state.
	 * @param   RunStore                $run_store  Active-run store.
	 *
	 * @return  void
	 */
	private function handle_batch_run_action( BatchInterface $batch, string $batch_name, string $run_id, array $chunk_args, RunState $state, RunStore $run_store ): void {
		$context = new BatchContext( $run_id, $state->start_args, \array_slice( $state->queue, 1 ) );
		try {
			$batch->process_chunk( $chunk_args, $context );
		} catch ( \Throwable $throwable ) {
			$this->failure_lifecycle->handle_batch_failure( $batch, $batch_name, $run_id, $state, $run_store, $throwable, $chunk_args );

			return;
		}

		$reset_at = $this->clock->now()->getTimestamp();
		if ( $this->terminal_transitions->enforce_delivery_fence( 'Batch', $batch_name, $run_id, $state, $run_store, $reset_at, $state->heartbeat_at ) ) {
			return;
		}

		try {
			$delay = $this->lock_windows->continue_delay( $batch_name, $run_id );
		} catch ( \Throwable $throwable ) {
			if ( $this->terminal_transitions->enforce_delivery_fence( 'Batch', $batch_name, $run_id, $state, $run_store, $reset_at, $reset_at ) ) {
				return;
			}

			$this->fail_processed_batch_chunk( $batch, $batch_name, $run_id, $state, $run_store, $context->get_queue(), $reset_at, EngineError::from_throwable( $throwable ), 'execution', ApiErrorCode::ExecutionFailed );

			return;
		}

		if ( $this->terminal_transitions->enforce_delivery_fence( 'Batch', $batch_name, $run_id, $state, $run_store, $reset_at, $reset_at ) ) {
			return;
		}

		$now = $this->clock->now()->getTimestamp();
		if ( $delay > \PHP_INT_MAX - $now ) {
			$this->fail_processed_batch_chunk( $batch, $batch_name, $run_id, $state, $run_store, $context->get_queue(), $reset_at, new EngineError( \sprintf( 'Batch "%s" could not schedule the continue action because its delay exceeds supported Unix seconds; return a smaller non-negative delay from the continue-delay filter.', $batch_name ) ), 'scheduling', ApiErrorCode::BackendRejected );

			return;
		}
		$fire_at     = $now + $delay;
		$replacement = $state->with_queue( $context->get_queue() )->with_failed_attempts( 0 )->with_heartbeat_at( $reset_at )->with_action_seq( $state->action_seq + 1 )->with_executing( false )->with_pending( PendingAction::single( 'continue', $fire_at, 10 ) );
		if ( null === $run_store->replace_if_state_matches( $run_id, $state, $replacement ) ) {
			return;
		}
		$state = $replacement;

		$scheduled = $this->scheduler->schedule_single( self::CONTINUE_HOOK, $fire_at, array( $batch_name, $run_id, $state->action_seq ), $batch_name . '|' . $run_id, 10 );
		if ( $scheduled->is_failure() ) {
			$this->terminal_transitions->fail_batch( $batch, $batch_name, $run_id, $state, $run_store, EngineError::scheduling( 'Batch', $batch_name, 'continue', $scheduled->error ), 'scheduling', EngineError::api_code_for_scheduling( $scheduled->error ) );
		}
	}

	/**
	 * Commits processed queue state without a successor before terminal failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   BatchInterface                $batch      Registered batch.
	 * @param   string                        $batch_name Complete owner-qualified batch identity.
	 * @param   string                        $run_id     Run identifier.
	 * @param   RunState                      $state      Fenced running state.
	 * @param   RunStore                      $run_store  Active-run store.
	 * @param   list<array<array-key, mixed>> $queue      Committed queue after the processed chunk.
	 * @param   int                           $reset_at   Post-callback liveness timestamp.
	 * @param   EngineError                   $error      Terminal failure detail.
	 * @param   string                        $stage      Terminalization stage.
	 * @param   ApiErrorCode                  $code       Machine-readable cause classification.
	 *
	 * @return  void
	 */
	private function fail_processed_batch_chunk( BatchInterface $batch, string $batch_name, string $run_id, RunState $state, RunStore $run_store, array $queue, int $reset_at, EngineError $error, string $stage, ApiErrorCode $code ): void {
		$replacement = $state->with_queue( $queue )->with_failed_attempts( 0 )->with_heartbeat_at( $reset_at )->with_action_seq( $state->action_seq + 1 )->with_executing( false )->with_pending( null );
		if ( null === $run_store->replace_if_state_matches( $run_id, $state, $replacement ) ) {
			return;
		}

		$this->terminal_transitions->fail_batch( $batch, $batch_name, $run_id, $replacement, $run_store, $error, $stage, $code );
	}

	/**
	 * Resolves the bounded future liveness timestamp for one contract callback.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   TaskInterface|BatchInterface $contract Registered work contract.
	 *
	 * @return  int
	 */
	private function execution_lease_at( TaskInterface|BatchInterface $contract ): int {
		try {
			$declared = $contract->max_runtime();
		} catch ( \Throwable ) {
			// An unusable declaration falls back to the default lease instead of escaping the delivery unfenced.
			$declared = null;
		}

		$lease = $this->lock_windows->execution_lease( $declared );
		$now   = $this->clock->now()->getTimestamp();
		if ( $now > \PHP_INT_MAX - $lease ) {
			return \PHP_INT_MAX;
		}

		return $now + $lease;
	}

	/**
	 * Returns the batch recorded for one internal action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $batch_name Complete owner-qualified batch identity.
	 * @param   string $run_id    Run identifier.
	 * @param   string $stage     Internal batch stage.
	 *
	 * @return  BatchInterface|null
	 */
	private function batch_for_action( string $batch_name, string $run_id, string $stage ): ?BatchInterface {
		$batch = 'batch' === $this->work->kind( $batch_name )
			? $this->batches->get( $batch_name )
			: null;

		if ( null === $batch ) {
			$this->logger->warning(
				'Batch action references an unregistered batch; register the batch before dispatching its action.',
				array(
					'batch_name' => $batch_name,
					'run_id'     => $run_id,
					'stage'      => $stage,
				)
			);
		}

		return $batch;
	}

	/**
	 * Fails a live run whose required task or batch is no longer registered.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch' $work_type Work contract type.
	 * @param   string         $identity  Complete owner-qualified task or batch identity.
	 * @param   string         $run_id    Run identifier.
	 * @param   RunState       $state     Fenced running state.
	 * @param   RunStore       $run_store Active-run store.
	 *
	 * @return  void
	 */
	private function fail_orphaned_run( string $work_type, string $identity, string $run_id, RunState $state, RunStore $run_store ): void {
		$error = new EngineError( \sprintf( '%1$s identity "%2$s" has no registered %4$s implementation for run "%3$s"; register that %4$s or purge the run.', $work_type, $identity, $run_id, \strtolower( $work_type ) ) );

		$this->terminal_transitions->fail_unregistered_run( $work_type, $identity, $run_id, $state, $run_store, $error );
	}

	/**
	 * Returns a normalized queue from the queue filter boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $chunks Filtered queue value.
	 *
	 * @return  list<array<array-key, mixed>>|EngineError
	 */
	private function materialize_filtered_queue( mixed $chunks ): array|EngineError {
		if ( ! \is_array( $chunks ) ) {
			return new EngineError( 'Batch queue filter returned a non-array value; return one argument array per chunk.', \UnexpectedValueException::class );
		}

		return $this->materialize_queue( $chunks );
	}

	/**
	 * Returns one normalized oldest-first queue from an iterable source.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   iterable<mixed> $chunks Generated or filtered chunks.
	 *
	 * @throws  \Throwable When the iterable fails during traversal.
	 *
	 * @return  list<array<array-key, mixed>>|EngineError
	 */
	private function materialize_queue( iterable $chunks ): array|EngineError {
		$queue = array();
		foreach ( $chunks as $chunk_args ) {
			$index = \count( $queue );
			if ( ! \is_array( $chunk_args ) ) {
				return new EngineError( \sprintf( 'Batch queue chunk at index %d must be an argument array.', $index ), \UnexpectedValueException::class );
			}
			if ( ! PortableArguments::is_valid( $chunk_args ) ) {
				return new EngineError( \sprintf( 'Batch queue chunk at index %d must contain only null, scalar, or nested array values.', $index ), \UnexpectedValueException::class );
			}

			$queue[] = $chunk_args;
		}

		return $queue;
	}

	// endregion
}

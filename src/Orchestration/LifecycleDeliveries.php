<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Orchestration;

use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\BackendInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Delivers the engine's internal task and batch lifecycle actions.
 *
 * Same-sequence redelivery remains at-least-once execution and relies on task and batch idempotency.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class LifecycleDeliveries {
	// region FIELDS AND CONSTANTS

	/**
	 * Internal hook that resumes a batch after its inter-chunk delay.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const CONTINUE_HOOK = 'a8csp/background_tasks/continue';

	/**
	 * Internal hook that reconciles a terminal batch run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const CLEANUP_HOOK = 'a8csp/background_tasks/cleanup';

	/**
	 * Internal hook that executes task work or one batch chunk.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const RUN_HOOK = 'a8csp/background_tasks/run';

	/**
	 * Internal hook that generates and starts a batch queue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const START_HOOK = 'a8csp/background_tasks/start';

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
	 * @param   BackendInterface    $scheduler            Scheduling facade boundary.
	 * @param   StoreFactory        $stores               Name-bound store factory.
	 * @param   LoggerInterface     $logger               Log event sink.
	 * @param   ClockInterface      $clock                Timestamp source.
	 * @param   LockWindows         $lock_windows         Filterable run-lock timing policy.
	 * @param   TerminalTransitions $terminal_transitions Fenced terminal-write coordinator.
	 * @param   FailureLifecycle    $failure_lifecycle    Retry adjudication coordinator.
	 */
	public function __construct(
		private TaskRegistry $tasks,
		private BatchRegistry $batches,
		private BackendInterface $scheduler,
		private StoreFactory $stores,
		private LoggerInterface $logger,
		private ClockInterface $clock,
		private LockWindows $lock_windows,
		private TerminalTransitions $terminal_transitions,
		private FailureLifecycle $failure_lifecycle,
	) {}

	// endregion

	// region METHODS

	/**
	 * Handles queue generation for one scheduled batch run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $batch_name Stable batch name.
	 * @param   string $run_id    Run identifier.
	 * @param   int    $action_seq Expected lifecycle action sequence.
	 *
	 * @return  void
	 */
	public function handle_start_action( string $batch_name, string $run_id, int $action_seq ): void {
		$run_store = $this->stores->run_store( $batch_name );
		$state     = $this->terminal_transitions->active_run_state( 'Batch', $batch_name, $run_id, $action_seq, $run_store );
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
			$queue = $this->materialize_filtered_queue(
				\apply_filters(
					'a8csp/background_tasks/queue/' . $batch_name,
					$queue,
					$state->start_args,
					$run_id
				)
			);
		} catch ( \Throwable $throwable ) {
			if ( $this->terminal_transitions->supersede_if_fence_lost( 'Batch', $batch_name, $run_id, $state, $run_store ) ) {
				return;
			}

			$this->terminal_transitions->fail_batch(
				$batch,
				$batch_name,
				$run_id,
				$state,
				$run_store,
				EngineError::from_throwable( $throwable )
			);

			return;
		}

		if ( $this->terminal_transitions->supersede_if_fence_lost( 'Batch', $batch_name, $run_id, $state, $run_store ) ) {
			return;
		}

		$replacement = $state
			->with_queue( $queue )
			->with_action_seq( $state->action_seq + 1 );
		if ( null === $run_store->transition_state( $run_id, $state, $replacement ) ) {
			return;
		}
		$state = $replacement;
		try {
			$this->terminal_transitions->fire_started( $batch_name, $run_id, $state->start_args );
		} catch ( \Throwable $throwable ) {
			if ( $this->terminal_transitions->supersede_if_fence_lost( 'Batch', $batch_name, $run_id, $state, $run_store ) ) {
				return;
			}

			$this->terminal_transitions->fail_batch(
				$batch,
				$batch_name,
				$run_id,
				$state,
				$run_store,
				EngineError::from_throwable( $throwable )
			);

			return;
		}

		if ( $this->terminal_transitions->supersede_if_fence_lost( 'Batch', $batch_name, $run_id, $state, $run_store ) ) {
			return;
		}

		$scheduled = $this->scheduler->enqueue_async(
			self::CONTINUE_HOOK,
			array( $batch_name, $run_id, $state->action_seq ),
			$batch_name . '|' . $run_id
		);
		if ( $scheduled->is_failure() ) {
			$this->terminal_transitions->fail_batch(
				$batch,
				$batch_name,
				$run_id,
				$state,
				$run_store,
				EngineError::scheduling( 'Batch', $batch_name, 'continue', $scheduled->error )
			);
		}
	}

	/**
	 * Handles one queue advancement for a scheduled batch run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $batch_name Stable batch name.
	 * @param   string $run_id    Run identifier.
	 * @param   int    $action_seq Expected lifecycle action sequence.
	 *
	 * @return  void
	 */
	public function handle_continue_action( string $batch_name, string $run_id, int $action_seq ): void {
		$run_store = $this->stores->run_store( $batch_name );
		$state     = $this->terminal_transitions->active_run_state( 'Batch', $batch_name, $run_id, $action_seq, $run_store );
		if ( null === $state ) {
			return;
		}

		$batch = $this->batch_for_action( $batch_name, $run_id, 'continue' );
		if ( null === $batch ) {
			$this->fail_orphaned_run( 'Batch', $batch_name, $run_id, $state, $run_store );

			return;
		}

		if ( array() === $state->queue ) {
			$replacement = $state->with_action_seq( $state->action_seq + 1 );
			if ( null === $run_store->transition_state( $run_id, $state, $replacement ) ) {
				return;
			}
			$state     = $replacement;
			$scheduled = $this->scheduler->enqueue_async(
				self::CLEANUP_HOOK,
				array( $batch_name, $run_id, $state->action_seq ),
				$batch_name . '|' . $run_id
			);
			if ( $scheduled->is_failure() ) {
				$this->terminal_transitions->fail_batch(
					$batch,
					$batch_name,
					$run_id,
					$state,
					$run_store,
					EngineError::scheduling( 'Batch', $batch_name, 'cleanup', $scheduled->error )
				);
			}

			return;
		}

		$chunk_args  = $state->queue[0];
		$replacement = $state
			->with_queue( \array_slice( $state->queue, 1 ) )
			->with_action_seq( $state->action_seq + 1 );
		if ( null === $run_store->transition_state( $run_id, $state, $replacement ) ) {
			return;
		}
		$state     = $replacement;
		$scheduled = $this->scheduler->enqueue_async(
			self::RUN_HOOK,
			array( $batch_name, $run_id, $chunk_args, $state->action_seq ),
			$batch_name . '|' . $run_id
		);
		if ( $scheduled->is_failure() ) {
			$this->terminal_transitions->fail_batch(
				$batch,
				$batch_name,
				$run_id,
				$state,
				$run_store,
				EngineError::scheduling( 'Batch', $batch_name, 'run', $scheduled->error )
			);
		}
	}

	/**
	 * Dispatches one scheduled run action to its registered task or batch.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                      $name                     Stable task or batch name.
	 * @param   string                      $run_id                   Run identifier.
	 * @param   array<array-key, mixed>|int $chunk_args_or_action_seq Batch chunk arguments or a task action sequence.
	 * @param   int|null                    $action_seq               Batch action sequence, or null for a task action.
	 *
	 * @return  void
	 */
	public function handle_run_action(
		string $name,
		string $run_id,
		array|int $chunk_args_or_action_seq,
		?int $action_seq = null
	): void {
		$chunk_args   = \is_int( $chunk_args_or_action_seq ) ? null : $chunk_args_or_action_seq;
		$received_seq = \is_int( $chunk_args_or_action_seq ) ? $chunk_args_or_action_seq : $action_seq;
		$work_type    = null === $chunk_args ? 'Task' : 'Batch';
		$run_store    = $this->stores->run_store( $name );
		$state        = $this->terminal_transitions->active_run_state( $work_type, $name, $run_id, $received_seq, $run_store );
		if ( null === $state ) {
			return;
		}

		$task  = $this->tasks->get( $name );
		$batch = $this->batches->get( $name );
		if ( null !== $task && null !== $batch ) {
			$this->logger->warning(
				'Run action name is registered as both a task and a batch; rename one registration before dispatching the action.',
				array(
					'name'   => $name,
					'run_id' => $run_id,
				)
			);
			$this->fail_orphaned_run( $work_type, $name, $run_id, $state, $run_store );

			return;
		}

		if ( null !== $task ) {
			if ( null !== $chunk_args ) {
				$this->logger->warning(
					'Task run action carries batch chunk arguments; schedule task runs with only the task name and run identifier.',
					array(
						'task_name' => $name,
						'run_id'    => $run_id,
					)
				);

				return;
			}

			$this->handle_task_run_action( $task, $name, $run_id, $state, $run_store );

			return;
		}

		if ( null !== $batch ) {
			if ( null === $chunk_args ) {
				$this->logger->warning(
					'Batch run action is missing chunk arguments; schedule it with the dequeued chunk as the third argument.',
					array(
						'batch_name' => $name,
						'run_id'     => $run_id,
					)
				);

				return;
			}

			$this->handle_batch_run_action( $batch, $name, $run_id, $chunk_args, $state, $run_store );

			return;
		}

		$this->logger->warning(
			null === $chunk_args
				? 'Task run action references an unregistered task; register the task before dispatching its run action.'
				: 'Batch run action references an unregistered batch; register the batch before dispatching its run action.',
			array(
				( null === $chunk_args ? 'task_name' : 'batch_name' ) => $name,
				'run_id' => $run_id,
			)
		);
		$this->fail_orphaned_run( $work_type, $name, $run_id, $state, $run_store );
	}

	/**
	 * Handles terminal success for one drained batch run.
	 *
	 * Once success handling begins, every remaining write touches only this run's rows, and lock release
	 * self-guards against a new owner. The outcome remains Completed regardless of current lock ownership;
	 * recording another outcome would lie.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $batch_name Stable batch name.
	 * @param   string $run_id    Run identifier.
	 * @param   int    $action_seq Expected lifecycle action sequence.
	 *
	 * @return  void
	 */
	public function handle_cleanup_action( string $batch_name, string $run_id, int $action_seq ): void {
		$run_store = $this->stores->run_store( $batch_name );
		$state     = $this->terminal_transitions->active_run_state( 'Batch', $batch_name, $run_id, $action_seq, $run_store );
		if ( null === $state ) {
			return;
		}

		$batch = $this->batch_for_action( $batch_name, $run_id, 'cleanup' );
		if ( null === $batch ) {
			$this->fail_orphaned_run( 'Batch', $batch_name, $run_id, $state, $run_store );

			return;
		}

		if ( array() !== $state->queue ) {
			$this->terminal_transitions->fail_batch(
				$batch,
				$batch_name,
				$run_id,
				$state,
				$run_store,
				new EngineError(
					\sprintf(
						'Batch "%s" reached cleanup with queued chunks; schedule cleanup only after continue observes an empty queue.',
						$batch_name
					)
				)
			);

			return;
		}

		$terminal_state = $state
			->with_status( RunStatus::Completed )
			->with_heartbeat_at( $this->clock->now()->getTimestamp() );

		// Completed listeners observe the terminal snapshot before exact cleanup deletes it and appends history.
		$this->terminal_transitions->execute_terminal_transition(
			$batch_name,
			$run_id,
			$state,
			$terminal_state,
			$run_store,
			function () use ( $batch, $batch_name, $run_id, $state ): void {
				try {
					$batch->on_success( $run_id, $state->start_args );
				} catch ( \Throwable $throwable ) {
					$this->logger->error(
						'Batch success callback failed after all chunks completed; fix the batch on_success callback.',
						array(
							'batch_name'        => $batch_name,
							'run_id'            => $run_id,
							'exception_class'   => $throwable::class,
							'exception_message' => $throwable->getMessage(),
						)
					);
				}
			},
			'completed'
		);
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
	 * Executes one task run after shared-hook dispatch.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   TaskInterface $task      Registered task.
	 * @param   string        $task_name Stable task name.
	 * @param   string        $run_id    Run identifier.
	 * @param   RunState      $state     Fenced running state.
	 * @param   RunStore      $run_store Active-run store.
	 *
	 * @return  void
	 */
	private function handle_task_run_action(
		TaskInterface $task,
		string $task_name,
		string $run_id,
		RunState $state,
		RunStore $run_store
	): void {
		try {
			$task->handle( $state->start_args );
		} catch ( \Throwable $throwable ) {
			$this->failure_lifecycle->handle_failed_attempt(
				'Task',
				$task_name,
				$run_id,
				$state,
				$run_store,
				$throwable,
				static fn (): RetryPolicy => $task->get_retry_policy(),
				function (
					RunState $failure_state,
					EngineError $error,
					int $attempts_used
				) use (
					$task_name,
					$run_id,
					$run_store
				): void {
					$this->terminal_transitions->fail_run(
						$task_name,
						$run_id,
						$failure_state,
						$run_store,
						$error,
						$attempts_used
					);
				}
			);

			return;
		}

		if ( $this->terminal_transitions->supersede_if_fence_lost( 'Task', $task_name, $run_id, $state, $run_store ) ) {
			return;
		}

		$this->terminal_transitions->complete_run( $task_name, $run_id, $state, $run_store );
	}

	/**
	 * Executes one batch chunk and schedules the next continue after a normal return.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   BatchInterface          $batch      Registered batch.
	 * @param   string                  $batch_name Stable batch name.
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $chunk_args Chunk arguments.
	 * @param   RunState                $state      Fenced running state.
	 * @param   RunStore                $run_store  Active-run store.
	 *
	 * @return  void
	 */
	private function handle_batch_run_action(
		BatchInterface $batch,
		string $batch_name,
		string $run_id,
		array $chunk_args,
		RunState $state,
		RunStore $run_store
	): void {
		$context = new BatchContext( $run_id, $state->start_args, $state->queue );
		try {
			$batch->process_chunk( $chunk_args, $context );
		} catch ( \Throwable $throwable ) {
			$this->failure_lifecycle->handle_failed_attempt(
				'Batch',
				$batch_name,
				$run_id,
				$state,
				$run_store,
				$throwable,
				static fn (): RetryPolicy => $batch->get_retry_policy(),
				function (
					RunState $failure_state,
					EngineError $error,
					int $attempts_used
				) use (
					$batch,
					$batch_name,
					$run_id,
					$run_store
				): void {
					$this->terminal_transitions->fail_batch(
						$batch,
						$batch_name,
						$run_id,
						$failure_state,
						$run_store,
						$error,
						$attempts_used
					);
				},
				$chunk_args
			);

			return;
		}

		if ( $this->terminal_transitions->supersede_if_fence_lost( 'Batch', $batch_name, $run_id, $state, $run_store ) ) {
			return;
		}

		$replacement = $state
			->with_queue( $context->get_queue() )
			->with_chunk_retries( 0 )
			->with_action_seq( $state->action_seq + 1 );
		if ( null === $run_store->transition_state( $run_id, $state, $replacement ) ) {
			return;
		}
		$state = $replacement;

		try {
			$delay = $this->lock_windows->continue_delay( $batch_name, $run_id );
		} catch ( \Throwable $throwable ) {
			if ( $this->terminal_transitions->supersede_if_fence_lost( 'Batch', $batch_name, $run_id, $state, $run_store ) ) {
				return;
			}

			$this->terminal_transitions->fail_batch(
				$batch,
				$batch_name,
				$run_id,
				$state,
				$run_store,
				EngineError::from_throwable( $throwable )
			);

			return;
		}

		if ( $this->terminal_transitions->supersede_if_fence_lost( 'Batch', $batch_name, $run_id, $state, $run_store ) ) {
			return;
		}

		$now = $this->clock->now()->getTimestamp();
		if ( $delay > \PHP_INT_MAX - $now ) {
			$this->terminal_transitions->fail_batch(
				$batch,
				$batch_name,
				$run_id,
				$state,
				$run_store,
				new EngineError(
					\sprintf(
						'Batch "%s" could not schedule the continue action because its delay exceeds supported Unix seconds; return a smaller non-negative delay from the continue-delay filter.',
						$batch_name
					)
				)
			);

			return;
		}

		$scheduled = $this->scheduler->schedule_single(
			self::CONTINUE_HOOK,
			$now + $delay,
			array( $batch_name, $run_id, $state->action_seq ),
			$batch_name . '|' . $run_id,
			10
		);
		if ( $scheduled->is_failure() ) {
			$this->terminal_transitions->fail_batch(
				$batch,
				$batch_name,
				$run_id,
				$state,
				$run_store,
				EngineError::scheduling( 'Batch', $batch_name, 'continue', $scheduled->error )
			);
		}
	}

	/**
	 * Returns a batch only when one internal action resolves unambiguously.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $batch_name Stable batch name.
	 * @param   string $run_id    Run identifier.
	 * @param   string $stage     Internal batch stage.
	 *
	 * @return  BatchInterface|null
	 */
	private function batch_for_action( string $batch_name, string $run_id, string $stage ): ?BatchInterface {
		$batch = $this->batches->get( $batch_name );
		if ( null !== $batch && null !== $this->tasks->get( $batch_name ) ) {
			$this->logger->warning(
				'Batch action name is registered as both a task and a batch; rename one registration before dispatching the action.',
				array(
					'batch_name' => $batch_name,
					'run_id'     => $run_id,
					'stage'      => $stage,
				)
			);

			return null;
		}

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
	 * Fails a live run whose task or batch registration no longer resolves unambiguously.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch' $work_type Work contract type.
	 * @param   string         $name      Stable task or batch name.
	 * @param   string         $run_id    Run identifier.
	 * @param   RunState       $state     Fenced running state.
	 * @param   RunStore       $run_store Active-run store.
	 *
	 * @return  void
	 */
	private function fail_orphaned_run(
		string $work_type,
		string $name,
		string $run_id,
		RunState $state,
		RunStore $run_store
	): void {
		$error = new EngineError(
			\sprintf(
				'%1$s name "%2$s" is no longer registered unambiguously for run "%3$s"; re-register exactly one %4$s under that name or purge the run.',
				$work_type,
				$name,
				$run_id,
				\strtolower( $work_type )
			)
		);

		$terminal_state = $state
			->with_status( RunStatus::Failed )
			->with_heartbeat_at( $this->clock->now()->getTimestamp() );

		$this->terminal_transitions->execute_terminal_transition(
			$name,
			$run_id,
			$state,
			$terminal_state,
			$run_store,
			function () use ( $error, $name, $run_id, $state ): void {
				$this->stores->failed_run_store( $name )->record(
					$run_id,
					$this->clock->now()->getTimestamp(),
					$state->start_args,
					RunState::increment_attempts_safely( $state->chunk_retries ),
					$error
				);
			},
			'failed',
			$error
		);
	}

	/**
	 * Returns a normalized queue from the queue filter boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $chunks Filtered queue value.
	 *
	 * @throws  \UnexpectedValueException When the filter does not return an array.
	 *
	 * @return  list<array<array-key, mixed>>
	 */
	private function materialize_filtered_queue( mixed $chunks ): array {
		if ( ! \is_array( $chunks ) ) {
			throw new \UnexpectedValueException(
				'Batch queue filter returned a non-array value; return one argument array per chunk.'
			);
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
	 * @throws  \UnexpectedValueException When one chunk is not an argument array.
	 *
	 * @return  list<array<array-key, mixed>>
	 */
	private function materialize_queue( iterable $chunks ): array {
		$queue = array();
		foreach ( $chunks as $chunk_args ) {
			if ( ! \is_array( $chunk_args ) ) {
				throw new \UnexpectedValueException(
					'Batch queue contains a non-array chunk; generate and filter one argument array per chunk.'
				);
			}

			$queue[] = $chunk_args;
		}

		return $queue;
	}

	// endregion
}

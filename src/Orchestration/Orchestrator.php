<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Orchestration;

use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\NonRetryableExceptionInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Support\ScalarTree;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\StoreFactory;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Coordinates registered tasks and batches from dispatch through terminal cleanup.
 *
 * Same-sequence redelivery remains at-least-once execution and relies on task and batch idempotency.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Orchestrator {
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
	 * Literal consumer lifecycle hooks keep their names greppable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, string>
	 */
	private const LIFECYCLE_HOOKS = array(
		'started'    => 'a8csp/background_tasks/started',
		'completed'  => 'a8csp/background_tasks/completed',
		'failed'     => 'a8csp/background_tasks/failed',
		'superseded' => 'a8csp/background_tasks/superseded',
	);

	/**
	 * Highest scheduler priority accepted by the orchestration API.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const MAX_PRIORITY = 255;

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
	 * Decimal width reserved for a run identifier's random suffix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const RUN_ID_RANDOM_DIGITS = 19;

	/**
	 * Decimal width reserved for a run identifier's timestamp prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const RUN_ID_TIME_DIGITS = 20;

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
	 * @param   TaskRegistry        $tasks         Registered task instances.
	 * @param   BatchRegistry       $batches       Registered batch instances.
	 * @param   BackendInterface    $scheduler     Scheduling facade boundary.
	 * @param   OverlapGuard        $overlap_guard Execution-overlap guard.
	 * @param   StoreFactory        $stores        Name-bound store factory.
	 * @param   LoggerInterface     $logger        Log event sink.
	 * @param   ClockInterface      $clock         Timestamp source.
	 * @param   LockWindows         $lock_windows  Filterable run-lock timing policy.
	 * @param   RandomizerInterface $randomizer   Run identifier randomness.
	 */
	public function __construct(
		private TaskRegistry $tasks,
		private BatchRegistry $batches,
		private BackendInterface $scheduler,
		private OverlapGuard $overlap_guard,
		private StoreFactory $stores,
		private LoggerInterface $logger,
		private ClockInterface $clock,
		private LockWindows $lock_windows,
		private RandomizerInterface $randomizer,
	) {}

	// endregion

	// region METHODS

	/**
	 * Creates and schedules one run for a registered task.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $task_name Stable task name.
	 * @param   array<array-key, mixed> $args      Task arguments.
	 * @param   int                     $delay     Scheduling delay in seconds.
	 * @param   bool                    $unique    Whether the backend retains an identical async action.
	 * @param   int                     $priority  Advisory priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
	public function enqueue(
		string $task_name,
		array $args = array(),
		int $delay = 0,
		bool $unique = false,
		int $priority = 10
	): AbstractResult {
		$result = $this->dispatch_task(
			$task_name,
			$args,
			$delay,
			$unique,
			$priority,
			OverlapPolicy::Skip
		);
		if ( $result->is_failure() ) {
			return $result;
		}

		$value = $result->value;

		return $value instanceof TaskDispatchSkipped
			? new Failure( $value->error )
			: new Success( $value );
	}

	/**
	 * Dispatches a task under the schedule overlap policy without expanding the consumer task API.
	 *
	 * Allow uses a per-run fencing identity, Skip returns a typed held outcome, and Replace transfers
	 * the shared-identity lock through the same takeover helper as batch start. Task callbacks always
	 * receive the original arguments. Manual retry of an Allow run intentionally re-enters the public
	 * unsalted enqueue path because the failed store retains only those original arguments.
	 *
	 * @internal Schedule execution only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $task_name Stable task name.
	 * @param   array<array-key, mixed> $args      Task arguments.
	 * @param   OverlapPolicy           $overlap   Schedule overlap policy.
	 * @param   int                     $priority  Advisory priority from 0 through 255.
	 * @param   \Closure|null           $on_accepted Internal callback after backend acceptance and before started hooks.
	 *
	 * @return  AbstractResult<string|TaskDispatchSkipped, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a scheduled-task dispatch failure must be handled, not dropped' )]
	public function dispatch_scheduled_task(
		string $task_name,
		array $args,
		OverlapPolicy $overlap,
		int $priority = 10,
		?\Closure $on_accepted = null
	): AbstractResult {
		return $this->dispatch_task(
			$task_name,
			$args,
			0,
			// Only Skip has a stable single-flight identity worth backend-deduplicating.
			unique: OverlapPolicy::Skip === $overlap,
			priority: $priority,
			overlap: $overlap,
			on_accepted: $on_accepted
		);
	}

	/**
	 * A non-unique start whose arguments are already running takes over the incumbent's lock, and the
	 * incumbent stops at its next fence; a unique start fails while a live incumbent holds the lock.
	 * A crash between takeover and enqueueing converges through the staleness-reclaim model.
	 *
	 * A scheduling failure after replacement ownership transfers leaves the incumbent fenced; a
	 * caller handles the returned failure by starting the batch again.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $batch_name Stable batch name.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   bool                    $unique     Whether a fresh incumbent causes Failure instead of replacement and
	 *                                              backend uniqueness is requested.
	 * @param   int                     $priority   Advisory priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a batch-start failure must be handled, not dropped' )]
	public function start_batch(
		string $batch_name,
		array $start_args = array(),
		bool $unique = false,
		int $priority = 10
	): AbstractResult {
		$batch = $this->batches->get( $batch_name );
		if ( null !== $batch && null !== $this->tasks->get( $batch_name ) ) {
			$error = EngineError::ambiguous_name( $batch_name );
			$this->logger->warning( $error->message, array( 'name' => $batch_name ) );

			return new Failure( $error );
		}

		if ( null === $batch ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Batch "%s" is not registered; register it before starting it.',
						$batch_name
					)
				)
			);
		}

		if ( 0 > $priority || self::MAX_PRIORITY < $priority ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Batch "%1$s" priority %2$d is invalid; pass a value from 0 through %3$d.',
						$batch_name,
						$priority,
						self::MAX_PRIORITY
					)
				)
			);
		}

		$args_hash = $this->args_hash( $batch_name, $start_args, 'Batch' );
		if ( $args_hash instanceof Failure ) {
			return $args_hash;
		}

		$now            = $this->clock->now()->getTimestamp();
		$run_id         = $this->run_id( $now );
		$latest_pointer = $this->stores->latest_run_pointer( $batch_name );
		$claim          = $this->overlap_guard->claim(
			$batch_name,
			$args_hash,
			$run_id,
			$this->lock_windows->lock_staleness( $batch_name, $run_id )
		);
		if ( ClaimResult::Held === $claim && $unique ) {
			$running_run_id = $this->overlap_guard->owner_run_id( $batch_name, $args_hash );

			return new Failure(
				new EngineError(
					null === $running_run_id
						? \sprintf(
							'Batch "%s" encountered a held lock whose current owner could not be read; retry the start against the current lock state.',
							$batch_name
						)
						: \sprintf(
							'Batch "%1$s" is already running as run "%2$s"; wait for that run to finish before starting the same arguments.',
							$batch_name,
							$running_run_id
						)
				)
			);
		}

		$run_store = $this->stores->run_store( $batch_name );
		$state     = $this->create_run_state_and_replace_if_held(
			'Batch',
			$batch_name,
			$run_id,
			$start_args,
			$args_hash,
			array(),
			$claim,
			$run_store
		);
		if ( $state instanceof Failure ) {
			return $state;
		}

		$latest_pointer->record( $run_id, $args_hash );
		$scheduled = $this->scheduler->enqueue_async(
			self::START_HOOK,
			array( $batch_name, $run_id, $state->action_seq ),
			$batch_name . '|' . $run_id,
			$unique,
			$priority
		);
		if ( $scheduled->is_failure() ) {
			$this->overlap_guard->release( $batch_name, $args_hash, $run_id );
			$run_store->delete( $run_id );

			return $scheduled;
		}

		$this->stores->run_history( $batch_name )->record_started( $run_id, $args_hash );

		return new Success( $run_id );
	}

	/**
	 * Starts a fresh run from one retained failed run's original arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Stable task or batch name.
	 * @param   string $run_id Retained failed-run identifier.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError> Success carries the new run identifier after
	 *          re-enqueueing; it does not report whether the work ran or succeeded.
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed( string $name, string $run_id ): AbstractResult {
		$task  = $this->tasks->get( $name );
		$batch = $this->batches->get( $name );
		if ( null !== $task && null !== $batch ) {
			$error = EngineError::ambiguous_name( $name );
			$this->logger->warning( $error->message, array( 'name' => $name ) );

			return new Failure( $error );
		}

		if ( null === $task && null === $batch ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Background-work "%s" is not registered; register the matching task or batch before retrying its failed run.',
						$name
					)
				)
			);
		}

		$failed_store = $this->stores->failed_run_store( $name );
		$entries      = $failed_store->all();
		$entry        = null;
		foreach ( $entries as $candidate ) {
			if ( $run_id === $candidate['run_id'] ) {
				$entry = $candidate;
				break;
			}
		}

		if ( null === $entry ) {
			$retained_run_ids = \array_column( $entries, 'run_id' );
			$correction       = array() === $retained_run_ids
				? 'retry a run identifier returned by the failed-run store after a terminal failure is recorded.'
				: \sprintf(
					'retry one of the retained run identifiers: "%s".',
					\implode( '", "', $retained_run_ids )
				);

			return new Failure(
				new EngineError(
					\sprintf(
						'Failed run "%1$s" for background-work "%2$s" is not retained; %3$s',
						$run_id,
						$name,
						$correction
					)
				)
			);
		}

		$result = null !== $task
			? $this->enqueue( $name, $entry['start_args'] )
			: $this->start_batch( $name, $entry['start_args'] );
		if ( $result->is_success() ) {
			$failed_store->remove( $run_id );
		}

		return $result;
	}

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
		$state     = $this->active_run_state( 'Batch', $batch_name, $run_id, $action_seq, $run_store );
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
			if ( $this->supersede_if_fence_lost( 'Batch', $batch_name, $run_id, $state, $run_store ) ) {
				return;
			}

			$this->fail_batch(
				$batch,
				$batch_name,
				$run_id,
				$state,
				$run_store,
				EngineError::from_throwable( $throwable )
			);

			return;
		}

		if ( $this->supersede_if_fence_lost( 'Batch', $batch_name, $run_id, $state, $run_store ) ) {
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
			$this->fire_lifecycle_hooks( 'started', $batch_name, $run_id, $state->start_args );
		} catch ( \Throwable $throwable ) {
			if ( $this->supersede_if_fence_lost( 'Batch', $batch_name, $run_id, $state, $run_store ) ) {
				return;
			}

			$this->fail_batch(
				$batch,
				$batch_name,
				$run_id,
				$state,
				$run_store,
				EngineError::from_throwable( $throwable )
			);

			return;
		}

		if ( $this->supersede_if_fence_lost( 'Batch', $batch_name, $run_id, $state, $run_store ) ) {
			return;
		}

		$scheduled = $this->scheduler->enqueue_async(
			self::CONTINUE_HOOK,
			array( $batch_name, $run_id, $state->action_seq ),
			$batch_name . '|' . $run_id
		);
		if ( $scheduled->is_failure() ) {
			$this->fail_batch(
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
		$state     = $this->active_run_state( 'Batch', $batch_name, $run_id, $action_seq, $run_store );
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
				$this->fail_batch(
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
			$this->fail_batch(
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
		$state        = $this->active_run_state( $work_type, $name, $run_id, $received_seq, $run_store );
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
		$state     = $this->active_run_state( 'Batch', $batch_name, $run_id, $action_seq, $run_store );
		if ( null === $state ) {
			return;
		}

		$batch = $this->batch_for_action( $batch_name, $run_id, 'cleanup' );
		if ( null === $batch ) {
			$this->fail_orphaned_run( 'Batch', $batch_name, $run_id, $state, $run_store );

			return;
		}

		if ( array() !== $state->queue ) {
			$this->fail_batch(
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
		$terminal_raw   = $this->claim_terminal_transition( $run_id, $state, $terminal_state, $run_store );
		if ( null === $terminal_raw ) {
			return;
		}

		try {
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

			// Completed listeners observe the terminal snapshot before exact cleanup deletes it and appends history.
			$this->fire_lifecycle_hooks( 'completed', $batch_name, $run_id, $state->start_args );
		} finally {
			$this->finish_terminal_run( $batch_name, $run_id, $terminal_state, $terminal_raw, $run_store );
		}
	}

	/**
	 * Reclaims a stale lock only after confirming its owning run option remains absent.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name      Stable task or batch name.
	 * @param   string $args_hash Stable argument identity.
	 * @param   string $run_id    Lock owner run identifier.
	 *
	 * @return  void
	 */
	public function reconcile_orphaned_lock( string $name, string $args_hash, string $run_id ): void {
		$run_store = $this->stores->run_store( $name );
		$snapshot  = $run_store->inspect( $run_id );
		if ( null === $snapshot && $run_store->last_inspect_failed() ) {
			return;
		}

		$state = $snapshot['state'] ?? null;
		if ( null !== $state && $args_hash === $state->args_hash ) {
			return;
		}

		if ( null !== $snapshot && null === $state ) {
			if ( ! $run_store->delete_exact( $run_id, $snapshot['raw'] ) ) {
				return;
			}

			$this->logger->warning(
				'Deleted corrupt run option while reconciling its execution-overlap lock.',
				array(
					'name'   => $name,
					'run_id' => $run_id,
				)
			);
		}

		if ( ! $this->overlap_guard->delete_stale_owned_lock(
			$name,
			$args_hash,
			$run_id,
			$this->lock_windows->lock_staleness( $name, $run_id )
		) ) {
			return;
		}

		$this->logger->warning(
			'Reclaimed stale execution-overlap lock without a valid matching run option.',
			array(
				'name'      => $name,
				'args_hash' => $args_hash,
				'run_id'    => $run_id,
			)
		);
	}

	/**
	 * Reconciles one running crash orphan or old terminal run through terminal machinery.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name           Stable task or batch name.
	 * @param   string $run_id         Run identifier.
	 * @param   int    $terminal_grace Grace before belt-and-braces terminal cleanup.
	 *
	 * @return  string|null Transferred argument identity whose foreign lock must remain as fence evidence.
	 */
	public function reconcile_run( string $name, string $run_id, int $terminal_grace ): ?string {
		$run_store = $this->stores->run_store( $name );
		$snapshot  = $run_store->inspect( $run_id );
		$state     = $snapshot['state'] ?? null;
		if ( null === $snapshot ) {
			return null;
		}
		if ( null === $state ) {
			if ( $run_store->delete_exact( $run_id, $snapshot['raw'] ) ) {
				$this->logger->warning(
					'Deleted corrupt run option during maintenance sweep.',
					array(
						'name'   => $name,
						'run_id' => $run_id,
					)
				);
			}

			return null;
		}

		if ( RunStatus::Running === $state->status ) {
			$staleness = $this->lock_windows->lock_staleness( $name, $run_id );
			$fence     = $this->overlap_guard->fence_abandoned_run(
				$name,
				$state->args_hash,
				$run_id,
				$staleness
			);
			if (
				MaintenanceFenceOutcome::Owned === $fence
				|| MaintenanceFenceOutcome::Indeterminate === $fence
			) {
				return null;
			}

			$batch     = $this->batches->get( $name );
			$work_type = null !== $batch && null === $this->tasks->get( $name ) ? 'Batch' : 'Task';
			if ( MaintenanceFenceOutcome::Transferred === $fence ) {
				// A transferred lock can appear while the incumbent is still inside its callback; a fresh run heartbeat leaves terminalization to that worker's next ownership fence.
				if ( ! $this->lock_windows->heartbeat_is_stale( $state->heartbeat_at, $staleness ) ) {
					return $state->args_hash;
				}

				$latest_run_id = $this->stores
					->latest_run_pointer( $name )
					->get_latest_for_hash( $state->args_hash );
				$this->supersede_run(
					$name,
					$run_id,
					$latest_run_id,
					$state,
					$run_store,
					$work_type,
					$snapshot['raw']
				);

				return null;
			}

			$error = new EngineError(
				\sprintf(
					'Run "%1$s" for background-work "%2$s" was failed by the maintenance crash-reclaim path because its owned lock was stale or missing.',
					$run_id,
					$name
				)
			);
			$this->logger->warning(
				'Reclaimed running run whose owned execution-overlap lock was stale or missing.',
				array(
					'name'   => $name,
					'run_id' => $run_id,
				)
			);
			$attempts = RunState::increment_attempts_safely( $state->chunk_retries );
			if ( null !== $batch && null === $this->tasks->get( $name ) ) {
				$this->fail_batch(
					$batch,
					$name,
					$run_id,
					$state,
					$run_store,
					$error,
					$attempts,
					$snapshot['raw']
				);
			} else {
				$this->fail_run(
					$name,
					$run_id,
					$state,
					$run_store,
					$error,
					$attempts,
					$snapshot['raw']
				);
			}

			return null;
		}

		$now = $this->clock->now()->getTimestamp();
		if (
			$state->heartbeat_at > \PHP_INT_MAX - $terminal_grace
			|| $now <= $state->heartbeat_at + $terminal_grace
		) {
			return null;
		}

		if ( $this->finish_terminal_run( $name, $run_id, $state, $snapshot['raw'], $run_store ) ) {
			$this->logger->warning(
				'Reclaimed old terminal run option left behind after transition cleanup.',
				array(
					'name'   => $name,
					'run_id' => $run_id,
					'status' => $state->status->value,
				)
			);
		}

		return null;
	}

	/**
	 * Registers the internal lifecycle action.
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
	 * Creates and schedules one task run under a resolved overlap policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $task_name Stable task name.
	 * @param   array<array-key, mixed> $args      Task arguments.
	 * @param   int                     $delay     Scheduling delay in seconds.
	 * @param   bool                    $unique    Whether backend uniqueness is requested.
	 * @param   int                     $priority  Advisory priority from 0 through 255.
	 * @param   OverlapPolicy           $overlap   Execution-overlap policy.
	 * @param   \Closure|null           $on_accepted Internal callback after backend acceptance and before started hooks.
	 *
	 * @return  AbstractResult<string|TaskDispatchSkipped, EngineError|SchedulingError>
	 */
	private function dispatch_task(
		string $task_name,
		array $args,
		int $delay,
		bool $unique,
		int $priority,
		OverlapPolicy $overlap,
		?\Closure $on_accepted = null
	): AbstractResult {
		$task = $this->tasks->get( $task_name );
		if ( null !== $task && null !== $this->batches->get( $task_name ) ) {
			$error = EngineError::ambiguous_name( $task_name );
			$this->logger->warning( $error->message, array( 'name' => $task_name ) );

			return new Failure( $error );
		}

		if ( null === $task ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Task "%s" is not registered; register it before enqueueing.',
						$task_name
					)
				)
			);
		}

		if ( 0 > $priority || self::MAX_PRIORITY < $priority ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Task "%1$s" priority %2$d is invalid; pass a value from 0 through %3$d.',
						$task_name,
						$priority,
						self::MAX_PRIORITY
					)
				)
			);
		}

		$args_hash = $this->args_hash( $task_name, $args );
		if ( $args_hash instanceof Failure ) {
			return $args_hash;
		}

		$now = $this->clock->now()->getTimestamp();
		if ( 0 < $delay && $delay > \PHP_INT_MAX - $now ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Task "%1$s" delay %2$d exceeds supported Unix seconds; pass a smaller delay.',
						$task_name,
						$delay
					)
				)
			);
		}

		$run_id = $this->run_id( $now );
		if ( OverlapPolicy::Allow === $overlap ) {
			// Allow gets a per-run lock identity so concurrent occurrences never contend; Held can then only mean run-id collision.
			$args_hash = \hash( 'sha256', $args_hash . '|' . $run_id );
		}

		$latest_pointer = $this->stores->latest_run_pointer( $task_name );
		$claim          = $this->overlap_guard->claim(
			$task_name,
			$args_hash,
			$run_id,
			$this->lock_windows->lock_staleness( $task_name, $run_id )
		);
		if ( ClaimResult::Held === $claim && OverlapPolicy::Skip === $overlap ) {
			$running_run_id = $this->overlap_guard->owner_run_id( $task_name, $args_hash );
			if ( null === $running_run_id ) {
				return new Failure(
					new EngineError(
						\sprintf(
							'Task "%s" could not confirm the owner of a contended overlap lock; repair database writes and retry the dispatch.',
							$task_name
						)
					)
				);
			}

			return new Success(
				new TaskDispatchSkipped(
					$running_run_id,
					EngineError::held_task( $task_name, $running_run_id )
				)
			);
		}

		if ( ClaimResult::Held === $claim && OverlapPolicy::Allow === $overlap ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Task "%1$s" generated a duplicate per-run overlap identity for run "%2$s"; retry so the run receives a fresh identifier.',
						$task_name,
						$run_id
					)
				)
			);
		}

		$run_store = $this->stores->run_store( $task_name );
		$state     = $this->create_run_state_and_replace_if_held(
			'Task',
			$task_name,
			$run_id,
			$args,
			$args_hash,
			array( $args ),
			$claim,
			$run_store
		);
		if ( $state instanceof Failure ) {
			return $state;
		}

		if ( 0 < $delay ) {
			$fire_at = $now + $delay;
			if ( ! $this->overlap_guard->heartbeat( $task_name, $args_hash, $run_id, $fire_at ) ) {
				$this->overlap_guard->release( $task_name, $args_hash, $run_id );
				$run_store->delete( $run_id );

				return new Failure(
					new EngineError(
						\sprintf(
							'Task "%s" lost lock ownership while preparing its delayed action; enqueue it again against the current lock state.',
							$task_name
						)
					)
				);
			}

			$replacement = $state->with_heartbeat_at( $fire_at );
			if ( null === $run_store->transition_state( $run_id, $state, $replacement ) ) {
				return new Failure(
					new EngineError(
						\sprintf(
							'Task "%s" lost its live run state while preparing its delayed action; retry the enqueue against the current run state.',
							$task_name
						)
					)
				);
			}
			$state = $replacement;
		}

		$latest_pointer->record( $run_id, $args_hash );
		$action_args = array( $task_name, $run_id, $state->action_seq );
		$group       = $task_name . '|' . $run_id;
		$scheduled   = 0 === $delay
			? $this->scheduler->enqueue_async( self::RUN_HOOK, $action_args, $group, $unique, $priority )
			: $this->scheduler->schedule_single( self::RUN_HOOK, $now + $delay, $action_args, $group, $priority );

		if ( $scheduled->is_failure() ) {
			$this->overlap_guard->release( $task_name, $args_hash, $run_id );
			$run_store->delete( $run_id );

			return $scheduled;
		}

		$on_accepted?->__invoke();
		$this->stores->run_history( $task_name )->record_started( $run_id, $args_hash );
		try {
			$this->fire_lifecycle_hooks( 'started', $task_name, $run_id, $args );
		} catch ( \Throwable $throwable ) {
			$error = new EngineError(
				\sprintf(
					'Task "%1$s" started listener failed: %2$s Fix the started-hook listener before enqueueing the task again.',
					$task_name,
					$throwable->getMessage()
				),
				$throwable::class
			);
			$this->fail_run( $task_name, $run_id, $state, $run_store, $error, 1 );

			return new Failure( $error );
		}

		return new Success( $run_id );
	}

	/**
	 * Persists provisional run state and transfers a held lock before returning ownership.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch'                $work_type Work contract type.
	 * @param   string                        $name      Stable task or batch name.
	 * @param   string                        $run_id    Replacement run identifier.
	 * @param   array<array-key, mixed>       $args      Start arguments.
	 * @param   string                        $args_hash Stable argument identity.
	 * @param   list<array<array-key, mixed>> $queue     Initial run queue.
	 * @param   ClaimResult                   $claim     Initial lock-claim outcome.
	 * @param   RunStore                      $run_store Active-run store.
	 *
	 * @return  RunState|Failure<EngineError>
	 */
	private function create_run_state_and_replace_if_held(
		string $work_type,
		string $name,
		string $run_id,
		array $args,
		string $args_hash,
		array $queue,
		ClaimResult $claim,
		RunStore $run_store
	): RunState|Failure {
		$state = $run_store->create( $run_id, $args, $args_hash, $queue );
		if ( null === $state ) {
			if ( ClaimResult::Held !== $claim ) {
				$this->overlap_guard->release( $name, $args_hash, $run_id );
			}

			return new Failure(
				new EngineError(
					\sprintf(
						'Run "%1$s" for %2$s "%3$s" could not be persisted; remove the conflicting run option before retrying.',
						$run_id,
						\strtolower( $work_type ),
						$name
					)
				)
			);
		}

		if ( ClaimResult::Held !== $claim ) {
			return $state;
		}

		if ( $this->overlap_guard->replace( $name, $args_hash, $run_id ) ) {
			return $state;
		}

		$run_store->delete( $run_id );

		return new Failure(
			new EngineError(
				\sprintf(
					'%1$s "%2$s" lock ownership changed while the replacement was claiming it; retry the %3$s against the current owner.',
					$work_type,
					$name,
					'Task' === $work_type ? 'dispatch' : 'start'
				)
			)
		);
	}

	/**
	 * Applies the retry decision ladder after one task or batch attempt fails.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \Closure(): RetryPolicy $policy_provider
	 * @phpstan-param \Closure(RunState, EngineError, int): void $terminal_failure
	 *
	 * @param   'Task'|'Batch'               $work_type        Work contract type.
	 * @param   string                       $name             Stable task or batch name.
	 * @param   string                       $run_id           Run identifier.
	 * @param   RunState                     $state            Fenced running state.
	 * @param   RunStore                     $run_store        Active-run store.
	 * @param   \Throwable                   $throwable        Failed attempt detail.
	 * @param   \Closure                     $policy_provider  Lazy contract-policy provider.
	 * @param   \Closure                     $terminal_failure Terminal failure transition.
	 * @param   array<array-key, mixed>|null $chunk_args       Batch chunk arguments, or null for a task.
	 *
	 * @throws  \Throwable When a terminal failure callback or lifecycle listener fails.
	 *
	 * @return  void
	 */
	private function handle_failed_attempt(
		string $work_type,
		string $name,
		string $run_id,
		RunState $state,
		RunStore $run_store,
		\Throwable $throwable,
		\Closure $policy_provider,
		\Closure $terminal_failure,
		?array $chunk_args = null
	): void {
		if ( $this->supersede_if_fence_lost( $work_type, $name, $run_id, $state, $run_store ) ) {
			return;
		}

		$attempts_used = $state->chunk_retries + 1;
		$error         = EngineError::from_throwable( $throwable );
		if ( $throwable instanceof NonRetryableExceptionInterface ) {
			$terminal_failure( $state, $error, $attempts_used );

			return;
		}

		try {
			$policy = $this->retry_policy( $name, $policy_provider() );
		} catch ( \Throwable $retry_policy_failure ) {
			if ( $this->supersede_if_fence_lost( $work_type, $name, $run_id, $state, $run_store ) ) {
				return;
			}

			$terminal_failure(
				$state,
				EngineError::retry_policy( $work_type, $name, $retry_policy_failure ),
				$attempts_used
			);

			return;
		}

		if ( $this->supersede_if_fence_lost( $work_type, $name, $run_id, $state, $run_store ) ) {
			return;
		}

		if ( $attempts_used >= $policy->max_attempts ) {
			$terminal_failure( $state, $error, $attempts_used );

			return;
		}

		$retry_failure = $this->reschedule_retry(
			$work_type,
			$name,
			$run_id,
			$state,
			$run_store,
			$policy,
			$attempts_used,
			$chunk_args
		);
		if ( null !== $retry_failure ) {
			$retry_state = $retry_failure['state'];
			if ( $this->supersede_if_fence_lost( $work_type, $name, $run_id, $retry_state, $run_store ) ) {
				return;
			}

			$terminal_failure( $retry_state, $retry_failure['error'], $attempts_used );
		}
	}

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
			$this->handle_failed_attempt(
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
					$this->fail_run(
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

		if ( $this->supersede_if_fence_lost( 'Task', $task_name, $run_id, $state, $run_store ) ) {
			return;
		}

		$this->complete_run( $task_name, $run_id, $state, $run_store );
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
			$this->handle_failed_attempt(
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
					$this->fail_batch(
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

		if ( $this->supersede_if_fence_lost( 'Batch', $batch_name, $run_id, $state, $run_store ) ) {
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
			if ( $this->supersede_if_fence_lost( 'Batch', $batch_name, $run_id, $state, $run_store ) ) {
				return;
			}

			$this->fail_batch(
				$batch,
				$batch_name,
				$run_id,
				$state,
				$run_store,
				EngineError::from_throwable( $throwable )
			);

			return;
		}

		if ( $this->supersede_if_fence_lost( 'Batch', $batch_name, $run_id, $state, $run_store ) ) {
			return;
		}

		$now = $this->clock->now()->getTimestamp();
		if ( $delay > \PHP_INT_MAX - $now ) {
			$this->fail_batch(
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
			$this->fail_batch(
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
	 * Fences and heartbeats one recoverable running state for a lifecycle action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch' $work_type Work contract type.
	 * @param   string         $name      Stable task or batch name.
	 * @param   string         $run_id    Run identifier.
	 * @param   int|null       $action_seq Received lifecycle action sequence.
	 * @param   RunStore       $run_store Active-run store.
	 *
	 * @return  RunState|null
	 */
	private function active_run_state(
		string $work_type,
		string $name,
		string $run_id,
		?int $action_seq,
		RunStore $run_store
	): ?RunState {
		$state = $run_store->get( $run_id );
		if ( null === $state ) {
			$this->log_missing_run( $name, $run_id, $work_type );

			return null;
		}

		if ( $action_seq !== $state->action_seq ) {
			$this->logger->info(
				'Stale lifecycle action delivery dropped.',
				array(
					'expected' => $state->action_seq,
					'received' => $action_seq,
					'run_id'   => $run_id,
				)
			);

			return null;
		}

		$context_name = \strtolower( $work_type ) . '_name';
		if ( RunStatus::Running !== $state->status ) {
			$this->logger->warning(
				$work_type . ' run is already terminal; allow the reconciliation sweep to finish its cleanup.',
				array(
					$context_name => $name,
					'run_id'      => $run_id,
					'status'      => $state->status->value,
				)
			);

			return null;
		}

		// A lost heartbeat CAS means a replacement or reclaim took the lock, so the run fences itself.
		if ( $this->supersede_if_fence_lost( $work_type, $name, $run_id, $state, $run_store ) ) {
			return null;
		}

		$state = $run_store->refresh_heartbeat( $run_id, $state );
		if ( null === $state ) {
			return null;
		}

		$latest_pointer = $this->stores->latest_run_pointer( $name );
		$latest_run_id  = $latest_pointer->get_latest_for_hash( $state->args_hash );

		// The lock CAS is authoritative because a bounded pointer can be evicted or lag a concurrent start commit.
		if ( $run_id !== $latest_run_id ) {
			$latest_pointer->repair_for_hash( $run_id, $state->args_hash );
		}

		return $state;
	}

	/**
	 * Transitions a run to Superseded when its owner-scoped heartbeat fence fails.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch' $work_type Work contract type.
	 * @param   string         $name      Stable task or batch name.
	 * @param   string         $run_id    Run identifier.
	 * @param   RunState       $state     Running state observed before the fence.
	 * @param   RunStore       $run_store Active-run store.
	 * @param   int|null       $at        Liveness timestamp, or null to use the current clock time.
	 *
	 * @return  bool Whether the failed fence transitioned the run to Superseded.
	 */
	private function supersede_if_fence_lost(
		string $work_type,
		string $name,
		string $run_id,
		RunState $state,
		RunStore $run_store,
		?int $at = null
	): bool {
		if ( $this->overlap_guard->heartbeat( $name, $state->args_hash, $run_id, $at ) ) {
			return false;
		}

		$latest_run_id = $this->stores
			->latest_run_pointer( $name )
			->get_latest_for_hash( $state->args_hash );
		$this->supersede_run(
			$name,
			$run_id,
			$latest_run_id,
			$state,
			$run_store,
			$work_type
		);

		return true;
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
		$terminal_raw   = $this->claim_terminal_transition( $run_id, $state, $terminal_state, $run_store );
		if ( null === $terminal_raw ) {
			return;
		}
		$this->stores->failed_run_store( $name )->record(
			$run_id,
			$this->clock->now()->getTimestamp(),
			$state->start_args,
			RunState::increment_attempts_safely( $state->chunk_retries ),
			$error
		);

		try {
			$this->fire_lifecycle_hooks( 'failed', $name, $run_id, $state->start_args, $error );
		} finally {
			$this->finish_terminal_run( $name, $run_id, $terminal_state, $terminal_raw, $run_store );
		}
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

	/**
	 * Persists one terminal batch failure before callbacks, hooks, and active-state cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   BatchInterface $batch      Failed batch.
	 * @param   string         $batch_name Stable batch name.
	 * @param   string         $run_id     Run identifier.
	 * @param   RunState       $state      Running state.
	 * @param   RunStore       $run_store  Active-run store.
	 * @param   EngineError    $error      Failure detail.
	 * @param   int|null       $attempts   Attempts consumed before failure, or null to derive the count.
	 * @param   string|null    $expected_raw Exact maintenance snapshot, or null for a live transition.
	 *
	 * @return  void
	 */
	private function fail_batch(
		BatchInterface $batch,
		string $batch_name,
		string $run_id,
		RunState $state,
		RunStore $run_store,
		EngineError $error,
		?int $attempts = null,
		?string $expected_raw = null
	): void {
		$terminal_state = $state
			->with_status( RunStatus::Failed )
			->with_heartbeat_at( $this->clock->now()->getTimestamp() );
		$terminal_raw   = $this->claim_terminal_transition(
			$run_id,
			$state,
			$terminal_state,
			$run_store,
			$expected_raw
		);
		if ( null === $terminal_raw ) {
			return;
		}
		$this->stores->failed_run_store( $batch_name )->record(
			$run_id,
			$this->clock->now()->getTimestamp(),
			$state->start_args,
			$attempts ?? RunState::increment_attempts_safely( $state->chunk_retries ),
			$error
		);

		try {
			try {
				$batch->on_failure( $run_id, $state->start_args, $error );
			} finally {
				$this->fire_lifecycle_hooks( 'failed', $batch_name, $run_id, $state->start_args, $error );
			}
		} finally {
			$this->finish_terminal_run( $batch_name, $run_id, $terminal_state, $terminal_raw, $run_store );
		}
	}

	/**
	 * Returns the SHA-256 identity of insertion-ordered JSON with preserved float fractions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name      Stable task or batch name.
	 * @param   array<array-key, mixed> $args      Start arguments.
	 * @param   'Task'|'Batch'          $work_type Work contract type.
	 *
	 * @return  string|Failure<EngineError>
	 */
	#[\NoDiscard( 'an argument-hash failure must be handled, not dropped' )]
	private function args_hash( string $name, array $args, string $work_type = 'Task' ): string|Failure {
		$encoded         = false;
		$exception_class = null;
		try {
			$encoded = \wp_json_encode( $args, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION );
		} catch ( \JsonException $exception ) {
			$exception_class = $exception::class;
		}

		if ( ! \is_string( $encoded ) || ! ScalarTree::is_valid( $args ) ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'%1$s "%2$s" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.',
						$work_type,
						$name
					),
					$exception_class
				)
			);
		}

		return \hash( 'sha256', $encoded );
	}

	/**
	 * Returns a lexically time-ordered identifier with a 63-bit random suffix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $timestamp Run creation timestamp.
	 *
	 * @return  string
	 */
	private function run_id( int $timestamp ): string {
		return \sprintf(
			'%0' . self::RUN_ID_TIME_DIGITS . 'd-%0' . self::RUN_ID_RANDOM_DIGITS . 'd',
			$timestamp,
			$this->randomizer->int( 0, \PHP_INT_MAX )
		);
	}

	/**
	 * Resolves a valid name-specific policy from the contract policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $name            Stable task or batch name.
	 * @param   RetryPolicy $contract_policy Policy supplied by the work contract.
	 *
	 * @return  RetryPolicy
	 */
	private function retry_policy( string $name, RetryPolicy $contract_policy ): RetryPolicy {
		$filtered_policy = \apply_filters(
			'a8csp/background_tasks/retry_policy/' . $name,
			$contract_policy
		);
		if ( $filtered_policy instanceof RetryPolicy ) {
			return $filtered_policy;
		}

		$this->logger->warning(
			'Retry policy filter returned an invalid value; return a RetryPolicy instance to override the contract policy.',
			array(
				'name'          => $name,
				'returned_type' => \get_debug_type( $filtered_policy ),
			)
		);

		return $contract_policy;
	}

	/**
	 * Persists retry state, fires retry hooks, and schedules the same run action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch'               $work_type  Work contract type.
	 * @param   string                       $name       Stable task or batch name.
	 * @param   string                       $run_id     Run identifier.
	 * @param   RunState                     $state      Exact persisted state before the retry transition.
	 * @param   RunStore                     $run_store  Active-run store.
	 * @param   RetryPolicy                  $policy     Resolved retry policy.
	 * @param   int                          $attempt    Consumed-attempt count.
	 * @param   array<array-key, mixed>|null $chunk_args Batch chunk arguments, or null for a task.
	 *
	 * @return  array{state: RunState, error: EngineError}|null Exact failed state and detail, or null after success or a lost fence.
	 */
	private function reschedule_retry(
		string $work_type,
		string $name,
		string $run_id,
		RunState $state,
		RunStore $run_store,
		RetryPolicy $policy,
		int $attempt,
		?array $chunk_args = null
	): ?array {
		try {
			$delay = $policy->delay_for_attempt( $attempt, $this->randomizer );
			$now   = $this->clock->now()->getTimestamp();
			if ( $delay > \PHP_INT_MAX - $now ) {
				return array(
					'state' => $state,
					'error' => new EngineError(
						\sprintf(
							'%1$s "%2$s" could not schedule the retry action because its delay exceeds supported Unix seconds; configure a smaller retry-policy delay.',
							$work_type,
							$name
						)
					),
				);
			}
		} catch ( \Throwable $throwable ) {
			return array(
				'state' => $state,
				'error' => EngineError::retry_preparation( $work_type, $name, $throwable ),
			);
		}

		$fire_at = $now + $delay;
		if ( $this->supersede_if_fence_lost( $work_type, $name, $run_id, $state, $run_store, $fire_at ) ) {
			return null;
		}

		try {
			$replacement = $state
				->with_chunk_retries( $attempt )
				->with_heartbeat_at( $fire_at )
				->with_action_seq( $state->action_seq + 1 );
			if ( null === $run_store->transition_state( $run_id, $state, $replacement ) ) {
				return null;
			}
			$state = $replacement;
			$this->fire_retrying_hooks( $name, $run_id, $state->start_args, $attempt, $delay );
		} catch ( \Throwable $throwable ) {
			return array(
				'state' => $state,
				'error' => EngineError::retry_preparation( $work_type, $name, $throwable ),
			);
		}

		if ( $this->supersede_if_fence_lost( $work_type, $name, $run_id, $state, $run_store, $fire_at ) ) {
			return null;
		}

		try {
			$action_args = array( $name, $run_id );
			if ( null !== $chunk_args ) {
				$action_args[] = $chunk_args;
			}
			$action_args[] = $state->action_seq;

			$scheduled = $this->scheduler->schedule_single(
				self::RUN_HOOK,
				$fire_at,
				$action_args,
				$name . '|' . $run_id,
				10
			);
			if ( $scheduled->is_failure() ) {
				return array(
					'state' => $state,
					'error' => EngineError::scheduling( $work_type, $name, 'retry', $scheduled->error ),
				);
			}

			return null;
		} catch ( \Throwable $throwable ) {
			return array(
				'state' => $state,
				'error' => EngineError::retry_preparation( $work_type, $name, $throwable ),
			);
		}
	}

	/**
	 * Fires the name-specific retrying hook before its generic companion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name       Stable task or batch name.
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 * @param   int                     $attempt    One-indexed number of the failed attempt.
	 * @param   int                     $delay      Delay before the next attempt in seconds.
	 *
	 * @return  void
	 */
	private function fire_retrying_hooks(
		string $name,
		string $run_id,
		array $start_args,
		int $attempt,
		int $delay
	): void {
		try {
			\do_action(
				'a8csp/background_tasks/retrying/' . $name,
				$run_id,
				$start_args,
				$attempt,
				$delay
			);
		} finally {
			\do_action(
				'a8csp/background_tasks/retrying',
				$name,
				$run_id,
				$start_args,
				$attempt,
				$delay
			);
		}
	}

	/**
	 * Marks a successful run before firing hooks and releasing its active state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $task_name Stable task name.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Running state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  void
	 */
	private function complete_run( string $task_name, string $run_id, RunState $state, RunStore $run_store ): void {
		$terminal_state = $state
			->with_chunk_retries( 0 )
			->with_status( RunStatus::Completed )
			->with_heartbeat_at( $this->clock->now()->getTimestamp() );
		$terminal_raw   = $this->claim_terminal_transition( $run_id, $state, $terminal_state, $run_store );
		if ( null === $terminal_raw ) {
			return;
		}

		try {
			$this->fire_lifecycle_hooks( 'completed', $task_name, $run_id, $terminal_state->start_args );
		} finally {
			$this->finish_terminal_run( $task_name, $run_id, $terminal_state, $terminal_raw, $run_store );
		}
	}

	/**
	 * Persists failure detail before firing hooks and releasing active state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $task_name    Stable task name.
	 * @param   string      $run_id       Run identifier.
	 * @param   RunState    $state        Running state.
	 * @param   RunStore    $run_store    Active-run store.
	 * @param   EngineError $error         Task failure detail.
	 * @param   int         $attempts_used Attempts consumed by the invocation.
	 * @param   string|null $expected_raw  Exact maintenance snapshot, or null for a live transition.
	 *
	 * @return  void
	 */
	private function fail_run(
		string $task_name,
		string $run_id,
		RunState $state,
		RunStore $run_store,
		EngineError $error,
		int $attempts_used,
		?string $expected_raw = null
	): void {
		$terminal_state = $state
			->with_status( RunStatus::Failed )
			->with_heartbeat_at( $this->clock->now()->getTimestamp() );
		$terminal_raw   = $this->claim_terminal_transition(
			$run_id,
			$state,
			$terminal_state,
			$run_store,
			$expected_raw
		);
		if ( null === $terminal_raw ) {
			return;
		}
		$this->stores->failed_run_store( $task_name )->record(
			$run_id,
			$this->clock->now()->getTimestamp(),
			$state->start_args,
			$attempts_used,
			$error
		);

		try {
			$this->fire_lifecycle_hooks( 'failed', $task_name, $run_id, $state->start_args, $error );
		} finally {
			$this->finish_terminal_run( $task_name, $run_id, $terminal_state, $terminal_raw, $run_store );
		}
	}

	/**
	 * Fences a run that no longer owns its overlap lock before hooks and active-state release.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $name          Stable task or batch name.
	 * @param   string         $run_id        Run identifier.
	 * @param   string|null    $latest_run_id Latest discoverable pointer value for the argument identity.
	 * @param   RunState       $state         Running state.
	 * @param   RunStore       $run_store     Active-run store.
	 * @param   'Task'|'Batch' $work_type     Work contract type.
	 * @param   string|null    $expected_raw  Exact maintenance snapshot, or null for a live transition.
	 *
	 * @return  void
	 */
	private function supersede_run(
		string $name,
		string $run_id,
		?string $latest_run_id,
		RunState $state,
		RunStore $run_store,
		string $work_type = 'Task',
		?string $expected_raw = null
	): void {
		$terminal_state = $state
			->with_status( RunStatus::Superseded )
			->with_heartbeat_at( $this->clock->now()->getTimestamp() );
		$terminal_raw   = $this->claim_terminal_transition(
			$run_id,
			$state,
			$terminal_state,
			$run_store,
			$expected_raw
		);
		if ( null === $terminal_raw ) {
			return;
		}
		$context_name = \strtolower( $work_type ) . '_name';
		$this->logger->info(
			'Superseded ' . \strtolower( $work_type ) . ' run after its ownership fence failed.',
			array(
				$context_name   => $name,
				'run_id'        => $run_id,
				'latest_run_id' => $latest_run_id,
			)
		);

		try {
			$this->fire_lifecycle_hooks( 'superseded', $name, $run_id, $state->start_args );
		} finally {
			$this->finish_terminal_run( $name, $run_id, $terminal_state, $terminal_raw, $run_store );
		}
	}

	/**
	 * Claims the terminal state transition only while the complete observed run still matches.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $run_id      Run identifier.
	 * @param   RunState    $expected    Complete state observed by the terminalizing path.
	 * @param   RunState    $replacement Terminal replacement state.
	 * @param   RunStore    $run_store   Active-run store.
	 * @param   string|null $expected_raw Exact pre-gate snapshot supplied by maintenance, or null.
	 *
	 * @return  string|null Exact terminal snapshot bytes for cleanup, or null after a lost fence.
	 */
	private function claim_terminal_transition(
		string $run_id,
		RunState $expected,
		RunState $replacement,
		RunStore $run_store,
		?string $expected_raw = null
	): ?string {
		return null === $expected_raw
			? $run_store->transition_state( $run_id, $expected, $replacement )
			: $run_store->transition( $run_id, $expected_raw, $replacement );
	}

	/**
	 * Exact-deletes terminal storage before appending the existing terminal-history buffer.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $name      Stable task or batch name.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Terminalizing run state.
	 * @param   string   $terminal_raw Exact terminal snapshot bytes.
	 * @param   RunStore $run_store    Active-run store.
	 *
	 * @return  bool Whether the run option was confirmed absent before history was appended.
	 */
	private function finish_terminal_run(
		string $name,
		string $run_id,
		RunState $state,
		string $terminal_raw,
		RunStore $run_store
	): bool {
		$this->overlap_guard->release( $name, $state->args_hash, $run_id );
		if ( ! $run_store->delete_exact( $run_id, $terminal_raw ) ) {
			$this->logger->error(
				'Terminal run option could not be deleted; repair WordPress option writes before cleanup retries.',
				array(
					'name'   => $name,
					'run_id' => $run_id,
					'status' => $state->status->value,
				)
			);

			return false;
		}

		$this->stores->run_history( $name )->record_completed( $run_id, $state->args_hash );

		return true;
	}

	/**
	 * Fires the name-specific lifecycle hook before its generic companion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param 'started'|'completed'|'failed'|'superseded' $event
	 *
	 * @param   string                  $event      Lifecycle event name.
	 * @param   string                  $name       Stable task or batch name.
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 * @param   EngineError|null        $error      Failure detail for a failed event.
	 *
	 * @return  void
	 */
	private function fire_lifecycle_hooks(
		string $event,
		string $name,
		string $run_id,
		array $start_args,
		?EngineError $error = null
	): void {
		$hook = self::LIFECYCLE_HOOKS[ $event ];

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Map values are full prefixed lifecycle hook literals.
		if ( null === $error ) {
			try {
				\do_action( $hook . '/' . $name, $run_id, $start_args );
			} finally {
				\do_action( $hook, $name, $run_id, $start_args );
			}

			return;
		}

		try {
			\do_action( $hook . '/' . $name, $run_id, $start_args, $error );
		} finally {
			\do_action( $hook, $name, $run_id, $start_args, $error );
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * Records the reconciliation path for missing or malformed active state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $name      Stable task or batch name.
	 * @param   string         $run_id    Run identifier.
	 * @param   'Task'|'Batch' $work_type Work contract type.
	 *
	 * @return  void
	 */
	private function log_missing_run( string $name, string $run_id, string $work_type = 'Task' ): void {
		$context_name = \strtolower( $work_type ) . '_name';
		$this->logger->warning(
			$work_type . ' run state is missing or corrupt; allow the reconciliation sweep to release any remaining lock.',
			array(
				$context_name => $name,
				'run_id'      => $run_id,
			)
		);
	}

	// endregion
}

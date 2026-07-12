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
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
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
	 * Default delay between completed batch chunks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const CONTINUE_DELAY = 60;

	/**
	 * Internal hook that resumes a batch after its inter-chunk delay.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const CONTINUE_HOOK = self::HOOK_PREFIX . 'continue';

	/**
	 * Internal hook that reconciles a terminal batch run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const CLEANUP_HOOK = self::HOOK_PREFIX . 'cleanup';

	/**
	 * Prefix for internal orchestration hooks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const HOOK_PREFIX = 'a8csp/background_tasks/';

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
	private const RUN_HOOK = self::HOOK_PREFIX . 'run';

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
	private const START_HOOK = self::HOOK_PREFIX . 'start';

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
		$task = $this->tasks->get( $task_name );
		if ( null !== $task && null !== $this->batches->get( $task_name ) ) {
			return $this->ambiguous_name_failure( $task_name );
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

		$run_id         = $this->run_id( $now );
		$latest_pointer = $this->stores->latest_run_pointer( $task_name );
		$claim          = $this->overlap_guard->claim(
			$task_name,
			$args_hash,
			$run_id,
			$this->lock_staleness( $task_name, $run_id )
		);
		if ( ClaimResult::Held === $claim ) {
			$running_run_id = $latest_pointer->get_latest_for_hash( $args_hash );

			return new Failure(
				new EngineError(
					null === $running_run_id
						? \sprintf(
							'Task "%s" has a running lock without a recoverable run identifier; reconcile the lock before enqueueing the same arguments.',
							$task_name
						)
						: \sprintf(
							'Task "%1$s" is already running as run "%2$s"; wait for that run to finish before enqueueing the same arguments.',
							$task_name,
							$running_run_id
						)
				)
			);
		}

		$run_store = $this->stores->run_store( $task_name );
		$state     = $run_store->create( $run_id, $args, $args_hash, array( $args ) );
		if ( null === $state ) {
			$this->overlap_guard->release( $task_name, $args_hash, $run_id );

			return new Failure(
				new EngineError(
					\sprintf(
						'Run "%1$s" for task "%2$s" could not be persisted; remove the conflicting run option before retrying.',
						$run_id,
						$task_name
					)
				)
			);
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
	 * Creates and schedules one run for a registered batch.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $batch_name Stable batch name.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   bool                    $unique     Whether the backend retains an identical start action.
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
			return $this->ambiguous_name_failure( $batch_name );
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
			$this->lock_staleness( $batch_name, $run_id )
		);
		if ( ClaimResult::Held === $claim ) {
			$running_run_id = $latest_pointer->get_latest_for_hash( $args_hash );

			return new Failure(
				new EngineError(
					null === $running_run_id
						? \sprintf(
							'Batch "%s" has a running lock without a recoverable run identifier; reconcile the lock before starting the same arguments.',
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
		$state     = $run_store->create( $run_id, $start_args, $args_hash, array() );
		if ( null === $state ) {
			$this->overlap_guard->release( $batch_name, $args_hash, $run_id );

			return new Failure(
				new EngineError(
					\sprintf(
						'Run "%1$s" for batch "%2$s" could not be persisted; remove the conflicting run option before retrying.',
						$run_id,
						$batch_name
					)
				)
			);
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
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed( string $name, string $run_id ): AbstractResult {
		$task  = $this->tasks->get( $name );
		$batch = $this->batches->get( $name );
		if ( null !== $task && null !== $batch ) {
			return $this->ambiguous_name_failure( $name );
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
			if ( ! $this->retains_ownership_after_callback( 'Batch', $batch_name, $run_id, $state ) ) {
				return;
			}

			$this->fail_batch(
				$batch,
				$batch_name,
				$run_id,
				$state,
				$run_store,
				new EngineError( $throwable->getMessage(), $throwable::class )
			);

			return;
		}

		if ( ! $this->retains_ownership_after_callback( 'Batch', $batch_name, $run_id, $state ) ) {
			return;
		}

		$state = $state
			->with_queue( $queue )
			->with_action_seq( $state->action_seq + 1 );
		$run_store->save( $run_id, $state );
		try {
			$this->fire_lifecycle_hooks( 'started', $batch_name, $run_id, $state->start_args );
		} catch ( \Throwable $throwable ) {
			$this->fail_batch(
				$batch,
				$batch_name,
				$run_id,
				$state,
				$run_store,
				new EngineError( $throwable->getMessage(), $throwable::class )
			);

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
				$this->scheduling_failure( $batch_name, 'continue', $scheduled->error )
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
			$state = $state->with_action_seq( $state->action_seq + 1 );
			$run_store->save( $run_id, $state );
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
					$this->scheduling_failure( $batch_name, 'cleanup', $scheduled->error )
				);
			}

			return;
		}

		$chunk_args = $state->queue[0];
		$state      = $state
			->with_queue( \array_slice( $state->queue, 1 ) )
			->with_action_seq( $state->action_seq + 1 );
		$run_store->save( $run_id, $state );
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
				$this->scheduling_failure( $batch_name, 'run', $scheduled->error )
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

		try {
			$batch->on_success( $run_id, $state->start_args );
			$this->fire_lifecycle_hooks( 'completed', $batch_name, $run_id, $state->start_args );
		} finally {
			if ( $this->retains_ownership_after_callback( 'Batch', $batch_name, $run_id, $state ) ) {
				$run_store->save( $run_id, $state->with_status( RunStatus::Completed ) );
				$this->finish_terminal_run( $batch_name, $run_id, $state, $run_store );
			}
		}
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
			if ( ! $this->retains_ownership_after_callback( 'Task', $task_name, $run_id, $state ) ) {
				return;
			}

			$attempts_used = $state->chunk_retries + 1;
			$error         = new EngineError( $throwable->getMessage(), $throwable::class );
			if ( $throwable instanceof NonRetryableExceptionInterface ) {
				$this->fail_run( $task_name, $run_id, $state, $run_store, $error, $attempts_used );

				return;
			}

			try {
				$policy = $this->retry_policy( $task_name, $task->get_retry_policy() );
			} catch ( \Throwable $retry_policy_failure ) {
				$this->fail_run(
					$task_name,
					$run_id,
					$state,
					$run_store,
					$this->retry_policy_failure( 'Task', $task_name, $retry_policy_failure ),
					$attempts_used
				);

				return;
			}

			if ( $attempts_used >= $policy->max_attempts ) {
				$this->fail_run( $task_name, $run_id, $state, $run_store, $error, $attempts_used );

				return;
			}

			$retry_state = $state->with_chunk_retries( $attempts_used );
			$retry_error = $this->reschedule_retry(
				'Task',
				$task_name,
				$run_id,
				$retry_state,
				$run_store,
				$policy
			);
			if ( null !== $retry_error ) {
				$this->fail_run(
					$task_name,
					$run_id,
					$retry_state,
					$run_store,
					$retry_error,
					$attempts_used
				);
			}

			return;
		}

		if ( ! $this->retains_ownership_after_callback( 'Task', $task_name, $run_id, $state ) ) {
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
			if ( ! $this->retains_ownership_after_callback( 'Batch', $batch_name, $run_id, $state ) ) {
				return;
			}

			$attempts_used = $state->chunk_retries + 1;
			$error         = new EngineError( $throwable->getMessage(), $throwable::class );
			if ( $throwable instanceof NonRetryableExceptionInterface ) {
				$this->fail_batch(
					$batch,
					$batch_name,
					$run_id,
					$state,
					$run_store,
					$error,
					$attempts_used
				);

				return;
			}

			try {
				$policy = $this->retry_policy( $batch_name, $batch->get_retry_policy() );
			} catch ( \Throwable $retry_policy_failure ) {
				$this->fail_batch(
					$batch,
					$batch_name,
					$run_id,
					$state,
					$run_store,
					$this->retry_policy_failure( 'Batch', $batch_name, $retry_policy_failure ),
					$attempts_used
				);

				return;
			}

			if ( $attempts_used >= $policy->max_attempts ) {
				$this->fail_batch(
					$batch,
					$batch_name,
					$run_id,
					$state,
					$run_store,
					$error,
					$attempts_used
				);

				return;
			}

			$retry_state = $state->with_chunk_retries( $attempts_used );
			$retry_error = $this->reschedule_retry(
				'Batch',
				$batch_name,
				$run_id,
				$retry_state,
				$run_store,
				$policy,
				$chunk_args
			);
			if ( null !== $retry_error ) {
				$this->fail_batch(
					$batch,
					$batch_name,
					$run_id,
					$retry_state,
					$run_store,
					$retry_error,
					$attempts_used
				);
			}

			return;
		}

		if ( ! $this->retains_ownership_after_callback( 'Batch', $batch_name, $run_id, $state ) ) {
			return;
		}

		$state = $state
			->with_queue( $context->get_queue() )
			->with_chunk_retries( 0 )
			->with_action_seq( $state->action_seq + 1 );
		$run_store->save( $run_id, $state );

		try {
			$delay = $this->continue_delay( $batch_name, $run_id );
		} catch ( \Throwable $throwable ) {
			$this->fail_batch(
				$batch,
				$batch_name,
				$run_id,
				$state,
				$run_store,
				new EngineError( $throwable->getMessage(), $throwable::class )
			);

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
						'Batch "%s" could not schedule the continue action because its delay exceeds supported Unix seconds; return a smaller positive delay from the continue-delay filter.',
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
				$this->scheduling_failure( $batch_name, 'continue', $scheduled->error )
			);
		}
	}

	/**
	 * Resolves the positive inter-chunk delay for one batch run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $batch_name Stable batch name.
	 * @param   string $run_id    Run identifier.
	 *
	 * @return  int
	 */
	private function continue_delay( string $batch_name, string $run_id ): int {
		$delay = \apply_filters(
			'a8csp/background_tasks/continue_delay',
			self::CONTINUE_DELAY,
			$batch_name,
			$run_id
		);

		return \is_int( $delay ) && 0 < $delay ? $delay : self::CONTINUE_DELAY;
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

		$latest_run_id = $this->stores
			->latest_run_pointer( $name )
			->get_latest_for_hash( $state->args_hash );
		// Bounded latest pointers routinely evict identities, so a missing pointer defers authority to the lock CAS.
		if ( null !== $latest_run_id && $run_id !== $latest_run_id ) {
			$this->supersede_run( $name, $run_id, $latest_run_id, $state, $run_store, $work_type );

			return null;
		}

		if ( ! $this->overlap_guard->heartbeat( $name, $state->args_hash, $run_id ) ) {
			$this->supersede_run( $name, $run_id, $latest_run_id, $state, $run_store, $work_type );

			return null;
		}

		$state = $run_store->refresh_heartbeat( $run_id );
		if ( null === $state ) {
			$this->log_missing_run( $name, $run_id, $work_type );

			return null;
		}

		return $state;
	}

	/**
	 * Confirms owner-scoped lock authority after a user callback returns.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch' $work_type Work contract type.
	 * @param   string         $name      Stable task or batch name.
	 * @param   string         $run_id    Run identifier.
	 * @param   RunState       $state     State observed before the callback.
	 *
	 * @return  bool Whether the same run still owns the lock.
	 */
	private function retains_ownership_after_callback(
		string $work_type,
		string $name,
		string $run_id,
		RunState $state
	): bool {
		if ( $this->overlap_guard->heartbeat( $name, $state->args_hash, $run_id ) ) {
			return true;
		}

		$this->logger->info(
			'Run ownership moved during a user callback; state commit abandoned.',
			array(
				\strtolower( $work_type ) . '_name' => $name,
				'run_id'                            => $run_id,
			)
		);

		return false;
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

		$run_store->save( $run_id, $state->with_status( RunStatus::Failed ) );
		$this->stores->failed_run_store( $name )->record(
			$run_id,
			$this->clock->now()->getTimestamp(),
			$state->start_args,
			\max( 1, $state->chunk_retries + 1 ),
			$error
		);

		try {
			$this->fire_lifecycle_hooks( 'failed', $name, $run_id, $state->start_args, $error );
		} finally {
			$this->finish_terminal_run( $name, $run_id, $state, $run_store );
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
		?int $attempts = null
	): void {
		$run_store->save( $run_id, $state->with_status( RunStatus::Failed ) );
		$this->stores->failed_run_store( $batch_name )->record(
			$run_id,
			$this->clock->now()->getTimestamp(),
			$state->start_args,
			$attempts ?? \max( 1, $state->chunk_retries + 1 ),
			$error
		);

		try {
			try {
				$batch->on_failure( $run_id, $state->start_args, $error );
			} finally {
				$this->fire_lifecycle_hooks( 'failed', $batch_name, $run_id, $state->start_args, $error );
			}
		} finally {
			$this->finish_terminal_run( $batch_name, $run_id, $state, $run_store );
		}
	}

	/**
	 * Converts a failed lifecycle schedule into terminal batch detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                     $batch_name Stable batch name.
	 * @param   'continue'|'run'|'cleanup' $stage Internal action that was not scheduled.
	 * @param   SchedulingError            $error      Scheduling failure.
	 *
	 * @return  EngineError
	 */
	private function scheduling_failure( string $batch_name, string $stage, SchedulingError $error ): EngineError {
		return new EngineError(
			\sprintf(
				'Batch "%1$s" could not schedule the %2$s action: %3$s',
				$batch_name,
				$stage,
				$error->message
			),
			SchedulingError::class
		);
	}

	/**
	 * Returns a failure that names the global background-work identity correction.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Ambiguous task and batch name.
	 *
	 * @return  Failure<EngineError>
	 */
	private function ambiguous_name_failure( string $name ): Failure {
		return new Failure(
			new EngineError(
				\sprintf(
					'Background-work name "%s" is registered as both a task and a batch; rename one registration so each name identifies exactly one type.',
					$name
				)
			)
		);
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

		if ( ! \is_string( $encoded ) || ! $this->is_scalar_tree( $args ) ) {
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
	 * Returns whether every argument leaf remains portable through option storage.
	 *
	 * JSON encoding runs first so recursive or excessively deep arrays never reach this traversal.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $values Argument values.
	 *
	 * @return  bool
	 */
	private function is_scalar_tree( array $values ): bool {
		foreach ( $values as $value ) {
			if ( \is_array( $value ) ) {
				if ( ! $this->is_scalar_tree( $value ) ) {
					return false;
				}

				continue;
			}

			if ( null !== $value && ! \is_scalar( $value ) ) {
				return false;
			}
		}

		return true;
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
	 * Resolves the per-run lock window above twice the continue delay.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Stable task or batch name.
	 * @param   string $run_id Run identifier.
	 *
	 * @return  int
	 */
	private function lock_staleness( string $name, string $run_id ): int {
		$continue_delay = $this->continue_delay( $name, $run_id );

		$default_staleness = 15 * \MINUTE_IN_SECONDS;
		$staleness         = \apply_filters(
			'a8csp/background_tasks/lock_staleness/' . $name,
			$default_staleness
		);
		if ( ! \is_int( $staleness ) || 1 > $staleness ) {
			$staleness = $default_staleness;
		}

		$floor = $continue_delay > \intdiv( \PHP_INT_MAX, 2 )
			? \PHP_INT_MAX
			: 2 * $continue_delay;

		return \max( $staleness, $floor );
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
	 * Converts a retry-policy boundary throwable into terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch' $work_type Work contract type.
	 * @param   string         $name      Stable task or batch name.
	 * @param   \Throwable     $throwable Retry-policy provider or filter failure.
	 *
	 * @return  EngineError
	 */
	private function retry_policy_failure( string $work_type, string $name, \Throwable $throwable ): EngineError {
		return new EngineError(
			\sprintf(
				'%1$s "%2$s" could not resolve the retry policy: %3$s Fix the retry policy provider or filter before retrying the failed run manually.',
				$work_type,
				$name,
				$throwable->getMessage()
			),
			$throwable::class
		);
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
	 * @param   RunState                     $state      State carrying the consumed-attempt count.
	 * @param   RunStore                     $run_store  Active-run store.
	 * @param   RetryPolicy                  $policy     Resolved retry policy.
	 * @param   array<array-key, mixed>|null $chunk_args Batch chunk arguments, or null for a task.
	 *
	 * @return  EngineError|null Terminal retry detail, or null after a successful reschedule.
	 */
	private function reschedule_retry(
		string $work_type,
		string $name,
		string $run_id,
		RunState $state,
		RunStore $run_store,
		RetryPolicy $policy,
		?array $chunk_args = null
	): ?EngineError {
		try {
			$attempt = $state->chunk_retries;
			$delay   = $policy->delay_for_attempt( $attempt, $this->randomizer );
			$now     = $this->clock->now()->getTimestamp();
			if ( $delay > \PHP_INT_MAX - $now ) {
				return new EngineError(
					\sprintf(
						'%1$s "%2$s" could not schedule the retry action because its delay exceeds supported Unix seconds; configure a smaller retry-policy delay.',
						$work_type,
						$name
					)
				);
			}

			$fire_at = $now + $delay;
			if ( ! $this->overlap_guard->heartbeat( $name, $state->args_hash, $run_id, $fire_at ) ) {
				$this->logger->info(
					'Retry reschedule dropped after run ownership moved.',
					array(
						'name'   => $name,
						'run_id' => $run_id,
					)
				);

				return null;
			}

			$state = $state
				->with_heartbeat_at( $fire_at )
				->with_action_seq( $state->action_seq + 1 );
			$run_store->save( $run_id, $state );
			$this->fire_retrying_hooks( $name, $run_id, $state->start_args, $attempt, $delay );

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
				return new EngineError(
					\sprintf(
						'%1$s "%2$s" could not schedule the retry action: %3$s',
						$work_type,
						$name,
						$scheduled->error->message
					),
					SchedulingError::class
				);
			}

			return null;
		} catch ( \Throwable $throwable ) {
			return new EngineError(
				\sprintf(
					'%1$s "%2$s" could not prepare the retry action: %3$s Fix the retry policy, randomness source, retrying hook, or scheduler before retrying the failed run manually.',
					$work_type,
					$name,
					$throwable->getMessage()
				),
				$throwable::class
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
		$state = $state->with_chunk_retries( 0 );
		$run_store->save( $run_id, $state->with_status( RunStatus::Completed ) );

		try {
			$this->fire_lifecycle_hooks( 'completed', $task_name, $run_id, $state->start_args );
		} finally {
			$this->finish_terminal_run( $task_name, $run_id, $state, $run_store );
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
	 *
	 * @return  void
	 */
	private function fail_run(
		string $task_name,
		string $run_id,
		RunState $state,
		RunStore $run_store,
		EngineError $error,
		int $attempts_used
	): void {
		$run_store->save( $run_id, $state->with_status( RunStatus::Failed ) );
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
			$this->finish_terminal_run( $task_name, $run_id, $state, $run_store );
		}
	}

	/**
	 * Fences a non-latest run before firing hooks and releasing active state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $name          Stable task or batch name.
	 * @param   string         $run_id        Run identifier.
	 * @param   string|null    $latest_run_id Latest run for the argument identity.
	 * @param   RunState       $state         Running state.
	 * @param   RunStore       $run_store     Active-run store.
	 * @param   'Task'|'Batch' $work_type     Work contract type.
	 *
	 * @return  void
	 */
	private function supersede_run(
		string $name,
		string $run_id,
		?string $latest_run_id,
		RunState $state,
		RunStore $run_store,
		string $work_type = 'Task'
	): void {
		$run_store->save( $run_id, $state->with_status( RunStatus::Superseded ) );
		$context_name = \strtolower( $work_type ) . '_name';
		$this->logger->info(
			'Superseded ' . \strtolower( $work_type ) . ' run before execution.',
			array(
				$context_name   => $name,
				'run_id'        => $run_id,
				'latest_run_id' => $latest_run_id,
			)
		);

		try {
			$this->fire_lifecycle_hooks( 'superseded', $name, $run_id, $state->start_args );
		} finally {
			$this->finish_terminal_run( $name, $run_id, $state, $run_store );
		}
	}

	/**
	 * Releases lock and run storage before appending the existing terminal-history buffer.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $name      Stable task or batch name.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Terminalizing run state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  void
	 */
	private function finish_terminal_run( string $name, string $run_id, RunState $state, RunStore $run_store ): void {
		$this->overlap_guard->release( $name, $state->args_hash, $run_id );
		$run_store->delete( $run_id );
		$this->stores->run_history( $name )->record_completed( $run_id, $state->args_hash );
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
		if ( null === $error ) {
			try {
				\do_action( 'a8csp/background_tasks/' . $event . '/' . $name, $run_id, $start_args );
			} finally {
				\do_action( 'a8csp/background_tasks/' . $event, $name, $run_id, $start_args );
			}

			return;
		}

		try {
			\do_action( 'a8csp/background_tasks/' . $event . '/' . $name, $run_id, $start_args, $error );
		} finally {
			\do_action( 'a8csp/background_tasks/' . $event, $name, $run_id, $start_args, $error );
		}
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

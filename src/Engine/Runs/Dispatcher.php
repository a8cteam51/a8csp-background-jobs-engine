<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Tasks\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Randomization\RandomizerInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Helpers\ScalarTree;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Admits registered task and batch runs to the scheduling backend.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Dispatcher {
	// region FIELDS AND CONSTANTS

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
	 * @param   ClockInterface      $clock         Timestamp source.
	 * @param   RandomizerInterface $randomizer    Run identifier randomness.
	 * @param   LoggerInterface     $logger        Log event sink.
	 * @param   LockWindows         $lock_windows  Filterable run-lock timing policy.
	 * @param   TerminalTransitions $terminal_transitions Fenced terminal-write coordinator.
	 */
	public function __construct(
		private TaskRegistry $tasks,
		private BatchRegistry $batches,
		private BackendInterface $scheduler,
		private OverlapGuard $overlap_guard,
		private StoreFactory $stores,
		private ClockInterface $clock,
		private RandomizerInterface $randomizer,
		private LoggerInterface $logger,
		private LockWindows $lock_windows,
		private TerminalTransitions $terminal_transitions,
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
			'a8csp_background_tasks/start',
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
	 * Cancels one retained run that is not executing or pending batch cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Stable task or batch name.
	 * @param   string $run_id Retained run identifier.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	public function cancel( string $name, string $run_id ): AbstractResult {
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
						'Background-work "%s" is not registered; register the matching task or batch before cancelling its run.',
						$name
					)
				)
			);
		}

		$run_store = $this->stores->run_store( $name );
		$snapshot  = $run_store->inspect( $run_id );
		if ( null === $snapshot || null === $snapshot['state'] ) {
			return $this->cancel_not_retained( $name, $run_id );
		}

		$state = $snapshot['state'];
		if ( RunStatus::Running !== $state->status ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Run "%1$s" is already terminal (%2$s); a finished run cannot be cancelled.',
						$run_id,
						$state->status->value
					)
				)
			);
		}

		if ( $state->executing ) {
			return $this->cancel_executing( $run_id );
		}

		if ( null !== $batch && array() === $state->queue && 1 < $state->action_seq ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Run "%s" has no chunks left to process; the pending cleanup completes it.',
						$run_id
					)
				)
			);
		}

		$cancelled = $this->terminal_transitions->cancel_run(
			$name,
			$run_id,
			$state,
			$run_store,
			$snapshot['raw'],
			fn () => $this->unschedule_group( $name . '|' . $run_id )
		);
		if ( $cancelled ) {
			return new Success( $run_id );
		}

		$latest = $run_store->inspect( $run_id );
		if ( null !== $latest && null !== $latest['state'] && $latest['state']->executing ) {
			return $this->cancel_executing( $run_id );
		}

		return new Failure(
			new EngineError(
				\sprintf(
					'Run "%s" changed state while the cancel was in flight; re-inspect the run before retrying.',
					$run_id
				)
			)
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the corrective failure for an absent or corrupt retained run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Stable task or batch name.
	 * @param   string $run_id Run identifier.
	 *
	 * @return  Failure<EngineError>
	 */
	private function cancel_not_retained( string $name, string $run_id ): Failure {
		return new Failure(
			new EngineError(
				\sprintf(
					'Run "%1$s" for background-work "%2$s" is not retained; nothing remains to cancel.',
					$run_id,
					$name
				)
			)
		);
	}

	/**
	 * Returns the corrective failure for a run whose admitted delivery is still executing.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @return  Failure<EngineError>
	 */
	private function cancel_executing( string $run_id ): Failure {
		return new Failure(
			new EngineError(
				\sprintf(
					'Run "%s" is executing; a run in flight completes or fails on its own.',
					$run_id
				)
			)
		);
	}

	/**
	 * Clears every pending action in one scheduler group through the facade's group-only form.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $group Per-run scheduler group.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	private function unschedule_group( string $group ): AbstractResult {
		$scheduler = $this->scheduler instanceof SchedulerFacade
			? $this->scheduler
			: new SchedulerFacade( array( $this->scheduler ) );

		return $scheduler->unschedule_group( $group );
	}

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
			? $this->scheduler->enqueue_async( 'a8csp_background_tasks/run', $action_args, $group, $unique, $priority )
			: $this->scheduler->schedule_single( 'a8csp_background_tasks/run', $now + $delay, $action_args, $group, $priority );

		if ( $scheduled->is_failure() ) {
			$this->overlap_guard->release( $task_name, $args_hash, $run_id );
			$run_store->delete( $run_id );

			return $scheduled;
		}

		$on_accepted?->__invoke();
		$this->stores->run_history( $task_name )->record_started( $run_id, $args_hash );
		try {
			$this->terminal_transitions->fire_started( $task_name, $run_id, $args );
		} catch ( \Throwable $throwable ) {
			$error = new EngineError(
				\sprintf(
					'Task "%1$s" started listener failed: %2$s Fix the started-hook listener before enqueueing the task again.',
					$task_name,
					$throwable->getMessage()
				),
				$throwable::class
			);
			$this->terminal_transitions->fail_run( $task_name, $run_id, $state, $run_store, $error, 1 );

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

	// endregion
}

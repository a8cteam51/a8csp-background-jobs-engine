<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Orchestration;

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
 * Coordinates one registered task from enqueue through terminal cleanup.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Orchestrator {
	// region FIELDS AND CONSTANTS

	private const CONTINUE_DELAY       = 60;
	private const HOOK_PREFIX          = 'a8csp/background_tasks/';
	private const MAX_PRIORITY         = 255;
	private const RUN_HOOK             = self::HOOK_PREFIX . 'run';
	private const RUN_ID_RANDOM_DIGITS = 19;
	private const RUN_ID_TIME_DIGITS   = 20;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   TaskRegistry        $tasks         Registered task instances.
	 * @param   BackendInterface    $scheduler     Scheduling facade boundary.
	 * @param   OverlapGuard        $overlap_guard Execution-overlap guard.
	 * @param   StoreFactory        $stores        Name-bound store factory.
	 * @param   LoggerInterface     $logger        Log event sink.
	 * @param   ClockInterface      $clock         Timestamp source.
	 * @param   RandomizerInterface $randomizer   Run identifier randomness.
	 */
	public function __construct(
		private TaskRegistry $tasks,
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
		if ( null === $this->tasks->get( $task_name ) ) {
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
			$this->lock_staleness( $task_name )
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
		$action_args = array( $task_name, $run_id );
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
		$this->fire_lifecycle_hooks( 'started', $task_name, $run_id, $args );

		return new Success( $run_id );
	}

	/**
	 * Handles the internal action for one scheduled run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $task_name Stable task name.
	 * @param   string $run_id    Run identifier.
	 *
	 * @return  void
	 */
	public function handle_run_action( string $task_name, string $run_id ): void {
		$task = $this->tasks->get( $task_name );
		if ( null === $task ) {
			$this->logger->warning(
				'Task run action references an unregistered task; register the task before dispatching its run action.',
				array(
					'task_name' => $task_name,
					'run_id'    => $run_id,
				)
			);

			return;
		}

		$run_store = $this->stores->run_store( $task_name );
		$state     = $run_store->get( $run_id );
		if ( null === $state ) {
			$this->log_missing_run( $task_name, $run_id );

			return;
		}
		if ( RunStatus::Running !== $state->status ) {
			$this->logger->warning(
				'Task run is already terminal; allow the reconciliation sweep to finish its cleanup.',
				array(
					'task_name' => $task_name,
					'run_id'    => $run_id,
					'status'    => $state->status->value,
				)
			);

			return;
		}

		$owns_lock = $this->overlap_guard->heartbeat( $task_name, $state->args_hash, $run_id );
		$state     = $run_store->refresh_heartbeat( $run_id );
		if ( null === $state ) {
			$this->log_missing_run( $task_name, $run_id );

			return;
		}

		$latest_run_id = $this->stores
			->latest_run_pointer( $task_name )
			->get_latest_for_hash( $state->args_hash );
		if ( ! $owns_lock || ( null !== $latest_run_id && $run_id !== $latest_run_id ) ) {
			$this->supersede_run( $task_name, $run_id, $latest_run_id, $state, $run_store );

			return;
		}

		try {
			$task->handle( $state->start_args );
		} catch ( \Throwable $throwable ) {
			$this->fail_run( $task_name, $run_id, $state, $run_store, $throwable );

			return;
		}

		$this->complete_run( $task_name, $run_id, $state, $run_store );
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
		\add_action( self::RUN_HOOK, array( $this, 'handle_run_action' ), 10, 2 );
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the SHA-256 identity of insertion-ordered JSON with preserved float fractions.
	 *
	 * @param   string                  $task_name Stable task name.
	 * @param   array<array-key, mixed> $args      Task arguments.
	 *
	 * @return  string|Failure<EngineError>
	 */
	#[\NoDiscard( 'an argument-hash failure must be handled, not dropped' )]
	private function args_hash( string $task_name, array $args ): string|Failure {
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
						'Task "%s" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.',
						$task_name
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
	 * Resolves the per-task lock window above twice the continue delay.
	 *
	 * @param   string $task_name Stable task name.
	 *
	 * @return  int
	 */
	private function lock_staleness( string $task_name ): int {
		$continue_delay = \apply_filters( 'a8csp/background_tasks/continue_delay', self::CONTINUE_DELAY );
		if ( ! \is_int( $continue_delay ) || 1 > $continue_delay ) {
			$continue_delay = self::CONTINUE_DELAY;
		}

		$default_staleness = 15 * \MINUTE_IN_SECONDS;
		$staleness         = \apply_filters(
			'a8csp/background_tasks/lock_staleness/' . $task_name,
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
	 * Marks a successful run before firing hooks and releasing its active state.
	 *
	 * @param   string   $task_name Stable task name.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Running state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  void
	 */
	private function complete_run( string $task_name, string $run_id, RunState $state, RunStore $run_store ): void {
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
	 * @param   string     $task_name Stable task name.
	 * @param   string     $run_id    Run identifier.
	 * @param   RunState   $state     Running state.
	 * @param   RunStore   $run_store Active-run store.
	 * @param   \Throwable $throwable Task failure.
	 *
	 * @return  void
	 */
	private function fail_run(
		string $task_name,
		string $run_id,
		RunState $state,
		RunStore $run_store,
		\Throwable $throwable
	): void {
		$error = new EngineError( $throwable->getMessage(), $throwable::class );
		$run_store->save( $run_id, $state->with_status( RunStatus::Failed ) );
		$this->stores->failed_run_store( $task_name )->record(
			$run_id,
			$this->clock->now()->getTimestamp(),
			$state->start_args,
			1,
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
	 * @param   string      $task_name    Stable task name.
	 * @param   string      $run_id       Run identifier.
	 * @param   string|null $latest_run_id Latest run for the argument identity.
	 * @param   RunState    $state        Running state.
	 * @param   RunStore    $run_store    Active-run store.
	 *
	 * @return  void
	 */
	private function supersede_run(
		string $task_name,
		string $run_id,
		?string $latest_run_id,
		RunState $state,
		RunStore $run_store
	): void {
		$run_store->save( $run_id, $state->with_status( RunStatus::Superseded ) );
		$this->logger->info(
			'Superseded task run before execution.',
			array(
				'task_name'     => $task_name,
				'run_id'        => $run_id,
				'latest_run_id' => $latest_run_id,
			)
		);

		try {
			$this->fire_lifecycle_hooks( 'superseded', $task_name, $run_id, $state->start_args );
		} finally {
			$this->finish_terminal_run( $task_name, $run_id, $state, $run_store );
		}
	}

	/**
	 * Releases lock and run storage before appending the existing terminal-history buffer.
	 *
	 * @param   string   $task_name Stable task name.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Terminalizing run state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  void
	 */
	private function finish_terminal_run( string $task_name, string $run_id, RunState $state, RunStore $run_store ): void {
		$this->overlap_guard->release( $task_name, $state->args_hash, $run_id );
		$run_store->delete( $run_id );
		$this->stores->run_history( $task_name )->record_completed( $run_id, $state->args_hash );
	}

	/**
	 * Fires the name-specific lifecycle hook before its generic companion.
	 *
	 * @phpstan-param 'started'|'completed'|'failed'|'superseded' $event
	 *
	 * @param   string                  $event      Lifecycle event name.
	 * @param   string                  $task_name  Stable task name.
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 * @param   EngineError|null        $error      Failure detail for a failed event.
	 *
	 * @return  void
	 */
	private function fire_lifecycle_hooks(
		string $event,
		string $task_name,
		string $run_id,
		array $start_args,
		?EngineError $error = null
	): void {
		if ( null === $error ) {
			\do_action( 'a8csp/background_tasks/' . $event . '/' . $task_name, $run_id, $start_args );
			\do_action( 'a8csp/background_tasks/' . $event, $task_name, $run_id, $start_args );

			return;
		}

		\do_action( 'a8csp/background_tasks/' . $event . '/' . $task_name, $run_id, $start_args, $error );
		\do_action( 'a8csp/background_tasks/' . $event, $task_name, $run_id, $start_args, $error );
	}

	/**
	 * Records the reconciliation path for missing or malformed active state.
	 *
	 * @param   string $task_name Stable task name.
	 * @param   string $run_id    Run identifier.
	 *
	 * @return  void
	 */
	private function log_missing_run( string $task_name, string $run_id ): void {
		$this->logger->warning(
			'Task run state is missing or corrupt; allow the reconciliation sweep to release any remaining lock.',
			array(
				'task_name' => $task_name,
				'run_id'    => $run_id,
			)
		);
	}

	// endregion
}

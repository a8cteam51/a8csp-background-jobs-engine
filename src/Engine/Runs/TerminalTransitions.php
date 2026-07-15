<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\HeartbeatOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Coordinates fenced terminal writes and active-run admission.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class TerminalTransitions {
	// region FIELDS AND CONSTANTS

	/**
	 * Literal consumer lifecycle hooks keep their names greppable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, string>
	 */
	private const LIFECYCLE_HOOKS = array(
		'started'    => 'a8csp_background_tasks/started',
		'completed'  => 'a8csp_background_tasks/completed',
		'failed'     => 'a8csp_background_tasks/failed',
		'cancelled'  => 'a8csp_background_tasks/cancelled',
		'superseded' => 'a8csp_background_tasks/superseded',
	);

	/**
	 * Required durable effects in their consumer-observable execution order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, array<'Task'|'Batch', list<string>>>
	 */
	private const TERMINAL_EFFECTS = array(
		'failed'     => array(
			'Batch' => array( 'retention', 'callbacks', 'hooks', 'history' ),
			'Task'  => array( 'retention', 'hooks', 'history' ),
		),
		'completed'  => array(
			'Batch' => array( 'callbacks', 'hooks', 'history' ),
			'Task'  => array( 'hooks', 'history' ),
		),
		'cancelled'  => array(
			'Batch' => array( 'hooks', 'history' ),
			'Task'  => array( 'hooks', 'history' ),
		),
		'superseded' => array(
			'Batch' => array( 'hooks', 'history' ),
			'Task'  => array( 'hooks', 'history' ),
		),
	);

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OverlapGuard    $overlap_guard Execution-overlap guard.
	 * @param   StoreFactory    $stores        Name-bound store factory.
	 * @param   ClockInterface  $clock         Timestamp source.
	 * @param   LockWindows     $lock_windows  Filterable run-lock timing policy.
	 * @param   LoggerInterface $logger       Log event sink.
	 */
	public function __construct(
		private OverlapGuard $overlap_guard,
		private StoreFactory $stores,
		private ClockInterface $clock,
		private LockWindows $lock_windows,
		private LoggerInterface $logger,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns the required durable effects for one terminal work kind.
	 *
	 * @internal Engine terminalization and maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunStatus      $status    Terminal run status.
	 * @param   'Task'|'Batch' $work_type Work contract type.
	 *
	 * @throws  \InvalidArgumentException When the status is not terminal or the work type is invalid.
	 *
	 * @return  list<string>
	 */
	public static function expected_effects( RunStatus $status, string $work_type ): array {
		$effects = self::TERMINAL_EFFECTS[ $status->value ][ $work_type ] ?? null;
		if ( null === $effects ) {
			throw new \InvalidArgumentException( 'Terminal effects require a terminal status and a Task or Batch work type.' );
		}

		return $effects;
	}

	/**
	 * Fences and heartbeats one recoverable running state for a lifecycle action.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param (\Closure(): int)|null $liveness_at
	 *
	 * @param   'Task'|'Batch' $work_type   Work contract type.
	 * @param   string         $name        Stable task or batch name.
	 * @param   string         $run_id      Run identifier.
	 * @param   int|null       $action_seq  Received lifecycle action sequence.
	 * @param   RunStore       $run_store   Active-run store.
	 * @param   \Closure|null  $liveness_at Lazy liveness timestamp, or null to use the current clock time.
	 *
	 * @return  RunState|null
	 */
	public function active_run_state( string $work_type, string $name, string $run_id, ?int $action_seq, RunStore $run_store, ?\Closure $liveness_at = null ): ?RunState {
		$state        = $run_store->get( $run_id );
		$context_name = \strtolower( $work_type ) . '_name';
		if ( null === $state ) {
			$this->logger->warning(
				$work_type . ' run state is missing or corrupt; allow the reconciliation sweep to release any remaining lock.',
				array(
					$context_name => $name,
					'run_id'      => $run_id,
				)
			);

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

		if (
			$state->executing
			&& ! $this->lock_windows->heartbeat_is_stale(
				$state->heartbeat_at,
				$this->lock_windows->lock_staleness( $name, $run_id )
			)
		) {
			$this->logger->debug(
				'Duplicate lifecycle action delivery dropped while the current delivery is still executing.',
				array(
					$context_name => $name,
					'run_id'      => $run_id,
					'action_seq'  => $state->action_seq,
				)
			);

			return null;
		}

		$at = null !== $liveness_at ? $liveness_at() : $this->clock->now()->getTimestamp();

		// Only confirmed lock ownership permits the delivery to refresh its run row and enter lifecycle work.
		if ( $this->abort_unless_fence_owned( $work_type, $name, $run_id, $state, $run_store, $at, $state->heartbeat_at ) ) {
			return null;
		}

		$state = $run_store->refresh_heartbeat( $run_id, $state, $at );
		if ( null === $state ) {
			return null;
		}

		$latest_pointer = $this->stores->latest_run_pointer( $name );
		$latest_run_id  = $latest_pointer->get_latest_for_hash( $state->args_hash );

		// The lock CAS is authoritative because a bounded pointer can be evicted or lag a concurrent start commit.
		if ( $run_id !== $latest_run_id && ! $latest_pointer->repair_for_hash( $run_id, $state->args_hash ) ) {
			$this->logger->warning(
				'Latest-run pointer repair failed; discovery metadata may remain stale.',
				array(
					'name'   => $name,
					'run_id' => $run_id,
				)
			);
		}

		return $state;
	}

	/**
	 * Marks a successful task before completing its durable terminal effects.
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
	public function complete_run( string $task_name, string $run_id, RunState $state, RunStore $run_store ): void {
		$terminal_state = $state
			->with_chunk_retries( 0 )
			->with_status( RunStatus::Completed )
			->with_heartbeat_at( $this->clock->now()->getTimestamp() )
			->with_pending( null );

		$this->claim_and_execute_terminal_transition( $task_name, $run_id, $state, $terminal_state, $run_store, 'Task' );
	}

	/**
	 * Marks a successful batch before completing its durable terminal effects.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   BatchInterface $batch      Completed batch.
	 * @param   string         $batch_name Stable batch name.
	 * @param   string         $run_id     Run identifier.
	 * @param   RunState       $state      Running state.
	 * @param   RunStore       $run_store  Active-run store.
	 *
	 * @return  void
	 */
	public function complete_batch( BatchInterface $batch, string $batch_name, string $run_id, RunState $state, RunStore $run_store ): void {
		$terminal_state = $state
			->with_status( RunStatus::Completed )
			->with_heartbeat_at( $this->clock->now()->getTimestamp() )
			->with_pending( null );

		$this->claim_and_execute_terminal_transition( $batch_name, $run_id, $state, $terminal_state, $run_store, 'Batch', $batch );
	}

	/**
	 * Claims a retained run as Cancelled before clearing its pending scheduler group and firing hooks.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \Closure(): mixed $clear_pending_actions
	 *
	 * @param   'Task'|'Batch' $work_type            Work contract type.
	 * @param   string         $name                 Stable task or batch name.
	 * @param   string         $run_id               Run identifier.
	 * @param   RunState       $state                Running state from the exact inspected snapshot.
	 * @param   RunStore       $run_store            Active-run store.
	 * @param   string         $expected_raw         Exact pre-cancel snapshot.
	 * @param   \Closure       $clear_pending_actions Winner-only scheduler-group clear.
	 *
	 * @return  bool Whether the cancellation transition was claimed.
	 */
	public function cancel_run( string $work_type, string $name, string $run_id, RunState $state, RunStore $run_store, string $expected_raw, \Closure $clear_pending_actions ): bool {
		$terminal_state = $state
			->with_status( RunStatus::Cancelled )
			->with_heartbeat_at( $this->clock->now()->getTimestamp() )
			->with_pending( null );
		$terminal_raw   = $this->claim_terminal_transition(
			$run_id,
			$state,
			$terminal_state,
			$run_store,
			$expected_raw,
		);
		if ( null === $terminal_raw ) {
			return false;
		}

		try {
			$clear_pending_actions();
		} finally {
			$this->execute_claimed_transition( $name, $run_id, $terminal_state, $terminal_raw, $run_store, $work_type );
		}

		return true;
	}

	/**
	 * Fails a run whose matching work contract no longer resolves.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch' $work_type  Work contract type carried by the lifecycle delivery.
	 * @param   string         $name       Stable task or batch name.
	 * @param   string         $run_id     Run identifier.
	 * @param   RunState       $state      Running state.
	 * @param   RunStore       $run_store  Active-run store.
	 * @param   EngineError    $error      Failure detail.
	 *
	 * @return  void
	 */
	public function fail_unregistered_run( string $work_type, string $name, string $run_id, RunState $state, RunStore $run_store, EngineError $error ): void {
		$attempts       = RunState::increment_attempts_safely( $state->chunk_retries );
		$terminal_state = $state
			->with_status( RunStatus::Failed )
			->with_chunk_retries( $attempts )
			->with_heartbeat_at( $this->clock->now()->getTimestamp() )
			->with_pending( null )
			->with_error(
				self::error_detail(
					$error,
					'execution',
					ApiErrorCode::UnknownWork,
					self::failed_chunk_for_state( $work_type, $state )
				)
			);

		$this->claim_and_execute_terminal_transition( $name, $run_id, $state, $terminal_state, $run_store, $work_type );
	}

	/**
	 * Persists one terminal batch failure before callbacks, hooks, and active-state cleanup.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<array-key, mixed>|null $failed_chunk
	 *
	 * @param   BatchInterface $batch      Failed batch.
	 * @param   string         $batch_name Stable batch name.
	 * @param   string         $run_id     Run identifier.
	 * @param   RunState       $state      Running state.
	 * @param   RunStore       $run_store  Active-run store.
	 * @param   EngineError    $error      Failure detail.
	 * @param   string         $stage      Terminalization stage.
	 * @param   ApiErrorCode   $code       Machine-readable cause classification.
	 * @param   array|null     $failed_chunk Batch chunk arguments for the failing chunk, or null.
	 * @param   int|null       $attempts   Attempts consumed before failure, or null to derive the count.
	 * @param   string|null    $expected_raw Exact maintenance snapshot, or null for a live transition.
	 *
	 * @return  void
	 */
	public function fail_batch( BatchInterface $batch, string $batch_name, string $run_id, RunState $state, RunStore $run_store, EngineError $error, string $stage, ApiErrorCode $code, ?array $failed_chunk = null, ?int $attempts = null, ?string $expected_raw = null ): void {
		$attempts       = $attempts ?? RunState::increment_attempts_safely( $state->chunk_retries );
		$terminal_state = $state
			->with_status( RunStatus::Failed )
			->with_chunk_retries( $attempts )
			->with_heartbeat_at( $this->clock->now()->getTimestamp() )
			->with_pending( null )
			->with_error( self::error_detail( $error, $stage, $code, $failed_chunk ) );

		$this->claim_and_execute_terminal_transition( $batch_name, $run_id, $state, $terminal_state, $run_store, 'Batch', $batch, $expected_raw );
	}

	/**
	 * Persists failure detail before firing hooks and releasing active state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<array-key, mixed>|null $failed_chunk
	 *
	 * @param   string       $task_name    Stable task name.
	 * @param   string       $run_id       Run identifier.
	 * @param   RunState     $state        Running state.
	 * @param   RunStore     $run_store    Active-run store.
	 * @param   EngineError  $error         Task failure detail.
	 * @param   int          $attempts_used Attempts consumed by the invocation.
	 * @param   string       $stage         Terminalization stage.
	 * @param   ApiErrorCode $code         Machine-readable cause classification.
	 * @param   array|null   $failed_chunk  Batch chunk arguments for the failing chunk, or null for a task.
	 * @param   string|null  $expected_raw  Exact maintenance snapshot, or null for a live transition.
	 *
	 * @return  void
	 */
	public function fail_run( string $task_name, string $run_id, RunState $state, RunStore $run_store, EngineError $error, int $attempts_used, string $stage, ApiErrorCode $code, ?array $failed_chunk = null, ?string $expected_raw = null ): void {
		$terminal_state = $state
			->with_status( RunStatus::Failed )
			->with_chunk_retries( $attempts_used )
			->with_heartbeat_at( $this->clock->now()->getTimestamp() )
			->with_pending( null )
			->with_error( self::error_detail( $error, $stage, $code, $failed_chunk ) );

		$this->claim_and_execute_terminal_transition( $task_name, $run_id, $state, $terminal_state, $run_store, 'Task', null, $expected_raw );
	}

	/**
	 * Finishes an already-claimed terminal transition only after every required effect is marked.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $name         Stable task or batch name.
	 * @param   string         $run_id       Run identifier.
	 * @param   RunState       $state        Terminalizing run state.
	 * @param   string         $terminal_raw Exact terminal snapshot bytes.
	 * @param   RunStore       $run_store    Active-run store.
	 * @param   'Task'|'Batch' $work_type    Work contract type.
	 *
	 * @return  bool Whether the run option is confirmed absent.
	 */
	public function finish_claimed_transition( string $name, string $run_id, RunState $state, string $terminal_raw, RunStore $run_store, string $work_type ): bool {
		return $this->finish_terminal_run( $name, $run_id, $state, $terminal_raw, $run_store, $work_type );
	}

	/**
	 * Replays missing effects for one already-claimed terminal transition and attempts its finish.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string              $name         Stable task or batch name.
	 * @param   string              $run_id       Run identifier.
	 * @param   RunState            $state        Terminal run state.
	 * @param   string              $terminal_raw Exact terminal snapshot bytes.
	 * @param   RunStore            $run_store    Active-run store.
	 * @param   'Task'|'Batch'      $work_type    Resolved work contract type.
	 * @param   BatchInterface|null $batch        Resolved batch, or null when no callback is available.
	 *
	 * @return  bool Whether the run option is confirmed absent.
	 */
	public function replay_terminal_run( string $name, string $run_id, RunState $state, string $terminal_raw, RunStore $run_store, string $work_type, ?BatchInterface $batch = null ): bool {
		return $this->execute_claimed_transition( $name, $run_id, $state, $terminal_raw, $run_store, $work_type, $batch );
	}

	/**
	 * Fires the started lifecycle hooks for one admitted run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name       Stable task or batch name.
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 *
	 * @return  void
	 */
	public function fire_started( string $name, string $run_id, array $start_args ): void {
		$this->fire_lifecycle_hooks( 'started', $name, $run_id, $start_args );
	}

	/**
	 * Aborts a delivery when its owner-scoped heartbeat is lost, stale, or indeterminate.
	 *
	 * Confirmed loss claims a Superseded transition. A stale delivery generation or indeterminate
	 * authoritative read leaves the running state untouched for a later delivery or the staleness
	 * sweep to resolve.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch' $work_type            Work contract type.
	 * @param   string         $name                 Stable task or batch name.
	 * @param   string         $run_id               Run identifier.
	 * @param   RunState       $state                Running state observed before the fence.
	 * @param   RunStore       $run_store            Active-run store.
	 * @param   int|null       $at                   Liveness timestamp, or null to use the current clock time.
	 * @param   int|null       $expected_heartbeat_at Expected heartbeat for one delivery generation, or null to accept any owned generation.
	 *
	 * @return  bool Whether the caller must abort this delivery.
	 */
	public function abort_unless_fence_owned( string $work_type, string $name, string $run_id, RunState $state, RunStore $run_store, ?int $at = null, ?int $expected_heartbeat_at = null ): bool {
		$outcome = $this->overlap_guard->heartbeat( $name, $state->args_hash, $run_id, $at, $expected_heartbeat_at );
		if ( HeartbeatOutcome::Owned === $outcome ) {
			return false;
		}
		if ( HeartbeatOutcome::Stale === $outcome ) {
			return true;
		}

		if ( HeartbeatOutcome::Indeterminate === $outcome ) {
			$context_name = \strtolower( $work_type ) . '_name';
			$this->logger->debug(
				$work_type . ' ownership fence is indeterminate; the delivery aborts without a terminal transition.',
				array(
					$context_name => $name,
					'run_id'      => $run_id,
				)
			);

			return true;
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
	public function supersede_run( string $name, string $run_id, ?string $latest_run_id, RunState $state, RunStore $run_store, string $work_type, ?string $expected_raw = null ): void {
		$terminal_state = $state
			->with_status( RunStatus::Superseded )
			->with_heartbeat_at( $this->clock->now()->getTimestamp() )
			->with_pending( null );
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

		$this->execute_claimed_transition( $name, $run_id, $terminal_state, $terminal_raw, $run_store, $work_type );
	}

	/**
	 * Claims a terminal state and executes only the winning transition's effects.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string              $name         Stable task or batch name.
	 * @param   string              $run_id       Run identifier.
	 * @param   RunState            $expected     Complete running state observed by the terminalizing path.
	 * @param   RunState            $replacement  Terminal replacement state.
	 * @param   RunStore            $run_store    Active-run store.
	 * @param   'Task'|'Batch'      $work_type    Work contract type.
	 * @param   BatchInterface|null $batch        Batch callback target, or null for a task or unresolved batch.
	 * @param   string|null         $expected_raw Exact maintenance snapshot, or null for a live transition.
	 *
	 * @return  bool Whether the terminal transition was claimed.
	 */
	private function claim_and_execute_terminal_transition( string $name, string $run_id, RunState $expected, RunState $replacement, RunStore $run_store, string $work_type, ?BatchInterface $batch = null, ?string $expected_raw = null ): bool {
		$terminal_raw = $this->claim_terminal_transition( $run_id, $expected, $replacement, $run_store, $expected_raw );
		if ( null === $terminal_raw ) {
			return false;
		}

		$this->execute_claimed_transition( $name, $run_id, $replacement, $terminal_raw, $run_store, $work_type, $batch );

		return true;
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
	private function claim_terminal_transition( string $run_id, RunState $expected, RunState $replacement, RunStore $run_store, ?string $expected_raw = null ): ?string {
		return null === $expected_raw
			? $run_store->transition_state( $run_id, $expected, $replacement )
			: $run_store->transition( $run_id, $expected_raw, $replacement );
	}

	/**
	 * Executes and marks every missing effect before attempting terminal cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string              $name         Stable task or batch name.
	 * @param   string              $run_id       Run identifier.
	 * @param   RunState            $state        Terminal run state.
	 * @param   string              $terminal_raw Exact terminal snapshot bytes.
	 * @param   RunStore            $run_store    Active-run store.
	 * @param   'Task'|'Batch'      $work_type    Work contract type.
	 * @param   BatchInterface|null $batch        Batch callback target, or null for a task or unresolved batch.
	 *
	 * @throws  \Throwable After the remaining effects and gated finish are attempted when an effect fails.
	 *
	 * @return  bool Whether the run option is confirmed absent.
	 */
	private function execute_claimed_transition( string $name, string $run_id, RunState $state, string $terminal_raw, RunStore $run_store, string $work_type, ?BatchInterface $batch = null ): bool {
		$expected       = self::expected_effects( $state->status, $work_type );
		$missing        = \array_values( \array_diff( $expected, $state->effects ) );
		$failure_detail = RunStatus::Failed === $state->status && array() !== \array_intersect( array( 'retention', 'callbacks', 'hooks' ), $missing )
			? $this->failure_detail( $name, $run_id, $state, $work_type )
			: null;
		$snapshot       = array(
			'raw'   => $terminal_raw,
			'state' => $state,
		);
		$effect_failure = null;

		foreach ( $expected as $effect ) {
			$current = $snapshot['state'];
			if ( \in_array( $effect, $current->effects, true ) ) {
				continue;
			}

			try {
				$landed = $this->execute_terminal_effect( $effect, $name, $run_id, $current, $work_type, $batch, $failure_detail );
			} catch ( \Throwable $throwable ) {
				$effect_failure ??= $throwable;
				$refreshed        = $this->refresh_terminal_snapshot( $run_id, $state->status, $run_store );
				if ( ! \is_array( $refreshed ) ) {
					throw $effect_failure;
				}

				$snapshot = $refreshed;
				continue;
			}

			if ( ! $landed ) {
				$refreshed = $this->refresh_terminal_snapshot( $run_id, $state->status, $run_store );
				if ( null === $refreshed ) {
					if ( null !== $effect_failure ) {
						throw $effect_failure;
					}

					return true;
				}
				if ( false === $refreshed ) {
					if ( null !== $effect_failure ) {
						throw $effect_failure;
					}

					return false;
				}

				$snapshot = $refreshed;
				continue;
			}

			$updated = $run_store->append_terminal_effect( $run_id, $current, $snapshot['raw'], $effect );
			if ( null === $updated ) {
				if ( null !== $effect_failure ) {
					throw $effect_failure;
				}

				return false;
			}

			$snapshot = $updated;
		}

		$finished = $this->finish_terminal_run( $name, $run_id, $snapshot['state'], $snapshot['raw'], $run_store, $work_type );
		if ( null !== $effect_failure ) {
			throw $effect_failure;
		}

		return $finished;
	}

	/**
	 * Re-reads terminal progress before a worker continues after an effect did not land.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string    $run_id   Run identifier.
	 * @param   RunStatus $status   Claimed terminal status.
	 * @param   RunStore  $run_store Active-run store.
	 *
	 * @return  array{raw: string, state: RunState}|false|null Current terminal snapshot, false when it cannot be trusted, or null when another worker finished it.
	 */
	private function refresh_terminal_snapshot( string $run_id, RunStatus $status, RunStore $run_store ): array|false|null {
		$inspected = $run_store->inspect( $run_id );
		if ( $inspected->is_failure() ) {
			return false;
		}

		$snapshot = $inspected->value;
		if ( null === $snapshot ) {
			return null;
		}

		$state = $snapshot['state'];
		if ( null === $state || $status !== $state->status ) {
			return false;
		}

		return array(
			'raw'   => $snapshot['raw'],
			'state' => $state,
		);
	}

	/**
	 * Executes one terminal effect and reports whether its durable outcome landed.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{error: EngineError, failure: RunFailure}|null $failure_detail
	 *
	 * @param   string              $effect    Terminal effect key.
	 * @param   string              $name      Stable task or batch name.
	 * @param   string              $run_id    Run identifier.
	 * @param   RunState            $state     Current terminal state.
	 * @param   'Task'|'Batch'      $work_type Work contract type.
	 * @param   BatchInterface|null $batch     Batch callback target, or null for a task or unresolved batch.
	 * @param   array|null          $failure_detail Reconstructed internal and consumer failure detail.
	 *
	 * @throws  \LogicException When the effect table contains an unsupported key.
	 * @throws  \Throwable      When an effect cannot complete.
	 *
	 * @return  bool Whether the effect landed and may be marked complete.
	 */
	private function execute_terminal_effect( string $effect, string $name, string $run_id, RunState $state, string $work_type, ?BatchInterface $batch, ?array $failure_detail ): bool {
		return match ( $effect ) {
			'retention' => $this->record_failed_run( $name, $run_id, $state, $work_type, $failure_detail ),
			'callbacks' => $this->fire_batch_callback( $name, $run_id, $state, $batch, $failure_detail['failure'] ?? null ),
			'hooks'     => $this->fire_terminal_hooks( $name, $run_id, $state, $failure_detail['failure'] ?? null ),
			'history'   => $this->record_terminal_history( $name, $run_id, $state ),
			default     => throw new \LogicException( 'The terminal effect table contains an unsupported effect key.' ),
		};
	}

	/**
	 * Persists one idempotent manual-retry entry for a failed run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{error: EngineError, failure: RunFailure}|null $failure_detail
	 *
	 * @param   string         $name           Stable task or batch name.
	 * @param   string         $run_id         Run identifier.
	 * @param   RunState       $state          Failed terminal state.
	 * @param   'Task'|'Batch' $work_type      Work contract type.
	 * @param   array|null     $failure_detail Reconstructed internal and consumer failure detail.
	 *
	 * @throws  \LogicException When failure detail is absent.
	 *
	 * @return  bool Whether the failed-run entry is confirmed persisted.
	 */
	private function record_failed_run( string $name, string $run_id, RunState $state, string $work_type, ?array $failure_detail ): bool {
		if ( null === $failure_detail ) {
			throw new \LogicException( 'Failed-run retention requires persisted terminal failure detail.' );
		}

		$retained = $this->stores->failed_run_store( $name )->record(
			$run_id,
			$state->heartbeat_at,
			$state->start_args,
			$failure_detail['failure']->attempts,
			$failure_detail['error'],
			$failure_detail['failure']
		);
		if ( $retained ) {
			return true;
		}

		$context_name = \strtolower( $work_type ) . '_name';
		$this->logger->warning(
			\sprintf( 'Failed run "%s" could not be retained for manual retry.', $run_id ),
			array(
				$context_name => $name,
				'run_id'      => $run_id,
			)
		);

		return false;
	}

	/**
	 * Fires one successful or failed batch callback with its established throwable policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string              $name   Stable batch name.
	 * @param   string              $run_id Run identifier.
	 * @param   RunState            $state  Terminal batch state.
	 * @param   BatchInterface|null $batch  Registered batch, or null when the callback must be skipped.
	 * @param   RunFailure|null     $failure Reconstructed consumer failure value.
	 *
	 * @throws  \LogicException When the state cannot support a batch callback.
	 * @throws  \Throwable      When a failed callback fails.
	 *
	 * @return  true
	 */
	private function fire_batch_callback( string $name, string $run_id, RunState $state, ?BatchInterface $batch, ?RunFailure $failure ): bool {
		if ( null === $batch ) {
			$this->logger->warning(
				'Terminal batch callback was skipped because the batch is no longer registered unambiguously.',
				array(
					'batch_name' => $name,
					'run_id'     => $run_id,
					'status'     => $state->status->value,
				)
			);

			return true;
		}

		if ( RunStatus::Completed === $state->status ) {
			try {
				$batch->on_success( $run_id, $state->start_args );
			} catch ( \Throwable $throwable ) {
				$this->logger->error(
					'Batch success callback failed after all chunks completed; fix the batch on_success callback.',
					array(
						'batch_name' => $name,
						'run_id'     => $run_id,
						'exception'  => $throwable,
					)
				);
			}

			return true;
		}

		if ( RunStatus::Failed !== $state->status || null === $failure ) {
			throw new \LogicException( 'Batch failure callbacks require a failed terminal state and failure detail.' );
		}

		$batch->on_failure( $run_id, $state->start_args, $failure );

		return true;
	}

	/**
	 * Fires the lifecycle-hook pair for one terminal state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string          $name    Stable task or batch name.
	 * @param   string          $run_id  Run identifier.
	 * @param   RunState        $state   Terminal run state.
	 * @param   RunFailure|null $failure Reconstructed consumer failure value.
	 *
	 * @throws  \LogicException When the state is not terminal.
	 * @throws  \Throwable      When a lifecycle hook fails.
	 *
	 * @return  true
	 */
	private function fire_terminal_hooks( string $name, string $run_id, RunState $state, ?RunFailure $failure ): bool {
		$event = match ( $state->status ) {
			RunStatus::Completed  => 'completed',
			RunStatus::Failed     => 'failed',
			RunStatus::Cancelled  => 'cancelled',
			RunStatus::Superseded => 'superseded',
			RunStatus::Running    => throw new \LogicException( 'Terminal hooks require a terminal run state.' ),
		};
		$this->fire_lifecycle_hooks( $event, $name, $run_id, $state->start_args, $failure );

		return true;
	}

	/**
	 * Persists one idempotent terminal-history entry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $name   Stable task or batch name.
	 * @param   string   $run_id Run identifier.
	 * @param   RunState $state  Terminal run state.
	 *
	 * @return  bool Whether the history entry is confirmed persisted.
	 */
	private function record_terminal_history( string $name, string $run_id, RunState $state ): bool {
		if ( $this->stores->run_history( $name )->record_terminal( $run_id, $state->args_hash, $state->status ) ) {
			return true;
		}

		$this->logger->warning(
			'Terminal run history could not be persisted; inspection data may be incomplete.',
			array(
				'name'   => $name,
				'run_id' => $run_id,
			)
		);

		return false;
	}

	/**
	 * Reconstructs persisted internal and consumer terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $name      Stable task or batch name.
	 * @param   string         $run_id    Run identifier.
	 * @param   RunState       $state     Failed terminal state.
	 * @param   'Task'|'Batch' $work_type Work contract type.
	 *
	 * @return  array{error: EngineError, failure: RunFailure}
	 */
	private function failure_detail( string $name, string $run_id, RunState $state, string $work_type ): array {
		if ( null !== $state->error ) {
			$error = new EngineError( $state->error['message'], $state->error['class'] );

			return array(
				'error'   => $error,
				'failure' => new RunFailure(
					name: $name,
					run_id: $run_id,
					attempts: \max( 1, $state->chunk_retries ),
					stage: $state->error['stage'],
					// Store read-validation guarantees the persisted code backs a known case, so from() cannot throw here.
					code: ApiErrorCode::from( $state->error['code'] ),
					summary: $error->message,
					failed_chunk: $state->error['failed_chunk'] ?? null,
				),
			);
		}

		$this->logger->warning(
			'Failed terminal run has no persisted failure detail; replay uses a generic failure.',
			array(
				'name'   => $name,
				'run_id' => $run_id,
			)
		);

		$error = new EngineError(
			\sprintf(
				'Run "%1$s" for background-work "%2$s" failed before recoverable terminal detail was persisted.',
				$run_id,
				$name
			)
		);

		return array(
			'error'   => $error,
			'failure' => new RunFailure(
				name: $name,
				run_id: $run_id,
				attempts: RunState::increment_attempts_safely( $state->chunk_retries ),
				stage: 'crash-reclaim',
				code: ApiErrorCode::StorageFailure,
				summary: $error->message,
				failed_chunk: self::failed_chunk_for_state( $work_type, $state ),
			),
		);
	}

	/**
	 * Converts failure detail to the persisted terminal shape.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   EngineError                  $error        Failure detail.
	 * @param   string                       $stage        Terminalization stage.
	 * @param   ApiErrorCode                 $code         Machine-readable cause classification.
	 * @param   array<array-key, mixed>|null $failed_chunk Batch chunk arguments for the failing chunk, or null.
	 *
	 * @return  array{class: string|null, message: string, stage: string, code: string, failed_chunk?: array<array-key, mixed>}
	 */
	private static function error_detail( EngineError $error, string $stage, ApiErrorCode $code, ?array $failed_chunk ): array {
		$detail = array(
			'class'   => $error->exception_class,
			'message' => $error->message,
			'stage'   => $stage,
			'code'    => $code->value,
		);
		if ( null !== $failed_chunk ) {
			$detail['failed_chunk'] = $failed_chunk;
		}

		return $detail;
	}

	/**
	 * Returns the queued chunk associated with a batch run action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch' $work_type Work contract type.
	 * @param   RunState       $state     Run state at terminalization.
	 *
	 * @return  array<array-key, mixed>|null
	 */
	private static function failed_chunk_for_state( string $work_type, RunState $state ): ?array {
		if ( 'Batch' !== $work_type || 'run' !== ( $state->pending['stage'] ?? null ) ) {
			return null;
		}

		$chunk = $state->queue[0] ?? null;

		return \is_array( $chunk ) ? $chunk : null;
	}

	/**
	 * Releases owned overlap state and exact-deletes only a fully effected terminal row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $name         Stable task or batch name.
	 * @param   string         $run_id       Run identifier.
	 * @param   RunState       $state        Terminal run state.
	 * @param   string         $terminal_raw Exact terminal snapshot bytes.
	 * @param   RunStore       $run_store    Active-run store.
	 * @param   'Task'|'Batch' $work_type    Work contract type.
	 *
	 * @return  bool Whether the run option is confirmed absent.
	 */
	private function finish_terminal_run( string $name, string $run_id, RunState $state, string $terminal_raw, RunStore $run_store, string $work_type ): bool {
		$this->overlap_guard->release( $name, $state->args_hash, $run_id );
		if ( array() !== \array_values( \array_diff( self::expected_effects( $state->status, $work_type ), $state->effects ) ) ) {
			return false;
		}

		if ( $run_store->delete_exact( $run_id, $terminal_raw ) ) {
			return true;
		}

		$inspected = $run_store->inspect( $run_id );
		if ( ! $inspected->is_failure() ) {
			$snapshot = $inspected->value;
			if ( null === $snapshot ) {
				return true;
			}
			if ( $terminal_raw !== $snapshot['raw'] ) {
				return false;
			}
		}

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

	/**
	 * Fires the name-specific lifecycle hook before its generic companion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param 'started'|'completed'|'failed'|'cancelled'|'superseded' $event
	 *
	 * @param   string                  $event      Lifecycle event name.
	 * @param   string                  $name       Stable task or batch name.
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 * @param   RunFailure|null         $failure    Failure detail for a failed event.
	 *
	 * @return  void
	 */
	private function fire_lifecycle_hooks( string $event, string $name, string $run_id, array $start_args, ?RunFailure $failure = null ): void {
		$hook = self::LIFECYCLE_HOOKS[ $event ];

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Map values are full prefixed lifecycle hook literals.
		if ( null === $failure ) {
			try {
				\do_action( $hook . '/' . $name, $run_id, $start_args );
			} finally {
				\do_action( $hook, $name, $run_id, $start_args );
			}

			return;
		}

		try {
			\do_action( $hook . '/' . $name, $run_id, $start_args, $failure );
		} finally {
			\do_action( $hook, $name, $run_id, $start_args, $failure );
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	// endregion
}

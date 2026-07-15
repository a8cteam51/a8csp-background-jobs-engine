<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
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
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OverlapGuard    $overlap_guard    Execution-overlap guard.
	 * @param   StoreFactory    $stores           Name-bound store factory.
	 * @param   ClockInterface  $clock            Timestamp source.
	 * @param   LockWindows     $lock_windows     Filterable run-lock timing policy.
	 * @param   LoggerInterface $logger           Log event sink.
	 * @param   TerminalEffects $terminal_effects Claimed terminal-effect executor.
	 */
	public function __construct( private OverlapGuard $overlap_guard, private StoreFactory $stores, private ClockInterface $clock, private LockWindows $lock_windows, private LoggerInterface $logger, private TerminalEffects $terminal_effects ) {}

	// endregion

	// region METHODS

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
	 * @param   string         $name        Complete owner-qualified task or batch identity.
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
			&& ! $this->lock_windows->heartbeat_is_stale( $state->heartbeat_at, $this->lock_windows->lock_staleness( $name, $run_id ) )
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

		$state = $run_store->mark_executing_with_heartbeat( $run_id, $state, $at );
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
	 * @param   string   $task_name Complete owner-qualified task identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Running state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  void
	 */
	public function complete_run( string $task_name, string $run_id, RunState $state, RunStore $run_store ): void {
		$terminal_state = $state->with_failed_attempts( 0 )->with_status( RunStatus::Completed )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null );

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
	 * @param   string         $batch_name Complete owner-qualified batch identity.
	 * @param   string         $run_id     Run identifier.
	 * @param   RunState       $state      Running state.
	 * @param   RunStore       $run_store  Active-run store.
	 *
	 * @return  void
	 */
	public function complete_batch( BatchInterface $batch, string $batch_name, string $run_id, RunState $state, RunStore $run_store ): void {
		$terminal_state = $state->with_status( RunStatus::Completed )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null );

		$this->claim_and_execute_terminal_transition( $batch_name, $run_id, $state, $terminal_state, $run_store, 'Batch', $batch );
	}

	/**
	 * Claims a retained run as Cancelled before clearing its pending scheduler group and firing hooks.
	 *
	 * The scheduler-group clear is best-effort. The cancelled state fences later delivery, so any
	 * leftover action is dropped when it observes the terminal run.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \Closure(): mixed $clear_pending_actions
	 *
	 * @param   'Task'|'Batch' $work_type            Work contract type.
	 * @param   string         $name                 Complete owner-qualified task or batch identity.
	 * @param   string         $run_id               Run identifier.
	 * @param   RunState       $state                Running state from the exact inspected snapshot.
	 * @param   RunStore       $run_store            Active-run store.
	 * @param   string         $expected_raw         Exact pre-cancel snapshot.
	 * @param   \Closure       $clear_pending_actions Winner-only scheduler-group clear.
	 *
	 * @return  bool Whether the cancellation transition was claimed.
	 */
	public function cancel_run( string $work_type, string $name, string $run_id, RunState $state, RunStore $run_store, string $expected_raw, \Closure $clear_pending_actions ): bool {
		$terminal_state = $state->with_status( RunStatus::Cancelled )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null );
		$terminal_raw   = $this->claim_terminal_transition( $run_id, $state, $terminal_state, $run_store, $expected_raw, );
		if ( null === $terminal_raw ) {
			return false;
		}

		try {
			$clear_pending_actions();
		} finally {
			$this->terminal_effects->execute_claimed_transition( $name, $run_id, $terminal_state, $terminal_raw, $run_store, $work_type );
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
	 * @param   string         $name       Complete owner-qualified task or batch identity.
	 * @param   string         $run_id     Run identifier.
	 * @param   RunState       $state      Running state.
	 * @param   RunStore       $run_store  Active-run store.
	 * @param   EngineError    $error      Failure detail.
	 *
	 * @return  void
	 */
	public function fail_unregistered_run( string $work_type, string $name, string $run_id, RunState $state, RunStore $run_store, EngineError $error ): void {
		$attempts       = RunState::increment_attempts_safely( $state->failed_attempts );
		$terminal_state = $state->with_status( RunStatus::Failed )->with_failed_attempts( $attempts )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null )->with_error( self::error_detail( $error, 'execution', ApiErrorCode::UnknownWork, self::failed_chunk_for_state( $work_type, $state ) ) );

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
	 * @param   string         $batch_name Complete owner-qualified batch identity.
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
		$attempts       = $attempts ?? RunState::increment_attempts_safely( $state->failed_attempts );
		$terminal_state = $state->with_status( RunStatus::Failed )->with_failed_attempts( $attempts )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null )->with_error( self::error_detail( $error, $stage, $code, $failed_chunk ) );

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
	 * @param   string       $task_name    Complete owner-qualified task identity.
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
		$terminal_state = $state->with_status( RunStatus::Failed )->with_failed_attempts( $attempts_used )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null )->with_error( self::error_detail( $error, $stage, $code, $failed_chunk ) );

		$this->claim_and_execute_terminal_transition( $task_name, $run_id, $state, $terminal_state, $run_store, 'Task', null, $expected_raw );
	}

	/**
	 * Aborts a delivery when its owner-scoped heartbeat is lost, generation-mismatched, or indeterminate.
	 *
	 * Confirmed loss attempts a Superseded transition and always aborts the delivery; a rival terminal
	 * compare-and-swap can prevent that transition from being claimed. A mismatched delivery generation or
	 * indeterminate authoritative read leaves the running state untouched for a later delivery or the
	 * staleness sweep to resolve.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch' $work_type            Work contract type.
	 * @param   string         $name                 Complete owner-qualified task or batch identity.
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
		if ( HeartbeatOutcome::GenerationMismatch === $outcome ) {
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

		$latest_run_id = $this->stores->latest_run_pointer( $name )->get_latest_for_hash( $state->args_hash );
		$this->supersede_run( $name, $run_id, $latest_run_id, $state, $run_store, $work_type );

		return true;
	}

	/**
	 * Fences a run that no longer owns its overlap lock before hooks and active-state release.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $name          Complete owner-qualified task or batch identity.
	 * @param   string         $run_id        Run identifier.
	 * @param   string|null    $latest_run_id Latest discoverable pointer value for the single-flight identity.
	 * @param   RunState       $state         Running state.
	 * @param   RunStore       $run_store     Active-run store.
	 * @param   'Task'|'Batch' $work_type     Work contract type.
	 * @param   string|null    $expected_raw  Exact maintenance snapshot, or null for a live transition.
	 *
	 * @return  void
	 */
	public function supersede_run( string $name, string $run_id, ?string $latest_run_id, RunState $state, RunStore $run_store, string $work_type, ?string $expected_raw = null ): void {
		$terminal_state = $state->with_status( RunStatus::Superseded )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null );
		$terminal_raw   = $this->claim_terminal_transition( $run_id, $state, $terminal_state, $run_store, $expected_raw );
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

		$this->terminal_effects->execute_claimed_transition( $name, $run_id, $terminal_state, $terminal_raw, $run_store, $work_type );
	}

	/**
	 * Claims a terminal state and executes only the winning transition's effects.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string              $name         Complete owner-qualified task or batch identity.
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

		$this->terminal_effects->execute_claimed_transition( $name, $run_id, $replacement, $terminal_raw, $run_store, $work_type, $batch );

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
		if ( 'Batch' !== $work_type || 'run' !== $state->pending?->stage ) {
			return null;
		}

		$chunk = $state->queue[0] ?? null;

		return \is_array( $chunk ) ? $chunk : null;
	}

	// endregion
}

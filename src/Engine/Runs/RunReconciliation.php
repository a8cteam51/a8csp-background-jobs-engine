<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\MaintenanceFenceOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\RedriveFenceOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\WorkRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Repairs stale locks, crashed runs, and incomplete terminal cleanup.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class RunReconciliation {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OverlapGuard        $overlap_guard        Execution-overlap guard.
	 * @param   StoreFactory        $stores               Name-bound store factory.
	 * @param   ClockInterface      $clock                Timestamp source.
	 * @param   LoggerInterface     $logger               Log event sink.
	 * @param   LockWindows         $lock_windows         Filterable run-lock timing policy.
	 * @param   TerminalTransitions $terminal_transitions Fenced terminal-write coordinator.
	 * @param   TerminalEffects     $terminal_effects     Claimed terminal-effect executor.
	 * @param   BatchRegistry       $batches              Registered batch instances.
	 * @param   WorkRegistry        $work                 Shared task-and-batch identity registry.
	 * @param   BackendInterface    $scheduler            Scheduling facade boundary.
	 */
	public function __construct(
		private OverlapGuard $overlap_guard,
		private StoreFactory $stores,
		private ClockInterface $clock,
		private LoggerInterface $logger,
		private LockWindows $lock_windows,
		private TerminalTransitions $terminal_transitions,
		private TerminalEffects $terminal_effects,
		private BatchRegistry $batches,
		private WorkRegistry $work,
		private BackendInterface $scheduler,
	) {}

	// endregion

	// region METHODS

	/**
	 * Reclaims a stale lock only when no valid run state matches its run identifier and arguments hash.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity  Complete owner-qualified task or batch identity.
	 * @param   string $args_hash Stable single-flight identity.
	 * @param   string $run_id    Lock owner run identifier.
	 *
	 * @return  void
	 */
	public function reconcile_orphaned_lock( string $identity, string $args_hash, string $run_id ): void {
		$run_store = $this->stores->run_store( $identity );
		$inspected = $run_store->inspect( $run_id );
		if ( $inspected->is_failure() ) {
			return;
		}

		$snapshot = $inspected->value;
		$state    = $snapshot['state'] ?? null;
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
					'name'   => $identity,
					'run_id' => $run_id,
				)
			);
		}

		if ( ! $this->overlap_guard->delete_stale_owned_lock( $identity, $args_hash, $run_id, $this->lock_windows->lock_staleness( $identity, $run_id ) ) ) {
			return;
		}

		$this->logger->warning(
			'Reclaimed stale execution-overlap lock without a valid matching run option.',
			array(
				'name'      => $identity,
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
	 * @param   string $identity       Complete owner-qualified task or batch identity.
	 * @param   string $run_id         Run identifier.
	 * @param   int    $terminal_grace Grace before belt-and-braces terminal cleanup.
	 *
	 * @return  AbstractResult<string|null, EngineError> Transferred single-flight identity whose foreign lock must remain as fence evidence.
	 */
	public function reconcile_run( string $identity, string $run_id, int $terminal_grace ): AbstractResult {
		$run_store = $this->stores->run_store( $identity );
		$inspected = $run_store->inspect( $run_id );
		if ( $inspected->is_failure() ) {
			return $inspected;
		}

		$snapshot = $inspected->value;
		$state    = $snapshot['state'] ?? null;
		if ( null === $snapshot ) {
			return new Success( null );
		}
		if ( null === $state ) {
			if ( $run_store->delete_exact( $run_id, $snapshot['raw'] ) ) {
				$this->logger->warning(
					'Deleted corrupt run option during maintenance sweep.',
					array(
						'name'   => $identity,
						'run_id' => $run_id,
					)
				);
			}

			return new Success( null );
		}

		if ( RunStatus::Running === $state->status ) {
			$staleness = $this->lock_windows->lock_staleness( $identity, $run_id );
			$fence     = $state->executing
				? $this->overlap_guard->fence_abandoned_run( $identity, $state->args_hash, $run_id, $staleness )
				: $this->overlap_guard->classify_run_fence( $identity, $state->args_hash, $run_id );

			$kind      = $this->work->kind( $identity );
			$batch     = 'batch' === $kind ? $this->batches->get( $identity ) : null;
			$work_type = 'batch' === $kind ? 'Batch' : 'Task';
			if ( MaintenanceFenceOutcome::Transferred === $fence ) {
				// A transferred lock can appear while the displaced incumbent is still inside its callback; its fresh run heartbeat leaves terminalization to that worker's next ownership fence.
				if ( ! $this->lock_windows->heartbeat_is_stale( $state->heartbeat_at, $staleness ) ) {
					return new Success( $state->args_hash );
				}

				return $this->supersede_transferred_run( $identity, $run_id, $state, $run_store, $work_type, $snapshot['raw'] );
			}

			if ( $state->executing ) {
				return $this->reconcile_executing_run( $identity, $run_id, $state, $run_store, $snapshot['raw'], $fence, $batch, $work_type );
			}

			return $this->reconcile_non_executing_run( $identity, $run_id, $state, $run_store, $snapshot['raw'], $staleness, $batch, $work_type );
		}

		return $this->reconcile_terminal_run( $identity, $run_id, $state, $run_store, $snapshot['raw'], $terminal_grace );
	}

	/**
	 * Reconciles a running row whose callback owns the execution attempt.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $identity     Complete owner-qualified task or batch identity.
	 * @param   string                  $run_id       Run identifier.
	 * @param   RunState                $state        Running state observed by maintenance.
	 * @param   RunStore                $run_store    Name-bound run store.
	 * @param   string                  $expected_raw Exact observed state.
	 * @param   MaintenanceFenceOutcome $fence        Executing-run fence outcome.
	 * @param   BatchInterface|null     $batch        Registered batch, or null when unavailable.
	 * @param   'Task'|'Batch'          $work_type    Work contract type.
	 *
	 * @return  AbstractResult<null, EngineError>
	 */
	private function reconcile_executing_run( string $identity, string $run_id, RunState $state, RunStore $run_store, string $expected_raw, MaintenanceFenceOutcome $fence, ?BatchInterface $batch, string $work_type ): AbstractResult {
		if (
			MaintenanceFenceOutcome::Owned === $fence
			|| MaintenanceFenceOutcome::Indeterminate === $fence
		) {
			return new Success( null );
		}

		$error = $this->crash_reclaim_error( $identity, $run_id );
		$this->logger->warning(
			'Reclaimed running run whose owned execution-overlap lock was stale or missing.',
			array(
				'name'   => $identity,
				'run_id' => $run_id,
			)
		);

		return $this->fail_crashed_run( $identity, $run_id, $state, $run_store, $error, $batch, $work_type, $expected_raw );
	}

	/**
	 * Reconciles a running row between deliveries or redrives its pending action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string              $identity     Complete owner-qualified task or batch identity.
	 * @param   string              $run_id       Run identifier.
	 * @param   RunState            $state        Running state observed by maintenance.
	 * @param   RunStore            $run_store    Name-bound run store.
	 * @param   string              $expected_raw Exact observed state.
	 * @param   int                 $staleness    Lock-staleness window in seconds.
	 * @param   BatchInterface|null $batch        Registered batch, or null when unavailable.
	 * @param   'Task'|'Batch'      $work_type    Work contract type.
	 *
	 * @return  AbstractResult<null, EngineError>
	 */
	private function reconcile_non_executing_run( string $identity, string $run_id, RunState $state, RunStore $run_store, string $expected_raw, int $staleness, ?BatchInterface $batch, string $work_type ): AbstractResult {
		if ( ! $this->lock_windows->heartbeat_is_stale( $state->heartbeat_at, $staleness ) ) {
			return new Success( null );
		}

		if ( null === $state->pending ) {
			$fence = $this->overlap_guard->fence_abandoned_run( $identity, $state->args_hash, $run_id, $staleness );
			if (
				MaintenanceFenceOutcome::Owned === $fence
				|| MaintenanceFenceOutcome::Indeterminate === $fence
			) {
				return new Success( null );
			}
			if ( MaintenanceFenceOutcome::Transferred === $fence ) {
				return $this->supersede_transferred_run( $identity, $run_id, $state, $run_store, $work_type, $expected_raw );
			}

			$error = $this->crash_reclaim_error( $identity, $run_id );
			$this->logger->warning(
				'Reclaimed stale running run that carries no pending-action descriptor.',
				array(
					'name'   => $identity,
					'run_id' => $run_id,
				)
			);
		} else {
			$redrive_fence = $this->overlap_guard->prepare_run_redrive_fence( $identity, $state->args_hash, $run_id, $state->created_at, $state->heartbeat_at, $staleness );
			if (
				RedriveFenceOutcome::Live === $redrive_fence
				|| RedriveFenceOutcome::Indeterminate === $redrive_fence
			) {
				return new Success( null );
			}
			if ( RedriveFenceOutcome::Transferred === $redrive_fence ) {
				return $this->supersede_transferred_run( $identity, $run_id, $state, $run_store, $work_type, $expected_raw );
			}

			$scheduled = $this->redrive_pending_action( $identity, $run_id, $state, $work_type );
			if ( ! $scheduled->is_failure() ) {
				return new Success( null );
			}
			$this->logger->warning(
				'Pending-action redrive was rejected by the scheduler; maintenance skipped terminal handling.',
				array(
					'name'         => $identity,
					'run_id'       => $run_id,
					'error_class'  => $scheduled->error::class,
					'error_reason' => $scheduled->error->reason->value,
				)
			);

			return new Success( null );
		}

		return $this->fail_crashed_run( $identity, $run_id, $state, $run_store, $error, $batch, $work_type, $expected_raw );
	}

	/**
	 * Replays terminal cleanup after its grace period expires.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $identity       Complete owner-qualified task or batch identity.
	 * @param   string   $run_id         Run identifier.
	 * @param   RunState $state          Terminal state observed by maintenance.
	 * @param   RunStore $run_store      Name-bound run store.
	 * @param   string   $expected_raw   Exact observed state.
	 * @param   int      $terminal_grace Grace before belt-and-braces terminal cleanup.
	 *
	 * @return  AbstractResult<null, EngineError>
	 */
	private function reconcile_terminal_run( string $identity, string $run_id, RunState $state, RunStore $run_store, string $expected_raw, int $terminal_grace ): AbstractResult {
		$now = $this->clock->now()->getTimestamp();
		if (
			$state->heartbeat_at > \PHP_INT_MAX - $terminal_grace
			|| $now <= $state->heartbeat_at + $terminal_grace
		) {
			return new Success( null );
		}

		$kind           = $this->work->kind( $identity );
		$resolved_batch = null;
		if ( 'batch' === $kind ) {
			$work_type      = 'Batch';
			$resolved_batch = $this->batches->get( $identity );
		} elseif ( 'task' === $kind ) {
			$work_type = 'Task';
		} else {
			// The callback superset lets an unresolved terminal row converge without inventing a persisted work-kind field.
			$work_type = \in_array( $state->status, array( RunStatus::Completed, RunStatus::Failed ), true )
				? 'Batch'
				: 'Task';
		}

		if ( $this->terminal_effects->replay_terminal_run( $identity, $run_id, $state, $expected_raw, $run_store, $work_type, $resolved_batch ) ) {
			$this->logger->warning(
				'Reclaimed old terminal run option left behind after transition cleanup.',
				array(
					'name'   => $identity,
					'run_id' => $run_id,
					'status' => $state->status->value,
				)
			);
		}

		return new Success( null );
	}

	/**
	 * Fails a crashed running row through its work-kind terminal path.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string              $identity     Complete owner-qualified task or batch identity.
	 * @param   string              $run_id       Run identifier.
	 * @param   RunState            $state        Running state observed by maintenance.
	 * @param   RunStore            $run_store    Name-bound run store.
	 * @param   EngineError         $error        Crash-reclaim terminal failure detail.
	 * @param   BatchInterface|null $batch        Registered batch, or null when unavailable.
	 * @param   'Task'|'Batch'      $work_type    Work contract type.
	 * @param   string              $expected_raw Exact observed state.
	 *
	 * @return  AbstractResult<null, EngineError>
	 */
	private function fail_crashed_run( string $identity, string $run_id, RunState $state, RunStore $run_store, EngineError $error, ?BatchInterface $batch, string $work_type, string $expected_raw ): AbstractResult {
		$attempts     = RunState::increment_attempts_safely( $state->failed_attempts );
		$failed_chunk = 'Batch' === $work_type && 'run' === $state->pending?->stage
			? ( $state->queue[0] ?? null )
			: null;
		if ( null !== $batch ) {
			$this->terminal_transitions->fail_batch( $batch, $identity, $run_id, $state, $run_store, $error, RunFailureStage::CrashReclaim, ApiErrorCode::ExecutionFailed, $failed_chunk, $attempts, $expected_raw );
		} else {
			$this->terminal_transitions->fail_task( $identity, $run_id, $state, $run_store, $error, $attempts, RunFailureStage::CrashReclaim, ApiErrorCode::ExecutionFailed, null, $expected_raw );
		}

		return new Success( null );
	}

	/**
	 * Supersedes a running row after its execution-overlap fence transfers ownership.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $identity     Complete owner-qualified task or batch identity.
	 * @param   string         $run_id       Run identifier.
	 * @param   RunState       $state        Running state observed by maintenance.
	 * @param   RunStore       $run_store    Name-bound run store.
	 * @param   'Task'|'Batch' $work_type    Work contract type.
	 * @param   string         $expected_raw Exact observed state.
	 *
	 * @return  AbstractResult<null, EngineError>
	 */
	private function supersede_transferred_run( string $identity, string $run_id, RunState $state, RunStore $run_store, string $work_type, string $expected_raw ): AbstractResult {
		$latest_run_id = $this->stores->latest_run_pointer( $identity )->get_latest_for_hash( $state->args_hash );
		$this->terminal_transitions->supersede_run( $identity, $run_id, $latest_run_id, $state, $run_store, $work_type, $expected_raw );

		return new Success( null );
	}

	/**
	 * Re-enqueues the exact pending lifecycle action represented by a running row.
	 *
	 * Maintenance skips terminal handling after a redrive rejection because a previously accepted delivery may still be queued.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $identity  Complete owner-qualified task or batch identity.
	 * @param   string         $run_id    Run identifier.
	 * @param   RunState       $state     Stale non-executing running state.
	 * @param   'Task'|'Batch' $work_type Work contract type.
	 *
	 * @throws  \LogicException When a schema-valid descriptor conflicts with its run state.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	private function redrive_pending_action( string $identity, string $run_id, RunState $state, string $work_type ): AbstractResult {
		$pending = $state->pending;
		if ( null === $pending ) {
			throw new \LogicException( 'Pending-action redrive requires a durable descriptor.' );
		}

		$args = array( $identity, $run_id );
		if ( 'run' === $pending->stage && 'Batch' === $work_type ) {
			$chunk_args = $state->queue[0] ?? null;
			if ( ! \is_array( $chunk_args ) ) {
				throw new \LogicException( 'Pending batch run redrive requires a retained queue head.' );
			}

			$args[] = $chunk_args;
		}
		$args[] = $state->action_seq;
		$hook   = 'run' === $pending->stage
			? ( 'Batch' === $work_type ? 'a8csp_background_tasks/run_chunk' : 'a8csp_background_tasks/run_task' )
			: 'a8csp_background_tasks/' . $pending->stage;
		$group  = $identity . '|' . $run_id;
		if ( 'async' === $pending->mode ) {
			return $this->scheduler->enqueue_async( $hook, $args, $group, $pending->priority );
		}

		$fire_at = $pending->fire_at;
		if ( ! \is_int( $fire_at ) ) {
			throw new \LogicException( 'Pending single-action redrive requires an integer fire time.' );
		}

		return $this->scheduler->schedule_single( $hook, \max( $this->clock->now()->getTimestamp(), $fire_at ), $args, $group, $pending->priority );
	}

	/**
	 * Returns the stable crash reclaim terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified task or batch identity.
	 * @param   string $run_id   Run identifier.
	 *
	 * @return  EngineError
	 */
	private function crash_reclaim_error( string $identity, string $run_id ): EngineError {
		return new EngineError( \sprintf( 'Run "%1$s" for background-work "%2$s" was failed by the maintenance crash reclaim path because its owned lock was stale or missing.', $run_id, $identity ) );
	}

	// endregion
}

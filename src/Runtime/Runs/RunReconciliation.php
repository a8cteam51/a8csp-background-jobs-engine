<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\MaintenanceFenceOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\RedeliveryFenceOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\KindHandlerInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\BackendInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
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
	 * @phpstan-param array<string, KindHandlerInterface> $handlers
	 *
	 * @param   OverlapGuard     $overlap_guard        Execution-overlap guard.
	 * @param   StoreFactory     $stores               Name-bound store factory.
	 * @param   ClockInterface   $clock                Timestamp source.
	 * @param   LoggerInterface  $logger               Log event sink.
	 * @param   LockWindows      $lock_windows         Filterable run-lock timing policy.
	 * @param   RunTransitions   $terminal_transitions Fenced terminal-write coordinator.
	 * @param   LifecycleEffects $terminal_effects     Claimed terminal-effect executor.
	 * @param   array            $handlers             Kind handlers keyed by their persisted keys.
	 * @param   BackendInterface $scheduler            Scheduling facade boundary.
	 */
	public function __construct(
		private OverlapGuard $overlap_guard,
		private StoreFactory $stores,
		private ClockInterface $clock,
		private LoggerInterface $logger,
		private LockWindows $lock_windows,
		private RunTransitions $terminal_transitions,
		private LifecycleEffects $terminal_effects,
		private array $handlers,
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
	 * @param   string $identity  Complete owner-qualified job or chunked job identity.
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
					'identity' => $identity,
					'run_id'   => $run_id,
				)
			);
		}

		if ( ! $this->overlap_guard->delete_stale_owned_lock( $identity, $args_hash, $run_id, $this->lock_windows->lock_staleness( $identity, $run_id ) ) ) {
			return;
		}

		$this->logger->warning(
			'Reclaimed stale execution-overlap lock without a valid matching run option.',
			array(
				'identity'  => $identity,
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
	 * @param   string $identity       Complete owner-qualified job or chunked job identity.
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
						'identity' => $identity,
						'run_id'   => $run_id,
					)
				);
			}

			return new Success( null );
		}

		if ( RunStatus::Running !== $state->status ) {
			$now = $this->clock->now()->getTimestamp();
			if (
				$state->heartbeat_at > \PHP_INT_MAX - $terminal_grace
				|| $now <= $state->heartbeat_at + $terminal_grace
			) {
				return new Success( null );
			}

			$missing_effects = \array_values( \array_diff( LifecycleEffects::expected_effects( $state->status ), $state->effects ) );
			$failure_detail  = null;
			if ( RunStatus::Failed === $state->status && array() !== \array_intersect( array( 'retention', 'hooks' ), $missing_effects ) ) {
				// Terminal cleanup needs the handler only to reconstruct kind-specific detail for a failed row whose persisted error is gone; an unregistered kind degrades to a generic failure rather than stranding the row, so a null handler is tolerated here where the running-row reclamation below requires one.
				$handler          = $this->handlers[ $state->kind ] ?? null;
				$fallback_details = null === $state->error && null !== $handler
					? $handler->failure_details( $state )
					: null;
				$failure_detail   = $this->terminal_effects->resolve_failure_detail( $identity, $run_id, $state, $fallback_details );
			}

			return $this->reconcile_terminal_run( $identity, $run_id, $state, $run_store, $snapshot['raw'], $failure_detail );
		}

		$handler = $this->handlers[ $state->kind ] ?? null;
		if ( null === $handler ) {
			$this->logger->warning(
				'Background-work run kind has no registered handler; maintenance left the run untouched.',
				array(
					'identity' => $identity,
					'run_id'   => $run_id,
					'kind'     => $state->kind,
				)
			);

			return new Success( null );
		}

		$staleness = $this->lock_windows->lock_staleness( $identity, $run_id );
		$fence     = $state->executing
			? $this->overlap_guard->fence_abandoned_run( $identity, $state->args_hash, $run_id, $staleness )
			: $this->overlap_guard->classify_run_fence( $identity, $state->args_hash, $run_id );

		if ( MaintenanceFenceOutcome::Transferred === $fence ) {
			// A transferred lock can appear during displaced execution; its fresh run heartbeat leaves terminalization to that worker's next ownership fence.
			if ( ! $this->lock_windows->heartbeat_is_stale( $state->heartbeat_at, $staleness ) ) {
				return new Success( $state->args_hash );
			}

			return $this->supersede_transferred_run( $identity, $run_id, $state, $run_store, $snapshot['raw'] );
		}

		if ( $state->executing ) {
			return $this->reconcile_executing_run( $identity, $run_id, $state, $run_store, $snapshot['raw'], $fence, $handler );
		}

		return $this->reconcile_non_executing_run( $identity, $run_id, $state, $run_store, $snapshot['raw'], $staleness, $handler );
	}

	// endregion

	// region HELPERS

	/**
	 * Reconciles a running row whose execution owns the current attempt.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $identity     Complete owner-qualified job or chunked job identity.
	 * @param   string                  $run_id       Run identifier.
	 * @param   RunState                $state        Running state observed by maintenance.
	 * @param   RunStore                $run_store    Name-bound run store.
	 * @param   string                  $expected_raw Exact observed state.
	 * @param   MaintenanceFenceOutcome $fence        Executing-run fence outcome.
	 * @param   KindHandlerInterface    $handler      Resolved kind handler.
	 *
	 * @return  AbstractResult<null, EngineError>
	 */
	private function reconcile_executing_run( string $identity, string $run_id, RunState $state, RunStore $run_store, string $expected_raw, MaintenanceFenceOutcome $fence, KindHandlerInterface $handler ): AbstractResult {
		if (
			MaintenanceFenceOutcome::Owned === $fence
			|| MaintenanceFenceOutcome::Indeterminate === $fence
		) {
			return new Success( null );
		}

		$error = $this->crash_reclamation_error( $identity, $run_id );
		$this->logger->warning(
			'Reclaimed running run whose owned execution-overlap lock was stale or missing.',
			array(
				'identity' => $identity,
				'run_id'   => $run_id,
			)
		);

		return $this->fail_crashed_run( $identity, $run_id, $state, $run_store, $error, $handler, $expected_raw );
	}

	/**
	 * Reconciles a running row between deliveries or redelivers its pending action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string               $identity     Complete owner-qualified job or chunked job identity.
	 * @param   string               $run_id       Run identifier.
	 * @param   RunState             $state        Running state observed by maintenance.
	 * @param   RunStore             $run_store    Name-bound run store.
	 * @param   string               $expected_raw Exact observed state.
	 * @param   int                  $staleness    Lock-staleness window in seconds.
	 * @param   KindHandlerInterface $handler      Resolved kind handler.
	 *
	 * @return  AbstractResult<null, EngineError>
	 */
	private function reconcile_non_executing_run( string $identity, string $run_id, RunState $state, RunStore $run_store, string $expected_raw, int $staleness, KindHandlerInterface $handler ): AbstractResult {
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
				return $this->supersede_transferred_run( $identity, $run_id, $state, $run_store, $expected_raw );
			}

			$error = $this->crash_reclamation_error( $identity, $run_id );
			$this->logger->warning(
				'Reclaimed stale running run that carries no pending-action descriptor.',
				array(
					'identity' => $identity,
					'run_id'   => $run_id,
				)
			);
		} else {
			$redelivery_fence = $this->overlap_guard->prepare_run_redelivery_fence( $identity, $state->args_hash, $run_id, $state->created_at, $state->heartbeat_at, $staleness );
			if (
				RedeliveryFenceOutcome::Live === $redelivery_fence
				|| RedeliveryFenceOutcome::Indeterminate === $redelivery_fence
			) {
				return new Success( null );
			}
			if ( RedeliveryFenceOutcome::Transferred === $redelivery_fence ) {
				return $this->supersede_transferred_run( $identity, $run_id, $state, $run_store, $expected_raw );
			}

			$scheduled = $this->redeliver_pending_action( $identity, $run_id, $state );
			if ( ! $scheduled->is_failure() ) {
				return new Success( null );
			}
			$this->logger->warning(
				'Pending-action redelivery was rejected by the scheduler; restore scheduler availability so maintenance can retry the pending action.',
				array(
					'identity'     => $identity,
					'run_id'       => $run_id,
					'error_class'  => $scheduled->error::class,
					'error_reason' => $scheduled->error->reason->value,
				)
			);

			return new Success( null );
		}

		return $this->fail_crashed_run( $identity, $run_id, $state, $run_store, $error, $handler, $expected_raw );
	}

	/**
	 * Replays terminal cleanup after its grace period expires.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{error: EngineError, failure: RunFailure}|null $failure_detail
	 *
	 * @param   string     $identity       Complete owner-qualified job or chunked job identity.
	 * @param   string     $run_id         Run identifier.
	 * @param   RunState   $state          Terminal state observed by maintenance.
	 * @param   RunStore   $run_store      Name-bound run store.
	 * @param   string     $expected_raw   Exact observed state.
	 * @param   array|null $failure_detail Reconstructed internal and client failure detail.
	 *
	 * @return  AbstractResult<null, EngineError>
	 */
	private function reconcile_terminal_run( string $identity, string $run_id, RunState $state, RunStore $run_store, string $expected_raw, ?array $failure_detail ): AbstractResult {
		if ( $this->terminal_effects->replay_terminal_run( $identity, $run_id, $state, $expected_raw, $run_store, $failure_detail ) ) {
			$this->logger->warning(
				'Reclaimed old terminal run option left behind after transition cleanup.',
				array(
					'identity' => $identity,
					'run_id'   => $run_id,
					'status'   => $state->status->value,
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
	 * @param   string               $identity     Complete owner-qualified job or chunked job identity.
	 * @param   string               $run_id       Run identifier.
	 * @param   RunState             $state        Running state observed by maintenance.
	 * @param   RunStore             $run_store    Name-bound run store.
	 * @param   EngineError          $error        Crash-reclaim terminal failure detail.
	 * @param   KindHandlerInterface $handler      Resolved kind handler.
	 * @param   string               $expected_raw Exact observed state.
	 *
	 * @return  AbstractResult<null, EngineError>
	 */
	private function fail_crashed_run( string $identity, string $run_id, RunState $state, RunStore $run_store, EngineError $error, KindHandlerInterface $handler, string $expected_raw ): AbstractResult {
		$attempts = RunState::increment_attempts_safely( $state->failed_attempts );
		$this->terminal_transitions->fail_run( $handler, $identity, $run_id, $state, $run_store, $error, $attempts, RunFailureStage::crash_reclamation(), ErrorCode::ExecutionFailed, $handler->failure_details( $state ), $expected_raw );

		return new Success( null );
	}

	/**
	 * Supersedes a running row after its execution-overlap fence transfers ownership.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $identity     Complete owner-qualified job or chunked job identity.
	 * @param   string   $run_id       Run identifier.
	 * @param   RunState $state        Running state observed by maintenance.
	 * @param   RunStore $run_store    Name-bound run store.
	 * @param   string   $expected_raw Exact observed state.
	 *
	 * @return  AbstractResult<null, EngineError>
	 */
	private function supersede_transferred_run( string $identity, string $run_id, RunState $state, RunStore $run_store, string $expected_raw ): AbstractResult {
		$latest_run_id = $this->stores->latest_run_pointer( $identity )->get_latest_for_hash( $state->args_hash );
		$claimed       = $this->terminal_transitions->claim_superseded_run( $run_id, $state, $run_store, $expected_raw );
		if ( \is_array( $claimed ) ) {
			$this->terminal_transitions->execute_claimed_supersession( $identity, $run_id, $latest_run_id, $claimed, $run_store );
		}

		return new Success( null );
	}

	/**
	 * Re-enqueues the exact pending lifecycle action represented by a running row.
	 *
	 * Maintenance skips terminal handling after a redelivery rejection because a previously accepted delivery may still be queued.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $identity  Complete owner-qualified job or chunked job identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Stale non-executing running state.
	 *
	 * @throws  \LogicException When a schema-valid descriptor conflicts with its scheduling mode.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	private function redeliver_pending_action( string $identity, string $run_id, RunState $state ): AbstractResult {
		$pending = $state->pending;
		if ( null === $pending ) {
			throw new \LogicException( 'Pending-action redelivery requires a durable descriptor.' );
		}

		$args  = array( $identity, $run_id, $state->action_sequence );
		$hook  = ActionDeliveries::DELIVER_HOOK;
		$group = $identity . '|' . $run_id;
		if ( 'async' === $pending->mode ) {
			return $this->scheduler->enqueue_async( $hook, $args, $group, $pending->priority );
		}

		$fire_at = $pending->fire_at;
		if ( ! \is_int( $fire_at ) ) {
			throw new \LogicException( 'Pending single-action redelivery requires an integer fire time.' );
		}

		return $this->scheduler->schedule_single( $hook, \max( $this->clock->now()->getTimestamp(), $fire_at ), $args, $group, $pending->priority );
	}

	/**
	 * Returns the stable crash reclaim terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 * @param   string $run_id   Run identifier.
	 *
	 * @return  EngineError
	 */
	private function crash_reclamation_error( string $identity, string $run_id ): EngineError {
		return new EngineError( \sprintf( 'Run "%1$s" for background-work "%2$s" was failed by the maintenance crash reclaim path because its owned lock was stale or missing.', $run_id, $identity ) );
	}

	// endregion
}

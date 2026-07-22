<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\HeartbeatOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\KindHandlerInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
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
final readonly class RunTransitions {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OverlapGuard     $overlap_guard    Execution-overlap guard.
	 * @param   StoreFactory     $stores           Name-bound store factory.
	 * @param   ClockInterface   $clock            Timestamp source.
	 * @param   LockWindows      $lock_windows     Filterable run-lock timing policy.
	 * @param   LoggerInterface  $logger           Log event sink.
	 * @param   LifecycleEffects $terminal_effects Claimed terminal-effect executor.
	 */
	public function __construct(
		private OverlapGuard $overlap_guard,
		private StoreFactory $stores,
		private ClockInterface $clock,
		private LockWindows $lock_windows,
		private LoggerInterface $logger,
		private LifecycleEffects $terminal_effects,
	) {}

	// endregion

	// region METHODS

	/**
	 * Claims delivery ownership of one recoverable running state for a lifecycle action.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<string, KindHandlerInterface> $handlers
	 *
	 * @param   array    $handlers        Kind handlers keyed by their persisted keys.
	 * @param   string   $identity        Complete owner-qualified work identity.
	 * @param   string   $run_id          Run identifier.
	 * @param   int|null $action_sequence Received lifecycle action sequence.
	 * @param   RunStore $run_store       Active-run store.
	 *
	 * @return  ClaimedDelivery|null
	 */
	public function claim_delivery_ownership( array $handlers, string $identity, string $run_id, ?int $action_sequence, RunStore $run_store ): ?ClaimedDelivery {
		$inspection = $run_store->inspect( $run_id );
		if ( $inspection->is_failure() ) {
			$this->logger->warning(
				'Background-work run state could not be read; repair WordPress option reads and retry the delivery.',
				array(
					'name'   => $identity,
					'run_id' => $run_id,
					'error'  => $inspection->error->message,
				)
			);

			return null;
		}

		$snapshot = $inspection->value;
		if ( null === $snapshot ) {
			$this->logger->debug(
				'Stale delivery for a finished or cancelled run was dropped.',
				array(
					'name'   => $identity,
					'run_id' => $run_id,
				)
			);

			return null;
		}

		$state = $snapshot['state'];
		if ( null === $state ) {
			$this->logger->warning(
				'Background-work run state is corrupt; repair or remove the row so the reconciliation sweep can release any remaining lock.',
				array(
					'name'   => $identity,
					'run_id' => $run_id,
				)
			);

			return null;
		}

		$kind    = $state->kind;
		$handler = $handlers[ $kind ] ?? null;
		if ( null === $handler ) {
			$this->logger->warning(
				'Persisted run kind has no registered handler; the delivery was dropped without changing the run.',
				array(
					'name'   => $identity,
					'run_id' => $run_id,
					'kind'   => $kind,
				)
			);

			return null;
		}
		if ( ! $handler->owns_stage( $state->pending?->stage ) ) {
			$this->logger->warning(
				'Persisted lifecycle stage is not owned by the resolved kind handler; the delivery was dropped without changing the run.',
				array(
					'name'   => $identity,
					'run_id' => $run_id,
					'kind'   => $kind,
					'stage'  => $state->pending?->stage,
				)
			);

			return null;
		}

		$context_name = $kind . '_name';

		if ( $action_sequence !== $state->action_sequence ) {
			$this->logger->info(
				'Stale lifecycle action delivery dropped.',
				array(
					'name'     => $identity,
					'expected' => $state->action_sequence,
					'received' => $action_sequence,
					'run_id'   => $run_id,
				)
			);

			return null;
		}

		if ( RunStatus::Running !== $state->status ) {
			$this->logger->warning(
				$kind . ' run is already terminal; allow the reconciliation sweep to finish its cleanup.',
				array(
					$context_name => $identity,
					'run_id'      => $run_id,
					'status'      => $state->status->value,
				)
			);

			return null;
		}

		if (
			$state->executing
			&& ! $this->lock_windows->heartbeat_is_stale( $state->heartbeat_at, $this->lock_windows->lock_staleness( $identity, $run_id ) )
		) {
			$this->logger->debug(
				'Duplicate lifecycle action delivery dropped while the current delivery is still executing.',
				array(
					$context_name     => $identity,
					'run_id'          => $run_id,
					'action_sequence' => $state->action_sequence,
				)
			);

			return null;
		}

		$at = $handler->delivery_liveness_at( $identity, $run_id, $state ) ?? $this->clock->now()->getTimestamp();

		// Only confirmed lock ownership permits the delivery to refresh its run row and enter lifecycle work.
		if ( $this->enforce_delivery_fence( $handler, $identity, $run_id, $state, $run_store, $at, $state->heartbeat_at ) ) {
			return null;
		}

		$state = $run_store->mark_executing_with_heartbeat( $run_id, $state, $at );
		if ( $state instanceof Failure || null === $state ) {
			return null;
		}

		$latest_pointer = $this->stores->latest_run_pointer( $identity );
		$latest_run_id  = $latest_pointer->get_latest_for_hash( $state->args_hash );

		// The lock CAS is authoritative because a bounded pointer can be evicted or lag a concurrent start commit.
		if ( $run_id !== $latest_run_id && ! $latest_pointer->repair_for_hash( $run_id, $state->args_hash ) ) {
			$this->logger->warning(
				'Latest-run pointer repair failed; discovery metadata may remain stale.',
				array(
					'name'   => $identity,
					'run_id' => $run_id,
				)
			);
		}

		return new ClaimedDelivery( $handler, $state );
	}

	/**
	 * Marks successful work before completing its durable terminal effects.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   KindHandlerInterface $handler   Handler selected by the persisted kind.
	 * @param   JobInterface         $contract  Completed work contract.
	 * @param   string               $identity  Complete owner-qualified work identity.
	 * @param   string               $run_id    Run identifier.
	 * @param   RunState             $state     Running state.
	 * @param   RunStore             $run_store Active-run store.
	 *
	 * @return  void
	 */
	public function complete_run( KindHandlerInterface $handler, JobInterface $contract, string $identity, string $run_id, RunState $state, RunStore $run_store ): void {
		$previous_completed_run_id = $this->last_completed_run_id( $identity );
		$terminal_state            = $handler->completion_state( $state )->with_status( RunStatus::Completed )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null )->with_previous_completed_run_id( $previous_completed_run_id );

		$this->claim_and_execute_terminal_transition( $identity, $run_id, $state, $terminal_state, $run_store, $handler, $contract );
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
	 * @param   KindHandlerInterface $handler              Handler selected by the persisted kind.
	 * @param   string               $identity             Complete owner-qualified work identity.
	 * @param   string               $run_id               Run identifier.
	 * @param   RunState             $state                Running state from the exact inspected snapshot.
	 * @param   RunStore             $run_store            Active-run store.
	 * @param   string               $expected_raw         Exact pre-cancel snapshot.
	 * @param   \Closure             $clear_pending_actions Winner-only scheduler-group clear.
	 *
	 * @return  bool Whether the cancellation transition was claimed.
	 */
	public function cancel_run( KindHandlerInterface $handler, string $identity, string $run_id, RunState $state, RunStore $run_store, string $expected_raw, \Closure $clear_pending_actions ): bool {
		$terminal_state = $state->with_status( RunStatus::Cancelled )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null );
		$terminal_raw   = $this->claim_terminal_transition( $run_id, $state, $terminal_state, $run_store, $expected_raw, );
		if ( null === $terminal_raw ) {
			return false;
		}

		try {
			$clear_pending_actions();
		} finally {
			try {
				$this->terminal_effects->execute_claimed_transition( $identity, $run_id, $terminal_state, $terminal_raw, $run_store, $handler );
			} catch ( \Throwable $throwable ) {
				// The committed Cancelled state retains every unmarked effect for maintenance replay.
				$this->logger->error(
					'Cancelled-run terminal effects could not finish synchronously; the durable terminal row retains unmarked effects for maintenance replay.',
					array(
						'name'      => $identity,
						'run_id'    => $run_id,
						'exception' => $throwable,
					)
				);
			}
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
	 * @param   KindHandlerInterface $handler   Handler selected by the persisted kind.
	 * @param   string               $identity  Complete owner-qualified work identity.
	 * @param   string               $run_id    Run identifier.
	 * @param   RunState             $state     Running state.
	 * @param   RunStore             $run_store Active-run store.
	 * @param   EngineError          $error     Failure detail.
	 *
	 * @return  void
	 */
	public function fail_unregistered_run( KindHandlerInterface $handler, string $identity, string $run_id, RunState $state, RunStore $run_store, EngineError $error ): void {
		$attempts       = RunState::increment_attempts_safely( $state->failed_attempts );
		$terminal_state = $state->with_status( RunStatus::Failed )->with_failed_attempts( $attempts )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null )->with_error( self::error_detail( $error, RunFailureStage::execution(), ErrorCode::UnknownWork, $handler->failure_details( $state ) ) );

		$this->claim_and_execute_terminal_transition( $identity, $run_id, $state, $terminal_state, $run_store, $handler );
	}

	/**
	 * Persists failure detail before callbacks, hooks, and active-state cleanup.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<array-key, mixed>|null $details
	 *
	 * @param   KindHandlerInterface $handler        Handler selected by the persisted kind.
	 * @param   JobInterface|null    $callback_target Failed work contract, or null when unavailable.
	 * @param   string               $identity       Complete owner-qualified work identity.
	 * @param   string               $run_id         Run identifier.
	 * @param   RunState             $state          Running state.
	 * @param   RunStore             $run_store      Active-run store.
	 * @param   EngineError          $error          Failure detail.
	 * @param   int                  $attempts       Attempts consumed before failure.
	 * @param   RunFailureStage      $stage          Terminalization stage.
	 * @param   ErrorCode            $code           Machine-readable cause classification.
	 * @param   array|null           $details        Generic diagnostic payload, or null when no details are available.
	 * @param   string|null          $expected_raw   Exact maintenance snapshot, or null for a live transition.
	 *
	 * @return  void
	 */
	public function fail_run( KindHandlerInterface $handler, ?JobInterface $callback_target, string $identity, string $run_id, RunState $state, RunStore $run_store, EngineError $error, int $attempts, RunFailureStage $stage, ErrorCode $code, ?array $details = null, ?string $expected_raw = null ): void {
		$terminal_state = $state->with_status( RunStatus::Failed )->with_failed_attempts( $attempts )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null )->with_error( self::error_detail( $error, $stage, $code, $details ) );

		$this->claim_and_execute_terminal_transition( $identity, $run_id, $state, $terminal_state, $run_store, $handler, $callback_target, $expected_raw );
	}

	/**
	 * Aborts a delivery when its owner-scoped heartbeat is lost, generation-mismatched, or indeterminate.
	 *
	 * Confirmed loss attempts a Superseded transition and always aborts the delivery; a rival terminal
	 * compare-and-swap can prevent that transition from being claimed. A mismatched delivery generation or
	 * indeterminate authoritative storage access leaves the running state untouched for a later delivery or the
	 * staleness sweep to resolve.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   KindHandlerInterface $handler               Handler selected by the persisted kind.
	 * @param   string               $identity              Complete owner-qualified work identity.
	 * @param   string               $run_id                Run identifier.
	 * @param   RunState             $state                 Running state observed before the fence.
	 * @param   RunStore             $run_store             Active-run store.
	 * @param   int|null             $at                    Liveness timestamp, or null to use the current clock time.
	 * @param   int|null             $expected_heartbeat_at Expected heartbeat for one delivery generation, or null to accept any owned generation.
	 *
	 * @return  bool Whether the caller must abort this delivery.
	 */
	public function enforce_delivery_fence( KindHandlerInterface $handler, string $identity, string $run_id, RunState $state, RunStore $run_store, ?int $at = null, ?int $expected_heartbeat_at = null ): bool {
		$outcome = $this->overlap_guard->heartbeat( $identity, $state->args_hash, $run_id, $at, $expected_heartbeat_at );
		if ( HeartbeatOutcome::Owned === $outcome ) {
			return false;
		}
		if ( HeartbeatOutcome::GenerationMismatch === $outcome ) {
			return true;
		}

		if ( HeartbeatOutcome::Indeterminate === $outcome ) {
			$kind         = $handler->key();
			$context_name = $kind . '_name';
			$this->logger->debug(
				$kind . ' ownership fence is indeterminate; the delivery aborts without a terminal transition.',
				array(
					$context_name => $identity,
					'run_id'      => $run_id,
				)
			);

			return true;
		}

		$latest_run_id = $this->stores->latest_run_pointer( $identity )->get_latest_for_hash( $state->args_hash );
		$this->supersede_run( $identity, $run_id, $latest_run_id, $state, $run_store, $handler );

		return true;
	}

	/**
	 * Fences a run that no longer owns its overlap lock before hooks and active-state release.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string               $identity      Complete owner-qualified work identity.
	 * @param   string               $run_id        Run identifier.
	 * @param   string|null          $latest_run_id Latest discoverable pointer value for the single-flight identity.
	 * @param   RunState             $state         Running state.
	 * @param   RunStore             $run_store     Active-run store.
	 * @param   KindHandlerInterface $handler       Handler selected by the persisted kind.
	 * @param   string|null          $expected_raw  Exact maintenance snapshot, or null for a live transition.
	 *
	 * @return  void
	 */
	public function supersede_run( string $identity, string $run_id, ?string $latest_run_id, RunState $state, RunStore $run_store, KindHandlerInterface $handler, ?string $expected_raw = null ): void {
		$terminal_state = $state->with_status( RunStatus::Superseded )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null );
		$terminal_raw   = $this->claim_terminal_transition( $run_id, $state, $terminal_state, $run_store, $expected_raw );
		if ( null === $terminal_raw ) {
			return;
		}
		$kind         = $handler->key();
		$context_name = $kind . '_name';
		$this->logger->info(
			'Superseded ' . $kind . ' run after its ownership fence failed.',
			array(
				$context_name   => $identity,
				'run_id'        => $run_id,
				'latest_run_id' => $latest_run_id,
			)
		);

		$this->terminal_effects->execute_claimed_transition( $identity, $run_id, $terminal_state, $terminal_raw, $run_store, $handler );
	}

	// endregion

	// region HELPERS

	/**
	 * Claims a terminal state and executes only the winning transition's effects.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string               $identity        Complete owner-qualified work identity.
	 * @param   string               $run_id          Run identifier.
	 * @param   RunState             $expected        Complete running state observed by the terminalizing path.
	 * @param   RunState             $replacement     Terminal replacement state.
	 * @param   RunStore             $run_store       Active-run store.
	 * @param   KindHandlerInterface $handler         Handler selected by the persisted kind.
	 * @param   JobInterface|null    $callback_target Resolved callback target, or null when unavailable.
	 * @param   string|null          $expected_raw    Exact maintenance snapshot, or null for a live transition.
	 *
	 * @return  bool Whether the terminal transition was claimed.
	 */
	private function claim_and_execute_terminal_transition( string $identity, string $run_id, RunState $expected, RunState $replacement, RunStore $run_store, KindHandlerInterface $handler, ?JobInterface $callback_target = null, ?string $expected_raw = null ): bool {
		$terminal_raw = $this->claim_terminal_transition( $run_id, $expected, $replacement, $run_store, $expected_raw );
		if ( null === $terminal_raw ) {
			return false;
		}
		if ( RunStatus::Failed === $replacement->status ) {
			$this->logger->error(
				'Run failed permanently; correct the cause, then use failed-runs retry to start a fresh run.',
				array(
					'name'        => $identity,
					'run_id'      => $run_id,
					'attempts'    => $replacement->failed_attempts,
					'stage'       => $replacement->error['stage'] ?? null,
					'error_class' => $replacement->error['class'] ?? null,
				)
			);
		}

		$this->terminal_effects->execute_claimed_transition( $identity, $run_id, $replacement, $terminal_raw, $run_store, $handler, $callback_target );

		return true;
	}

	/**
	 * Claims the terminal state transition only while the complete observed run still matches.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $run_id       Run identifier.
	 * @param   RunState    $expected     Complete state observed by the terminalizing path.
	 * @param   RunState    $replacement  Terminal replacement state.
	 * @param   RunStore    $run_store    Active-run store.
	 * @param   string|null $expected_raw Exact pre-gate snapshot supplied by maintenance, or null.
	 *
	 * @return  string|null Exact terminal snapshot bytes for cleanup, or null after a lost fence.
	 */
	private function claim_terminal_transition( string $run_id, RunState $expected, RunState $replacement, RunStore $run_store, ?string $expected_raw = null ): ?string {
		$claimed = null === $expected_raw
			? $run_store->replace_if_state_matches( $run_id, $expected, $replacement )
			: $run_store->replace_if_raw_matches( $run_id, $expected_raw, $replacement );

		return $claimed instanceof Failure ? null : $claimed;
	}

	/**
	 * Returns the newest completed run from identity-global terminal recording order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 *
	 * @return  string|null
	 */
	private function last_completed_run_id( string $identity ): ?string {
		$entries = $this->stores->run_history( $identity )->terminal_entries();
		if ( null === $entries ) {
			$this->logger->warning(
				'Previous completed run could not be read while freezing completion callback state.',
				array( 'name' => $identity )
			);

			return null;
		}

		return RunHistory::newest_completed_run_id( $entries );
	}

	/**
	 * Converts failure detail to the persisted terminal shape.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   EngineError                  $error   Failure detail.
	 * @param   RunFailureStage              $stage   Terminalization stage.
	 * @param   ErrorCode                    $code    Machine-readable cause classification.
	 * @param   array<array-key, mixed>|null $details Generic diagnostic payload, or null when no details are available.
	 *
	 * @return  array{class: string|null, message: string, stage: string, code: string, details?: array<array-key, mixed>}
	 */
	private static function error_detail( EngineError $error, RunFailureStage $stage, ErrorCode $code, ?array $details ): array {
		$detail = array(
			'class'   => $error->exception_class,
			'message' => $error->message,
			'stage'   => $stage->value,
			'code'    => $code->value,
		);
		if ( null !== $details ) {
			$detail['details'] = $details;
		}

		return $detail;
	}

	// endregion
}

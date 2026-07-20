<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ChunkedJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\OneOffJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\JobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\HeartbeatOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\StoreFactory;
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
	 * @phpstan-param (\Closure(): int)|null $liveness_at
	 *
	 * @param   JobType|null  $expected_work_type Expected work contract type, or null to use the persisted kind.
	 * @param   string        $identity           Complete owner-qualified job or chunked job identity.
	 * @param   string        $run_id             Run identifier.
	 * @param   int|null      $action_sequence         Received lifecycle action sequence.
	 * @param   RunStore      $run_store          Active-run store.
	 * @param   \Closure|null $liveness_at        Lazy liveness timestamp, or null to use the current clock time.
	 *
	 * @return  RunState|null
	 */
	public function claim_delivery_ownership( ?JobType $expected_work_type, string $identity, string $run_id, ?int $action_sequence, RunStore $run_store, ?\Closure $liveness_at = null ): ?RunState {
		$inspection   = $run_store->inspect( $run_id );
		$work_label   = null === $expected_work_type ? 'Background-work' : $expected_work_type->value;
		$context_name = null === $expected_work_type ? 'name' : $expected_work_type->machine_key() . '_name';
		if ( $inspection->is_failure() ) {
			$this->logger->warning(
				$work_label . ' run state could not be read; repair WordPress option reads and retry the delivery.',
				array(
					$context_name => $identity,
					'run_id'      => $run_id,
					'error'       => $inspection->error->message,
				)
			);

			return null;
		}

		$snapshot = $inspection->value;
		if ( null === $snapshot ) {
			$this->logger->debug(
				'Stale delivery for a finished or cancelled run was dropped.',
				array(
					$context_name => $identity,
					'run_id'      => $run_id,
				)
			);

			return null;
		}

		$state = $snapshot['state'];
		if ( null === $state ) {
			$this->logger->warning(
				$work_label . ' run state is corrupt; repair or remove the row so the reconciliation sweep can release any remaining lock.',
				array(
					$context_name => $identity,
					'run_id'      => $run_id,
				)
			);

			return null;
		}

		$work_type    = $state->kind;
		$context_name = $work_type->machine_key() . '_name';
		if ( null !== $expected_work_type && $expected_work_type !== $work_type ) {
			$this->logger->warning(
				'Lifecycle stage does not apply to the persisted run kind; the stale or malformed delivery was dropped.',
				array(
					'name'           => $identity,
					'run_id'         => $run_id,
					'persisted_kind' => $work_type->value,
					'expected_kind'  => $expected_work_type->value,
				)
			);

			return null;
		}

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
				$work_type->value . ' run is already terminal; allow the reconciliation sweep to finish its cleanup.',
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

		$at = null !== $liveness_at ? $liveness_at() : $this->clock->now()->getTimestamp();

		// Only confirmed lock ownership permits the delivery to refresh its run row and enter lifecycle work.
		if ( $this->enforce_delivery_fence( $work_type, $identity, $run_id, $state, $run_store, $at, $state->heartbeat_at ) ) {
			return null;
		}

		$state = $run_store->mark_executing_with_heartbeat( $run_id, $state, $at );
		if ( null === $state ) {
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

		return $state;
	}

	/**
	 * Marks a successful job before completing its durable terminal effects.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OneOffJobInterface $job       Completed job.
	 * @param   string             $job_name  Complete owner-qualified job identity.
	 * @param   string             $run_id    Run identifier.
	 * @param   RunState           $state     Running state.
	 * @param   RunStore           $run_store Active-run store.
	 *
	 * @return  void
	 */
	public function complete_job( OneOffJobInterface $job, string $job_name, string $run_id, RunState $state, RunStore $run_store ): void {
		$previous_completed_run_id = $this->last_completed_run_id( $job_name );
		$terminal_state            = $state->with_failed_attempts( 0 )->with_status( RunStatus::Completed )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null )->with_previous_completed_run_id( $previous_completed_run_id );

		$this->claim_and_execute_terminal_transition( $job_name, $run_id, $state, $terminal_state, $run_store, JobType::Job, $job );
	}

	/**
	 * Marks a completed chunked job before completing its durable terminal effects.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ChunkedJobInterface $chunked_job      Completed chunked job.
	 * @param   string              $chunked_job_name Complete owner-qualified chunked job identity.
	 * @param   string              $run_id     Run identifier.
	 * @param   RunState            $state      Running state.
	 * @param   RunStore            $run_store  Active-run store.
	 *
	 * @return  void
	 */
	public function complete_chunked_job( ChunkedJobInterface $chunked_job, string $chunked_job_name, string $run_id, RunState $state, RunStore $run_store ): void {
		$previous_completed_run_id = $this->last_completed_run_id( $chunked_job_name );
		$terminal_state            = $state->with_status( RunStatus::Completed )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null )->with_previous_completed_run_id( $previous_completed_run_id );

		$this->claim_and_execute_terminal_transition( $chunked_job_name, $run_id, $state, $terminal_state, $run_store, JobType::ChunkedJob, $chunked_job );
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
	 * @param   JobType  $work_type             Work contract type.
	 * @param   string   $identity              Complete owner-qualified job or chunked job identity.
	 * @param   string   $run_id                Run identifier.
	 * @param   RunState $state                 Running state from the exact inspected snapshot.
	 * @param   RunStore $run_store             Active-run store.
	 * @param   string   $expected_raw          Exact pre-cancel snapshot.
	 * @param   \Closure $clear_pending_actions Winner-only scheduler-group clear.
	 *
	 * @return  bool Whether the cancellation transition was claimed.
	 */
	public function cancel_run( JobType $work_type, string $identity, string $run_id, RunState $state, RunStore $run_store, string $expected_raw, \Closure $clear_pending_actions ): bool {
		$terminal_state = $state->with_status( RunStatus::Cancelled )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null );
		$terminal_raw   = $this->claim_terminal_transition( $run_id, $state, $terminal_state, $run_store, $expected_raw, );
		if ( null === $terminal_raw ) {
			return false;
		}

		try {
			$clear_pending_actions();
		} finally {
			try {
				$this->terminal_effects->execute_claimed_transition( $identity, $run_id, $terminal_state, $terminal_raw, $run_store, $work_type );
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
	 * @param   JobType     $work_type Work contract type carried by the lifecycle delivery.
	 * @param   string      $identity  Complete owner-qualified job or chunked job identity.
	 * @param   string      $run_id    Run identifier.
	 * @param   RunState    $state     Running state.
	 * @param   RunStore    $run_store Active-run store.
	 * @param   EngineError $error     Failure detail.
	 *
	 * @return  void
	 */
	public function fail_unregistered_run( JobType $work_type, string $identity, string $run_id, RunState $state, RunStore $run_store, EngineError $error ): void {
		$attempts       = RunState::increment_attempts_safely( $state->failed_attempts );
		$terminal_state = $state->with_status( RunStatus::Failed )->with_failed_attempts( $attempts )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null )->with_error( self::error_detail( $error, RunFailureStage::Execution, ApiErrorCode::UnknownWork, self::failed_chunk_for_state( $work_type, $state ) ) );

		$this->claim_and_execute_terminal_transition( $identity, $run_id, $state, $terminal_state, $run_store, $work_type );
	}

	/**
	 * Persists one terminal chunked job failure before callbacks, hooks, and active-state cleanup.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<array-key, mixed>|null $failed_chunk
	 *
	 * @param   JobInterface|null $chunked_job      Failed chunked job, or null when its implementation is unavailable.
	 * @param   string            $chunked_job_name Complete owner-qualified chunked job identity.
	 * @param   string            $run_id           Run identifier.
	 * @param   RunState          $state            Running state.
	 * @param   RunStore          $run_store        Active-run store.
	 * @param   EngineError       $error            Failure detail.
	 * @param   RunFailureStage   $stage            Terminalization stage.
	 * @param   ApiErrorCode      $code             Machine-readable cause classification.
	 * @param   array|null        $failed_chunk     Chunked Job chunk arguments for the failing chunk, or null.
	 * @param   int|null          $attempts         Attempts consumed before failure, or null to derive the count.
	 * @param   string|null       $expected_raw     Exact maintenance snapshot, or null for a live transition.
	 *
	 * @return  void
	 */
	public function fail_chunked_job( ?JobInterface $chunked_job, string $chunked_job_name, string $run_id, RunState $state, RunStore $run_store, EngineError $error, RunFailureStage $stage, ApiErrorCode $code, ?array $failed_chunk = null, ?int $attempts = null, ?string $expected_raw = null ): void {
		$attempts       = $attempts ?? RunState::increment_attempts_safely( $state->failed_attempts );
		$terminal_state = $state->with_status( RunStatus::Failed )->with_failed_attempts( $attempts )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null )->with_error( self::error_detail( $error, $stage, $code, $failed_chunk ) );

		$this->claim_and_execute_terminal_transition( $chunked_job_name, $run_id, $state, $terminal_state, $run_store, JobType::ChunkedJob, $chunked_job, $expected_raw );
	}

	/**
	 * Persists failure detail before firing hooks and releasing active state.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<array-key, mixed>|null $failed_chunk
	 *
	 * @param   JobInterface|null $job           Failed job, or null when its implementation is unavailable.
	 * @param   string            $job_name      Complete owner-qualified job identity.
	 * @param   string            $run_id        Run identifier.
	 * @param   RunState          $state         Running state.
	 * @param   RunStore          $run_store     Active-run store.
	 * @param   EngineError       $error         Job failure detail.
	 * @param   int               $attempts_used Attempts consumed by the invocation.
	 * @param   RunFailureStage   $stage         Terminalization stage.
	 * @param   ApiErrorCode      $code          Machine-readable cause classification.
	 * @param   array|null        $failed_chunk  Chunked Job chunk arguments for the failing chunk, or null for a job.
	 * @param   string|null       $expected_raw  Exact maintenance snapshot, or null for a live transition.
	 *
	 * @return  void
	 */
	public function fail_job( ?JobInterface $job, string $job_name, string $run_id, RunState $state, RunStore $run_store, EngineError $error, int $attempts_used, RunFailureStage $stage, ApiErrorCode $code, ?array $failed_chunk = null, ?string $expected_raw = null ): void {
		$terminal_state = $state->with_status( RunStatus::Failed )->with_failed_attempts( $attempts_used )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null )->with_error( self::error_detail( $error, $stage, $code, $failed_chunk ) );

		$this->claim_and_execute_terminal_transition( $job_name, $run_id, $state, $terminal_state, $run_store, JobType::Job, $job, $expected_raw );
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
	 * @param   JobType  $work_type             Work contract type.
	 * @param   string   $identity              Complete owner-qualified job or chunked job identity.
	 * @param   string   $run_id                Run identifier.
	 * @param   RunState $state                 Running state observed before the fence.
	 * @param   RunStore $run_store             Active-run store.
	 * @param   int|null $at                    Liveness timestamp, or null to use the current clock time.
	 * @param   int|null $expected_heartbeat_at Expected heartbeat for one delivery generation, or null to accept any owned generation.
	 *
	 * @return  bool Whether the caller must abort this delivery.
	 */
	public function enforce_delivery_fence( JobType $work_type, string $identity, string $run_id, RunState $state, RunStore $run_store, ?int $at = null, ?int $expected_heartbeat_at = null ): bool {
		$outcome = $this->overlap_guard->heartbeat( $identity, $state->args_hash, $run_id, $at, $expected_heartbeat_at );
		if ( HeartbeatOutcome::Owned === $outcome ) {
			return false;
		}
		if ( HeartbeatOutcome::GenerationMismatch === $outcome ) {
			return true;
		}

		if ( HeartbeatOutcome::Indeterminate === $outcome ) {
			$context_name = $work_type->machine_key() . '_name';
			$this->logger->debug(
				$work_type->value . ' ownership fence is indeterminate; the delivery aborts without a terminal transition.',
				array(
					$context_name => $identity,
					'run_id'      => $run_id,
				)
			);

			return true;
		}

		$latest_run_id = $this->stores->latest_run_pointer( $identity )->get_latest_for_hash( $state->args_hash );
		$this->supersede_run( $identity, $run_id, $latest_run_id, $state, $run_store, $work_type );

		return true;
	}

	/**
	 * Fences a run that no longer owns its overlap lock before hooks and active-state release.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $identity      Complete owner-qualified job or chunked job identity.
	 * @param   string      $run_id        Run identifier.
	 * @param   string|null $latest_run_id Latest discoverable pointer value for the single-flight identity.
	 * @param   RunState    $state         Running state.
	 * @param   RunStore    $run_store     Active-run store.
	 * @param   JobType     $work_type     Work contract type.
	 * @param   string|null $expected_raw  Exact maintenance snapshot, or null for a live transition.
	 *
	 * @return  void
	 */
	public function supersede_run( string $identity, string $run_id, ?string $latest_run_id, RunState $state, RunStore $run_store, JobType $work_type, ?string $expected_raw = null ): void {
		$terminal_state = $state->with_status( RunStatus::Superseded )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null );
		$terminal_raw   = $this->claim_terminal_transition( $run_id, $state, $terminal_state, $run_store, $expected_raw );
		if ( null === $terminal_raw ) {
			return;
		}
		$context_name = $work_type->machine_key() . '_name';
		$this->logger->info(
			'Superseded ' . $work_type->label() . ' run after its ownership fence failed.',
			array(
				$context_name   => $identity,
				'run_id'        => $run_id,
				'latest_run_id' => $latest_run_id,
			)
		);

		$this->terminal_effects->execute_claimed_transition( $identity, $run_id, $terminal_state, $terminal_raw, $run_store, $work_type );
	}

	/**
	 * Claims a terminal state and executes only the winning transition's effects.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string            $identity        Complete owner-qualified job or chunked job identity.
	 * @param   string            $run_id          Run identifier.
	 * @param   RunState          $expected        Complete running state observed by the terminalizing path.
	 * @param   RunState          $replacement     Terminal replacement state.
	 * @param   RunStore          $run_store       Active-run store.
	 * @param   JobType           $work_type       Work contract type.
	 * @param   JobInterface|null $callback_target Resolved callback target, or null when unavailable.
	 * @param   string|null       $expected_raw    Exact maintenance snapshot, or null for a live transition.
	 *
	 * @return  bool Whether the terminal transition was claimed.
	 */
	private function claim_and_execute_terminal_transition( string $identity, string $run_id, RunState $expected, RunState $replacement, RunStore $run_store, JobType $work_type, ?JobInterface $callback_target = null, ?string $expected_raw = null ): bool {
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

		$this->terminal_effects->execute_claimed_transition( $identity, $run_id, $replacement, $terminal_raw, $run_store, $work_type, $callback_target );

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
		return null === $expected_raw
			? $run_store->replace_if_state_matches( $run_id, $expected, $replacement )
			: $run_store->replace_if_raw_matches( $run_id, $expected_raw, $replacement );
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
	 * @param   EngineError                  $error        Failure detail.
	 * @param   RunFailureStage              $stage        Terminalization stage.
	 * @param   ApiErrorCode                 $code         Machine-readable cause classification.
	 * @param   array<array-key, mixed>|null $failed_chunk Chunked Job chunk arguments for the failing chunk, or null.
	 *
	 * @return  array{class: string|null, message: string, stage: string, code: string, failed_chunk?: array<array-key, mixed>}
	 */
	private static function error_detail( EngineError $error, RunFailureStage $stage, ApiErrorCode $code, ?array $failed_chunk ): array {
		$detail = array(
			'class'   => $error->exception_class,
			'message' => $error->message,
			'stage'   => $stage->value,
			'code'    => $code->value,
		);
		if ( null !== $failed_chunk ) {
			$detail['failed_chunk'] = $failed_chunk;
		}

		return $detail;
	}

	/**
	 * Returns the queued chunk associated with a chunk-processing continuation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   JobType  $work_type Work contract type.
	 * @param   RunState $state     Run state at terminalization.
	 *
	 * @return  array<array-key, mixed>|null
	 */
	private static function failed_chunk_for_state( JobType $work_type, RunState $state ): ?array {
		if ( JobType::ChunkedJob !== $work_type || 'continue' !== $state->pending?->stage ) {
			return null;
		}

		$chunk = $state->queue[0] ?? null;

		return \is_array( $chunk ) ? $chunk : null;
	}

	// endregion
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Coordinates fenced terminal writes and active-run admission.
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
	 * Fences and heartbeats one recoverable running state for a lifecycle action.
	 *
	 * @internal Engine product service.
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
	public function active_run_state( string $work_type, string $name, string $run_id, ?int $action_seq, RunStore $run_store ): ?RunState {
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
	public function complete_run( string $task_name, string $run_id, RunState $state, RunStore $run_store ): void {
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
	 * Claims a retained run as Cancelled before clearing its pending scheduler group and firing hooks.
	 *
	 * @internal Engine product service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \Closure(): mixed $clear_pending_actions
	 *
	 * @param   string   $name                  Stable task or batch name.
	 * @param   string   $run_id                Run identifier.
	 * @param   RunState $state                 Running state from the exact inspected snapshot.
	 * @param   RunStore $run_store             Active-run store.
	 * @param   string   $expected_raw          Exact pre-cancel snapshot.
	 * @param   \Closure $clear_pending_actions Winner-only scheduler-group clear.
	 *
	 * @return  bool Whether the cancellation transition was claimed.
	 */
	public function cancel_run( string $name, string $run_id, RunState $state, RunStore $run_store, string $expected_raw, \Closure $clear_pending_actions ): bool {
		$terminal_state = $state
			->with_status( RunStatus::Cancelled )
			->with_heartbeat_at( $this->clock->now()->getTimestamp() );

		return $this->execute_terminal_transition(
			$name,
			$run_id,
			$state,
			$terminal_state,
			$run_store,
			$clear_pending_actions,
			true,
			'cancelled',
			$expected_raw
		);
	}

	/**
	 * Claims and completes one winner-gated terminal transition.
	 *
	 * Required cleanup encloses winner effects and hooks in the finish contour so an effect failure cannot strand terminal state.
	 * Re-drivable effects remain outside that contour so an effect failure preserves the claim for maintenance.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param 'completed'|'failed'|'cancelled'|'superseded' $event
	 * @phpstan-param EngineError|null ...$hook_extras
	 *
	 * @param   string      $name                          Stable task or batch name.
	 * @param   string      $run_id                        Run identifier.
	 * @param   RunState    $state                         Running state.
	 * @param   RunState    $terminal_state                Terminal replacement state.
	 * @param   RunStore    $run_store                     Active-run store.
	 * @param   \Closure    $pre_hook_effects              Winner-only side effects that precede lifecycle hooks.
	 * @param   bool        $finish_despite_effect_failure Whether effect failure still finishes terminal cleanup.
	 * @param   string      $event                         Terminal lifecycle event name.
	 * @param   string|null $expected_raw                  Exact maintenance snapshot, or null for a live transition.
	 * @param   mixed       ...$hook_extras                Lifecycle-hook payload after the start arguments.
	 *
	 * @return  bool Whether the terminal transition was claimed.
	 */
	public function execute_terminal_transition( string $name, string $run_id, RunState $state, RunState $terminal_state, RunStore $run_store, \Closure $pre_hook_effects, bool $finish_despite_effect_failure, string $event, ?string $expected_raw = null, mixed ...$hook_extras ): bool {
		$terminal_raw = $this->claim_terminal_transition(
			$run_id,
			$state,
			$terminal_state,
			$run_store,
			$expected_raw
		);
		if ( null === $terminal_raw ) {
			return false;
		}

		if ( $finish_despite_effect_failure ) {
			try {
				try {
					$pre_hook_effects();
				} finally {
					$this->fire_lifecycle_hooks( $event, $name, $run_id, $state->start_args, ...$hook_extras );
				}
			} finally {
				$this->finish_terminal_run( $name, $run_id, $terminal_state, $terminal_raw, $run_store );
			}
		} else {
			$pre_hook_effects();

			try {
				$this->fire_lifecycle_hooks( $event, $name, $run_id, $state->start_args, ...$hook_extras );
			} finally {
				$this->finish_terminal_run( $name, $run_id, $terminal_state, $terminal_raw, $run_store );
			}
		}

		return true;
	}

	/**
	 * Persists one terminal batch failure before callbacks, hooks, and active-state cleanup.
	 *
	 * @internal Engine product service.
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
	public function fail_batch( BatchInterface $batch, string $batch_name, string $run_id, RunState $state, RunStore $run_store, EngineError $error, ?int $attempts = null, ?string $expected_raw = null ): void {
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
	public function fail_run( string $task_name, string $run_id, RunState $state, RunStore $run_store, EngineError $error, int $attempts_used, ?string $expected_raw = null ): void {
		$terminal_state = $state
			->with_status( RunStatus::Failed )
			->with_heartbeat_at( $this->clock->now()->getTimestamp() );

		$this->execute_terminal_transition(
			$task_name,
			$run_id,
			$state,
			$terminal_state,
			$run_store,
			function () use ( $attempts_used, $error, $run_id, $state, $task_name ): void {
				$this->stores->failed_run_store( $task_name )->record(
					$run_id,
					$this->clock->now()->getTimestamp(),
					$state->start_args,
					$attempts_used,
					$error
				);
			},
			false,
			'failed',
			$expected_raw,
			$error
		);
	}

	/**
	 * Finishes an already-claimed terminal transition without another compare-and-swap.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $name         Stable task or batch name.
	 * @param   string   $run_id       Run identifier.
	 * @param   RunState $state        Terminalizing run state.
	 * @param   string   $terminal_raw Exact terminal snapshot bytes.
	 * @param   RunStore $run_store    Active-run store.
	 *
	 * @return  bool Whether the run option was confirmed absent before history was appended.
	 */
	public function finish_claimed_transition( string $name, string $run_id, RunState $state, string $terminal_raw, RunStore $run_store ): bool {
		return $this->finish_terminal_run( $name, $run_id, $state, $terminal_raw, $run_store );
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
	 * Transitions a run to Superseded when its owner-scoped heartbeat fence fails.
	 *
	 * @internal Engine product service.
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
	public function supersede_if_fence_lost( string $work_type, string $name, string $run_id, RunState $state, RunStore $run_store, ?int $at = null ): bool {
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
	private function claim_terminal_transition( string $run_id, RunState $expected, RunState $replacement, RunStore $run_store, ?string $expected_raw = null ): ?string {
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
	private function finish_terminal_run( string $name, string $run_id, RunState $state, string $terminal_raw, RunStore $run_store ): bool {
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

		$this->stores->run_history( $name )->record_terminal( $run_id, $state->args_hash, $state->status );

		return true;
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
	 * @param   EngineError|null        $error      Failure detail for a failed event.
	 *
	 * @return  void
	 */
	private function fire_lifecycle_hooks( string $event, string $name, string $run_id, array $start_args, ?EngineError $error = null ): void {
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

	// endregion
}

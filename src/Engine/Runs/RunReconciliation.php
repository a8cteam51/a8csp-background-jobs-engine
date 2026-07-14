<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\MaintenanceFenceOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Tasks\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Repairs stale locks, crashed runs, and incomplete terminal cleanup.
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
	 * @param   OverlapGuard        $overlap_guard       Execution-overlap guard.
	 * @param   StoreFactory        $stores              Name-bound store factory.
	 * @param   ClockInterface      $clock               Timestamp source.
	 * @param   LoggerInterface     $logger              Log event sink.
	 * @param   LockWindows         $lock_windows        Filterable run-lock timing policy.
	 * @param   TerminalTransitions $terminal_transitions Fenced terminal-write coordinator.
	 * @param   TaskRegistry        $tasks               Registered task instances.
	 * @param   BatchRegistry       $batches             Registered batch instances.
	 */
	public function __construct(
		private OverlapGuard $overlap_guard,
		private StoreFactory $stores,
		private ClockInterface $clock,
		private LoggerInterface $logger,
		private LockWindows $lock_windows,
		private TerminalTransitions $terminal_transitions,
		private TaskRegistry $tasks,
		private BatchRegistry $batches,
	) {}

	// endregion

	// region METHODS

	/**
	 * Reclaims a stale lock only after confirming its owning run option remains absent.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name      Stable task or batch name.
	 * @param   string $args_hash Stable argument identity.
	 * @param   string $run_id    Lock owner run identifier.
	 *
	 * @return  void
	 */
	public function reconcile_orphaned_lock( string $name, string $args_hash, string $run_id ): void {
		$run_store = $this->stores->run_store( $name );
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
					'name'   => $name,
					'run_id' => $run_id,
				)
			);
		}

		if ( ! $this->overlap_guard->delete_stale_owned_lock(
			$name,
			$args_hash,
			$run_id,
			$this->lock_windows->lock_staleness( $name, $run_id )
		) ) {
			return;
		}

		$this->logger->warning(
			'Reclaimed stale execution-overlap lock without a valid matching run option.',
			array(
				'name'      => $name,
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
	 * @param   string $name           Stable task or batch name.
	 * @param   string $run_id         Run identifier.
	 * @param   int    $terminal_grace Grace before belt-and-braces terminal cleanup.
	 *
	 * @return  AbstractResult<string|null, EngineError> Transferred argument identity whose foreign lock must remain as fence evidence.
	 */
	public function reconcile_run( string $name, string $run_id, int $terminal_grace ): AbstractResult {
		$run_store = $this->stores->run_store( $name );
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
						'name'   => $name,
						'run_id' => $run_id,
					)
				);
			}

			return new Success( null );
		}

		if ( RunStatus::Running === $state->status ) {
			$staleness = $this->lock_windows->lock_staleness( $name, $run_id );
			$fence     = $this->overlap_guard->fence_abandoned_run(
				$name,
				$state->args_hash,
				$run_id,
				$staleness
			);
			if (
				MaintenanceFenceOutcome::Owned === $fence
				|| MaintenanceFenceOutcome::Indeterminate === $fence
			) {
				return new Success( null );
			}

			$batch     = $this->batches->get( $name );
			$work_type = null !== $batch && null === $this->tasks->get( $name ) ? 'Batch' : 'Task';
			if ( MaintenanceFenceOutcome::Transferred === $fence ) {
				// A transferred lock can appear while the incumbent is still inside its callback; a fresh run heartbeat leaves terminalization to that worker's next ownership fence.
				if ( ! $this->lock_windows->heartbeat_is_stale( $state->heartbeat_at, $staleness ) ) {
					return new Success( $state->args_hash );
				}

				$latest_run_id = $this->stores
					->latest_run_pointer( $name )
					->get_latest_for_hash( $state->args_hash );
				$this->terminal_transitions->supersede_run(
					$name,
					$run_id,
					$latest_run_id,
					$state,
					$run_store,
					$work_type,
					$snapshot['raw']
				);

				return new Success( null );
			}

			$error = new EngineError(
				\sprintf(
					'Run "%1$s" for background-work "%2$s" was failed by the maintenance crash-reclaim path because its owned lock was stale or missing.',
					$run_id,
					$name
				)
			);
			$this->logger->warning(
				'Reclaimed running run whose owned execution-overlap lock was stale or missing.',
				array(
					'name'   => $name,
					'run_id' => $run_id,
				)
			);
			$attempts = RunState::increment_attempts_safely( $state->chunk_retries );
			if ( null !== $batch && null === $this->tasks->get( $name ) ) {
				$this->terminal_transitions->fail_batch(
					$batch,
					$name,
					$run_id,
					$state,
					$run_store,
					$error,
					$attempts,
					$snapshot['raw']
				);
			} else {
				$this->terminal_transitions->fail_run(
					$name,
					$run_id,
					$state,
					$run_store,
					$error,
					$attempts,
					$snapshot['raw']
				);
			}

			return new Success( null );
		}

		$now = $this->clock->now()->getTimestamp();
		if (
			$state->heartbeat_at > \PHP_INT_MAX - $terminal_grace
			|| $now <= $state->heartbeat_at + $terminal_grace
		) {
			return new Success( null );
		}

		if ( $this->terminal_transitions->finish_claimed_transition( $name, $run_id, $state, $snapshot['raw'], $run_store ) ) {
			$this->logger->warning(
				'Reclaimed old terminal run option left behind after transition cleanup.',
				array(
					'name'   => $name,
					'run_id' => $run_id,
					'status' => $state->status->value,
				)
			);
		}

		return new Success( null );
	}

	// endregion
}

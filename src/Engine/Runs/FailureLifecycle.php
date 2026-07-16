<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\NonRetryableExceptionInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\TerminalTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\Randomization\RandomizerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Coordinates retry adjudication, persistence, scheduling, and lifecycle hooks.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class FailureLifecycle {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   BackendInterface    $scheduler           Scheduling facade boundary.
	 * @param   ClockInterface      $clock               Timestamp source.
	 * @param   RandomizerInterface $randomizer          Retry-delay randomness.
	 * @param   LoggerInterface     $logger              Log event sink.
	 * @param   TerminalTransitions $terminal_transitions Fenced terminal-write coordinator.
	 */
	public function __construct(
		private BackendInterface $scheduler,
		private ClockInterface $clock,
		private RandomizerInterface $randomizer,
		private LoggerInterface $logger,
		private TerminalTransitions $terminal_transitions,
	) {}

	// endregion

	// region METHODS

	/**
	 * Applies the retry decision ladder after one task attempt fails.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   TaskInterface $task      Failed task contract.
	 * @param   string        $task_name Complete owner-qualified task identity.
	 * @param   string        $run_id    Run identifier.
	 * @param   RunState      $state     Fenced running state.
	 * @param   RunStore      $run_store Active-run store.
	 * @param   \Throwable    $throwable Failed attempt detail.
	 *
	 * @return  void
	 */
	public function handle_task_failure( TaskInterface $task, string $task_name, string $run_id, RunState $state, RunStore $run_store, \Throwable $throwable ): void {
		$this->handle_failure( $task, $task_name, $run_id, $state, $run_store, $throwable );
	}

	/**
	 * Applies the retry decision ladder after one batch chunk attempt fails.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   BatchInterface          $batch      Failed batch contract.
	 * @param   string                  $batch_name Complete owner-qualified batch identity.
	 * @param   string                  $run_id     Run identifier.
	 * @param   RunState                $state      Fenced running state.
	 * @param   RunStore                $run_store  Active-run store.
	 * @param   \Throwable              $throwable  Failed attempt detail.
	 * @param   array<array-key, mixed> $chunk_args Batch chunk arguments.
	 *
	 * @return  void
	 */
	public function handle_batch_failure( BatchInterface $batch, string $batch_name, string $run_id, RunState $state, RunStore $run_store, \Throwable $throwable, array $chunk_args ): void {
		$this->handle_failure( $batch, $batch_name, $run_id, $state, $run_store, $throwable, $chunk_args );
	}

	/**
	 * Applies the shared retry decision ladder after one work attempt fails.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   TaskInterface|BatchInterface $contract   Failed work contract.
	 * @param   string                       $identity   Complete owner-qualified work identity.
	 * @param   string                       $run_id     Run identifier.
	 * @param   RunState                     $state      Fenced running state.
	 * @param   RunStore                     $run_store  Active-run store.
	 * @param   \Throwable                   $throwable  Failed attempt detail.
	 * @param   array<array-key, mixed>|null $chunk_args Batch chunk arguments, or null for a task.
	 *
	 * @return  void
	 */
	private function handle_failure( TaskInterface|BatchInterface $contract, string $identity, string $run_id, RunState $state, RunStore $run_store, \Throwable $throwable, ?array $chunk_args = null ): void {
		$work_type = $contract instanceof BatchInterface ? 'Batch' : 'Task';
		$reset_at  = $this->clock->now()->getTimestamp();
		if ( $this->terminal_transitions->enforce_delivery_fence( $work_type, $identity, $run_id, $state, $run_store, $reset_at, $state->heartbeat_at ) ) {
			return;
		}
		$state = $run_store->mark_executing_with_heartbeat( $run_id, $state, $reset_at );
		if ( null === $state ) {
			return;
		}

		$attempts_used = $state->failed_attempts + 1;
		$error         = 'Batch' === $work_type && $throwable instanceof InvalidBatchChunkException
			? new EngineError( InvalidBatchChunkException::MESSAGE, \InvalidArgumentException::class )
			: EngineError::from_throwable( $throwable );
		if ( $throwable instanceof NonRetryableExceptionInterface ) {
			$this->fail_terminally( $contract, $identity, $run_id, $state, $run_store, $error, $attempts_used, RunFailureStage::Execution, ApiErrorCode::ExecutionFailed, $chunk_args );

			return;
		}

		try {
			$policy = $this->retry_policy( $identity, $contract->get_retry_policy() );
		} catch ( \Throwable $retry_policy_failure ) {
			if ( $this->terminal_transitions->enforce_delivery_fence( $work_type, $identity, $run_id, $state, $run_store, $state->heartbeat_at, $state->heartbeat_at ) ) {
				return;
			}

			$this->fail_terminally( $contract, $identity, $run_id, $state, $run_store, EngineError::retry_policy( $work_type, $identity, $retry_policy_failure ), $attempts_used, RunFailureStage::Execution, ApiErrorCode::ExecutionFailed, $chunk_args );

			return;
		}

		if ( $this->terminal_transitions->enforce_delivery_fence( $work_type, $identity, $run_id, $state, $run_store, $state->heartbeat_at, $state->heartbeat_at ) ) {
			return;
		}

		if ( $attempts_used >= $policy->max_attempts ) {
			$this->fail_terminally( $contract, $identity, $run_id, $state, $run_store, $error, $attempts_used, RunFailureStage::Execution, ApiErrorCode::ExecutionFailed, $chunk_args );

			return;
		}

		$retry_failure = $this->reschedule_retry( $work_type, $identity, $run_id, $state, $run_store, $policy, $attempts_used, $chunk_args );
		if ( null !== $retry_failure ) {
			$retry_state = $retry_failure['state'];
			if ( $this->terminal_transitions->enforce_delivery_fence( $work_type, $identity, $run_id, $retry_state, $run_store, $retry_state->heartbeat_at, $retry_state->heartbeat_at ) ) {
				return;
			}

			$this->fail_terminally( $contract, $identity, $run_id, $retry_state, $run_store, $retry_failure['error'], $attempts_used, $retry_failure['stage'], $retry_failure['code'], $chunk_args );
		}
	}

	/**
	 * Dispatches one terminal failure through its contract-specific transition.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   TaskInterface|BatchInterface $contract      Failed work contract.
	 * @param   string                       $identity      Complete owner-qualified work identity.
	 * @param   string                       $run_id        Run identifier.
	 * @param   RunState                     $state         Fenced running state.
	 * @param   RunStore                     $run_store     Active-run store.
	 * @param   EngineError                  $error         Terminal failure detail.
	 * @param   int                          $attempts_used Attempts consumed by the invocation.
	 * @param   RunFailureStage              $stage         Terminalization stage.
	 * @param   ApiErrorCode                 $code          Machine-readable cause classification.
	 * @param   array<array-key, mixed>|null $chunk_args    Batch chunk arguments, or null for a task.
	 *
	 * @return  void
	 */
	private function fail_terminally( TaskInterface|BatchInterface $contract, string $identity, string $run_id, RunState $state, RunStore $run_store, EngineError $error, int $attempts_used, RunFailureStage $stage, ApiErrorCode $code, ?array $chunk_args ): void {
		if ( $contract instanceof BatchInterface ) {
			$this->terminal_transitions->fail_batch( $contract, $identity, $run_id, $state, $run_store, $error, $stage, $code, $chunk_args, $attempts_used );

			return;
		}

		$this->terminal_transitions->fail_task( $identity, $run_id, $state, $run_store, $error, $attempts_used, $stage, $code, $chunk_args );
	}

	/**
	 * Resolves a valid identity-specific policy from the contract policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $identity        Complete owner-qualified task or batch identity.
	 * @param   RetryPolicy $contract_policy Policy supplied by the work contract.
	 *
	 * @return  RetryPolicy
	 */
	private function retry_policy( string $identity, RetryPolicy $contract_policy ): RetryPolicy {
		$filtered_policy = \apply_filters( 'a8csp_background_tasks/retry_policy/' . $identity, $contract_policy );
		if ( $filtered_policy instanceof RetryPolicy ) {
			return $filtered_policy;
		}

		$this->logger->warning(
			'Retry policy filter returned an invalid value; return a RetryPolicy instance to override the contract policy.',
			array(
				'name'          => $identity,
				'returned_type' => \get_debug_type( $filtered_policy ),
			)
		);

		return $contract_policy;
	}

	/**
	 * Persists retry state, fires retry hooks, and schedules the same run action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch'               $work_type  Work contract type.
	 * @param   string                       $identity   Complete owner-qualified task or batch identity.
	 * @param   string                       $run_id     Run identifier.
	 * @param   RunState                     $state      Exact persisted state before the retry transition.
	 * @param   RunStore                     $run_store  Active-run store.
	 * @param   RetryPolicy                  $policy     Resolved retry policy.
	 * @param   int                          $attempt    Consumed-attempt count.
	 * @param   array<array-key, mixed>|null $chunk_args Batch chunk arguments, or null for a task.
	 *
	 * @return  array{state: RunState, error: EngineError, stage: RunFailureStage, code: ApiErrorCode}|null Exact failed state and
	 *          detail, or null after successful scheduling, a lost live-state transition, or an aborting ownership fence.
	 */
	private function reschedule_retry( string $work_type, string $identity, string $run_id, RunState $state, RunStore $run_store, RetryPolicy $policy, int $attempt, ?array $chunk_args = null ): ?array {
		try {
			$delay = $this->randomizer->int( 0, $policy->delay_ceiling_for_attempt( $attempt ) );
			$now   = $this->clock->now()->getTimestamp();
			if ( $delay > \PHP_INT_MAX - $now ) {
				return array(
					'state' => $state,
					'error' => new EngineError( \sprintf( '%1$s "%2$s" could not schedule the retry action because its delay exceeds supported Unix seconds; configure a smaller retry-policy delay.', $work_type, $identity ) ),
					'stage' => RunFailureStage::Scheduling,
					'code'  => ApiErrorCode::BackendRejected,
				);
			}
		} catch ( \Throwable $throwable ) {
			return array(
				'state' => $state,
				'error' => EngineError::retry_preparation( $work_type, $identity, $throwable ),
				'stage' => RunFailureStage::Scheduling,
				'code'  => ApiErrorCode::EngineUnavailable,
			);
		}

		$fire_at = $now + $delay;
		if ( $this->terminal_transitions->enforce_delivery_fence( $work_type, $identity, $run_id, $state, $run_store, $fire_at, $state->heartbeat_at ) ) {
			return null;
		}

		try {
			$replacement = $state->with_failed_attempts( $attempt )->with_heartbeat_at( $fire_at )->with_action_seq( $state->action_seq + 1 )->with_executing( false )->with_pending( PendingAction::single( 'run', $fire_at, 10 ) );
		} catch ( \Throwable $throwable ) {
			return array(
				'state' => $state,
				'error' => EngineError::retry_state( $work_type, $identity, $throwable ),
				'stage' => RunFailureStage::Scheduling,
				'code'  => ApiErrorCode::EngineUnavailable,
			);
		}

		try {
			$transitioned = $run_store->replace_if_state_matches( $run_id, $state, $replacement );
		} catch ( \Throwable $throwable ) {
			$context_name = \strtolower( $work_type ) . '_name';
			$this->logger->warning(
				'Retry state could not be persisted; the reconciliation sweep retains the run until storage recovers.',
				array(
					$context_name     => $identity,
					'run_id'          => $run_id,
					'exception_class' => \get_debug_type( $throwable ),
				)
			);

			return null;
		}
		if ( null === $transitioned ) {
			return null;
		}
		$state = $replacement;

		try {
			$this->fire_retrying_hooks( $identity, $run_id, $state->start_args, $attempt, $delay );
		} catch ( \Throwable $throwable ) {
			return array(
				'state' => $state,
				'error' => EngineError::retry_preparation( $work_type, $identity, $throwable ),
				'stage' => RunFailureStage::Execution,
				'code'  => ApiErrorCode::ExecutionFailed,
			);
		}

		if ( $this->terminal_transitions->enforce_delivery_fence( $work_type, $identity, $run_id, $state, $run_store, $fire_at, $state->heartbeat_at ) ) {
			return null;
		}

		try {
			$action_args = array( $identity, $run_id );
			if ( null !== $chunk_args ) {
				$action_args[] = $chunk_args;
			}
			$action_args[] = $state->action_seq;

			$scheduled = $this->scheduler->schedule_single( 'a8csp_background_tasks/run', $fire_at, $action_args, $identity . '|' . $run_id, 10 );
			if ( $scheduled->is_failure() ) {
				return array(
					'state' => $state,
					'error' => EngineError::scheduling( $work_type, $identity, 'retry', $scheduled->error ),
					'stage' => RunFailureStage::Scheduling,
					'code'  => EngineError::api_code_for_scheduling( $scheduled->error ),
				);
			}

			return null;
		} catch ( \Throwable $throwable ) {
			return array(
				'state' => $state,
				'error' => EngineError::retry_preparation( $work_type, $identity, $throwable ),
				'stage' => RunFailureStage::Scheduling,
				'code'  => ApiErrorCode::BackendUnavailable,
			);
		}
	}

	/**
	 * Fires the identity-specific retrying hook before its generic companion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $identity   Complete owner-qualified task or batch identity.
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 * @param   int                     $attempt    One-indexed number of the failed attempt.
	 * @param   int                     $delay      Delay before the next attempt in seconds.
	 *
	 * @return  void
	 */
	private function fire_retrying_hooks( string $identity, string $run_id, array $start_args, int $attempt, int $delay ): void {
		try {
			\do_action( 'a8csp_background_tasks/retrying/' . $identity, $run_id, $start_args, $attempt, $delay );
		} finally {
			\do_action( 'a8csp_background_tasks/retrying', $identity, $run_id, $start_args, $attempt, $delay );
		}
	}

	// endregion
}

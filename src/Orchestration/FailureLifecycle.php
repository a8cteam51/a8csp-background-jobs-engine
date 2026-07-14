<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Orchestration;

use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\NonRetryableExceptionInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\BackendInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Coordinates retry adjudication, persistence, scheduling, and lifecycle hooks.
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
	 * Applies the retry decision ladder after one task or batch attempt fails.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \Closure(): RetryPolicy $policy_provider
	 * @phpstan-param \Closure(RunState, EngineError, int): void $terminal_failure
	 *
	 * @param   'Task'|'Batch'               $work_type        Work contract type.
	 * @param   string                       $name             Stable task or batch name.
	 * @param   string                       $run_id           Run identifier.
	 * @param   RunState                     $state            Fenced running state.
	 * @param   RunStore                     $run_store        Active-run store.
	 * @param   \Throwable                   $throwable        Failed attempt detail.
	 * @param   \Closure                     $policy_provider  Lazy contract-policy provider.
	 * @param   \Closure                     $terminal_failure Terminal failure transition.
	 * @param   array<array-key, mixed>|null $chunk_args       Batch chunk arguments, or null for a task.
	 *
	 * @throws  \Throwable When a terminal failure callback or lifecycle listener fails.
	 *
	 * @return  void
	 */
	public function handle_failed_attempt(
		string $work_type,
		string $name,
		string $run_id,
		RunState $state,
		RunStore $run_store,
		\Throwable $throwable,
		\Closure $policy_provider,
		\Closure $terminal_failure,
		?array $chunk_args = null
	): void {
		if ( $this->terminal_transitions->supersede_if_fence_lost( $work_type, $name, $run_id, $state, $run_store ) ) {
			return;
		}

		$attempts_used = $state->chunk_retries + 1;
		$error         = EngineError::from_throwable( $throwable );
		if ( $throwable instanceof NonRetryableExceptionInterface ) {
			$terminal_failure( $state, $error, $attempts_used );

			return;
		}

		try {
			$policy = $this->retry_policy( $name, $policy_provider() );
		} catch ( \Throwable $retry_policy_failure ) {
			if ( $this->terminal_transitions->supersede_if_fence_lost( $work_type, $name, $run_id, $state, $run_store ) ) {
				return;
			}

			$terminal_failure(
				$state,
				EngineError::retry_policy( $work_type, $name, $retry_policy_failure ),
				$attempts_used
			);

			return;
		}

		if ( $this->terminal_transitions->supersede_if_fence_lost( $work_type, $name, $run_id, $state, $run_store ) ) {
			return;
		}

		if ( $attempts_used >= $policy->max_attempts ) {
			$terminal_failure( $state, $error, $attempts_used );

			return;
		}

		$retry_failure = $this->reschedule_retry(
			$work_type,
			$name,
			$run_id,
			$state,
			$run_store,
			$policy,
			$attempts_used,
			$chunk_args
		);
		if ( null !== $retry_failure ) {
			$retry_state = $retry_failure['state'];
			if ( $this->terminal_transitions->supersede_if_fence_lost( $work_type, $name, $run_id, $retry_state, $run_store ) ) {
				return;
			}

			$terminal_failure( $retry_state, $retry_failure['error'], $attempts_used );
		}
	}

	/**
	 * Resolves a valid name-specific policy from the contract policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $name            Stable task or batch name.
	 * @param   RetryPolicy $contract_policy Policy supplied by the work contract.
	 *
	 * @return  RetryPolicy
	 */
	private function retry_policy( string $name, RetryPolicy $contract_policy ): RetryPolicy {
		$filtered_policy = \apply_filters(
			'a8csp/background_tasks/retry_policy/' . $name,
			$contract_policy
		);
		if ( $filtered_policy instanceof RetryPolicy ) {
			return $filtered_policy;
		}

		$this->logger->warning(
			'Retry policy filter returned an invalid value; return a RetryPolicy instance to override the contract policy.',
			array(
				'name'          => $name,
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
	 * @param   string                       $name       Stable task or batch name.
	 * @param   string                       $run_id     Run identifier.
	 * @param   RunState                     $state      Exact persisted state before the retry transition.
	 * @param   RunStore                     $run_store  Active-run store.
	 * @param   RetryPolicy                  $policy     Resolved retry policy.
	 * @param   int                          $attempt    Consumed-attempt count.
	 * @param   array<array-key, mixed>|null $chunk_args Batch chunk arguments, or null for a task.
	 *
	 * @return  array{state: RunState, error: EngineError}|null Exact failed state and detail, or null after success or a lost fence.
	 */
	private function reschedule_retry(
		string $work_type,
		string $name,
		string $run_id,
		RunState $state,
		RunStore $run_store,
		RetryPolicy $policy,
		int $attempt,
		?array $chunk_args = null
	): ?array {
		try {
			$delay = $policy->delay_for_attempt( $attempt, $this->randomizer );
			$now   = $this->clock->now()->getTimestamp();
			if ( $delay > \PHP_INT_MAX - $now ) {
				return array(
					'state' => $state,
					'error' => new EngineError(
						\sprintf(
							'%1$s "%2$s" could not schedule the retry action because its delay exceeds supported Unix seconds; configure a smaller retry-policy delay.',
							$work_type,
							$name
						)
					),
				);
			}
		} catch ( \Throwable $throwable ) {
			return array(
				'state' => $state,
				'error' => EngineError::retry_preparation( $work_type, $name, $throwable ),
			);
		}

		$fire_at = $now + $delay;
		if ( $this->terminal_transitions->supersede_if_fence_lost( $work_type, $name, $run_id, $state, $run_store, $fire_at ) ) {
			return null;
		}

		try {
			$replacement = $state
				->with_chunk_retries( $attempt )
				->with_heartbeat_at( $fire_at )
				->with_action_seq( $state->action_seq + 1 );
			if ( null === $run_store->transition_state( $run_id, $state, $replacement ) ) {
				return null;
			}
			$state = $replacement;
			$this->fire_retrying_hooks( $name, $run_id, $state->start_args, $attempt, $delay );
		} catch ( \Throwable $throwable ) {
			return array(
				'state' => $state,
				'error' => EngineError::retry_preparation( $work_type, $name, $throwable ),
			);
		}

		if ( $this->terminal_transitions->supersede_if_fence_lost( $work_type, $name, $run_id, $state, $run_store, $fire_at ) ) {
			return null;
		}

		try {
			$action_args = array( $name, $run_id );
			if ( null !== $chunk_args ) {
				$action_args[] = $chunk_args;
			}
			$action_args[] = $state->action_seq;

			$scheduled = $this->scheduler->schedule_single(
				'a8csp/background_tasks/run',
				$fire_at,
				$action_args,
				$name . '|' . $run_id,
				10
			);
			if ( $scheduled->is_failure() ) {
				return array(
					'state' => $state,
					'error' => EngineError::scheduling( $work_type, $name, 'retry', $scheduled->error ),
				);
			}

			return null;
		} catch ( \Throwable $throwable ) {
			return array(
				'state' => $state,
				'error' => EngineError::retry_preparation( $work_type, $name, $throwable ),
			);
		}
	}

	/**
	 * Fires the name-specific retrying hook before its generic companion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name       Stable task or batch name.
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 * @param   int                     $attempt    One-indexed number of the failed attempt.
	 * @param   int                     $delay      Delay before the next attempt in seconds.
	 *
	 * @return  void
	 */
	private function fire_retrying_hooks(
		string $name,
		string $run_id,
		array $start_args,
		int $attempt,
		int $delay
	): void {
		try {
			\do_action(
				'a8csp/background_tasks/retrying/' . $name,
				$run_id,
				$start_args,
				$attempt,
				$delay
			);
		} finally {
			\do_action(
				'a8csp/background_tasks/retrying',
				$name,
				$run_id,
				$start_args,
				$attempt,
				$delay
			);
		}
	}

	// endregion
}

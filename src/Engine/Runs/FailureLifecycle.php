<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ChunkedJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\OneOffJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\NonRetryableExceptionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Backends\BackendInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\RandomizerInterface;
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
	 * @param   BackendInterface    $scheduler            Scheduling facade boundary.
	 * @param   ClockInterface      $clock                Timestamp source.
	 * @param   RandomizerInterface $randomizer           Retry-delay randomness.
	 * @param   LoggerInterface     $logger               Log event sink.
	 * @param   RunTransitions      $terminal_transitions Fenced terminal-write coordinator.
	 */
	public function __construct(
		private BackendInterface $scheduler,
		private ClockInterface $clock,
		private RandomizerInterface $randomizer,
		private LoggerInterface $logger,
		private RunTransitions $terminal_transitions,
	) {}

	// endregion

	// region METHODS

	/**
	 * Applies the retry decision ladder after one job attempt fails.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OneOffJobInterface $job      Failed job contract.
	 * @param   string             $job_name Complete owner-qualified job identity.
	 * @param   string             $run_id    Run identifier.
	 * @param   RunState           $state     Fenced running state.
	 * @param   RunStore           $run_store Active-run store.
	 * @param   \Throwable         $throwable Failed attempt detail.
	 *
	 * @return  void
	 */
	public function handle_job_failure( OneOffJobInterface $job, string $job_name, string $run_id, RunState $state, RunStore $run_store, \Throwable $throwable ): void {
		$this->handle_failure( 'Job', $job, $job_name, $run_id, $state, $run_store, $throwable );
	}

	/**
	 * Applies the retry decision ladder after one chunked job chunk attempt fails.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ChunkedJobInterface     $chunked_job      Failed chunked job contract.
	 * @param   string                  $chunked_job_name Complete owner-qualified chunked job identity.
	 * @param   string                  $run_id     Run identifier.
	 * @param   RunState                $state      Fenced running state.
	 * @param   RunStore                $run_store  Active-run store.
	 * @param   \Throwable              $throwable  Failed attempt detail.
	 * @param   array<array-key, mixed> $chunk_args Chunked Job chunk arguments.
	 *
	 * @return  void
	 */
	public function handle_chunked_job_failure( ChunkedJobInterface $chunked_job, string $chunked_job_name, string $run_id, RunState $state, RunStore $run_store, \Throwable $throwable, array $chunk_args ): void {
		$this->handle_failure( 'ChunkedJob', $chunked_job, $chunked_job_name, $run_id, $state, $run_store, $throwable, $chunk_args );
	}

	/**
	 * Applies the shared retry decision ladder after one work attempt fails.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Job'|'ChunkedJob'                     $work_type  Work contract type selected by the typed delivery path.
	 * @param   OneOffJobInterface|ChunkedJobInterface $contract   Failed work contract.
	 * @param   string                                 $identity   Complete owner-qualified work identity.
	 * @param   string                                 $run_id     Run identifier.
	 * @param   RunState                               $state      Fenced running state.
	 * @param   RunStore                               $run_store  Active-run store.
	 * @param   \Throwable                             $throwable  Failed attempt detail.
	 * @param   array<array-key, mixed>|null           $chunk_args Chunked Job chunk arguments, or null for a job.
	 *
	 * @return  void
	 */
	private function handle_failure( string $work_type, OneOffJobInterface|ChunkedJobInterface $contract, string $identity, string $run_id, RunState $state, RunStore $run_store, \Throwable $throwable, ?array $chunk_args = null ): void {
		$reset_at = $this->clock->now()->getTimestamp();
		if ( $this->terminal_transitions->enforce_delivery_fence( $work_type, $identity, $run_id, $state, $run_store, $reset_at, $state->heartbeat_at ) ) {
			return;
		}
		$state = $run_store->mark_executing_with_heartbeat( $run_id, $state, $reset_at );
		if ( null === $state ) {
			return;
		}

		$attempts_used = $state->failed_attempts + 1;
		$error         = 'ChunkedJob' === $work_type && $throwable instanceof InvalidChunkException
			? new EngineError( $throwable->getMessage(), \InvalidArgumentException::class )
			: EngineError::from_throwable( $throwable );
		if ( $throwable instanceof NonRetryableExceptionInterface ) {
			$this->fail_terminally( $work_type, $contract, $identity, $run_id, $state, $run_store, $error, $attempts_used, RunFailureStage::Execution, ApiErrorCode::ExecutionFailed, $chunk_args );

			return;
		}

		try {
			$policy = $this->retry_policy( $identity, $contract->get_retry_policy() );
		} catch ( \Throwable $retry_policy_failure ) {
			if ( $this->terminal_transitions->enforce_delivery_fence( $work_type, $identity, $run_id, $state, $run_store, $state->heartbeat_at, $state->heartbeat_at ) ) {
				return;
			}

			$this->fail_terminally( $work_type, $contract, $identity, $run_id, $state, $run_store, EngineError::retry_policy( $work_type, $identity, $retry_policy_failure ), $attempts_used, RunFailureStage::Execution, ApiErrorCode::ExecutionFailed, $chunk_args );

			return;
		}

		if ( $this->terminal_transitions->enforce_delivery_fence( $work_type, $identity, $run_id, $state, $run_store, $state->heartbeat_at, $state->heartbeat_at ) ) {
			return;
		}

		if ( $attempts_used >= $policy->max_attempts ) {
			$this->fail_terminally( $work_type, $contract, $identity, $run_id, $state, $run_store, $error, $attempts_used, RunFailureStage::Execution, ApiErrorCode::ExecutionFailed, $chunk_args );

			return;
		}

		$retry_failure = $this->reschedule_retry( $work_type, $identity, $run_id, $state, $run_store, $policy, $attempts_used, $error );
		if ( null !== $retry_failure ) {
			$retry_state = $retry_failure['state'];
			if ( $this->terminal_transitions->enforce_delivery_fence( $work_type, $identity, $run_id, $retry_state, $run_store, $retry_state->heartbeat_at, $retry_state->heartbeat_at ) ) {
				return;
			}

			$this->fail_terminally( $work_type, $contract, $identity, $run_id, $retry_state, $run_store, $retry_failure['error'], $attempts_used, $retry_failure['stage'], $retry_failure['code'], $chunk_args );
		}
	}

	/**
	 * Dispatches one terminal failure through its contract-specific transition.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Job'|'ChunkedJob'                     $work_type     Work contract type selected by the typed delivery path.
	 * @param   OneOffJobInterface|ChunkedJobInterface $contract      Failed work contract.
	 * @param   string                                 $identity      Complete owner-qualified work identity.
	 * @param   string                                 $run_id        Run identifier.
	 * @param   RunState                               $state         Fenced running state.
	 * @param   RunStore                               $run_store     Active-run store.
	 * @param   EngineError                            $error         Terminal failure detail.
	 * @param   int                                    $attempts_used Attempts consumed by the invocation.
	 * @param   RunFailureStage                        $stage         Terminalization stage.
	 * @param   ApiErrorCode                           $code          Machine-readable cause classification.
	 * @param   array<array-key, mixed>|null           $chunk_args    Chunked Job chunk arguments, or null for a job.
	 *
	 * @return  void
	 */
	private function fail_terminally( string $work_type, OneOffJobInterface|ChunkedJobInterface $contract, string $identity, string $run_id, RunState $state, RunStore $run_store, EngineError $error, int $attempts_used, RunFailureStage $stage, ApiErrorCode $code, ?array $chunk_args ): void {
		if ( 'ChunkedJob' === $work_type && $contract instanceof ChunkedJobInterface ) {
			$this->terminal_transitions->fail_chunked_job( $contract, $identity, $run_id, $state, $run_store, $error, $stage, $code, $chunk_args, $attempts_used );

			return;
		}

		$this->terminal_transitions->fail_job( $identity, $run_id, $state, $run_store, $error, $attempts_used, $stage, $code, $chunk_args );
	}

	/**
	 * Resolves a valid identity-specific policy from the contract policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $identity        Complete owner-qualified job or chunked job identity.
	 * @param   RetryPolicy $contract_policy Policy supplied by the work contract.
	 *
	 * @return  RetryPolicy
	 */
	private function retry_policy( string $identity, RetryPolicy $contract_policy ): RetryPolicy {
		/**
		 * Filters the retry policy for one work identity.
		 *
		 * The dynamic portion of the hook name, `$identity`, refers to the owner-qualified work identity.
		 *
		 * @since   1.0.0
		 * @version 1.0.0
		 *
		 * @param   RetryPolicy $contract_policy Retry policy supplied by the work contract.
		 */
		$filtered_policy = \apply_filters( 'a8csp_jobs_engine/retry_policy/' . $identity, $contract_policy );
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
	 * @param   'Job'|'ChunkedJob' $work_type Work contract type.
	 * @param   string             $identity  Complete owner-qualified job or chunked job identity.
	 * @param   string             $run_id    Run identifier.
	 * @param   RunState           $state     Exact persisted state before the retry transition.
	 * @param   RunStore           $run_store Active-run store.
	 * @param   RetryPolicy        $policy    Resolved retry policy.
	 * @param   int                $attempt   Consumed-attempt count.
	 * @param   EngineError        $error     Failed-attempt detail.
	 *
	 * @return  array{state: RunState, error: EngineError, stage: RunFailureStage, code: ApiErrorCode}|null Exact failed state and
	 *          detail, or null after successful scheduling, a lost live-state transition, or an aborting ownership fence.
	 */
	private function reschedule_retry( string $work_type, string $identity, string $run_id, RunState $state, RunStore $run_store, RetryPolicy $policy, int $attempt, EngineError $error ): ?array {
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
			$replacement = $state->with_failed_attempts( $attempt )->with_heartbeat_at( $fire_at )->with_action_sequence( $state->action_sequence + 1 )->with_executing( false )->with_pending( PendingAction::single( 'run', $fire_at, 10 ) );
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
			$context_name = ( 'Job' === $work_type ? 'job' : 'chunked_job' ) . '_name';
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
			$this->fire_retry_scheduled_hooks( $identity, $run_id, $state->start_args, $attempt, $delay );
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
			$scheduled = $this->scheduler->schedule_single( 'ChunkedJob' === $work_type ? 'a8csp_jobs_engine/run_chunk' : 'a8csp_jobs_engine/run_job', $fire_at, array( $identity, $run_id, $state->action_sequence ), $identity . '|' . $run_id, 10 );
			if ( $scheduled->is_failure() ) {
				return array(
					'state' => $state,
					'error' => EngineError::scheduling( $work_type, $identity, 'retry', $scheduled->error ),
					'stage' => RunFailureStage::Scheduling,
					'code'  => EngineError::api_code_for_scheduling( $scheduled->error ),
				);
			}

			$this->logger->warning(
				'Run attempt failed and was scheduled for retry; correct recurring failures before the retry policy is exhausted.',
				array(
					'name'         => $identity,
					'run_id'       => $run_id,
					'attempt'      => $attempt,
					'max_attempts' => $policy->max_attempts,
					'delay'        => $delay,
					'error_class'  => $error->exception_class,
				)
			);

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
	 * Fires the retry-scheduled hooks after the retry state persists.
	 *
	 * The identity-specific hook precedes its generic companion and the retry action scheduling write.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $identity   Complete owner-qualified job or chunked job identity.
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 * @param   int                     $attempt    One-indexed number of the failed attempt.
	 * @param   int                     $delay      Delay before the next attempt in seconds.
	 *
	 * @return  void
	 */
	private function fire_retry_scheduled_hooks( string $identity, string $run_id, array $start_args, int $attempt, int $delay ): void {
		try {
			/**
			 * Fires after retry state is persisted for one failed work attempt.
			 *
			 * The dynamic portion of the hook name, `$identity`, refers to the owner-qualified work identity.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   string                  $run_id     Run identifier.
			 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
			 * @param   int                     $attempt    One-indexed number of the failed attempt.
			 * @param   int                     $delay      Delay before the next attempt in seconds.
			 */
			\do_action( 'a8csp_jobs_engine/retry_scheduled/' . $identity, $run_id, $start_args, $attempt, $delay );
		} finally {
			/**
			 * Fires after the identity-specific retry-scheduled hook.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   string                  $identity   Complete owner-qualified job or chunked job identity.
			 * @param   string                  $run_id     Run identifier.
			 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
			 * @param   int                     $attempt    One-indexed number of the failed attempt.
			 * @param   int                     $delay      Delay before the next attempt in seconds.
			 */
			\do_action( 'a8csp_jobs_engine/retry_scheduled', $identity, $run_id, $start_args, $attempt, $delay );
		}
	}

	// endregion
}

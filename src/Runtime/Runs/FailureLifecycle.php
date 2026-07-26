<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\RandomizerInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\KindHandlerInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
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
	 * @param   DeliveryScheduler   $delivery_scheduler   Lifecycle-delivery scheduler.
	 * @param   ClockInterface      $clock                Timestamp source.
	 * @param   RandomizerInterface $randomizer           Retry-delay randomness.
	 * @param   LoggerInterface     $logger               Log event sink.
	 * @param   RunTransitions      $terminal_transitions Fenced terminal-write coordinator.
	 * @param   LifecycleEffects    $lifecycle_effects    Client lifecycle-hook dispatcher.
	 */
	public function __construct(
		private DeliveryScheduler $delivery_scheduler,
		private ClockInterface $clock,
		private RandomizerInterface $randomizer,
		private LoggerInterface $logger,
		private RunTransitions $terminal_transitions,
		private LifecycleEffects $lifecycle_effects,
	) {}

	// endregion

	// region METHODS

	/**
	 * Applies the shared retry decision ladder after one work attempt fails.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   KindHandlerInterface         $handler        Handler selected by the persisted kind.
	 * @param   JobOptions                   $options        Registered policy declaration.
	 * @param   Identity                     $identity       Complete scope-qualified work identity.
	 * @param   string                       $run_id         Run identifier.
	 * @param   RunState                     $state          Fenced running state.
	 * @param   RunStore                     $run_store      Active-run store.
	 * @param   \Throwable                   $throwable      Failed attempt detail.
	 * @param   RunFailureStage              $terminal_stage Failure stage when the retry ladder terminalizes the attempt.
	 * @param   string                       $retry_stage    Pending-action stage for another attempt.
	 * @param   array<array-key, mixed>|null $details        Generic diagnostic payload, or null when no details are available.
	 *
	 * @return  void
	 */
	public function handle_failure( KindHandlerInterface $handler, JobOptions $options, Identity $identity, string $run_id, RunState $state, RunStore $run_store, \Throwable $throwable, RunFailureStage $terminal_stage, string $retry_stage, ?array $details = null ): void {
		$reset_at = $this->clock->now()->getTimestamp();
		if ( $this->terminal_transitions->enforce_delivery_fence( $handler, $identity, $run_id, $state, $run_store, $reset_at, $state->heartbeat_at ) ) {
			return;
		}
		$state = $run_store->mark_executing_with_heartbeat( $run_id, $state, $reset_at );
		if ( $state instanceof Failure || null === $state ) {
			return;
		}

		$attempts_used = $state->failed_attempts + 1;
		$error         = $handler->failure_error( $throwable );
		if ( $throwable instanceof NonRetryableException ) {
			$this->fail_terminally( $handler, $identity, $run_id, $state, $run_store, $error, $attempts_used, $terminal_stage, ErrorCode::ExecutionFailed, $details );

			return;
		}

		try {
			$policy = $this->retry_policy( $identity, $options->retry ?? new RetryPolicy() );
		} catch ( \Throwable $retry_policy_failure ) {
			if ( $this->terminal_transitions->enforce_delivery_fence( $handler, $identity, $run_id, $state, $run_store, $state->heartbeat_at, $state->heartbeat_at ) ) {
				return;
			}

			$this->fail_terminally( $handler, $identity, $run_id, $state, $run_store, EngineError::retry_policy( $handler->key(), $identity, $retry_policy_failure ), $attempts_used, $terminal_stage, ErrorCode::ExecutionFailed, $details );

			return;
		}

		if ( $this->terminal_transitions->enforce_delivery_fence( $handler, $identity, $run_id, $state, $run_store, $state->heartbeat_at, $state->heartbeat_at ) ) {
			return;
		}

		if ( $attempts_used >= $policy->max_attempts ) {
			$this->fail_terminally( $handler, $identity, $run_id, $state, $run_store, $error, $attempts_used, $terminal_stage, ErrorCode::ExecutionFailed, $details );

			return;
		}

		$retry_failure = $this->reschedule_retry( $handler, $identity, $run_id, $state, $run_store, $policy, $attempts_used, $error, $retry_stage );
		if ( null !== $retry_failure ) {
			$retry_state = $retry_failure['state'];
			if ( $this->terminal_transitions->enforce_delivery_fence( $handler, $identity, $run_id, $retry_state, $run_store, $retry_state->heartbeat_at, $retry_state->heartbeat_at ) ) {
				return;
			}

			$this->fail_terminally( $handler, $identity, $run_id, $retry_state, $run_store, $retry_failure['error'], $attempts_used, $retry_failure['stage'], $retry_failure['code'], $details );
		}
	}

	/**
	 * Dispatches one terminal failure through the resolved handler.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   KindHandlerInterface         $handler       Handler selected by the persisted kind.
	 * @param   Identity                     $identity      Complete scope-qualified work identity.
	 * @param   string                       $run_id        Run identifier.
	 * @param   RunState                     $state         Fenced running state.
	 * @param   RunStore                     $run_store     Active-run store.
	 * @param   EngineError                  $error         Terminal failure detail.
	 * @param   int                          $attempts_used Attempts consumed by the invocation.
	 * @param   RunFailureStage              $stage         Terminalization stage.
	 * @param   ErrorCode                    $code          Machine-readable cause classification.
	 * @param   array<array-key, mixed>|null $details       Generic diagnostic payload, or null when no details are available.
	 *
	 * @return  void
	 */
	private function fail_terminally( KindHandlerInterface $handler, Identity $identity, string $run_id, RunState $state, RunStore $run_store, EngineError $error, int $attempts_used, RunFailureStage $stage, ErrorCode $code, ?array $details ): void {
		$this->terminal_transitions->fail_run( $handler, $identity, $run_id, $state, $run_store, $error, $attempts_used, $stage, $code, $details );
	}

	/**
	 * Resolves a valid identity-specific policy from the registered or default policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity    $identity    Complete scope-qualified job or chunked job identity.
	 * @param   RetryPolicy $base_policy Registered or engine-default policy.
	 *
	 * @return  RetryPolicy
	 */
	private function retry_policy( Identity $identity, RetryPolicy $base_policy ): RetryPolicy {
		/**
		 * Filters the retry policy before work-identity-specific filtering.
		 *
		 * @since   1.0.0
		 * @version 1.0.0
		 *
		 * @param   RetryPolicy $base_policy Registered or engine-default retry policy.
		 * @param   string      $identity    Complete scope-qualified work identity.
		 */
		$filtered_policy = \apply_filters( 'a8csp_bgje/retry_policy', $base_policy, (string) $identity );

		/**
		 * Filters the retry policy for one work identity.
		 *
		 * The dynamic portion of the hook name, `$identity`, refers to the scope-qualified work identity.
		 *
		 * @since   1.0.0
		 * @version 1.0.0
		 *
		 * @param   RetryPolicy $filtered_policy Generic-filtered retry policy.
		 */
		$filtered_policy = \apply_filters( 'a8csp_bgje/retry_policy/' . (string) $identity, $filtered_policy );
		if ( $filtered_policy instanceof RetryPolicy ) {
			return $filtered_policy;
		}

		$this->logger->warning(
			'Retry policy filter returned an invalid value; return a RetryPolicy instance to override the registered policy.',
			array(
				'identity'      => (string) $identity,
				'returned_type' => \get_debug_type( $filtered_policy ),
			)
		);

		return $base_policy;
	}

	/**
	 * Persists retry state, fires retry hooks, and schedules the same work delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   KindHandlerInterface $handler     Handler selected by the persisted kind.
	 * @param   Identity             $identity    Complete scope-qualified job or chunked job identity.
	 * @param   string               $run_id      Run identifier.
	 * @param   RunState             $state       Exact persisted state before the retry transition.
	 * @param   RunStore             $run_store   Active-run store.
	 * @param   RetryPolicy          $policy      Resolved retry policy.
	 * @param   int                  $attempt     Consumed-attempt count.
	 * @param   EngineError          $error       Failed-attempt detail.
	 * @param   string               $retry_stage Pending-action stage for another attempt.
	 *
	 * @throws  \LogicException When the claimed delivery has no durable pending-action descriptor.
	 *
	 * @return  array{state: RunState, error: EngineError, stage: RunFailureStage, code: ErrorCode}|null Exact failed state and
	 *          detail, or null after successful scheduling, a lost live-state transition, or an aborting ownership fence.
	 */
	private function reschedule_retry( KindHandlerInterface $handler, Identity $identity, string $run_id, RunState $state, RunStore $run_store, RetryPolicy $policy, int $attempt, EngineError $error, string $retry_stage ): ?array {
		$kind = $handler->key();
		try {
			$delay = $this->randomizer->int( 0, $policy->delay_ceiling_for_attempt( $attempt ) );
			$now   = $this->clock->now()->getTimestamp();
			if ( $delay > \PHP_INT_MAX - $now ) {
				return array(
					'state' => $state,
					'error' => new EngineError( \sprintf( '%1$s "%2$s" could not schedule the retry action because its delay exceeds supported Unix seconds; configure a smaller retry-policy delay.', $kind, (string) $identity ) ),
					'stage' => RunFailureStage::scheduling(),
					'code'  => ErrorCode::BackendRejected,
				);
			}
		} catch ( \Throwable $throwable ) {
			return array(
				'state' => $state,
				'error' => EngineError::retry_preparation( $kind, $identity, $throwable ),
				'stage' => RunFailureStage::scheduling(),
				'code'  => ErrorCode::EngineUnavailable,
			);
		}

		$fire_at = $now + $delay;
		if ( $this->terminal_transitions->enforce_delivery_fence( $handler, $identity, $run_id, $state, $run_store, $fire_at, $state->heartbeat_at ) ) {
			return null;
		}

		$priority = $state->pending->priority ?? throw new \LogicException( 'Claimed retry delivery requires a durable pending-action descriptor.' );
		try {
			$pending     = PendingAction::single( $retry_stage, $fire_at, $priority );
			$replacement = $state->with_failed_attempts( $attempt )->with_heartbeat_at( $fire_at )->with_action_sequence( $state->action_sequence + 1 )->with_executing( false )->with_pending( $pending );
		} catch ( \Throwable $throwable ) {
			return array(
				'state' => $state,
				'error' => EngineError::retry_state( $kind, $identity, $throwable ),
				'stage' => RunFailureStage::scheduling(),
				'code'  => ErrorCode::EngineUnavailable,
			);
		}

		try {
			$transitioned = $run_store->replace_if_state_matches( $run_id, $state, $replacement );
		} catch ( \Throwable $throwable ) {
			$this->logger->warning(
				'Retry state could not be persisted; the reconciliation sweep retains the run until storage recovers.',
				array(
					'identity'        => (string) $identity,
					'run_id'          => $run_id,
					'kind'            => $kind,
					'exception_class' => \get_debug_type( $throwable ),
				)
			);

			return null;
		}
		if ( $transitioned instanceof Failure || null === $transitioned ) {
			return null;
		}
		$state = $replacement;

		try {
			$this->lifecycle_effects->fire_retry_scheduled( $identity, $run_id, $state->start_args, $attempt, $delay );
		} catch ( \Throwable $throwable ) {
			return array(
				'state' => $state,
				'error' => EngineError::retry_preparation( $kind, $identity, $throwable ),
				'stage' => RunFailureStage::execution(),
				'code'  => ErrorCode::ExecutionFailed,
			);
		}

		if ( $this->terminal_transitions->enforce_delivery_fence( $handler, $identity, $run_id, $state, $run_store, $fire_at, $state->heartbeat_at ) ) {
			return null;
		}

		try {
			$scheduled = $this->delivery_scheduler->schedule( $identity, $run_id, $state->action_sequence, $pending );
			if ( $scheduled->is_failure() ) {
				return array(
					'state' => $state,
					'error' => EngineError::scheduling( $kind, $identity, 'retry', $scheduled->error ),
					'stage' => RunFailureStage::scheduling(),
					'code'  => $scheduled->error->reason->api_code(),
				);
			}

			$this->logger->warning(
				'Run attempt failed and was scheduled for retry; correct recurring failures before the retry policy is exhausted.',
				array(
					'identity'     => (string) $identity,
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
				'error' => EngineError::retry_preparation( $kind, $identity, $throwable ),
				'stage' => RunFailureStage::scheduling(),
				'code'  => ErrorCode::BackendUnavailable,
			);
		}
	}

	// endregion
}

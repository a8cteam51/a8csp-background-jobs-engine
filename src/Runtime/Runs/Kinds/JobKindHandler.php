<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\JobExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Owns one-off job admission, delivery, failure, and inspection behavior.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class JobKindHandler extends AbstractKindHandler {
	// region FIELDS AND CONSTANTS

	/**
	 * Persisted key owned by this handler.
	 *
	 * Handler resolution keys the registry by this value and looks it up by a declaration's
	 * `JobKind`, so `JobKind::job()` carries a frozen public copy that must stay byte-equal.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string KIND = 'job';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   JobRegistry      $registry             Registered work definitions.
	 * @param   LoggerInterface  $logger               Log event sink.
	 * @param   ClockInterface   $clock                Timestamp source.
	 * @param   LockWindows      $lock_windows         Filterable run-lock timing policy.
	 * @param   RunTransitions   $terminal_transitions Fenced terminal-write coordinator.
	 * @param   LifecycleEffects $terminal_effects     Client lifecycle-effect executor.
	 * @param   FailureLifecycle $failure_lifecycle    Retry adjudication coordinator.
	 */
	public function __construct(
		JobRegistry $registry,
		LoggerInterface $logger,
		ClockInterface $clock,
		LockWindows $lock_windows,
		RunTransitions $terminal_transitions,
		private LifecycleEffects $terminal_effects,
		private FailureLifecycle $failure_lifecycle,
	) {
		parent::__construct( $registry, $logger, $clock, $lock_windows, $terminal_transitions );
	}

	// endregion

	// region METHODS

	/**
	 * Returns the opaque persisted job kind key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	#[\Override]
	public function key(): string {
		return self::KIND;
	}

	/**
	 * Fires started hooks after admission and terminalizes listener failure before scheduler acceptance.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified job identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Persisted running state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  EngineError|null Failure returned to the admission caller, or null.
	 */
	#[\Override]
	public function after_dispatch( Identity $identity, string $run_id, RunState $state, RunStore $run_store ): ?EngineError {
		try {
			$this->terminal_effects->fire_started( $identity, $run_id, $state->start_args );
		} catch ( \Throwable $throwable ) {
			$exception_type = \get_debug_type( $throwable );
			$error          = new EngineError(
				\sprintf( '%1$s "%2$s" started listener failed because %3$s was thrown. Fix the started-hook listener before dispatching the job again.', self::KIND, (string) $identity, $exception_type ),
				$exception_type,
				reason: EngineErrorReason::ExecutionFailed,
				context: array(
					'identity' => (string) $identity,
					'run_id'   => $run_id,
				),
			);
			$terminalized   = $this->terminal_transitions->fail_run( $this, $identity, $run_id, $state, $run_store, $error, 1, RunFailureStage::execution(), ErrorCode::ExecutionFailed );
			if ( ! $terminalized ) {
				// The run keeps its pending descriptor when the terminal write is unconfirmed, and stale-state maintenance
				// redelivers a run in that shape rather than terminalizing it. Reporting the listener failure as definite
				// would tell a caller the work is finished with while it is still scheduled to run.
				return new EngineError(
					\sprintf( '%1$s "%2$s" started listener failed and the run could not be terminalized; authoritative storage did not confirm the terminal write, so this run may still be delivered. Repair option writes and inspect the run before compensating for it.', self::KIND, (string) $identity ),
					$exception_type,
					reason: EngineErrorReason::StorageFailure,
					context: array(
						'identity' => (string) $identity,
						'run_id'   => $run_id,
					),
				);
			}

			return $error;
		}

		return null;
	}

	/**
	 * Clears consumed retries when a one-off job completes successfully.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunState $state Fenced executing state.
	 *
	 * @return  RunState
	 */
	#[\Override]
	public function completion_state( RunState $state ): RunState {
		return $state->with_failed_attempts( 0 );
	}

	/**
	 * Executes one fenced job delivery and its terminal transition.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified job identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Fenced executing state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  void
	 */
	#[\Override]
	public function deliver( Identity $identity, string $run_id, RunState $state, RunStore $run_store ): void {
		$execution = $this->execution( $identity );
		$options   = $this->options( $identity );
		if ( ! $execution instanceof JobExecutionInterface || null === $options ) {
			$this->logger->warning(
				'job delivery references an unregistered execution; register the job before dispatching its run action.',
				array(
					'identity' => (string) $identity,
					'run_id'   => $run_id,
				)
			);
			$this->fail_orphaned_run( $identity, $run_id, $state, $run_store );

			return;
		}

		$start_args = PortableArguments::without_references( $state->start_args );
		$context    = new RunContext( RunId::from( $run_id ), $start_args );
		try {
			$execution->handle( $start_args, $context );
		} catch ( \Throwable $throwable ) {
			$this->failure_lifecycle->handle_failure( $this, $options, $identity, $run_id, $state, $run_store, $throwable, RunFailureStage::execution(), 'run' );

			return;
		}

		if ( $this->terminal_transitions->enforce_delivery_fence( $this, $identity, $run_id, $state, $run_store, null, $state->heartbeat_at ) ) {
			return;
		}

		$this->terminal_transitions->complete_run( $this, $identity, $run_id, $state, $run_store );
	}

	/**
	 * Returns the execution role a one-off job definition must implement.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  class-string
	 */
	#[\Override]
	protected function execution_role(): string {
		return JobExecutionInterface::class;
	}

	/**
	 * Returns the lifecycle stages owned by one-off job delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  non-empty-list<string>
	 */
	#[\Override]
	protected function stages(): array {
		return array( 'run' );
	}

	// endregion
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobExecution;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunContext;
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
	 * @param   JobRegistry      $work                 Registered work definitions.
	 * @param   LoggerInterface  $logger               Log event sink.
	 * @param   ClockInterface   $clock                Timestamp source.
	 * @param   LockWindows      $lock_windows         Filterable run-lock timing policy.
	 * @param   RunTransitions   $terminal_transitions Fenced terminal-write coordinator.
	 * @param   LifecycleEffects $terminal_effects     Client lifecycle-effect executor.
	 * @param   FailureLifecycle $failure_lifecycle    Retry adjudication coordinator.
	 */
	public function __construct(
		private JobRegistry $work,
		LoggerInterface $logger,
		ClockInterface $clock,
		LockWindows $lock_windows,
		RunTransitions $terminal_transitions,
		private LifecycleEffects $terminal_effects,
		private FailureLifecycle $failure_lifecycle,
	) {
		parent::__construct( $logger, $clock, $lock_windows, $terminal_transitions );
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
	 * Validates and registers a standard-job definition.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string        $identity   Complete owner-qualified job identity.
	 * @param   JobDefinition $definition Definition resolved to this handler.
	 *
	 * @throws  \InvalidArgumentException When the execution object does not implement JobExecution.
	 *
	 * @return  void
	 */
	#[\Override]
	public function register( string $identity, JobDefinition $definition ): void {
		if ( ! $definition->execution instanceof JobExecution ) {
			throw new \InvalidArgumentException( \sprintf( 'Job kind "%1$s" requires execution implementing %2$s; %3$s given.', self::KIND, JobExecution::class, \get_debug_type( $definition->execution ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
		}

		$this->work->register( $identity, $definition );
	}

	/**
	 * Returns the registered standard-job execution for an identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job identity.
	 *
	 * @return  JobExecution|null
	 */
	#[\Override]
	public function execution( string $identity ): ?JobExecution {
		$execution = $this->work->execution( $identity );

		return self::KIND === $this->work->kind( $identity ) && $execution instanceof JobExecution ? $execution : null;
	}

	/**
	 * Returns the registered standard-job policy declaration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job identity.
	 *
	 * @return  JobOptions|null
	 */
	#[\Override]
	public function options( string $identity ): ?JobOptions {
		return self::KIND === $this->work->kind( $identity ) ? $this->work->options( $identity ) : null;
	}

	/**
	 * Returns whether the stage belongs to one-off job delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $stage Persisted lifecycle stage, or null.
	 *
	 * @return  bool
	 */
	#[\Override]
	public function owns_stage( ?string $stage ): bool {
		return 'run' === $stage;
	}

	/**
	 * Seeds no kind-owned state because one-off jobs execute from the shared start arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 *
	 * @return  array<array-key, mixed>
	 */
	#[\Override]
	public function initial_kind_state( array $start_args ): array {
		return array();
	}

	/**
	 * Returns the first durable job delivery for the requested delay.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $scheduled_at Delivery timestamp.
	 * @param   int $delay        Requested delay in seconds.
	 * @param   int $priority     Scheduler priority.
	 *
	 * @return  PendingAction
	 */
	#[\Override]
	public function initial_pending( int $scheduled_at, int $delay, int $priority ): PendingAction {
		return 0 === $delay
			? PendingAction::async( 'run', $priority )
			: PendingAction::single( 'run', $scheduled_at, $priority );
	}

	/**
	 * Returns the job admission verb used in corrective diagnostics.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	#[\Override]
	public function dispatch_verb(): string {
		return 'dispatch';
	}

	/**
	 * Fires started hooks after scheduler acceptance and terminalizes listener failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $identity  Complete owner-qualified job identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Persisted running state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  EngineError|null Failure returned to the admission caller, or null.
	 */
	#[\Override]
	public function after_dispatch( string $identity, string $run_id, RunState $state, RunStore $run_store ): ?EngineError {
		try {
			$this->terminal_effects->fire_started( $identity, $run_id, $state->start_args );
		} catch ( \Throwable $throwable ) {
			$exception_type = \get_debug_type( $throwable );
			$error          = new EngineError(
				\sprintf( '%1$s "%2$s" started listener failed because %3$s was thrown. Fix the started-hook listener before enqueueing the job again.', self::KIND, $identity, $exception_type ),
				$exception_type,
				reason: EngineErrorReason::ExecutionFailed,
				context: array(
					'name'   => $identity,
					'run_id' => $run_id,
				),
			);
			$this->terminal_transitions->fail_run( $this, $identity, $run_id, $state, $run_store, $error, 1, RunFailureStage::execution(), ErrorCode::ExecutionFailed );

			return $error;
		}

		return null;
	}

	/**
	 * Allows cancellation for every retained nonexecuting job state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $run_id Run identifier.
	 * @param   RunState $state  Current running state.
	 *
	 * @return  EngineError|null
	 */
	#[\Override]
	public function cancellation_error( string $run_id, RunState $state ): ?EngineError {
		return null;
	}

	/**
	 * Returns the bounded execution lease for a registered job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $identity Complete owner-qualified job identity.
	 * @param   string   $run_id   Run identifier.
	 * @param   RunState $state    Persisted run-stage state.
	 *
	 * @return  int|null Null when no registered definition can declare an execution lease.
	 */
	#[\Override]
	public function delivery_liveness_at( string $identity, string $run_id, RunState $state ): ?int {
		$options = $this->options( $identity );
		if ( null === $options ) {
			return null;
		}

		return $this->execution_lease_at( $options );
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
	 * @param   string   $identity  Complete owner-qualified job identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Fenced executing state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  void
	 */
	#[\Override]
	public function deliver( string $identity, string $run_id, RunState $state, RunStore $run_store ): void {
		$execution = $this->execution( $identity );
		$options   = $this->options( $identity );
		if ( null === $execution || null === $options ) {
			$this->logger->warning(
				'job delivery references an unregistered execution; register the job before dispatching its run action.',
				array(
					'job_name' => $identity,
					'run_id'   => $run_id,
				)
			);
			$this->fail_orphaned_run( $identity, $run_id, $state, $run_store );

			return;
		}

		$context = new RunContext( $run_id, $state->start_args );
		try {
			$execution->handle( $state->start_args, $context );
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
	 * Converts a job execution throwable to durable failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Throwable $throwable Execution failure.
	 *
	 * @return  EngineError
	 */
	#[\Override]
	public function failure_error( \Throwable $throwable ): EngineError {
		return EngineError::from_throwable( $throwable );
	}

	/**
	 * Returns no failure details because one-off jobs have no kind-specific diagnostics.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunState $state Run state at terminalization.
	 *
	 * @return  array<array-key, mixed>|null
	 */
	#[\Override]
	public function failure_details( RunState $state ): ?array {
		return null;
	}

	/**
	 * Returns no queue depth because it is not an observable job metric.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunState $state Live run state.
	 *
	 * @return  int|null
	 */
	#[\Override]
	public function queue_depth( RunState $state ): ?int {
		return null;
	}

	// endregion
}

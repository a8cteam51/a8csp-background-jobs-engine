<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\ChunkedJobExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\ChunkedRunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\DeliveryScheduler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\InvalidChunkException;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Owns chunked-job admission, delivery, failure, and inspection behavior.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ChunkedJobKindHandler extends AbstractKindHandler {
	// region FIELDS AND CONSTANTS

	/**
	 * Persisted key owned by this handler.
	 *
	 * Handler resolution keys the registry by this value and looks it up by a declaration's
	 * `JobKind`, so `JobKind::chunked_job()` carries a frozen public copy that must stay byte-equal.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string KIND = 'chunked_job';

	/**
	 * Maximum persisted serialization bytes accepted for one materialized queue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_QUEUE_BYTES = RunStore::MAX_KIND_STATE_BYTES;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   JobRegistry       $registry             Registered work definitions.
	 * @param   DeliveryScheduler $delivery_scheduler   Lifecycle-delivery scheduler.
	 * @param   LoggerInterface   $logger               Log event sink.
	 * @param   ClockInterface    $clock                Timestamp source.
	 * @param   LockWindows       $lock_windows         Filterable run-lock timing policy.
	 * @param   RunTransitions    $terminal_transitions Fenced terminal-write coordinator.
	 * @param   LifecycleEffects  $terminal_effects     Client lifecycle-effect executor.
	 * @param   FailureLifecycle  $failure_lifecycle    Retry adjudication coordinator.
	 */
	public function __construct(
		private JobRegistry $registry,
		private DeliveryScheduler $delivery_scheduler,
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
	 * Returns the opaque persisted chunked-job kind key.
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
	 * Validates and registers a chunked-job definition.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity      $identity   Complete scope-qualified chunked-job identity.
	 * @param   JobDefinition $definition Definition resolved to this handler.
	 *
	 * @throws  \InvalidArgumentException When the execution object does not implement ChunkedJobExecutionInterface.
	 *
	 * @return  void
	 */
	#[\Override]
	public function register( Identity $identity, JobDefinition $definition ): void {
		if ( ! $definition->execution instanceof ChunkedJobExecutionInterface ) {
			throw new \InvalidArgumentException( \sprintf( 'Job kind "%1$s" requires execution implementing %2$s; %3$s given.', self::KIND, ChunkedJobExecutionInterface::class, \get_debug_type( $definition->execution ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
		}

		$this->registry->register( $identity, $definition );
	}

	/**
	 * Returns the registered chunked-job execution for an identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified chunked-job identity.
	 *
	 * @return  ChunkedJobExecutionInterface|null
	 */
	#[\Override]
	public function execution( Identity $identity ): ?ChunkedJobExecutionInterface {
		$execution = $this->registry->execution( $identity );

		return self::KIND === $this->registry->kind( $identity ) && $execution instanceof ChunkedJobExecutionInterface ? $execution : null;
	}

	/**
	 * Returns the registered chunked-job policy declaration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified chunked-job identity.
	 *
	 * @return  JobOptions|null
	 */
	#[\Override]
	public function options( Identity $identity ): ?JobOptions {
		return self::KIND === $this->registry->kind( $identity ) ? $this->registry->options( $identity ) : null;
	}

	/**
	 * Returns whether the stage belongs to chunked-job delivery.
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
		return \in_array( $stage, array( 'start', 'continue' ), true );
	}

	/**
	 * Seeds the handler-owned bare-list queue payload empty until the start delivery generates chunks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 *
	 * @return  list<array<array-key, mixed>>
	 */
	#[\Override]
	public function initial_kind_state( array $start_args ): array {
		return array();
	}

	/**
	 * Returns the initial start delivery for an admitted chunked job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int|null $fire_at  Absolute first-delivery timestamp, or null for asynchronous admission.
	 * @param   int      $now      Admission timestamp.
	 * @param   int      $priority Scheduler priority.
	 *
	 * @return  PendingAction
	 */
	#[\Override]
	public function initial_pending( ?int $fire_at, int $now, int $priority ): PendingAction {
		return null === $fire_at || $fire_at <= $now
			? PendingAction::async( 'start', $priority )
			: PendingAction::single( 'start', $fire_at, $priority );
	}

	/**
	 * Defers started effects until the generated queue is durably persisted.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified chunked-job identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Persisted running state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  EngineError|null
	 */
	#[\Override]
	public function after_dispatch( Identity $identity, string $run_id, RunState $state, RunStore $run_store ): ?EngineError {
		return null;
	}

	/**
	 * Refuses cancellation after the queue drains and its continuation becomes authoritative.
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
		$queue = $this->queue_for_state( $state );
		if ( $queue instanceof EngineError ) {
			return $queue;
		}
		// Only a continuation completes a drained run, so any other pending stage leaves cancellation the operator's one way out.
		if ( array() !== $queue || 1 >= $state->action_sequence || 'continue' !== $state->pending?->stage ) {
			return null;
		}

		return new EngineError( \sprintf( 'Run "%s" has no chunks left to process; the pending continuation completes it.', $run_id ), reason: EngineErrorReason::RunNotCancellable, context: array( 'run_id' => $run_id ), );
	}

	/**
	 * Returns the bounded execution lease for a registered chunked job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified chunked-job identity.
	 * @param   string   $run_id   Run identifier.
	 * @param   RunState $state    Persisted chunked-job stage state.
	 *
	 * @return  int|null Null when no registered definition can declare an execution lease.
	 */
	#[\Override]
	public function delivery_liveness_at( Identity $identity, string $run_id, RunState $state ): ?int {
		$options = $this->options( $identity );
		if ( null === $options ) {
			return null;
		}

		return $this->execution_lease_at( $options );
	}

	/**
	 * Preserves chunked-job retry state when a drained continuation completes the run.
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
		return $state;
	}

	/**
	 * Routes one fenced delivery through its persisted chunked-job stage.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified chunked-job identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Fenced executing state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  void
	 */
	#[\Override]
	public function deliver( Identity $identity, string $run_id, RunState $state, RunStore $run_store ): void {
		match ( $state->pending?->stage ) {
			'start'    => $this->handle_start( $identity, $run_id, $state, $run_store ),
			'continue' => $this->handle_continue( $identity, $run_id, $state, $run_store ),
			default    => null,
		};
	}

	/**
	 * Converts a chunk execution throwable to durable failure detail.
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
		return $throwable instanceof InvalidChunkException
			? new EngineError( $throwable->getMessage(), \InvalidArgumentException::class )
			: EngineError::from_throwable( $throwable );
	}

	/**
	 * Excludes deterministic chunk-context validation failures from retry policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Throwable $throwable Execution failure.
	 *
	 * @return  bool
	 */
	#[\Override]
	public function is_failure_retryable( \Throwable $throwable ): bool {
		return ! ( $throwable instanceof InvalidChunkException );
	}

	/**
	 * Returns diagnostic details for the authoritative queue head of a failed continuation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunState $state Run state at terminalization.
	 *
	 * @return  array<array-key, mixed>|null Generic diagnostic payload, or null when no failing chunk is available.
	 */
	#[\Override]
	public function failure_details( RunState $state ): ?array {
		if ( 'continue' !== $state->pending?->stage ) {
			return null;
		}

		$queue = $this->queue_for_state( $state );
		if ( $queue instanceof EngineError ) {
			return null;
		}

		$chunk = $queue[0] ?? null;

		return self::details_for_chunk( \is_array( $chunk ) ? $chunk : null );
	}

	/**
	 * Returns the number of chunks retained in the authoritative queue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunState $state Live run state.
	 *
	 * @return  int|null Null when the handler-owned payload is malformed.
	 */
	#[\Override]
	public function queue_depth( RunState $state ): ?int {
		$queue = $this->queue_for_state( $state );

		return $queue instanceof EngineError ? null : \count( $queue );
	}

	// endregion

	// region DELIVERY

	/**
	 * Generates and persists the initial queue before scheduling its first continuation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified chunked-job identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Fenced executing state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @throws  \LogicException When the claimed delivery has no durable pending-action descriptor.
	 *
	 * @return  void
	 */
	private function handle_start( Identity $identity, string $run_id, RunState $state, RunStore $run_store ): void {
		$execution = $this->execution_for_action( $identity, $run_id, 'start' );
		if ( null === $execution ) {
			$this->fail_orphaned_run( $identity, $run_id, $state, $run_store );

			return;
		}
		$stored_queue = $this->queue_for_state( $state );
		if ( $stored_queue instanceof EngineError ) {
			$this->fail_malformed_kind_state( $identity, $run_id, $state, $run_store, $stored_queue );

			return;
		}

		$start_args = PortableArguments::without_references( $state->start_args );
		$context    = new RunContext( RunId::from( $run_id ), $start_args );
		try {
			$queue = $this->materialize_queue( $execution->generate_queue( $start_args, $context ) );
		} catch ( \Throwable $throwable ) {
			$this->failure_lifecycle->handle_failure( $this, $this->options( $identity ) ?? new JobOptions(), $identity, $run_id, $state, $run_store, $throwable, RunFailureStage::queue_generation(), 'start' );

			return;
		}
		if ( $queue instanceof EngineError ) {
			$this->fail_start( $identity, $run_id, $state, $run_store, $queue, ErrorCode::PayloadRejected );

			return;
		}

		try {
			/**
			 * Filters the generated chunk queue before work-identity-specific filtering.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   list<array<array-key, mixed>> $queue      Complete list of chunk argument arrays.
			 * @param   string                        $identity   Complete scope-qualified chunked-job identity.
			 * @param   array<array-key, mixed>       $start_args Arguments supplied when the run started.
			 * @param   string                        $run_id     Run identifier.
			 */
			$queue = \apply_filters( 'a8csp_bgje/queue', $queue, (string) $identity, $start_args, $run_id );

			/**
			 * Filters the generated chunk queue for a chunked job.
			 *
			 * The dynamic portion of the hook name, `$identity`, refers to the scope-qualified work identity.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   list<array<array-key, mixed>> $queue      Generic-filtered list of chunk argument arrays.
			 * @param   array<array-key, mixed>       $start_args Arguments supplied when the run started.
			 * @param   string                        $run_id     Run identifier.
			 */
			$queue = $this->materialize_filtered_queue( \apply_filters( 'a8csp_bgje/queue/' . (string) $identity, $queue, $start_args, $run_id ) );
		} catch ( \Throwable $throwable ) {
			$this->fail_start( $identity, $run_id, $state, $run_store, EngineError::from_throwable( $throwable ), ErrorCode::ExecutionFailed );

			return;
		}
		if ( $queue instanceof EngineError ) {
			$this->fail_start( $identity, $run_id, $state, $run_store, $queue, ErrorCode::PayloadRejected );

			return;
		}

		$reset_at = $this->clock->now()->getTimestamp();
		if ( $this->terminal_transitions->enforce_delivery_fence( $this, $identity, $run_id, $state, $run_store, $reset_at, $state->heartbeat_at ) ) {
			return;
		}

		$priority     = $state->pending->priority ?? throw new \LogicException( 'Claimed chunked-job delivery requires a durable pending-action descriptor.' );
		$pending      = PendingAction::async( 'continue', $priority );
		$replacement  = $state->with_kind_state( $queue )->with_failed_attempts( 0 )->with_heartbeat_at( $reset_at )->with_action_sequence( $state->action_sequence + 1 )->with_executing( false )->with_pending( $pending );
		$transitioned = $run_store->replace_if_state_matches( $run_id, $state, $replacement );
		if ( $transitioned instanceof Failure ) {
			// replace_if_state_matches(), replace_if_raw_matches(), and mark_executing_with_heartbeat()
			// collapse lost CAS and SQL write failure to null, so Failure denotes payload rejection.
			if ( $this->terminal_transitions->enforce_delivery_fence( $this, $identity, $run_id, $state, $run_store, $reset_at, $reset_at ) ) {
				return;
			}
			$this->terminal_transitions->fail_run( $this, $identity, $run_id, $state, $run_store, $transitioned->error, RunState::increment_attempts_safely( $state->failed_attempts ), RunFailureStage::queue_generation(), ErrorCode::PayloadRejected );

			return;
		}
		if ( null === $transitioned ) {
			return;
		}
		$state = $replacement;
		try {
			$this->terminal_effects->fire_started( $identity, $run_id, $state->start_args );
		} catch ( \Throwable $throwable ) {
			if ( $this->terminal_transitions->enforce_delivery_fence( $this, $identity, $run_id, $state, $run_store, $state->heartbeat_at, $state->heartbeat_at ) ) {
				return;
			}

			$this->terminal_transitions->fail_run( $this, $identity, $run_id, $state, $run_store, EngineError::from_throwable( $throwable ), RunState::increment_attempts_safely( $state->failed_attempts ), RunFailureStage::execution(), ErrorCode::ExecutionFailed );

			return;
		}

		if ( $this->terminal_transitions->enforce_delivery_fence( $this, $identity, $run_id, $state, $run_store, $state->heartbeat_at, $state->heartbeat_at ) ) {
			return;
		}

		$this->schedule_async_successor( $identity, $run_id, $state, $run_store );
	}

	/**
	 * Processes the current queue head or completes a drained queue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified chunked-job identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Fenced executing state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @throws  \LogicException When the claimed delivery has no durable pending-action descriptor.
	 *
	 * @return  void
	 */
	private function handle_continue( Identity $identity, string $run_id, RunState $state, RunStore $run_store ): void {
		$execution = $this->execution_for_action( $identity, $run_id, 'continue' );
		if ( null === $execution ) {
			$this->fail_orphaned_run( $identity, $run_id, $state, $run_store );

			return;
		}
		$queue = $this->queue_for_state( $state );
		if ( $queue instanceof EngineError ) {
			$this->fail_malformed_kind_state( $identity, $run_id, $state, $run_store, $queue );

			return;
		}

		if ( array() === $queue ) {
			$this->terminal_transitions->complete_run( $this, $identity, $run_id, $state, $run_store );

			return;
		}

		$this->process_chunk( $execution, $identity, $run_id, $state, $run_store, $queue );
	}

	/**
	 * Executes one chunk and schedules the next continuation after its queue state persists.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ChunkedJobExecutionInterface  $execution   Registered chunked-job execution.
	 * @param   Identity                      $identity    Complete scope-qualified chunked-job identity.
	 * @param   string                        $run_id      Run identifier.
	 * @param   RunState                      $state       Fenced executing state.
	 * @param   RunStore                      $run_store   Active-run store.
	 * @param   list<array<array-key, mixed>> $queue       Validated queue in processing order.
	 *
	 * @throws  \LogicException When the claimed delivery has no durable pending-action descriptor.
	 *
	 * @return  void
	 */
	private function process_chunk( ChunkedJobExecutionInterface $execution, Identity $identity, string $run_id, RunState $state, RunStore $run_store, array $queue ): void {
		$chunk_args = $queue[0] ?? null;
		if ( ! \is_array( $chunk_args ) ) {
			$this->terminal_transitions->fail_run( $this, $identity, $run_id, $state, $run_store, new EngineError( \sprintf( '%1$s "%2$s" reached chunk execution without a queued chunk; schedule continue only while the authoritative queue has a head.', self::KIND, (string) $identity ) ), RunState::increment_attempts_safely( $state->failed_attempts ), RunFailureStage::execution(), ErrorCode::UnsupportedOperation );

			return;
		}
		$chunk_args = PortableArguments::without_references( $chunk_args );

		$context = new ChunkedRunContext( $run_id, $state->start_args, \array_slice( $queue, 1 ) );
		try {
			$execution->process_chunk( $chunk_args, $context );
		} catch ( \Throwable $throwable ) {
			$this->failure_lifecycle->handle_failure( $this, $this->options( $identity ) ?? new JobOptions(), $identity, $run_id, $state, $run_store, $throwable, RunFailureStage::execution(), 'continue', self::details_for_chunk( $chunk_args ) );

			return;
		}

		$reset_at = $this->clock->now()->getTimestamp();
		if ( $this->terminal_transitions->enforce_delivery_fence( $this, $identity, $run_id, $state, $run_store, $reset_at, $state->heartbeat_at ) ) {
			return;
		}

		try {
			$delay = $this->lock_windows->continue_delay( $identity, $run_id );
		} catch ( \Throwable $throwable ) {
			if ( $this->terminal_transitions->enforce_delivery_fence( $this, $identity, $run_id, $state, $run_store, $reset_at, $reset_at ) ) {
				return;
			}

			$this->fail_processed_chunk( $identity, $run_id, $state, $run_store, $context->get_queue(), $reset_at, EngineError::from_throwable( $throwable ), RunFailureStage::execution(), ErrorCode::ExecutionFailed );

			return;
		}

		if ( $this->terminal_transitions->enforce_delivery_fence( $this, $identity, $run_id, $state, $run_store, $reset_at, $reset_at ) ) {
			return;
		}

		$now = $this->clock->now()->getTimestamp();
		if ( $delay > \PHP_INT_MAX - $now ) {
			$this->fail_processed_chunk( $identity, $run_id, $state, $run_store, $context->get_queue(), $reset_at, new EngineError( \sprintf( '%1$s "%2$s" could not schedule the continue action because its delay exceeds supported Unix seconds; return a smaller non-negative delay from the continue-delay filter.', self::KIND, (string) $identity ) ), RunFailureStage::scheduling(), ErrorCode::BackendRejected );

			return;
		}
		$priority     = $state->pending->priority ?? throw new \LogicException( 'Claimed chunked-job delivery requires a durable pending-action descriptor.' );
		$fire_at      = $now + $delay;
		$pending      = PendingAction::single( 'continue', $fire_at, $priority );
		$replacement  = $state->with_kind_state( $context->get_queue() )->with_failed_attempts( 0 )->with_heartbeat_at( $reset_at )->with_action_sequence( $state->action_sequence + 1 )->with_executing( false )->with_pending( $pending );
		$transitioned = $run_store->replace_if_state_matches( $run_id, $state, $replacement );
		if ( $transitioned instanceof Failure ) {
			if ( $this->terminal_transitions->enforce_delivery_fence( $this, $identity, $run_id, $state, $run_store, $reset_at, $reset_at ) ) {
				return;
			}
			$this->terminal_transitions->fail_run( $this, $identity, $run_id, $state, $run_store, $transitioned->error, RunState::increment_attempts_safely( $state->failed_attempts ), RunFailureStage::scheduling(), ErrorCode::PayloadRejected, $this->failure_details( $state ) );

			return;
		}
		if ( null === $transitioned ) {
			return;
		}

		$scheduled = $this->delivery_scheduler->schedule( $identity, $run_id, $replacement->action_sequence, $pending );
		if ( $scheduled->is_failure() ) {
			$this->terminal_transitions->fail_run( $this, $identity, $run_id, $replacement, $run_store, EngineError::scheduling( self::KIND, $identity, 'continue', $scheduled->error ), RunState::increment_attempts_safely( $replacement->failed_attempts ), RunFailureStage::scheduling(), $scheduled->error->reason->api_code() );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Projects one failing chunk into the generic diagnostic payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed>|null $chunk Chunk arguments, or null when no failing chunk is available.
	 *
	 * @return  array<array-key, mixed>|null
	 */
	private static function details_for_chunk( ?array $chunk ): ?array {
		return null === $chunk ? null : array( 'failed_chunk' => $chunk );
	}

	/**
	 * Schedules one asynchronous persisted successor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified chunked-job identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Persisted successor state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @throws  \LogicException When the persisted successor has no durable pending-action descriptor.
	 *
	 * @return  void
	 */
	private function schedule_async_successor( Identity $identity, string $run_id, RunState $state, RunStore $run_store ): void {
		$pending   = $state->pending ?? throw new \LogicException( 'Persisted chunked-job successor requires a durable pending-action descriptor.' );
		$scheduled = $this->delivery_scheduler->schedule( $identity, $run_id, $state->action_sequence, $pending );
		if ( $scheduled->is_failure() ) {
			$this->terminal_transitions->fail_run( $this, $identity, $run_id, $state, $run_store, EngineError::scheduling( self::KIND, $identity, 'continue', $scheduled->error ), RunState::increment_attempts_safely( $state->failed_attempts ), RunFailureStage::scheduling(), $scheduled->error->reason->api_code() );
		}
	}

	/**
	 * Fails startup after preserving its post-execution liveness fence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity    $identity  Complete scope-qualified chunked-job identity.
	 * @param   string      $run_id    Run identifier.
	 * @param   RunState    $state     Fenced running state.
	 * @param   RunStore    $run_store Active-run store.
	 * @param   EngineError $error     Terminal failure detail.
	 * @param   ErrorCode   $code      Machine-readable cause classification.
	 *
	 * @return  void
	 */
	private function fail_start( Identity $identity, string $run_id, RunState $state, RunStore $run_store, EngineError $error, ErrorCode $code ): void {
		$reset_at = $this->clock->now()->getTimestamp();
		if ( $this->terminal_transitions->enforce_delivery_fence( $this, $identity, $run_id, $state, $run_store, $reset_at, $state->heartbeat_at ) ) {
			return;
		}
		$marked = $run_store->mark_executing_with_heartbeat( $run_id, $state, $reset_at );
		if ( $marked instanceof Failure ) {
			if ( $this->terminal_transitions->enforce_delivery_fence( $this, $identity, $run_id, $state, $run_store, $reset_at, $reset_at ) ) {
				return;
			}
			$this->terminal_transitions->fail_run( $this, $identity, $run_id, $state, $run_store, $marked->error, RunState::increment_attempts_safely( $state->failed_attempts ), RunFailureStage::queue_generation(), ErrorCode::PayloadRejected );

			return;
		}
		if ( null === $marked ) {
			return;
		}
		$state = $marked;

		$this->terminal_transitions->fail_run( $this, $identity, $run_id, $state, $run_store, $error, RunState::increment_attempts_safely( $state->failed_attempts ), RunFailureStage::queue_generation(), $code );
	}

	/**
	 * Fails a delivery whose handler-owned persisted payload is malformed.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity    $identity  Complete scope-qualified chunked-job identity.
	 * @param   string      $run_id    Run identifier.
	 * @param   RunState    $state     Fenced running state.
	 * @param   RunStore    $run_store Active-run store.
	 * @param   EngineError $error     Handler-owned payload failure.
	 *
	 * @return  void
	 */
	private function fail_malformed_kind_state( Identity $identity, string $run_id, RunState $state, RunStore $run_store, EngineError $error ): void {
		$this->terminal_transitions->fail_run( $this, $identity, $run_id, $state, $run_store, $error, RunState::increment_attempts_safely( $state->failed_attempts ), RunFailureStage::execution(), ErrorCode::PayloadRejected );
	}

	/**
	 * Commits processed queue state without a successor before terminal failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity                      $identity  Complete scope-qualified chunked-job identity.
	 * @param   string                        $run_id    Run identifier.
	 * @param   RunState                      $state     Fenced running state.
	 * @param   RunStore                      $run_store Active-run store.
	 * @param   list<array<array-key, mixed>> $queue     Committed queue after the processed chunk.
	 * @param   int                           $reset_at  Post-execution liveness timestamp.
	 * @param   EngineError                   $error     Terminal failure detail.
	 * @param   RunFailureStage               $stage     Terminalization stage.
	 * @param   ErrorCode                     $code      Machine-readable cause classification.
	 *
	 * @return  void
	 */
	private function fail_processed_chunk( Identity $identity, string $run_id, RunState $state, RunStore $run_store, array $queue, int $reset_at, EngineError $error, RunFailureStage $stage, ErrorCode $code ): void {
		$replacement  = $state->with_kind_state( $queue )->with_failed_attempts( 0 )->with_heartbeat_at( $reset_at )->with_action_sequence( $state->action_sequence + 1 )->with_executing( false )->with_pending( null );
		$transitioned = $run_store->replace_if_state_matches( $run_id, $state, $replacement );
		if ( $transitioned instanceof Failure ) {
			if ( $this->terminal_transitions->enforce_delivery_fence( $this, $identity, $run_id, $state, $run_store, $reset_at, $reset_at ) ) {
				return;
			}
			$this->terminal_transitions->fail_run( $this, $identity, $run_id, $state, $run_store, $transitioned->error, RunState::increment_attempts_safely( $state->failed_attempts ), $stage, ErrorCode::PayloadRejected );

			return;
		}
		if ( null === $transitioned ) {
			return;
		}

		$this->terminal_transitions->fail_run( $this, $identity, $run_id, $replacement, $run_store, $error, RunState::increment_attempts_safely( $replacement->failed_attempts ), $stage, $code );
	}

	/**
	 * Returns the registered chunked job recorded for one internal action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified chunked-job identity.
	 * @param   string   $run_id   Run identifier.
	 * @param   string   $stage    Internal lifecycle stage.
	 *
	 * @return  ChunkedJobExecutionInterface|null
	 */
	private function execution_for_action( Identity $identity, string $run_id, string $stage ): ?ChunkedJobExecutionInterface {
		$execution = $this->execution( $identity );
		if ( null === $execution ) {
			$this->logger->warning(
				'chunked_job delivery references an unregistered execution; register the chunked job before dispatching its action.',
				array(
					'identity' => (string) $identity,
					'run_id'   => $run_id,
					'stage'    => $stage,
				)
			);
		}

		return $execution;
	}

	/**
	 * Returns the validated oldest-first queue stored as this handler's bare-list payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunState $state Persisted chunked-job state.
	 *
	 * @return  list<array<array-key, mixed>>|EngineError
	 */
	private function queue_for_state( RunState $state ): array|EngineError {
		// Hydration guarantees a portable payload, so the read path checks only the list-of-arrays
		// shape this handler owns; full materialization (per-chunk encoding and byte ceilings)
		// belongs to the write path.
		if (
			! \array_is_list( $state->kind_state )
			|| \array_any( $state->kind_state, static fn ( mixed $chunk ): bool => ! \is_array( $chunk ) )
		) {
			return new EngineError( 'chunked_job kind_state must be an oldest-first list of portable argument arrays.', \UnexpectedValueException::class, EngineErrorReason::PayloadRejected );
		}

		return \array_map( static fn ( mixed $chunk ): array => (array) $chunk, $state->kind_state );
	}

	/**
	 * Returns a normalized queue from the queue filter boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $chunks Filtered queue value.
	 *
	 * @return  list<array<array-key, mixed>>|EngineError
	 */
	private function materialize_filtered_queue( mixed $chunks ): array|EngineError {
		if ( ! \is_array( $chunks ) ) {
			return new EngineError( 'chunked_job queue filter returned a non-array value; return one argument array per chunk.', \UnexpectedValueException::class );
		}

		return $this->materialize_queue( $chunks );
	}

	/**
	 * Returns one normalized oldest-first queue from an iterable source.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   iterable<mixed> $chunks Generated or filtered chunks.
	 *
	 * @throws  \Throwable When the iterable fails during traversal.
	 *
	 * @return  list<array<array-key, mixed>>|EngineError
	 */
	private function materialize_queue( iterable $chunks ): array|EngineError {
		$queue       = array();
		$queue_bytes = 0;
		foreach ( $chunks as $chunk_args ) {
			$index = \count( $queue );
			if ( ! \is_array( $chunk_args ) ) {
				return new EngineError( \sprintf( 'chunked_job queue chunk at index %d must be an argument array.', $index ), \UnexpectedValueException::class );
			}
			if ( ! PortableArguments::is_valid( $chunk_args ) ) {
				return new EngineError( \sprintf( 'chunked_job queue chunk at index %d must contain only null, scalar, or nested array values.', $index ), \UnexpectedValueException::class );
			}
			$chunk_args = PortableArguments::without_references( $chunk_args );
			try {
				$encoded_chunk = \wp_json_encode( $chunk_args, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION );
			} catch ( \JsonException ) {
				$encoded_chunk = false;
			}
			if ( ! \is_string( $encoded_chunk ) ) {
				return new EngineError( \sprintf( 'chunked_job queue chunk at index %d must contain only null, scalar, or nested array values.', $index ), \UnexpectedValueException::class );
			}

			$chunk_bytes = \strlen( $encoded_chunk );
			if ( ChunkedRunContext::MAX_CHUNK_BYTES < $chunk_bytes ) {
				return new EngineError( \sprintf( 'chunked_job queue chunk at index %1$d contains %2$d JSON bytes; the limit is %3$d bytes.', $index, $chunk_bytes, ChunkedRunContext::MAX_CHUNK_BYTES ), \UnexpectedValueException::class );
			}

			$queue[] = $chunk_args;

			// A list of reference-free members serializes to its members plus an index envelope, so this sum
			// always runs under the real total: crossing the ceiling here proves the whole queue crosses it,
			// and stopping bounds materialization of an unsized generator. The rebuild above is what makes
			// the members reference-free; shared references would serialize the whole to less than its parts
			// and let this sum overshoot. The exact measurement below decides the boundary.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- A validated chunk is a known array, so this is the branch maybe_serialize() takes; it measures the persisted representation without producing one.
			$queue_bytes += \strlen( \serialize( $chunk_args ) );
			if ( self::MAX_QUEUE_BYTES < $queue_bytes ) {
				break;
			}
		}

		$serialized_queue = \maybe_serialize( $queue );
		if ( ! \is_string( $serialized_queue ) ) {
			return new EngineError( 'chunked_job queue could not be serialized for persistence.', \UnexpectedValueException::class );
		}
		$queue_bytes = \strlen( $serialized_queue );
		if ( self::MAX_QUEUE_BYTES < $queue_bytes ) {
			return new EngineError( \sprintf( 'chunked_job queue contains %1$d persisted serialization bytes; the limit is %2$d bytes.', $queue_bytes, self::MAX_QUEUE_BYTES ), \UnexpectedValueException::class );
		}

		return $queue;
	}

	// endregion
}

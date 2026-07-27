<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\BackendInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\HeartbeatOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockClaimOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockClaimResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockTransferOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\RandomizerInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\KindHandlerInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Admits registered background-work runs to the scheduling backend.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Dispatcher {
	// region FIELDS AND CONSTANTS

	/**
	 * Highest scheduler priority accepted by run admission.
	 *
	 * `Schedule::MAX_PRIORITY` and `JobOptions::MAX_PRIORITY` mirror this dispatch-owned limit
	 * because the frozen public models keep their constants private. Lowering this ceiling without
	 * lowering both leaves a model accepting an input that admission then rejects.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int MAX_PRIORITY = 255;

	/**
	 * Latest absolute timestamp accepted by run admission.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_RUN_AT = 253_402_300_799;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<string, KindHandlerInterface> $handlers
	 *
	 * @param   JobRegistry         $registry               Registered work definitions.
	 * @param   array               $handlers               Kind handlers keyed by their persisted keys.
	 * @param   BackendInterface    $scheduler              Scheduling facade boundary.
	 * @param   DeliveryScheduler   $delivery_scheduler     Lifecycle-delivery scheduler.
	 * @param   OverlapGuard        $overlap_guard          Execution-overlap guard.
	 * @param   OverlapIdentity     $overlap_identity       Stable single-flight identity resolver.
	 * @param   StoreFactory        $stores                 Name-bound store factory.
	 * @param   ClockInterface      $clock                  Timestamp source.
	 * @param   RandomizerInterface $randomizer             Run identifier randomness.
	 * @param   LoggerInterface     $logger                 Log event sink.
	 * @param   LockWindows         $lock_windows           Filterable run-lock timing policy.
	 * @param   RunTransitions      $terminal_transitions   Fenced terminal-write coordinator.
	 */
	public function __construct(
		private JobRegistry $registry,
		private array $handlers,
		private BackendInterface $scheduler,
		private DeliveryScheduler $delivery_scheduler,
		private OverlapGuard $overlap_guard,
		private OverlapIdentity $overlap_identity,
		private StoreFactory $stores,
		private ClockInterface $clock,
		private RandomizerInterface $randomizer,
		private LoggerInterface $logger,
		private LockWindows $lock_windows,
		private RunTransitions $terminal_transitions,
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers one definition through its installed kind handler.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity      $identity   Complete scope-qualified work identity.
	 * @param   JobDefinition $definition Definition to register.
	 *
	 * @throws  \InvalidArgumentException When the kind is not installed or its execution role is incompatible.
	 *
	 * @return  void
	 */
	public function register( Identity $identity, JobDefinition $definition ): void {
		$kind    = $definition->kind->value;
		$handler = $this->handlers[ $kind ] ?? null;
		if ( null === $handler ) {
			throw new \InvalidArgumentException( \sprintf( 'Job kind "%s" is not installed in this engine.', $kind ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
		}

		$handler->register( $identity, $definition );
	}

	/**
	 * Creates and schedules one run through its registered kind handler.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity                $identity Complete scope-qualified work identity.
	 * @param   array<array-key, mixed> $args     Start arguments.
	 * @param   int|null                $fire_at  Absolute first-delivery timestamp, or null for asynchronous admission.
	 * @param   int|null                $priority Scheduler priority from 0 through 255, or null to defer to the job default.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a job-dispatch failure must be handled, not dropped' )]
	public function dispatch( Identity $identity, array $args = array(), ?int $fire_at = null, ?int $priority = null ): AbstractResult {
		$kind = $this->registry->kind( $identity );
		if ( null === $kind ) {
			return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register it before dispatching.', (string) $identity ), reason: EngineErrorReason::UnknownJob, context: array( 'identity' => (string) $identity ), ) );
		}
		$handler = $this->handler( $kind );
		$options = $handler->options( $identity );
		if ( null === $handler->execution( $identity ) || null === $options ) {
			return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register it before dispatching.', (string) $identity ), reason: EngineErrorReason::UnknownJob, context: array( 'identity' => (string) $identity ), ) );
		}

		return $this->imperative_result( $this->dispatch_resolved( $handler, $options, $identity, $args, $fire_at, $priority, $options->overlap ?? OverlapPolicy::Reject ) );
	}

	/**
	 * Dispatches a schedule target through its registered kind handler.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity                $identity                        Complete scope-qualified work identity.
	 * @param   array<array-key, mixed> $args                            Target arguments.
	 * @param   int|null                $priority                        Scheduler priority from 0 through 255, or null to defer to the job default.
	 * @param   \Closure|null           $on_accepted                     Internal callback after backend acceptance and before history.
	 * @param   bool                    $terminalize_overlap_key_failure Whether resolver failure consumes a recurring occurrence as a failed run.
	 *
	 * @return  AbstractResult<string|SkippedJobDispatch, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a scheduled-target dispatch failure must be handled, not dropped' )]
	public function dispatch_scheduled_target( Identity $identity, array $args, ?int $priority = null, ?\Closure $on_accepted = null, bool $terminalize_overlap_key_failure = false ): AbstractResult {
		$kind = $this->registry->kind( $identity );
		if ( null === $kind ) {
			return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register it before dispatching.', (string) $identity ), reason: EngineErrorReason::UnknownJob, context: array( 'identity' => (string) $identity ), ) );
		}
		$handler = $this->handler( $kind );
		$options = $handler->options( $identity );
		if ( null === $handler->execution( $identity ) || null === $options ) {
			return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register it before dispatching.', (string) $identity ), reason: EngineErrorReason::UnknownJob, context: array( 'identity' => (string) $identity ), ) );
		}

		return $this->dispatch_resolved( $handler, $options, $identity, $args, null, $priority, $options->overlap ?? OverlapPolicy::Reject, $on_accepted, terminalize_overlap_key_failure: $terminalize_overlap_key_failure );
	}

	/**
	 * Starts a fresh run from one retained failed run's original arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified work identity.
	 * @param   string   $run_id   Retained failed-run identifier.
	 *
	 * @throws  \InvalidArgumentException When the run identifier is malformed.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed( Identity $identity, string $run_id ): AbstractResult {
		if ( null === RunIdentity::parse( $run_id ) ) {
			throw new \InvalidArgumentException( 'Run identifier is malformed; pass a run ID the engine returned.' );
		}

		$registered_kind = $this->registry->kind( $identity );
		$failed_store    = $this->stores->failed_run_store( $identity );
		$read            = $failed_store->all();
		if ( $read->is_failure() ) {
			if ( null === $registered_kind ) {
				return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register the matching job or chunked job before retrying its failed run.', (string) $identity ), reason: EngineErrorReason::UnknownJob, context: array( 'identity' => (string) $identity ), ) );
			}

			return $read;
		}

		$entry = \array_find( $read->value, static fn ( array $candidate ): bool => $run_id === $candidate['run_id'] );
		if ( null === $entry ) {
			if ( null === $registered_kind ) {
				return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register the matching job or chunked job before retrying its failed run.', (string) $identity ), reason: EngineErrorReason::UnknownJob, context: array( 'identity' => (string) $identity ), ) );
			}

			$retained_run_ids = \array_column( $read->value, 'run_id' );
			$correction       = array() === $retained_run_ids
				? 'retry a run identifier returned by the failed-run store after a terminal failure is recorded.'
				: \sprintf( 'retry one of the retained run identifiers: "%s".', \implode( '", "', $retained_run_ids ) );

			return new Failure(
				new EngineError(
					\sprintf( 'Failed run "%1$s" for background-work "%2$s" is not retained; %3$s', $run_id, (string) $identity, $correction ),
					reason: EngineErrorReason::RunNotRetained,
					context: array(
						'identity' => (string) $identity,
						'run_id'   => $run_id,
					),
				)
			);
		}

		$kind     = $entry['kind'];
		$resolved = $this->registered_handler_for_persisted_kind( $identity, $run_id, $kind, 'retrying this failed run' );
		if ( $resolved instanceof Failure ) {
			return $resolved;
		}
		$handler = $resolved;
		$options = $handler->options( $identity );
		if ( null === $options ) {
			return $this->incompatible_kind_registration( $identity, $run_id, $kind, 'retrying this failed run' );
		}

		$args_hash = $this->overlap_identity->resolve( $handler->key(), $identity, $options, $entry['start_args'] );
		if ( $args_hash instanceof Failure ) {
			return $args_hash;
		}
		$retry_overlap = OverlapPolicy::Allow === ( $options->overlap ?? OverlapPolicy::Reject ) ? OverlapPolicy::Allow : OverlapPolicy::Reject;
		$result        = $this->imperative_result( $this->dispatch_resolved( $handler, $options, $identity, $entry['start_args'], null, $entry['priority'], $retry_overlap, resolved_args_hash: $args_hash ) );
		if ( $result->is_success() && ! $failed_store->remove( $run_id ) ) {
			$this->logger->warning(
				\sprintf( 'Retried run "%s" could not be removed from retained failed-run data.', $run_id ),
				array(
					'identity' => (string) $identity,
					'run_id'   => $run_id,
				)
			);
		}

		return $result;
	}

	/**
	 * Cancels one retained run that is neither executing nor kind-protected from cancellation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified work identity.
	 * @param   string   $run_id   Retained run identifier.
	 *
	 * @throws  \InvalidArgumentException When the run identifier is malformed.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	public function cancel( Identity $identity, string $run_id ): AbstractResult {
		if ( null === RunIdentity::parse( $run_id ) ) {
			throw new \InvalidArgumentException( 'Run identifier is malformed; pass a run ID the engine returned.' );
		}

		$registered_kind = $this->registry->kind( $identity );
		$run_store       = $this->stores->run_store( $identity );
		$inspected       = $run_store->inspect( $run_id );
		if ( $inspected->is_failure() ) {
			if ( null === $registered_kind ) {
				return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register the matching job or chunked job before cancelling its run.', (string) $identity ), reason: EngineErrorReason::UnknownJob, context: array( 'identity' => (string) $identity ), ) );
			}

			return new Failure(
				new EngineError(
					\sprintf( 'Run "%1$s" for background-work "%2$s" could not be read; retry the cancel once option reads succeed.', $run_id, (string) $identity ),
					reason: EngineErrorReason::StorageFailure,
					context: array(
						'identity' => (string) $identity,
						'run_id'   => $run_id,
					),
				)
			);
		}

		$snapshot = $inspected->value;
		if ( null === $snapshot || null === $snapshot['state'] ) {
			if ( null === $registered_kind ) {
				return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register the matching job or chunked job before cancelling its run.', (string) $identity ), reason: EngineErrorReason::UnknownJob, context: array( 'identity' => (string) $identity ), ) );
			}

			return $this->cancel_not_retained( $identity, $run_id );
		}
		$state    = $snapshot['state'];
		$resolved = $this->registered_handler_for_persisted_kind( $identity, $run_id, $state->kind, 'cancelling this run' );
		if ( $resolved instanceof Failure ) {
			return $resolved;
		}
		$handler = $resolved;
		if ( RunStatus::Running !== $state->status ) {
			return new Failure(
				new EngineError(
					\sprintf( 'Run "%1$s" is already terminal (%2$s); a finished run cannot be cancelled.', $run_id, $state->status->value ),
					reason: EngineErrorReason::RunNotCancellable,
					context: array(
						'run_id' => $run_id,
						'status' => $state->status->value,
					),
				)
			);
		}
		if ( $state->executing ) {
			return $this->cancel_executing( $run_id );
		}
		$cancellation_error = $handler->cancellation_error( $run_id, $state );
		if ( null !== $cancellation_error ) {
			return new Failure( $cancellation_error );
		}

		$cancelled = $this->terminal_transitions->cancel_run( $handler, $identity, $run_id, $state, $run_store, $snapshot['raw'], fn () => $this->scheduler->unschedule_group( (string) $identity . '|' . $run_id ) );
		if ( $cancelled instanceof Failure ) {
			return $cancelled;
		}
		if ( $cancelled ) {
			return new Success( $run_id );
		}

		$latest_read = $run_store->inspect( $run_id );
		$latest      = $latest_read->is_failure() ? null : $latest_read->value;
		if ( null !== $latest && null !== $latest['state'] && $latest['state']->executing ) {
			return $this->cancel_executing( $run_id );
		}

		return new Failure( new EngineError( \sprintf( 'Run "%s" changed state while the cancel was in flight; re-inspect the run before retrying.', $run_id ), reason: EngineErrorReason::RunNotCancellable, context: array( 'run_id' => $run_id ), ) );
	}

	// endregion

	// region HELPERS

	/**
	 * Creates and schedules one resolved definition run under an explicit overlap policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   KindHandlerInterface    $handler                         Resolved kind handler.
	 * @param   JobOptions              $options                         Registered policy declaration.
	 * @param   Identity                $identity                        Complete scope-qualified work identity.
	 * @param   array<array-key, mixed> $args                            Start arguments.
	 * @param   int|null                $fire_at                         Absolute first-delivery timestamp, or null for asynchronous admission.
	 * @param   int|null                $priority                        Scheduler priority, or null to defer to the job default.
	 * @param   OverlapPolicy           $overlap                         Effective overlap policy.
	 * @param   \Closure|null           $on_accepted                     Callback after scheduler acceptance.
	 * @param   string|null             $resolved_args_hash              Pre-resolved overlap identity for retry.
	 * @param   bool                    $terminalize_overlap_key_failure Whether resolver failure consumes a recurring occurrence as a failed run.
	 *
	 * @throws  \Throwable When kind-owned admission effects fail unexpectedly.
	 *
	 * @return  AbstractResult<string|SkippedJobDispatch, EngineError|SchedulingError>
	 */
	private function dispatch_resolved( KindHandlerInterface $handler, JobOptions $options, Identity $identity, array $args, ?int $fire_at, ?int $priority, OverlapPolicy $overlap, ?\Closure $on_accepted = null, ?string $resolved_args_hash = null, bool $terminalize_overlap_key_failure = false ): AbstractResult {
		$kind     = $handler->key();
		$priority = $priority ?? $options->priority ?? 10;
		if ( 0 > $priority || self::MAX_PRIORITY < $priority ) {
			return new Failure(
				new EngineError(
					\sprintf( '%1$s "%2$s" priority %3$d is invalid; pass a value from 0 through %4$d.', $kind, (string) $identity, $priority, self::MAX_PRIORITY ),
					reason: EngineErrorReason::PayloadRejected,
					context: array(
						'identity' => (string) $identity,
						'priority' => $priority,
					),
				)
			);
		}
		if ( null !== $fire_at && self::MAX_RUN_AT < $fire_at ) {
			return new Failure(
				new EngineError(
					\sprintf( '%1$s "%2$s" run_at %3$d is invalid; pass a value at or before %4$d.', $kind, (string) $identity, $fire_at, self::MAX_RUN_AT ),
					reason: EngineErrorReason::PayloadRejected,
					context: array(
						'identity' => (string) $identity,
						'run_at'   => $fire_at,
					),
				)
			);
		}

		if ( null === $resolved_args_hash ) {
			$args_hash = $this->overlap_identity->resolve( $kind, $identity, $options, $args );
			if ( $args_hash instanceof Failure && $terminalize_overlap_key_failure && EngineErrorReason::ExecutionFailed === $args_hash->error->reason ) {
				return $this->terminalize_overlap_key_failure( $handler, $identity, $args, $priority, $args_hash->error, $on_accepted );
			}
		} else {
			$args_hash = $resolved_args_hash;
		}
		if ( $args_hash instanceof Failure ) {
			return $args_hash;
		}
		$created_at = $this->clock->now()->getTimestamp();
		$run_id     = RunIdentity::generate( $created_at, $this->randomizer );
		if ( OverlapPolicy::Allow === $overlap ) {
			// Allow gets a per-run lock identity so a Contended selection can only represent a run-ID collision.
			$args_hash = $this->salted_args_hash( $args_hash, $run_id );
		}

		$latest_pointer = $this->stores->latest_run_pointer( $identity );
		$claim          = $this->overlap_guard->claim( $identity, $args_hash, $run_id, $this->lock_windows->lock_staleness( $identity, $run_id ) );
		if (
			LockClaimOutcome::Malformed === $claim->outcome
			|| LockClaimOutcome::Indeterminate === $claim->outcome
		) {
			return $this->invalid_lock_selection_failure( $kind, $identity, $run_id );
		}
		if ( LockClaimOutcome::Contended === $claim->outcome ) {
			if ( null === $claim->owner_run_id ) {
				return $this->invalid_lock_selection_failure( $kind, $identity, $run_id );
			}
			if ( $run_id === $claim->owner_run_id ) {
				return new Failure(
					new EngineError(
						\sprintf( '%1$s "%2$s" generated run "%3$s", but that identifier already owns the selected overlap lock; retry so the run receives a fresh identifier.', $kind, (string) $identity, $run_id ),
						reason: EngineErrorReason::OverlapHeld,
						context: array(
							'identity' => (string) $identity,
							'run_id'   => $run_id,
							'kind'     => $kind,
						),
					)
				);
			}
			if ( OverlapPolicy::Allow === $overlap ) {
				return new Failure( new EngineError( \sprintf( '%1$s "%2$s" generated a duplicate per-run overlap identity for run "%3$s"; retry so the run receives a fresh identifier.', $kind, (string) $identity, $run_id ), reason: EngineErrorReason::OverlapHeld, context: array( 'identity' => (string) $identity ), ) );
			}
			if ( false === $claim->stale && OverlapPolicy::Reject === $overlap ) {
				return new Success( new SkippedJobDispatch( $claim->owner_run_id, EngineError::held( $kind, $identity, $claim->owner_run_id ) ) );
			}
		}

		$run_store     = $this->stores->run_store( $identity );
		$admission_now = $this->clock->now()->getTimestamp();
		$admission     = $this->create_run_state_and_take_over_if_contended( $handler, $identity, $run_id, $args, $args_hash, $claim, $run_store, $fire_at, $admission_now, $priority );
		if ( $admission instanceof Failure ) {
			return $admission;
		}
		$state    = $admission['state'];
		$takeover = $admission['takeover'];

		$pending = $state->pending;
		if ( null !== $pending && 'single' === $pending->mode ) {
			$fire_at         = $pending->fire_at ?? throw new \LogicException( 'Pending single-action delivery requires an integer fire time.' );
			$heartbeat_error = match ( $this->overlap_guard->heartbeat( $identity, $args_hash, $run_id, $fire_at ) ) {
				HeartbeatOutcome::Owned => null,
				HeartbeatOutcome::Lost, HeartbeatOutcome::GenerationMismatch => new EngineError( \sprintf( '%1$s "%2$s" lost lock ownership while preparing its timed action; dispatch it again against the current lock state.', $kind, (string) $identity ), reason: EngineErrorReason::OverlapHeld, context: array( 'identity' => (string) $identity ), ),
				HeartbeatOutcome::Indeterminate => new EngineError( \sprintf( '%1$s "%2$s" could not confirm lock ownership while preparing its timed action; dispatch it again after authoritative storage access recovers.', $kind, (string) $identity ), reason: EngineErrorReason::StorageFailure, context: array( 'identity' => (string) $identity ), ),
			};
			if ( null !== $heartbeat_error ) {
				$this->roll_back_admitted_run( $identity, $args_hash, $run_id, $state, $run_store );
				$this->execute_takeover_effects( $identity, $run_id, $takeover, $run_store );

				return new Failure( $heartbeat_error );
			}

			$replacement  = $state->with_heartbeat_at( $fire_at );
			$transitioned = $run_store->replace_if_state_matches( $run_id, $state, $replacement );
			if ( $transitioned instanceof Failure ) {
				$this->roll_back_admitted_run( $identity, $args_hash, $run_id, $state, $run_store );
				$this->execute_takeover_effects( $identity, $run_id, $takeover, $run_store );

				return $transitioned;
			}
			if ( null === $transitioned ) {
				$this->roll_back_admitted_run( $identity, $args_hash, $run_id, $state, $run_store );
				$this->execute_takeover_effects( $identity, $run_id, $takeover, $run_store );

				return new Failure(
					new EngineError(
						\sprintf( '%1$s "%2$s" lost its live run state while preparing its timed action; retry against the current run state.', $kind, (string) $identity ),
						reason: EngineErrorReason::StorageFailure,
						context: array(
							'identity' => (string) $identity,
							'run_id'   => $run_id,
						),
					)
				);
			}
			$state = $replacement;
		}

		if ( ! $latest_pointer->record( $run_id, $args_hash ) ) {
			$this->logger->warning(
				'Latest-run pointer persistence failed; discovery metadata may lag until a later repair.',
				array(
					'identity' => (string) $identity,
					'run_id'   => $run_id,
				)
			);
		}

		$after_dispatch_error = null;
		try {
			$after_dispatch_error = $handler->after_dispatch( $identity, $run_id, $state, $run_store );
		} catch ( \Throwable $throwable ) {
			$this->execute_takeover_effects( $identity, $run_id, $takeover, $run_store );

			throw $throwable;
		}
		if ( null !== $after_dispatch_error ) {
			$this->execute_takeover_effects( $identity, $run_id, $takeover, $run_store );

			return new Failure( $after_dispatch_error );
		}

		$pending   = $state->pending ?? throw new \LogicException( 'Admitted run delivery requires a durable pending-action descriptor.' );
		$scheduled = $this->delivery_scheduler->schedule( $identity, $run_id, $state->action_sequence, $pending );
		if ( $scheduled->is_failure() ) {
			$this->roll_back_admitted_run( $identity, $args_hash, $run_id, $state, $run_store );
			$this->execute_takeover_effects( $identity, $run_id, $takeover, $run_store );

			return $scheduled;
		}

		try {
			$on_accepted?->__invoke();
			if ( ! $this->stores->run_history( $identity )->record_started( $run_id, $args_hash ) ) {
				$this->logger->warning(
					'Started run history could not be persisted; inspection data may be incomplete.',
					array(
						'identity' => (string) $identity,
						'run_id'   => $run_id,
					)
				);
			}
		} finally {
			$this->execute_takeover_effects( $identity, $run_id, $takeover, $run_store );
		}

		return new Success( $run_id );
	}

	/**
	 * Consumes one recurring occurrence as a retained failed run after resolver rejection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   KindHandlerInterface    $handler     Resolved kind handler.
	 * @param   Identity                $identity    Complete scope-qualified work identity.
	 * @param   array<array-key, mixed> $args        Start arguments.
	 * @param   int                     $priority    Admitted scheduler priority.
	 * @param   EngineError             $error       Resolver rejection detail.
	 * @param   \Closure|null           $on_accepted Callback after run creation and before terminalization.
	 *
	 * @return  AbstractResult<string, EngineError>
	 */
	private function terminalize_overlap_key_failure( KindHandlerInterface $handler, Identity $identity, array $args, int $priority, EngineError $error, ?\Closure $on_accepted ): AbstractResult {
		$kind      = $handler->key();
		$args_hash = $this->overlap_identity->canonical( $kind, $identity, $args );
		if ( $args_hash instanceof Failure ) {
			return $args_hash;
		}

		$now    = $this->clock->now()->getTimestamp();
		$run_id = RunIdentity::generate( $now, $this->randomizer );
		// A resolver failure has no trustworthy client overlap lane, so its diagnostic run cannot contend with working admissions.
		$args_hash = $this->salted_args_hash( $args_hash, $run_id );
		$claim     = $this->overlap_guard->claim( $identity, $args_hash, $run_id, $this->lock_windows->lock_staleness( $identity, $run_id ) );
		if (
			LockClaimOutcome::Malformed === $claim->outcome
			|| LockClaimOutcome::Indeterminate === $claim->outcome
		) {
			return $this->invalid_lock_selection_failure( $kind, $identity, $run_id );
		}
		if ( LockClaimOutcome::Claimed !== $claim->outcome ) {
			return new Failure( new EngineError( \sprintf( '%1$s "%2$s" generated a duplicate per-run overlap identity for run "%3$s"; retry so the run receives a fresh identifier.', $kind, (string) $identity, $run_id ), reason: EngineErrorReason::OverlapHeld, context: array( 'identity' => (string) $identity ), ) );
		}

		$run_store = $this->stores->run_store( $identity );
		$state     = $run_store->create( $run_id, $kind, $args, $args_hash, $handler->initial_kind_state( $args ), priority: $priority );
		if ( $state instanceof Failure ) {
			$this->overlap_guard->release( $identity, $args_hash, $run_id );

			return $state;
		}
		if ( null === $state ) {
			$this->overlap_guard->release( $identity, $args_hash, $run_id );

			return new Failure(
				new EngineError(
					\sprintf( 'Run "%1$s" for %2$s "%3$s" could not be persisted; remove the conflicting run option before retrying.', $run_id, $kind, (string) $identity ),
					reason: EngineErrorReason::StorageFailure,
					context: array(
						'identity' => (string) $identity,
						'run_id'   => $run_id,
						'kind'     => $kind,
					),
				)
			);
		}

		$on_accepted?->__invoke();
		$terminalized = $this->terminal_transitions->fail_run( $handler, $identity, $run_id, $state, $run_store, $error, 1, RunFailureStage::execution(), ErrorCode::ExecutionFailed, $handler->failure_details( $state ) );
		if ( ! $terminalized ) {
			$this->roll_back_admitted_run( $identity, $args_hash, $run_id, $state, $run_store );

			return new Failure(
				new EngineError(
					\sprintf( 'Run "%1$s" for %2$s "%3$s" could not persist its overlap-key resolver failure; repair option writes before retrying.', $run_id, $kind, (string) $identity ),
					reason: EngineErrorReason::StorageFailure,
					context: array(
						'identity' => (string) $identity,
						'run_id'   => $run_id,
						'kind'     => $kind,
					),
				)
			);
		}

		return new Success( $run_id );
	}

	/**
	 * Persists provisional run state and transfers a contended lock before returning ownership.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   KindHandlerInterface    $handler      Resolved kind handler.
	 * @param   Identity                $identity     Complete scope-qualified work identity.
	 * @param   string                  $run_id       Run identifier.
	 * @param   array<array-key, mixed> $args         Start arguments.
	 * @param   string                  $args_hash    Canonical overlap identity.
	 * @param   LockClaimResult         $claim        Initial overlap-lock selection.
	 * @param   RunStore                $run_store    Active-run store.
	 * @param   int|null                $fire_at      Absolute first-delivery timestamp, or null for asynchronous admission.
	 * @param   int                     $now          Admission timestamp.
	 * @param   int                     $priority     Scheduler priority.
	 *
	 * @return  array{state: RunState, takeover: array{run_id: string, claimed: array{raw: string, state: RunState}}|null}|Failure<EngineError>
	 */
	private function create_run_state_and_take_over_if_contended( KindHandlerInterface $handler, Identity $identity, string $run_id, array $args, string $args_hash, LockClaimResult $claim, RunStore $run_store, ?int $fire_at, int $now, int $priority ): array|Failure {
		$kind  = $handler->key();
		$state = $run_store->create( $run_id, $kind, $args, $args_hash, $handler->initial_kind_state( $args ), $handler->initial_pending( $fire_at, $now, $priority ), $priority );
		if ( $state instanceof Failure ) {
			if ( LockClaimOutcome::Claimed === $claim->outcome ) {
				$this->overlap_guard->release( $identity, $args_hash, $run_id );
			}

			return $state;
		}
		if ( null === $state ) {
			if ( LockClaimOutcome::Claimed === $claim->outcome ) {
				$this->overlap_guard->release( $identity, $args_hash, $run_id );
			}

			return new Failure(
				new EngineError(
					\sprintf( 'Run "%1$s" for %2$s "%3$s" could not be persisted; remove the conflicting run option before retrying.', $run_id, $kind, (string) $identity ),
					reason: EngineErrorReason::StorageFailure,
					context: array(
						'identity' => (string) $identity,
						'run_id'   => $run_id,
						'kind'     => $kind,
					),
				)
			);
		}
		if ( LockClaimOutcome::Claimed === $claim->outcome ) {
			return array(
				'state'    => $state,
				'takeover' => null,
			);
		}

		$incumbent_run_id = $claim->owner_run_id;
		$incumbent_raw    = $claim->raw;
		if ( LockClaimOutcome::Contended !== $claim->outcome || null === $incumbent_run_id || null === $incumbent_raw ) {
			$run_store->delete_if_unchanged( $run_id, $state );

			return $this->invalid_lock_selection_failure( $kind, $identity, $run_id );
		}

		$incumbent_read = $run_store->inspect( $incumbent_run_id );
		if ( $incumbent_read->is_failure() ) {
			$run_store->delete_if_unchanged( $run_id, $state );

			return new Failure(
				new EngineError(
					\sprintf( 'Run "%1$s" for %2$s "%3$s" could not read the contended run before overlap takeover; repair option reads and retry.', $run_id, $kind, (string) $identity ),
					reason: EngineErrorReason::StorageFailure,
					context: array(
						'identity' => (string) $identity,
						'run_id'   => $run_id,
						'kind'     => $kind,
					),
				)
			);
		}

		$incumbent_snapshot = $incumbent_read->value;
		if (
			null !== $incumbent_snapshot
			&& (
				null === $incumbent_snapshot['state']
				|| $args_hash !== $incumbent_snapshot['state']->args_hash
			)
		) {
			$run_store->delete_if_unchanged( $run_id, $state );

			return new Failure(
				new EngineError(
					\sprintf( 'Run "%1$s" for %2$s "%3$s" could not confirm a valid matching state for the contended lock owner; repair active-run storage and retry.', $run_id, $kind, (string) $identity ),
					reason: EngineErrorReason::StorageFailure,
					context: array(
						'identity' => (string) $identity,
						'run_id'   => $run_id,
						'kind'     => $kind,
					),
				)
			);
		}

		$claimed_incumbent = null;
		if ( null !== $incumbent_snapshot && RunStatus::Running === $incumbent_snapshot['state']->status ) {
			// This exact run-state CAS is the first linearization point: once it wins, the incumbent's in-flight completion CAS cannot commit after takeover.
			$claimed_incumbent = $this->terminal_transitions->claim_superseded_run( $incumbent_run_id, $incumbent_snapshot['state'], $run_store, $incumbent_snapshot['raw'] );
			if ( $claimed_incumbent instanceof Failure ) {
				$run_store->delete_if_unchanged( $run_id, $state );

				return new Failure(
					new EngineError(
						\sprintf( 'Run "%1$s" for %2$s "%3$s" could not confirm the incumbent supersession before overlap transfer; repair option writes and retry.', $run_id, $kind, (string) $identity ),
						reason: EngineErrorReason::StorageFailure,
						context: array(
							'identity' => (string) $identity,
							'run_id'   => $run_id,
							'kind'     => $kind,
						),
					)
				);
			}
			if ( null === $claimed_incumbent ) {
				$run_store->delete_if_unchanged( $run_id, $state );

				return new Failure(
					new EngineError(
						\sprintf( '%1$s "%2$s" incumbent run changed while the replacement was superseding it; retry the dispatch against the current incumbent state.', $kind, (string) $identity ),
						reason: EngineErrorReason::OverlapHeld,
						context: array(
							'identity' => (string) $identity,
							'run_id'   => $run_id,
							'kind'     => $kind,
						),
					)
				);
			}
		} elseif ( null !== $incumbent_snapshot && RunStatus::Superseded === $incumbent_snapshot['state']->status ) {
			// An unresolved lock transfer leaves Superseded effects pending; a retry with a definite transfer owns their replay.
			$claimed_incumbent = array(
				'raw'   => $incumbent_snapshot['raw'],
				'state' => $incumbent_snapshot['state'],
			);
		}

		// This exact lock CAS is the second linearization point for a running incumbent; a terminal snapshot crossed its own exact terminal CAS before admission observed it.
		$transfer = $this->overlap_guard->replace( $identity, $args_hash, $incumbent_run_id, $incumbent_raw, $run_id );
		if ( LockTransferOutcome::Indeterminate === $transfer ) {
			$run_store->delete_if_unchanged( $run_id, $state );

			return new Failure(
				new EngineError(
					\sprintf( 'Run "%1$s" for %2$s "%3$s" could not transfer overlap lock ownership because storage failed; repair option writes before retrying.', $run_id, $kind, (string) $identity ),
					reason: EngineErrorReason::StorageFailure,
					context: array(
						'identity' => (string) $identity,
						'run_id'   => $run_id,
						'kind'     => $kind,
					),
				)
			);
		}

		if ( LockTransferOutcome::Lost === $transfer ) {
			// A rival may advance the provisional state while the overlap transfer is in flight.
			$run_store->delete_if_unchanged( $run_id, $state );
			// The exact lock-transfer winner owns replay; competing snapshots cannot safely fire the same unmarked effects.

			return new Failure(
				new EngineError(
					\sprintf( '%1$s "%2$s" lock ownership changed while the replacement was claiming it; retry the dispatch against the current owner.', $kind, (string) $identity ),
					reason: EngineErrorReason::OverlapHeld,
					context: array(
						'identity' => (string) $identity,
						'kind'     => $kind,
					),
				)
			);
		}

		return array(
			'state'    => $state,
			'takeover' => null === $claimed_incumbent
				? null
				: array(
					'run_id'  => $incumbent_run_id,
					'claimed' => $claimed_incumbent,
				),
		);
	}

	/**
	 * Executes post-resolution supersession effects without abandoning the resolved admission.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity                                                                 $identity       Complete scope-qualified work identity.
	 * @param   string                                                                   $replacement_id Replacement run identifier.
	 * @param   array{run_id: string, claimed: array{raw: string, state: RunState}}|null $takeover       Claimed incumbent supersession, if any.
	 * @param   RunStore                                                                 $run_store      Active-run store.
	 *
	 * @return  void
	 */
	private function execute_takeover_effects( Identity $identity, string $replacement_id, ?array $takeover, RunStore $run_store ): void {
		if ( null === $takeover ) {
			return;
		}

		try {
			$this->terminal_transitions->execute_claimed_supersession( $identity, $takeover['run_id'], $replacement_id, $takeover['claimed'], $run_store );
		} catch ( \Throwable $throwable ) {
			// The committed Superseded row retains every unmarked effect for maintenance replay.
			$this->logger->error(
				'Superseded-run terminal effects could not finish synchronously; the durable terminal row retains unmarked effects for maintenance replay.',
				array(
					'identity'  => (string) $identity,
					'run_id'    => $takeover['run_id'],
					'exception' => $throwable,
				)
			);
		}
	}

	/**
	 * Returns a storage failure for an untrustworthy overlap-lock selection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $kind     Persisted work-kind key.
	 * @param   Identity $identity Complete scope-qualified work identity.
	 * @param   string   $run_id   Generated run identifier.
	 *
	 * @return  Failure<EngineError>
	 */
	private function invalid_lock_selection_failure( string $kind, Identity $identity, string $run_id ): Failure {
		return new Failure(
			new EngineError(
				\sprintf( 'Run "%1$s" for %2$s "%3$s" could not read a valid authoritative overlap lock row; repair overlap-lock storage and retry.', $run_id, $kind, (string) $identity ),
				reason: EngineErrorReason::StorageFailure,
				context: array(
					'identity' => (string) $identity,
					'run_id'   => $run_id,
					'kind'     => $kind,
				),
			)
		);
	}

	/**
	 * Returns the installed handler for one graph-owned key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $kind Persisted kind key.
	 *
	 * @throws  \LogicException When the engine graph has no handler for the installed kind.
	 *
	 * @return  KindHandlerInterface
	 */
	private function handler( string $kind ): KindHandlerInterface {
		$handler = $this->handlers[ $kind ] ?? null;
		if ( null === $handler ) {
			throw new \LogicException( \sprintf( 'The engine graph has no handler for installed kind "%s".', $kind ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
		}

		return $handler;
	}

	/**
	 * Resolves a persisted kind and verifies that the live identity still belongs to it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified work identity.
	 * @param   string   $run_id    Retained run identifier.
	 * @param   string   $kind      Persisted kind key.
	 * @param   string   $operation Corrective operation phrase.
	 *
	 * @return  KindHandlerInterface|Failure<EngineError>
	 */
	private function registered_handler_for_persisted_kind( Identity $identity, string $run_id, string $kind, string $operation ): KindHandlerInterface|Failure {
		$handler = $this->handlers[ $kind ] ?? null;
		if ( null === $handler || $kind !== $this->registry->kind( $identity ) || null === $handler->execution( $identity ) ) {
			return $this->incompatible_kind_registration( $identity, $run_id, $kind, $operation );
		}

		return $handler;
	}

	/**
	 * Returns the corrective failure for a missing or kind-incompatible live registration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified work identity.
	 * @param   string   $run_id    Retained run identifier.
	 * @param   string   $kind      Persisted kind key.
	 * @param   string   $operation Corrective operation phrase.
	 *
	 * @return  Failure<EngineError>
	 */
	private function incompatible_kind_registration( Identity $identity, string $run_id, string $kind, string $operation ): Failure {
		$registered_kind = $this->registry->kind( $identity );
		$registration    = null === $registered_kind
			? 'is not registered'
			: \sprintf( 'is registered as "%s"', $registered_kind );

		return new Failure(
			new EngineError(
				\sprintf( 'Background-work "%1$s" %2$s, but run "%3$s" was persisted as "%4$s"; register the matching %4$s before %5$s.', (string) $identity, $registration, $run_id, $kind, $operation ),
				reason: EngineErrorReason::UnknownJob,
				context: array(
					'identity' => (string) $identity,
					'run_id'   => $run_id,
					'kind'     => $kind,
				),
			)
		);
	}

	/**
	 * Converts a held-Reject skip into the imperative hard-failure contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   AbstractResult<string|SkippedJobDispatch, EngineError|SchedulingError> $result Resolved dispatch result.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	private function imperative_result( AbstractResult $result ): AbstractResult {
		if ( $result->is_failure() ) {
			return $result;
		}

		return $result->value instanceof SkippedJobDispatch ? new Failure( $result->value->error ) : new Success( $result->value );
	}

	/**
	 * Returns the corrective failure for an absent or corrupt retained run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified work identity.
	 * @param   string   $run_id   Retained run identifier.
	 *
	 * @return  Failure<EngineError>
	 */
	private function cancel_not_retained( Identity $identity, string $run_id ): Failure {
		return new Failure(
			new EngineError(
				\sprintf( 'Run "%1$s" for background-work "%2$s" is not retained; nothing remains to cancel.', $run_id, (string) $identity ),
				reason: EngineErrorReason::RunNotRetained,
				context: array(
					'identity' => (string) $identity,
					'run_id'   => $run_id,
				),
			)
		);
	}

	/**
	 * Returns the corrective failure for a run whose admitted delivery is executing.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Retained run identifier.
	 *
	 * @return  Failure<EngineError>
	 */
	private function cancel_executing( string $run_id ): Failure {
		return new Failure( new EngineError( \sprintf( 'Run "%s" is executing; a run in flight completes or fails on its own.', $run_id ), reason: EngineErrorReason::RunNotCancellable, context: array( 'run_id' => $run_id ), ) );
	}

	/**
	 * Rolls back an admitted run's lock and row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified work identity.
	 * @param   string   $args_hash Canonical overlap identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $expected  Exact provisional state admitted by this dispatch.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  void
	 */
	private function roll_back_admitted_run( Identity $identity, string $args_hash, string $run_id, RunState $expected, RunStore $run_store ): void {
		// Same-second dispatch or clock rollback can repeat the timestamp, but a rollback ABA also
		// requires the 63-bit suffix and complete provisional state to match, making the residual negligible.
		$run_deleted = $run_store->delete_if_unchanged( $run_id, $expected );
		// A changed run generation keeps its overlap fence; only this exact provisional row authorizes lock cleanup.
		$lock_release_confirmed = $run_deleted
			? $this->overlap_guard->release( $identity, $args_hash, $run_id )
			: false;
		if ( ! $lock_release_confirmed || ! $run_deleted ) {
			$this->logger->warning(
				'Scheduling rollback could not confirm complete cleanup; the run row may be redelivered by maintenance. Repair storage reads and writes before retrying.',
				array(
					'identity'               => (string) $identity,
					'run_id'                 => $run_id,
					'lock_release_confirmed' => $lock_release_confirmed,
					'run_deleted'            => $run_deleted,
				)
			);
		}
	}

	/**
	 * Returns the per-run overlap identity used by Allow admissions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $args_hash Canonical overlap identity.
	 * @param   string $run_id    Run identifier.
	 *
	 * @return  string
	 */
	private function salted_args_hash( string $args_hash, string $run_id ): string {
		return \hash( 'sha256', $args_hash . '|' . $run_id );
	}

	// endregion
}

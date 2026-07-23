<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\BackendInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\HeartbeatOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockClaimOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
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
	 * Highest scheduler priority accepted by the orchestration API.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_PRIORITY = 255;

	/**
	 * Maximum bytes accepted from a custom overlap-key resolver.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_OVERLAP_KEY_BYTES = 64;

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
	 * @param   JobRegistry         $registry             Registered work definitions.
	 * @param   array               $handlers             Kind handlers keyed by their persisted keys.
	 * @param   BackendInterface    $scheduler            Scheduling facade boundary.
	 * @param   OverlapGuard        $overlap_guard        Execution-overlap guard.
	 * @param   StoreFactory        $stores               Name-bound store factory.
	 * @param   ClockInterface      $clock                Timestamp source.
	 * @param   RandomizerInterface $randomizer           Run identifier randomness.
	 * @param   LoggerInterface     $logger               Log event sink.
	 * @param   LockWindows         $lock_windows         Filterable run-lock timing policy.
	 * @param   RunTransitions      $terminal_transitions Fenced terminal-write coordinator.
	 */
	public function __construct(
		private JobRegistry $registry,
		private array $handlers,
		private BackendInterface $scheduler,
		private OverlapGuard $overlap_guard,
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
	 * @param   string        $identity   Complete owner-qualified work identity.
	 * @param   JobDefinition $definition Definition to register.
	 *
	 * @throws  \InvalidArgumentException When the kind is not installed or its execution role is incompatible.
	 *
	 * @return  void
	 */
	public function register( string $identity, JobDefinition $definition ): void {
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
	 * @param   string                  $identity Complete owner-qualified work identity.
	 * @param   array<array-key, mixed> $args     Start arguments.
	 * @param   int                     $delay    Scheduling delay in seconds.
	 * @param   int|null                $priority Scheduler priority from 0 through 255, or null for the engine default.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a job-dispatch failure must be handled, not dropped' )]
	public function dispatch( string $identity, array $args = array(), int $delay = 0, ?int $priority = null ): AbstractResult {
		$kind = $this->registry->kind( $identity );
		if ( null === $kind ) {
			return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register it before dispatching.', $identity ), reason: EngineErrorReason::UnknownJob, context: array( 'identity' => $identity ), ) );
		}
		$handler = $this->handler( $kind );
		$options = $handler->options( $identity );
		if ( null === $handler->execution( $identity ) || null === $options ) {
			return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register it before dispatching.', $identity ), reason: EngineErrorReason::UnknownJob, context: array( 'identity' => $identity ), ) );
		}
		$priority ??= 10;

		return $this->imperative_result( $this->dispatch_resolved( $handler, $options, $identity, $args, $delay, $priority, $options->overlap ?? OverlapPolicy::Reject ) );
	}

	/**
	 * Dispatches a schedule target through its registered kind handler.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $identity    Complete owner-qualified work identity.
	 * @param   array<array-key, mixed> $args        Target arguments.
	 * @param   int                     $priority    Scheduler priority from 0 through 255.
	 * @param   \Closure|null           $on_accepted Internal callback after backend acceptance and before history.
	 *
	 * @return  AbstractResult<string|SkippedJobDispatch, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a scheduled-target dispatch failure must be handled, not dropped' )]
	public function dispatch_scheduled_target( string $identity, array $args, int $priority = 10, ?\Closure $on_accepted = null ): AbstractResult {
		$kind = $this->registry->kind( $identity );
		if ( null === $kind ) {
			return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register it before dispatching.', $identity ), reason: EngineErrorReason::UnknownJob, context: array( 'identity' => $identity ), ) );
		}
		$handler = $this->handler( $kind );
		$options = $handler->options( $identity );
		if ( null === $handler->execution( $identity ) || null === $options ) {
			return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register it before dispatching.', $identity ), reason: EngineErrorReason::UnknownJob, context: array( 'identity' => $identity ), ) );
		}

		return $this->dispatch_resolved( $handler, $options, $identity, $args, 0, $priority, $options->overlap ?? OverlapPolicy::Reject, $on_accepted );
	}

	/**
	 * Starts a fresh run from one retained failed run's original arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified work identity.
	 * @param   string $run_id   Retained failed-run identifier.
	 *
	 * @throws  \InvalidArgumentException When the run identifier is malformed.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed( string $identity, string $run_id ): AbstractResult {
		if ( null === RunIdentity::parse( $run_id ) ) {
			throw new \InvalidArgumentException( 'Run identifier is malformed; pass a run ID the engine returned.' );
		}

		$kind = $this->registry->kind( $identity );
		if ( null === $kind ) {
			return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register the matching job or chunked job before retrying its failed run.', $identity ), reason: EngineErrorReason::UnknownJob, context: array( 'identity' => $identity ), ) );
		}
		$handler = $this->handler( $kind );
		$options = $handler->options( $identity );
		if ( null === $handler->execution( $identity ) || null === $options ) {
			return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register the matching job or chunked job before retrying its failed run.', $identity ), reason: EngineErrorReason::UnknownJob, context: array( 'identity' => $identity ), ) );
		}

		$failed_store = $this->stores->failed_run_store( $identity );
		$read         = $failed_store->all();
		if ( $read->is_failure() ) {
			return $read;
		}

		$entry = \array_find( $read->value, static fn ( array $candidate ): bool => $run_id === $candidate['run_id'] );
		if ( null === $entry ) {
			$retained_run_ids = \array_column( $read->value, 'run_id' );
			$correction       = array() === $retained_run_ids
				? 'retry a run identifier returned by the failed-run store after a terminal failure is recorded.'
				: \sprintf( 'retry one of the retained run identifiers: "%s".', \implode( '", "', $retained_run_ids ) );

			return new Failure(
				new EngineError(
					\sprintf( 'Failed run "%1$s" for background-work "%2$s" is not retained; %3$s', $run_id, $identity, $correction ),
					reason: EngineErrorReason::RunNotRetained,
					context: array(
						'identity' => $identity,
						'run_id'   => $run_id,
					),
				)
			);
		}

		$args_hash = $this->overlap_args_hash( $handler->key(), $identity, $entry['start_args'], $this->custom_overlap_key( $options, $entry['start_args'] ) );
		if ( $args_hash instanceof Failure ) {
			return $args_hash;
		}
		$retry_overlap = OverlapPolicy::Allow === ( $options->overlap ?? OverlapPolicy::Reject ) ? OverlapPolicy::Allow : OverlapPolicy::Reject;
		$result        = $this->imperative_result( $this->dispatch_resolved( $handler, $options, $identity, $entry['start_args'], 0, 10, $retry_overlap, resolved_args_hash: $args_hash ) );
		if ( $result->is_success() && ! $failed_store->remove( $run_id ) ) {
			$this->logger->warning(
				\sprintf( 'Retried run "%s" could not be removed from retained failed-run data.', $run_id ),
				array(
					'identity' => $identity,
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
	 * @param   string $identity Complete owner-qualified work identity.
	 * @param   string $run_id   Retained run identifier.
	 *
	 * @throws  \InvalidArgumentException When the run identifier is malformed.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	public function cancel( string $identity, string $run_id ): AbstractResult {
		if ( null === RunIdentity::parse( $run_id ) ) {
			throw new \InvalidArgumentException( 'Run identifier is malformed; pass a run ID the engine returned.' );
		}

		$kind = $this->registry->kind( $identity );
		if ( null === $kind ) {
			return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register the matching job or chunked job before cancelling its run.', $identity ), reason: EngineErrorReason::UnknownJob, context: array( 'identity' => $identity ), ) );
		}
		$handler = $this->handler( $kind );
		if ( null === $handler->execution( $identity ) ) {
			return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register the matching job or chunked job before cancelling its run.', $identity ), reason: EngineErrorReason::UnknownJob, context: array( 'identity' => $identity ), ) );
		}

		$run_store = $this->stores->run_store( $identity );
		$inspected = $run_store->inspect( $run_id );
		if ( $inspected->is_failure() ) {
			return new Failure(
				new EngineError(
					\sprintf( 'Run "%1$s" for background-work "%2$s" could not be read; retry the cancel once option reads succeed.', $run_id, $identity ),
					reason: EngineErrorReason::StorageFailure,
					context: array(
						'identity' => $identity,
						'run_id'   => $run_id,
					),
				)
			);
		}

		$snapshot = $inspected->value;
		if ( null === $snapshot || null === $snapshot['state'] ) {
			return $this->cancel_not_retained( $identity, $run_id );
		}
		$state = $snapshot['state'];
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

		$cancelled = $this->terminal_transitions->cancel_run( $handler, $identity, $run_id, $state, $run_store, $snapshot['raw'], fn () => $this->unschedule_group( $identity . '|' . $run_id ) );
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
	 * @param   KindHandlerInterface    $handler            Resolved kind handler.
	 * @param   JobOptions              $options            Registered policy declaration.
	 * @param   string                  $identity           Complete owner-qualified work identity.
	 * @param   array<array-key, mixed> $args               Start arguments.
	 * @param   int                     $delay              Scheduling delay in seconds.
	 * @param   int                     $priority           Scheduler priority.
	 * @param   OverlapPolicy           $overlap            Effective overlap policy.
	 * @param   \Closure|null           $on_accepted        Callback after scheduler acceptance.
	 * @param   string|null             $resolved_args_hash Pre-resolved overlap identity for retry.
	 *
	 * @return  AbstractResult<string|SkippedJobDispatch, EngineError|SchedulingError>
	 */
	private function dispatch_resolved( KindHandlerInterface $handler, JobOptions $options, string $identity, array $args, int $delay, int $priority, OverlapPolicy $overlap, ?\Closure $on_accepted = null, ?string $resolved_args_hash = null ): AbstractResult {
		$kind = $handler->key();
		if ( 0 > $priority || self::MAX_PRIORITY < $priority ) {
			return new Failure(
				new EngineError(
					\sprintf( '%1$s "%2$s" priority %3$d is invalid; pass a value from 0 through %4$d.', $kind, $identity, $priority, self::MAX_PRIORITY ),
					reason: EngineErrorReason::PayloadRejected,
					context: array(
						'identity' => $identity,
						'priority' => $priority,
					),
				)
			);
		}

		$args_hash = $resolved_args_hash ?? $this->overlap_args_hash( $kind, $identity, $args, $this->custom_overlap_key( $options, $args ) );
		if ( $args_hash instanceof Failure ) {
			return $args_hash;
		}
		$now = $this->clock->now()->getTimestamp();
		if ( 0 < $delay && $delay > \PHP_INT_MAX - $now ) {
			return new Failure(
				new EngineError(
					\sprintf( '%1$s "%2$s" delay %3$d exceeds supported Unix seconds; pass a smaller delay.', $kind, $identity, $delay ),
					reason: EngineErrorReason::PayloadRejected,
					context: array(
						'delay'    => $delay,
						'identity' => $identity,
					),
				)
			);
		}
		$scheduled_at = $now + $delay;
		$run_id       = RunIdentity::generate( $now, $this->randomizer );
		if ( OverlapPolicy::Allow === $overlap ) {
			// Allow gets a per-run lock identity so concurrent occurrences never contend; NotClaimed can then only mean run-id collision.
			$args_hash = $this->salted_args_hash( $args_hash, $run_id );
		}

		$latest_pointer = $this->stores->latest_run_pointer( $identity );
		$claim          = $this->overlap_guard->claim( $identity, $args_hash, $run_id, $this->lock_windows->lock_staleness( $identity, $run_id ) );
		if ( LockClaimOutcome::NotClaimed === $claim && OverlapPolicy::Reject === $overlap ) {
			$owner = $this->overlap_guard->owner_run_id( $identity, $args_hash );
			if ( $owner->is_failure() ) {
				return new Failure( new EngineError( \sprintf( '%1$s "%2$s" could not confirm the owner of a contended overlap lock; repair database reads and retry the dispatch.', $kind, $identity ), reason: EngineErrorReason::StorageFailure, context: array( 'identity' => $identity ), ) );
			}
			$running_run_id = $owner->value;
			if ( null === $running_run_id ) {
				return new Failure( new EngineError( \sprintf( '%1$s "%2$s" could not confirm the owner of a contended overlap lock; retry the dispatch against the current lock state.', $kind, $identity ), reason: EngineErrorReason::OverlapHeld, context: array( 'identity' => $identity ), ) );
			}

			return new Success( new SkippedJobDispatch( $running_run_id, EngineError::held( $kind, $identity, $running_run_id ) ) );
		}
		if ( LockClaimOutcome::NotClaimed === $claim && OverlapPolicy::Allow === $overlap ) {
			return new Failure( new EngineError( \sprintf( '%1$s "%2$s" generated a duplicate per-run overlap identity for run "%3$s"; retry so the run receives a fresh identifier.', $kind, $identity, $run_id ), reason: EngineErrorReason::OverlapHeld, context: array( 'identity' => $identity ), ) );
		}

		$run_store = $this->stores->run_store( $identity );
		$state     = $this->create_run_state_and_replace_if_not_claimed( $handler, $identity, $run_id, $args, $args_hash, $claim, $run_store, $scheduled_at, $delay, $priority );
		if ( $state instanceof Failure ) {
			return $state;
		}

		if ( 0 < $delay ) {
			$heartbeat_error = match ( $this->overlap_guard->heartbeat( $identity, $args_hash, $run_id, $scheduled_at ) ) {
				HeartbeatOutcome::Owned => null,
				HeartbeatOutcome::Lost, HeartbeatOutcome::GenerationMismatch => new EngineError( \sprintf( '%1$s "%2$s" lost lock ownership while preparing its delayed action; dispatch it again against the current lock state.', $kind, $identity ), reason: EngineErrorReason::OverlapHeld, context: array( 'identity' => $identity ), ),
				HeartbeatOutcome::Indeterminate => new EngineError( \sprintf( '%1$s "%2$s" could not confirm lock ownership while preparing its delayed action; dispatch it again after authoritative storage access recovers.', $kind, $identity ), reason: EngineErrorReason::StorageFailure, context: array( 'identity' => $identity ), ),
			};
			if ( null !== $heartbeat_error ) {
				$this->roll_back_admitted_run( $identity, $args_hash, $run_id, $run_store );

				return new Failure( $heartbeat_error );
			}

			$replacement  = $state->with_heartbeat_at( $scheduled_at );
			$transitioned = $run_store->replace_if_state_matches( $run_id, $state, $replacement );
			if ( $transitioned instanceof Failure ) {
				$this->roll_back_admitted_run( $identity, $args_hash, $run_id, $run_store );

				return $transitioned;
			}
			if ( null === $transitioned ) {
				$this->roll_back_admitted_run( $identity, $args_hash, $run_id, $run_store );

				return new Failure(
					new EngineError(
						\sprintf( '%1$s "%2$s" lost its live run state while preparing its delayed action; retry against the current run state.', $kind, $identity ),
						reason: EngineErrorReason::StorageFailure,
						context: array(
							'identity' => $identity,
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
					'identity' => $identity,
					'run_id'   => $run_id,
				)
			);
		}
		$action_args = array( $identity, $run_id, $state->action_sequence );
		$group       = $identity . '|' . $run_id;
		$scheduled   = 0 === $delay
			? $this->scheduler->enqueue_async( ActionDeliveries::DELIVER_HOOK, $action_args, $group, $priority )
			: $this->scheduler->schedule_single( ActionDeliveries::DELIVER_HOOK, $scheduled_at, $action_args, $group, $priority );
		if ( $scheduled->is_failure() ) {
			$this->roll_back_admitted_run( $identity, $args_hash, $run_id, $run_store );

			return $scheduled;
		}

		$on_accepted?->__invoke();
		if ( ! $this->stores->run_history( $identity )->record_started( $run_id, $args_hash ) ) {
			$this->logger->warning(
				'Started run history could not be persisted; inspection data may be incomplete.',
				array(
					'identity' => $identity,
					'run_id'   => $run_id,
				)
			);
		}
		$after_dispatch_error = $handler->after_dispatch( $identity, $run_id, $state, $run_store );
		if ( null !== $after_dispatch_error ) {
			return new Failure( $after_dispatch_error );
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
	 * @param   string                  $identity     Complete owner-qualified work identity.
	 * @param   string                  $run_id       Run identifier.
	 * @param   array<array-key, mixed> $args         Start arguments.
	 * @param   string                  $args_hash    Canonical overlap identity.
	 * @param   LockClaimOutcome        $claim        Initial overlap-lock claim result.
	 * @param   RunStore                $run_store    Active-run store.
	 * @param   int                     $scheduled_at Delivery timestamp.
	 * @param   int                     $delay        Scheduling delay in seconds.
	 * @param   int                     $priority     Scheduler priority.
	 *
	 * @return  RunState|Failure<EngineError>
	 */
	private function create_run_state_and_replace_if_not_claimed( KindHandlerInterface $handler, string $identity, string $run_id, array $args, string $args_hash, LockClaimOutcome $claim, RunStore $run_store, int $scheduled_at, int $delay, int $priority ): RunState|Failure {
		$kind  = $handler->key();
		$state = $run_store->create( $run_id, $kind, $args, $args_hash, $handler->initial_kind_state( $args ), $handler->initial_pending( $scheduled_at, $delay, $priority ) );
		if ( $state instanceof Failure ) {
			if ( LockClaimOutcome::NotClaimed !== $claim ) {
				$this->overlap_guard->release( $identity, $args_hash, $run_id );
			}

			return $state;
		}
		if ( null === $state ) {
			if ( LockClaimOutcome::NotClaimed !== $claim ) {
				$this->overlap_guard->release( $identity, $args_hash, $run_id );
			}

			return new Failure(
				new EngineError(
					\sprintf( 'Run "%1$s" for %2$s "%3$s" could not be persisted; remove the conflicting run option before retrying.', $run_id, $kind, $identity ),
					reason: EngineErrorReason::StorageFailure,
					context: array(
						'identity' => $identity,
						'run_id'   => $run_id,
						'kind'     => $kind,
					),
				)
			);
		}
		if ( LockClaimOutcome::NotClaimed !== $claim ) {
			return $state;
		}
		if ( $this->overlap_guard->replace( $identity, $args_hash, $run_id ) ) {
			return $state;
		}

		$run_store->delete( $run_id );

		return new Failure(
			new EngineError(
				\sprintf( '%1$s "%2$s" lock ownership changed while the replacement was claiming it; retry the dispatch against the current owner.', $kind, $identity ),
				reason: EngineErrorReason::OverlapHeld,
				context: array(
					'identity' => $identity,
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
	 * @param   string $identity Complete owner-qualified work identity.
	 * @param   string $run_id   Retained run identifier.
	 *
	 * @return  Failure<EngineError>
	 */
	private function cancel_not_retained( string $identity, string $run_id ): Failure {
		return new Failure(
			new EngineError(
				\sprintf( 'Run "%1$s" for background-work "%2$s" is not retained; nothing remains to cancel.', $run_id, $identity ),
				reason: EngineErrorReason::RunNotRetained,
				context: array(
					'identity' => $identity,
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
	 * Clears every pending action in one scheduler group.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $group Backend grouping label.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	private function unschedule_group( string $group ): AbstractResult {
		$scheduler = $this->scheduler instanceof SchedulerFacade ? $this->scheduler : new SchedulerFacade( array( $this->scheduler ) );

		return $scheduler->unschedule_group( $group );
	}

	/**
	 * Rolls back an admitted run's lock and row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $identity  Complete owner-qualified work identity.
	 * @param   string   $args_hash Canonical overlap identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  void
	 */
	private function roll_back_admitted_run( string $identity, string $args_hash, string $run_id, RunStore $run_store ): void {
		$lock_release_confirmed = $this->overlap_guard->release( $identity, $args_hash, $run_id );
		$run_deleted            = $run_store->delete( $run_id );
		if ( ! $lock_release_confirmed || ! $run_deleted ) {
			$this->logger->warning(
				'Scheduling rollback could not confirm complete cleanup; the run row may be redelivered by maintenance. Repair storage reads and writes before retrying.',
				array(
					'identity'               => $identity,
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

	/**
	 * Returns the SHA-256 identity of insertion-ordered portable JSON.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $kind     Persisted kind key.
	 * @param   string                  $identity Complete owner-qualified work identity.
	 * @param   array<array-key, mixed> $args     Work arguments.
	 *
	 * @return  string|Failure<EngineError>
	 */
	private function args_hash( string $kind, string $identity, array $args ): string|Failure {
		$exception_class = null;
		try {
			$hash = PortableArguments::hash( $args );
		} catch ( \JsonException $exception ) {
			$exception_class = \get_debug_type( $exception );
			$hash            = null;
		}
		if ( null === $hash ) {
			return new Failure(
				new EngineError(
					\sprintf( '%1$s "%2$s" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.', $kind, $identity ),
					$exception_class,
					reason: EngineErrorReason::PayloadRejected,
					context: array(
						'identity' => $identity,
						'kind'     => $kind,
					),
				)
			);
		}

		return $hash;
	}

	/**
	 * Resolves an optional custom overlap identity from registered policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   JobOptions              $options Registered policy declaration.
	 * @param   array<array-key, mixed> $args    Work arguments.
	 *
	 * @return  string|null
	 */
	private function custom_overlap_key( JobOptions $options, array $args ): ?string {
		return null === $options->overlap_key ? null : ( $options->overlap_key )( $args );
	}

	/**
	 * Returns the canonical argument hash or the tagged hash of an opaque overlap key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $kind        Persisted kind key.
	 * @param   string                  $identity    Complete owner-qualified work identity.
	 * @param   array<array-key, mixed> $args        Work arguments.
	 * @param   string|null             $overlap_key Custom overlap identity, or null.
	 *
	 * @return  string|Failure<EngineError>
	 */
	private function overlap_args_hash( string $kind, string $identity, array $args, ?string $overlap_key ): string|Failure {
		$args_hash = $this->args_hash( $kind, $identity, $args );
		if ( $args_hash instanceof Failure || null === $overlap_key ) {
			return $args_hash;
		}
		if ( '' === $overlap_key || self::MAX_OVERLAP_KEY_BYTES < \strlen( $overlap_key ) ) {
			return new Failure( new EngineError( \sprintf( '%1$s "%2$s" overlap key must contain 1 to %3$d bytes when provided.', $kind, $identity, self::MAX_OVERLAP_KEY_BYTES ), reason: EngineErrorReason::PayloadRejected, context: array( 'identity' => $identity ), ) );
		}

		return \hash( 'sha256', 'dedup:' . $overlap_key );
	}

	// endregion
}

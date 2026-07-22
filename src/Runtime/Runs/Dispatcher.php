<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobInterface;
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
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\ChunkedJobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\JobKindHandler;
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
	 * @param   JobRegistry         $work                 Registered work contracts.
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
		private JobRegistry $work,
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
	 * Creates and schedules one run for a registered job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $job_name Complete owner-qualified job identity.
	 * @param   array<array-key, mixed> $args      Job arguments.
	 * @param   int                     $delay     Scheduling delay in seconds.
	 * @param   int                     $priority  Scheduler priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
	public function enqueue( string $job_name, array $args = array(), int $delay = 0, int $priority = 10 ): AbstractResult {
		$handler  = $this->handler( JobKindHandler::KIND );
		$contract = $handler->contract( $job_name );
		if ( null === $contract ) {
			return new Failure( new EngineError( \sprintf( '%1$s "%2$s" is not registered; register it before enqueueing.', $handler->key(), $job_name ), reason: EngineErrorReason::UnknownWork, context: array( 'name' => $job_name ), ) );
		}

		return $this->imperative_result( $this->dispatch_resolved( $handler, $contract, $job_name, $args, $delay, $priority, $contract->overlap_policy() ) );
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
		$kind     = $this->work->kind( $identity ) ?? JobKindHandler::KIND;
		$handler  = $this->handler( $kind );
		$contract = $handler->contract( $identity );
		if ( null === $contract ) {
			return new Failure( new EngineError( \sprintf( '%1$s "%2$s" is not registered; register it before dispatching.', $handler->key(), $identity ), reason: EngineErrorReason::UnknownWork, context: array( 'name' => $identity ), ) );
		}

		return $this->dispatch_resolved( $handler, $contract, $identity, $args, 0, $priority, $contract->overlap_policy(), $on_accepted );
	}

	/**
	 * Creates and schedules one run for a registered chunked job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $chunked_job_name Complete owner-qualified chunked-job identity.
	 * @param   array<array-key, mixed> $start_args      Arguments supplied when the run starts.
	 * @param   int                     $priority        Scheduler priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a chunked-job-start failure must be handled, not dropped' )]
	public function start( string $chunked_job_name, array $start_args = array(), int $priority = 10 ): AbstractResult {
		$handler  = $this->handler( ChunkedJobKindHandler::KIND );
		$contract = $handler->contract( $chunked_job_name );
		if ( null === $contract ) {
			return new Failure( new EngineError( \sprintf( '%1$s "%2$s" is not registered; register it before starting it.', $handler->key(), $chunked_job_name ), reason: EngineErrorReason::UnknownWork, context: array( 'name' => $chunked_job_name ), ) );
		}

		return $this->imperative_result( $this->dispatch_resolved( $handler, $contract, $chunked_job_name, $start_args, 0, $priority, $contract->overlap_policy() ) );
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

		$kind     = $this->work->kind( $identity );
		$contract = $this->work->contract( $identity );
		if ( null === $kind || null === $contract ) {
			return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register the matching job or chunked job before retrying its failed run.', $identity ), reason: EngineErrorReason::UnknownWork, context: array( 'name' => $identity ), ) );
		}
		$handler = $this->handler( $kind );

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
						'name'   => $identity,
						'run_id' => $run_id,
					),
				)
			);
		}

		$args_hash = $this->overlap_args_hash( $handler->key(), $identity, $entry['start_args'], $contract->overlap_key( $entry['start_args'] ) );
		if ( $args_hash instanceof Failure ) {
			return $args_hash;
		}
		$retry_overlap = OverlapPolicy::Allow === $contract->overlap_policy() ? OverlapPolicy::Allow : OverlapPolicy::Reject;
		$result        = $this->imperative_result( $this->dispatch_resolved( $handler, $contract, $identity, $entry['start_args'], 0, 10, $retry_overlap, resolved_args_hash: $args_hash ) );
		if ( $result->is_success() && ! $failed_store->remove( $run_id ) ) {
			$this->logger->warning(
				\sprintf( 'Retried run "%s" could not be removed from retained failed-run data.', $run_id ),
				array(
					'name'   => $identity,
					'run_id' => $run_id,
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

		$kind = $this->work->kind( $identity );
		if ( null === $kind || null === $this->work->contract( $identity ) ) {
			return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register the matching job or chunked job before cancelling its run.', $identity ), reason: EngineErrorReason::UnknownWork, context: array( 'name' => $identity ), ) );
		}
		$handler = $this->handler( $kind );

		$run_store = $this->stores->run_store( $identity );
		$inspected = $run_store->inspect( $run_id );
		if ( $inspected->is_failure() ) {
			return new Failure(
				new EngineError(
					\sprintf( 'Run "%1$s" for background-work "%2$s" could not be read; retry the cancel once option reads succeed.', $run_id, $identity ),
					reason: EngineErrorReason::StorageFailure,
					context: array(
						'name'   => $identity,
						'run_id' => $run_id,
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
	 * Creates and schedules one resolved contract run under an explicit overlap policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   KindHandlerInterface    $handler            Resolved kind handler.
	 * @param   JobInterface            $contract           Registered work contract.
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
	private function dispatch_resolved( KindHandlerInterface $handler, JobInterface $contract, string $identity, array $args, int $delay, int $priority, OverlapPolicy $overlap, ?\Closure $on_accepted = null, ?string $resolved_args_hash = null ): AbstractResult {
		$kind = $handler->key();
		if ( 0 > $priority || self::MAX_PRIORITY < $priority ) {
			return new Failure(
				new EngineError(
					\sprintf( '%1$s "%2$s" priority %3$d is invalid; pass a value from 0 through %4$d.', $kind, $identity, $priority, self::MAX_PRIORITY ),
					reason: EngineErrorReason::PayloadRejected,
					context: array(
						'name'     => $identity,
						'priority' => $priority,
					),
				)
			);
		}

		$args_hash = $resolved_args_hash ?? $this->overlap_args_hash( $kind, $identity, $args, $contract->overlap_key( $args ) );
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
						'delay' => $delay,
						'name'  => $identity,
					),
				)
			);
		}
		$scheduled_at = $now + $delay;
		$run_id       = RunIdentity::generate( $now, $this->randomizer );
		if ( OverlapPolicy::Allow === $overlap ) {
			// Allow gets a per-run lock identity so concurrent occurrences never contend; Held can then only mean run-id collision.
			$args_hash = $this->salted_args_hash( $args_hash, $run_id );
		}

		$latest_pointer = $this->stores->latest_run_pointer( $identity );
		$claim          = $this->overlap_guard->claim( $identity, $args_hash, $run_id, $this->lock_windows->lock_staleness( $identity, $run_id ) );
		if ( LockClaimOutcome::Held === $claim && OverlapPolicy::Reject === $overlap ) {
			$owner = $this->overlap_guard->owner_run_id( $identity, $args_hash );
			if ( $owner->is_failure() ) {
				return new Failure( new EngineError( \sprintf( '%1$s "%2$s" could not confirm the owner of a contended overlap lock; repair database reads and retry the %3$s.', $kind, $identity, $handler->dispatch_verb() ), reason: EngineErrorReason::StorageFailure, context: array( 'name' => $identity ), ) );
			}
			$running_run_id = $owner->value;
			if ( null === $running_run_id ) {
				return new Failure( new EngineError( \sprintf( '%1$s "%2$s" could not confirm the owner of a contended overlap lock; retry the %3$s against the current lock state.', $kind, $identity, $handler->dispatch_verb() ), reason: EngineErrorReason::OverlapHeld, context: array( 'name' => $identity ), ) );
			}

			return new Success( new SkippedJobDispatch( $running_run_id, EngineError::held( $kind, $identity, $running_run_id, $handler->dispatch_verb() ) ) );
		}
		if ( LockClaimOutcome::Held === $claim && OverlapPolicy::Allow === $overlap ) {
			return new Failure( new EngineError( \sprintf( '%1$s "%2$s" generated a duplicate per-run overlap identity for run "%3$s"; retry so the run receives a fresh identifier.', $kind, $identity, $run_id ), reason: EngineErrorReason::OverlapHeld, context: array( 'name' => $identity ), ) );
		}

		$run_store = $this->stores->run_store( $identity );
		$state     = $this->create_run_state_and_replace_if_held( $handler, $identity, $run_id, $args, $args_hash, $claim, $run_store, $scheduled_at, $delay, $priority );
		if ( $state instanceof Failure ) {
			return $state;
		}

		if ( 0 < $delay ) {
			$heartbeat_error = match ( $this->overlap_guard->heartbeat( $identity, $args_hash, $run_id, $scheduled_at ) ) {
				HeartbeatOutcome::Owned => null,
				HeartbeatOutcome::Lost, HeartbeatOutcome::GenerationMismatch => new EngineError( \sprintf( '%1$s "%2$s" lost lock ownership while preparing its delayed action; dispatch it again against the current lock state.', $kind, $identity ), reason: EngineErrorReason::OverlapHeld, context: array( 'name' => $identity ), ),
				HeartbeatOutcome::Indeterminate => new EngineError( \sprintf( '%1$s "%2$s" could not confirm lock ownership while preparing its delayed action; dispatch it again after authoritative storage access recovers.', $kind, $identity ), reason: EngineErrorReason::StorageFailure, context: array( 'name' => $identity ), ),
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
							'name'   => $identity,
							'run_id' => $run_id,
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
					'name'   => $identity,
					'run_id' => $run_id,
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
					'name'   => $identity,
					'run_id' => $run_id,
				)
			);
		}
		$after_dispatch_error = $handler->after_dispatch( $contract, $identity, $run_id, $state, $run_store );
		if ( null !== $after_dispatch_error ) {
			return new Failure( $after_dispatch_error );
		}

		return new Success( $run_id );
	}

	/**
	 * Persists provisional run state and transfers a held lock before returning ownership.
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
	private function create_run_state_and_replace_if_held( KindHandlerInterface $handler, string $identity, string $run_id, array $args, string $args_hash, LockClaimOutcome $claim, RunStore $run_store, int $scheduled_at, int $delay, int $priority ): RunState|Failure {
		$kind  = $handler->key();
		$state = $run_store->create( $run_id, $kind, $args, $args_hash, $handler->initial_kind_state( $args ), $handler->initial_pending( $scheduled_at, $delay, $priority ) );
		if ( $state instanceof Failure ) {
			if ( LockClaimOutcome::Held !== $claim ) {
				$this->overlap_guard->release( $identity, $args_hash, $run_id );
			}

			return $state;
		}
		if ( null === $state ) {
			if ( LockClaimOutcome::Held !== $claim ) {
				$this->overlap_guard->release( $identity, $args_hash, $run_id );
			}

			return new Failure(
				new EngineError(
					\sprintf( 'Run "%1$s" for %2$s "%3$s" could not be persisted; remove the conflicting run option before retrying.', $run_id, $kind, $identity ),
					reason: EngineErrorReason::StorageFailure,
					context: array(
						'name'   => $identity,
						'run_id' => $run_id,
						'kind'   => $kind,
					),
				)
			);
		}
		if ( LockClaimOutcome::Held !== $claim ) {
			return $state;
		}
		if ( $this->overlap_guard->replace( $identity, $args_hash, $run_id ) ) {
			return $state;
		}

		$run_store->delete( $run_id );

		return new Failure(
			new EngineError(
				\sprintf( '%1$s "%2$s" lock ownership changed while the replacement was claiming it; retry the %3$s against the current owner.', $kind, $identity, $handler->dispatch_verb() ),
				reason: EngineErrorReason::OverlapHeld,
				context: array(
					'name' => $identity,
					'kind' => $kind,
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
					'name'   => $identity,
					'run_id' => $run_id,
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
					'name'                   => $identity,
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
						'name' => $identity,
						'kind' => $kind,
					),
				)
			);
		}

		return $hash;
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
	 * @param   string|null             $overlap_key Contract-provided overlap identity, or null.
	 *
	 * @return  string|Failure<EngineError>
	 */
	private function overlap_args_hash( string $kind, string $identity, array $args, ?string $overlap_key ): string|Failure {
		$args_hash = $this->args_hash( $kind, $identity, $args );
		if ( $args_hash instanceof Failure || null === $overlap_key ) {
			return $args_hash;
		}
		if ( '' === $overlap_key || JobInterface::MAX_OVERLAP_KEY_BYTES < \strlen( $overlap_key ) ) {
			return new Failure( new EngineError( \sprintf( '%1$s "%2$s" overlap key must contain 1 to %3$d bytes when provided.', $kind, $identity, JobInterface::MAX_OVERLAP_KEY_BYTES ), reason: EngineErrorReason::PayloadRejected, context: array( 'name' => $identity ), ) );
		}

		return \hash( 'sha256', 'dedup:' . $overlap_key );
	}

	// endregion
}

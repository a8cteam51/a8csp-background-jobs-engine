<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ExistingRunPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\EngineErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\HeartbeatOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\LockClaimOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\RandomizerInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Backends\BackendInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\StoreFactory;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Admits registered job and chunked job runs to the scheduling backend.
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
	 * @param   JobRegistry         $work                 Registered job and chunked job instances.
	 * @param   BackendInterface    $scheduler            Scheduling facade boundary.
	 * @param   OverlapGuard        $overlap_guard        Execution-overlap guard.
	 * @param   StoreFactory        $stores               Name-bound store factory.
	 * @param   ClockInterface      $clock                Timestamp source.
	 * @param   RandomizerInterface $randomizer           Run identifier randomness.
	 * @param   LoggerInterface     $logger               Log event sink.
	 * @param   LockWindows         $lock_windows         Filterable run-lock timing policy.
	 * @param   RunTransitions      $terminal_transitions Fenced terminal-write coordinator.
	 * @param   LifecycleEffects    $terminal_effects     Client lifecycle-effect executor.
	 */
	public function __construct(
		private JobRegistry $work,
		private BackendInterface $scheduler,
		private OverlapGuard $overlap_guard,
		private StoreFactory $stores,
		private ClockInterface $clock,
		private RandomizerInterface $randomizer,
		private LoggerInterface $logger,
		private LockWindows $lock_windows,
		private RunTransitions $terminal_transitions,
		private LifecycleEffects $terminal_effects,
	) {}

	// endregion

	// region METHODS

	/**
	 * Creates and schedules one run for a registered job.
	 *
	 * A non-null deduplication key replaces the argument-derived single-flight identity. The key
	 * refuses another admission only for the incumbent run's lifetime and is reusable after that
	 * run reaches terminal cleanup; it is an admission-level mechanism, not a durable ledger.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $job_name Complete owner-qualified job identity.
	 * @param   array<array-key, mixed> $args      Job arguments.
	 * @param   int                     $delay     Scheduling delay in seconds.
	 * @param   string|null             $dedup_key Client deduplication key whose hash replaces the argument hash.
	 * @param   int                     $priority  Advisory priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
	public function enqueue( string $job_name, array $args = array(), int $delay = 0, ?string $dedup_key = null, int $priority = 10 ): AbstractResult {
		$result = $this->dispatch_job( $job_name, $args, $delay, $dedup_key, $priority, OverlapPolicy::Skip );
		if ( $result->is_failure() ) {
			return $result;
		}

		$value = $result->value;

		return $value instanceof SkippedJobDispatch
			? new Failure( $value->error )
			: new Success( $value );
	}

	/**
	 * Dispatches a job under the schedule overlap policy without expanding the client job API.
	 *
	 * Allow uses a per-run fencing identity, Skip returns a typed held outcome, and Replace transfers
	 * the shared-identity lock through the same takeover helper as chunked job start. Job callbacks always
	 * receive the original arguments. Manual retry of an Allow run intentionally re-enters the public
	 * unsalted enqueue path because the failed store retains only those original arguments.
	 *
	 * @internal Schedule execution only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $job_name   Complete owner-qualified job identity.
	 * @param   array<array-key, mixed> $args        Job arguments.
	 * @param   OverlapPolicy           $overlap     Schedule overlap policy.
	 * @param   int                     $priority    Advisory priority from 0 through 255.
	 * @param   \Closure|null           $on_accepted Internal callback after backend acceptance and before started hooks.
	 *
	 * @return  AbstractResult<string|SkippedJobDispatch, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a scheduled-job dispatch failure must be handled, not dropped' )]
	public function dispatch_scheduled_job( string $job_name, array $args, OverlapPolicy $overlap, int $priority = 10, ?\Closure $on_accepted = null ): AbstractResult {
		return $this->dispatch_job( $job_name, $args, 0, null, priority: $priority, overlap: $overlap, on_accepted: $on_accepted );
	}

	/**
	 * Creates and schedules one run for a registered chunked job.
	 *
	 * Replace takes over a fresh matching incumbent's lock, and the incumbent stops at its next
	 * fence. Reject refuses admission while that lock is held. A crash between takeover and
	 * enqueueing converges through the staleness-reclaim model.
	 *
	 * A scheduling failure after replacement ownership transfers leaves the incumbent fenced; a
	 * caller handles the returned failure by starting the chunked job again.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $chunked_job_name Complete owner-qualified chunked job identity.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   ExistingRunPolicy       $existing   Behavior when a fresh matching incumbent holds the lock.
	 * @param   int                     $priority   Advisory priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a chunked-job-start failure must be handled, not dropped' )]
	public function start_chunked_job( string $chunked_job_name, array $start_args = array(), ExistingRunPolicy $existing = ExistingRunPolicy::Replace, int $priority = 10 ): AbstractResult {
		$chunked_job = $this->work->chunked_job( $chunked_job_name );

		if ( null === $chunked_job ) {
			return new Failure( new EngineError( \sprintf( 'Chunked Job "%s" is not registered; register it before starting it.', $chunked_job_name ), reason: EngineErrorReason::UnknownWork, context: array( 'name' => $chunked_job_name ), ) );
		}

		if ( 0 > $priority || self::MAX_PRIORITY < $priority ) {
			return new Failure(
				new EngineError(
					\sprintf( 'Chunked Job "%1$s" priority %2$d is invalid; pass a value from 0 through %3$d.', $chunked_job_name, $priority, self::MAX_PRIORITY ),
					reason: EngineErrorReason::PayloadRejected,
					context: array(
						'name'     => $chunked_job_name,
						'priority' => $priority,
					),
				)
			);
		}

		$args_hash = $this->args_hash( $chunked_job_name, $start_args, JobType::ChunkedJob );
		if ( $args_hash instanceof Failure ) {
			return $args_hash;
		}

		$now            = $this->clock->now()->getTimestamp();
		$run_id         = RunIdentity::generate( $now, $this->randomizer );
		$latest_pointer = $this->stores->latest_run_pointer( $chunked_job_name );
		$claim          = $this->overlap_guard->claim( $chunked_job_name, $args_hash, $run_id, $this->lock_windows->lock_staleness( $chunked_job_name, $run_id ) );
		if ( LockClaimOutcome::Held === $claim && ExistingRunPolicy::Reject === $existing ) {
			$owner = $this->overlap_guard->owner_run_id( $chunked_job_name, $args_hash );
			if ( $owner->is_failure() ) {
				return new Failure( new EngineError( \sprintf( 'Chunked Job "%s" encountered a held lock whose current owner could not be read; repair database reads and retry the start.', $chunked_job_name ), reason: EngineErrorReason::StorageFailure, context: array( 'name' => $chunked_job_name ), ) );
			}

			$message = null === $owner->value
				? \sprintf( 'Chunked Job "%s" is contended by an overlap lock that no longer names an owner; retry the start against the current lock state.', $chunked_job_name )
				: \sprintf( 'Chunked Job "%1$s" is already running as run "%2$s"; wait for that run to finish before starting the same arguments.', $chunked_job_name, $owner->value );
			$context = null === $owner->value
				? array( 'name' => $chunked_job_name )
				: array( 'run_id' => $owner->value );

			return new Failure( new EngineError( $message, reason: EngineErrorReason::OverlapHeld, context: $context, ) );
		}

		$run_store = $this->stores->run_store( $chunked_job_name );
		$state     = $this->create_run_state_and_replace_if_held( JobType::ChunkedJob, $chunked_job_name, $run_id, $start_args, $args_hash, array(), $claim, $run_store, PendingAction::async( 'start', $priority ) );
		if ( $state instanceof Failure ) {
			return $state;
		}

		if ( ! $latest_pointer->record( $run_id, $args_hash ) ) {
			$this->logger->warning(
				'Latest-run pointer persistence failed; discovery metadata may lag until a later repair.',
				array(
					'chunked_job_name' => $chunked_job_name,
					'run_id'           => $run_id,
				)
			);
		}
		$scheduled = $this->scheduler->enqueue_async( 'a8csp_jobs_engine/start_chunked_job', array( $chunked_job_name, $run_id, $state->action_sequence ), $chunked_job_name . '|' . $run_id, $priority );
		if ( $scheduled->is_failure() ) {
			$this->roll_back_admitted_run( $chunked_job_name, $args_hash, $run_id, $run_store );

			return $scheduled;
		}

		if ( ! $this->stores->run_history( $chunked_job_name )->record_started( $run_id, $args_hash ) ) {
			$this->logger->warning(
				'Started run history could not be persisted; inspection data may be incomplete.',
				array(
					'chunked_job_name' => $chunked_job_name,
					'run_id'           => $run_id,
				)
			);
		}

		return new Success( $run_id );
	}

	/**
	 * Starts a fresh run from one retained failed run's original arguments.
	 *
	 * A retried run does not re-acquire its original deduplication key or existing-run policy. Job
	 * and Chunked Job retries are re-admitted under their argument identity and refuse a matching live run,
	 * so a retry does not collapse against a concurrent enqueue carrying the failed run's key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 * @param   string $run_id   Retained failed-run identifier.
	 *
	 * @throws  \InvalidArgumentException When the run identifier is malformed.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError> Success carries the new run identifier after
	 *          re-enqueueing; it does not report whether the work ran or succeeded.
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed( string $identity, string $run_id ): AbstractResult {
		if ( null === RunIdentity::parse( $run_id ) ) {
			throw new \InvalidArgumentException( 'Run identifier is malformed; pass a run ID the engine returned.' );
		}

		$job         = $this->work->job( $identity );
		$chunked_job = $this->work->chunked_job( $identity );

		if ( null === $job && null === $chunked_job ) {
			return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register the matching job or chunked job before retrying its failed run.', $identity ), reason: EngineErrorReason::UnknownWork, context: array( 'name' => $identity ), ) );
		}

		$failed_store = $this->stores->failed_run_store( $identity );
		$read         = $failed_store->all();
		if ( $read->is_failure() ) {
			return $read;
		}

		$entries = $read->value;
		$entry   = \array_find( $entries, static fn ( array $candidate ): bool => $run_id === $candidate['run_id'] );

		if ( null === $entry ) {
			$retained_run_ids = \array_column( $entries, 'run_id' );
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

		$result = null !== $job
			? $this->enqueue( $identity, $entry['start_args'] )
			: $this->start_chunked_job( $identity, $entry['start_args'], ExistingRunPolicy::Reject );
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
	 * Cancels one retained run that is not executing or pending chunked job cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
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

		$job         = $this->work->job( $identity );
		$chunked_job = $this->work->chunked_job( $identity );

		if ( null === $job && null === $chunked_job ) {
			return new Failure( new EngineError( \sprintf( 'Background-work "%s" is not registered; register the matching job or chunked job before cancelling its run.', $identity ), reason: EngineErrorReason::UnknownWork, context: array( 'name' => $identity ), ) );
		}

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

		if ( null !== $chunked_job && array() === $state->queue && 1 < $state->action_sequence ) {
			return new Failure( new EngineError( \sprintf( 'Run "%s" has no chunks left to process; the pending cleanup completes it.', $run_id ), reason: EngineErrorReason::RunNotCancellable, context: array( 'run_id' => $run_id ), ) );
		}

		$cancelled = $this->terminal_transitions->cancel_run( null !== $chunked_job ? JobType::ChunkedJob : JobType::Job, $identity, $run_id, $state, $run_store, $snapshot['raw'], fn () => $this->unschedule_group( $identity . '|' . $run_id ) );
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
	 * Returns the corrective failure for an absent or corrupt retained run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 * @param   string $run_id   Run identifier.
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
	 * Returns the corrective failure for a run whose admitted delivery is still executing.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @return  Failure<EngineError>
	 */
	private function cancel_executing( string $run_id ): Failure {
		return new Failure( new EngineError( \sprintf( 'Run "%s" is executing; a run in flight completes or fails on its own.', $run_id ), reason: EngineErrorReason::RunNotCancellable, context: array( 'run_id' => $run_id ), ) );
	}

	/**
	 * Clears every pending action in one scheduler group through the facade's group-only form.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $group Per-run scheduler group.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	private function unschedule_group( string $group ): AbstractResult {
		$scheduler = $this->scheduler instanceof SchedulerFacade
			? $this->scheduler
			: new SchedulerFacade( array( $this->scheduler ) );

		return $scheduler->unschedule_group( $group );
	}

	/**
	 * Creates and schedules one job run under a resolved overlap policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $job_name   Complete owner-qualified job identity.
	 * @param   array<array-key, mixed> $args        Job arguments.
	 * @param   int                     $delay       Scheduling delay in seconds.
	 * @param   string|null             $dedup_key   Client deduplication key whose hash replaces the argument hash.
	 * @param   int                     $priority    Advisory priority from 0 through 255.
	 * @param   OverlapPolicy           $overlap     Execution-overlap policy.
	 * @param   \Closure|null           $on_accepted Internal callback after backend acceptance and before started hooks.
	 *
	 * @return  AbstractResult<string|SkippedJobDispatch, EngineError|SchedulingError>
	 */
	private function dispatch_job( string $job_name, array $args, int $delay, ?string $dedup_key, int $priority, OverlapPolicy $overlap, ?\Closure $on_accepted = null ): AbstractResult {
		$job = $this->work->job( $job_name );

		if ( null === $job ) {
			return new Failure( new EngineError( \sprintf( 'Job "%s" is not registered; register it before enqueueing.', $job_name ), reason: EngineErrorReason::UnknownWork, context: array( 'name' => $job_name ), ) );
		}

		if ( 0 > $priority || self::MAX_PRIORITY < $priority ) {
			return new Failure(
				new EngineError(
					\sprintf( 'Job "%1$s" priority %2$d is invalid; pass a value from 0 through %3$d.', $job_name, $priority, self::MAX_PRIORITY ),
					reason: EngineErrorReason::PayloadRejected,
					context: array(
						'name'     => $job_name,
						'priority' => $priority,
					),
				)
			);
		}

		$args_hash = $this->args_hash( $job_name, $args );
		if ( $args_hash instanceof Failure ) {
			return $args_hash;
		}
		if ( null !== $dedup_key ) {
			// The dedup tag separates opaque keys from canonical JSON argument identities, whose encodings never start with "d".
			$args_hash = \hash( 'sha256', 'dedup:' . $dedup_key );
		}

		$now = $this->clock->now()->getTimestamp();
		if ( 0 < $delay && $delay > \PHP_INT_MAX - $now ) {
			return new Failure(
				new EngineError(
					\sprintf( 'Job "%1$s" delay %2$d exceeds supported Unix seconds; pass a smaller delay.', $job_name, $delay ),
					reason: EngineErrorReason::PayloadRejected,
					context: array(
						'delay' => $delay,
						'name'  => $job_name,
					),
				)
			);
		}
		$scheduled_at = $now + $delay;

		$run_id = RunIdentity::generate( $now, $this->randomizer );
		if ( OverlapPolicy::Allow === $overlap ) {
			// Allow gets a per-run lock identity so concurrent occurrences never contend; Held can then only mean run-id collision.
			$args_hash = \hash( 'sha256', $args_hash . '|' . $run_id );
		}

		$latest_pointer = $this->stores->latest_run_pointer( $job_name );
		$claim          = $this->overlap_guard->claim( $job_name, $args_hash, $run_id, $this->lock_windows->lock_staleness( $job_name, $run_id ) );
		if ( LockClaimOutcome::Held === $claim && OverlapPolicy::Skip === $overlap ) {
			$owner = $this->overlap_guard->owner_run_id( $job_name, $args_hash );
			if ( $owner->is_failure() ) {
				return new Failure( new EngineError( \sprintf( 'Job "%s" could not confirm the owner of a contended overlap lock; repair database reads and retry the dispatch.', $job_name ), reason: EngineErrorReason::StorageFailure, context: array( 'name' => $job_name ), ) );
			}

			$running_run_id = $owner->value;
			if ( null === $running_run_id ) {
				return new Failure( new EngineError( \sprintf( 'Job "%s" could not confirm the owner of a contended overlap lock; retry the dispatch against the current lock state.', $job_name ), reason: EngineErrorReason::OverlapHeld, context: array( 'name' => $job_name ), ) );
			}

			return new Success( new SkippedJobDispatch( $running_run_id, EngineError::held_job( $job_name, $running_run_id ) ) );
		}

		if ( LockClaimOutcome::Held === $claim && OverlapPolicy::Allow === $overlap ) {
			return new Failure( new EngineError( \sprintf( 'Job "%1$s" generated a duplicate per-run overlap identity for run "%2$s"; retry so the run receives a fresh identifier.', $job_name, $run_id ), reason: EngineErrorReason::OverlapHeld, context: array( 'name' => $job_name ), ) );
		}

		$run_store = $this->stores->run_store( $job_name );
		$state     = $this->create_run_state_and_replace_if_held( JobType::Job, $job_name, $run_id, $args, $args_hash, array( $args ), $claim, $run_store, 0 === $delay ? PendingAction::async( 'run', $priority ) : PendingAction::single( 'run', $scheduled_at, $priority ) );
		if ( $state instanceof Failure ) {
			return $state;
		}

		if ( 0 < $delay ) {
			$heartbeat_error = match ( $this->overlap_guard->heartbeat( $job_name, $args_hash, $run_id, $scheduled_at ) ) {
				HeartbeatOutcome::Owned => null,
				HeartbeatOutcome::Lost, HeartbeatOutcome::GenerationMismatch => new EngineError( \sprintf( 'Job "%s" lost lock ownership while preparing its delayed action; enqueue it again against the current lock state.', $job_name ), reason: EngineErrorReason::OverlapHeld, context: array( 'name' => $job_name ), ),
				HeartbeatOutcome::Indeterminate => new EngineError( \sprintf( 'Job "%s" could not confirm lock ownership while preparing its delayed action; enqueue it again after authoritative storage access recovers.', $job_name ), reason: EngineErrorReason::StorageFailure, context: array( 'name' => $job_name ), ),
			};
			if ( null !== $heartbeat_error ) {
				$this->roll_back_admitted_run( $job_name, $args_hash, $run_id, $run_store );

				return new Failure( $heartbeat_error );
			}

			$replacement = $state->with_heartbeat_at( $scheduled_at );
			if ( null === $run_store->replace_if_state_matches( $run_id, $state, $replacement ) ) {
				$this->roll_back_admitted_run( $job_name, $args_hash, $run_id, $run_store );

				return new Failure(
					new EngineError(
						\sprintf( 'Job "%s" lost its live run state while preparing its delayed action; retry the enqueue against the current run state.', $job_name ),
						reason: EngineErrorReason::StorageFailure,
						context: array(
							'name'   => $job_name,
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
					'job_name' => $job_name,
					'run_id'   => $run_id,
				)
			);
		}
		$action_args = array( $job_name, $run_id, $state->action_sequence );
		$group       = $job_name . '|' . $run_id;
		$scheduled   = 0 === $delay
			? $this->scheduler->enqueue_async( 'a8csp_jobs_engine/run_job', $action_args, $group, $priority )
			: $this->scheduler->schedule_single( 'a8csp_jobs_engine/run_job', $scheduled_at, $action_args, $group, $priority );

		if ( $scheduled->is_failure() ) {
			$this->roll_back_admitted_run( $job_name, $args_hash, $run_id, $run_store );

			return $scheduled;
		}

		$on_accepted?->__invoke();
		if ( ! $this->stores->run_history( $job_name )->record_started( $run_id, $args_hash ) ) {
			$this->logger->warning(
				'Started run history could not be persisted; inspection data may be incomplete.',
				array(
					'job_name' => $job_name,
					'run_id'   => $run_id,
				)
			);
		}
		try {
			$this->terminal_effects->fire_started( $job_name, $run_id, $args );
		} catch ( \Throwable $throwable ) {
			$exception_type = \get_debug_type( $throwable );
			$error          = new EngineError(
				\sprintf( 'Job "%1$s" started listener failed because %2$s was thrown. Fix the started-hook listener before enqueueing the job again.', $job_name, $exception_type ),
				$exception_type,
				reason: EngineErrorReason::ExecutionFailed,
				context: array(
					'name'   => $job_name,
					'run_id' => $run_id,
				),
			);
			$this->terminal_transitions->fail_job( $job_name, $run_id, $state, $run_store, $error, 1, RunFailureStage::Execution, ApiErrorCode::ExecutionFailed );

			return new Failure( $error );
		}

		return new Success( $run_id );
	}

	/**
	 * Persists provisional run state and transfers a held lock before returning ownership.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   JobType                       $work_type Work contract type.
	 * @param   string                        $identity  Complete owner-qualified job or chunked job identity.
	 * @param   string                        $run_id    Replacement run identifier.
	 * @param   array<array-key, mixed>       $args      Start arguments.
	 * @param   string                        $args_hash Stable single-flight identity.
	 * @param   list<array<array-key, mixed>> $queue     Initial run queue.
	 * @param   LockClaimOutcome              $claim     Initial lock-claim outcome.
	 * @param   RunStore                      $run_store Active-run store.
	 * @param   PendingAction                 $pending   Durable successor delivery.
	 *
	 * @return  RunState|Failure<EngineError>
	 */
	private function create_run_state_and_replace_if_held( JobType $work_type, string $identity, string $run_id, array $args, string $args_hash, array $queue, LockClaimOutcome $claim, RunStore $run_store, PendingAction $pending ): RunState|Failure {
		$state = $run_store->create( $run_id, $work_type, $args, $args_hash, $queue, $pending );
		if ( null === $state ) {
			if ( LockClaimOutcome::Held !== $claim ) {
				$this->overlap_guard->release( $identity, $args_hash, $run_id );
			}

			return new Failure(
				new EngineError(
					\sprintf( 'Run "%1$s" for %2$s "%3$s" could not be persisted; remove the conflicting run option before retrying.', $run_id, $work_type->label(), $identity ),
					reason: EngineErrorReason::StorageFailure,
					context: array(
						'name'      => $identity,
						'run_id'    => $run_id,
						'work_type' => $work_type->machine_key(),
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
				\sprintf( '%1$s "%2$s" lock ownership changed while the replacement was claiming it; retry the %3$s against the current owner.', $work_type->value, $identity, JobType::Job === $work_type ? 'dispatch' : 'start' ),
				reason: EngineErrorReason::OverlapHeld,
				context: array(
					'name'      => $identity,
					'work_type' => $work_type->machine_key(),
				),
			)
		);
	}

	/**
	 * Rolls back an admitted run's lock and row, warning when cleanup cannot be confirmed.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $name      Composed work identity.
	 * @param   string   $args_hash Argument identity hash owning the overlap lock.
	 * @param   string   $run_id    Admitted run identifier.
	 * @param   RunStore $run_store Store holding the admitted run row.
	 *
	 * @return  void
	 */
	private function roll_back_admitted_run( string $name, string $args_hash, string $run_id, RunStore $run_store ): void {
		$lock_release_confirmed = $this->overlap_guard->release( $name, $args_hash, $run_id );
		$run_deleted            = $run_store->delete( $run_id );
		if ( ! $lock_release_confirmed || ! $run_deleted ) {
			$this->logger->warning(
				'Scheduling rollback could not confirm complete cleanup; the run row may be redelivered by maintenance. Repair storage reads and writes before retrying.',
				array(
					'name'                   => $name,
					'run_id'                 => $run_id,
					'lock_release_confirmed' => $lock_release_confirmed,
					'run_deleted'            => $run_deleted,
				)
			);
		}
	}

	/**
	 * Returns the SHA-256 identity of insertion-ordered JSON with preserved float fractions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $identity  Complete owner-qualified job or chunked job identity.
	 * @param   array<array-key, mixed> $args      Start arguments.
	 * @param   JobType                 $work_type Work contract type.
	 *
	 * @return  string|Failure<EngineError>
	 */
	#[\NoDiscard( 'an argument-hash failure must be handled, not dropped' )]
	private function args_hash( string $identity, array $args, JobType $work_type = JobType::Job ): string|Failure {
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
					\sprintf( '%1$s "%2$s" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.', $work_type->value, $identity ),
					$exception_class,
					reason: EngineErrorReason::PayloadRejected,
					context: array(
						'name'      => $identity,
						'work_type' => $work_type->machine_key(),
					),
				)
			);
		}

		return $hash;
	}

	// endregion
}

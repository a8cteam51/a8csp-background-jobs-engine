<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\RegistrationUpdateOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\SkippedTaskDispatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkIdentity;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingError;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Delivers schedule occurrences under per-registration decision leases.
 *
 * @internal Engine schedule delivery only.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class OccurrenceDelivery {
	// region FIELDS AND CONSTANTS

	/**
	 * Internal recurring-occurrence delivery hook.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string SCHEDULE_HOOK = 'a8csp_background_tasks/schedule_due';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ScheduleRegistry $registry        Owner-scoped schedule registry.
	 * @param   Dispatcher       $dispatcher      Policy-aware target-task dispatcher.
	 * @param   OccurrenceLease  $lease           Per-registration occurrence decision lease.
	 * @param   CleanupIntents   $cleanup_intents Durable unknown-chain cleanup boundary.
	 * @param   ClockInterface   $clock           Current-time source.
	 * @param   LoggerInterface  $logger          Log event sink.
	 */
	public function __construct(
		private ScheduleRegistry $registry,
		private Dispatcher $dispatcher,
		private OccurrenceLease $lease,
		private CleanupIntents $cleanup_intents,
		private ClockInterface $clock,
		private LoggerInterface $logger,
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers the internal recurring-occurrence delivery action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function register_hooks(): void {
		\add_action( self::SCHEDULE_HOOK, array( $this, 'handle_schedule_due' ), 10, 1 );
	}

	/**
	 * Handles one recurring schedule occurrence.
	 *
	 * Schedule-driven tasks must be idempotent because backend redelivery, crash reclaim, and Replace
	 * takeover retain bounded at-least-once execution windows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @return  void
	 */
	public function handle_schedule_due( string $registration_key ): void {
		$lease_claim = $this->lease->claim( $registration_key );
		if ( OccurrenceLeaseOutcome::Held === $lease_claim->outcome ) {
			$this->logger->debug( 'Schedule occurrence skipped because its decision lease is held by a concurrent delivery.', array( 'registration_key' => $registration_key ) );

			return;
		}
		if ( OccurrenceLeaseOutcome::Indeterminate === $lease_claim->outcome ) {
			$operation = $lease_claim->storage_operation ?? 'storage';
			$message   = 'read' === $operation
				? 'Schedule occurrence could not claim its decision lease because the authoritative read failed; repair WordPress option reads, then retry delivery.'
				: 'Schedule occurrence could not claim its decision lease because the authoritative write failed; repair WordPress option writes, then retry delivery.';
			$this->logger->warning(
				$message,
				array(
					'registration_key'  => $registration_key,
					'storage_operation' => $operation,
				)
			);

			return;
		}

		$lease_handle = $lease_claim->claimed_lease();

		try {
			$this->handle_occurrence( $registration_key, $lease_handle );
		} finally {
			$lease_handle->release();
		}
	}

	/**
	 * Dispatches one manual schedule run while retaining occurrence-lease custody.
	 *
	 * @internal Schedule client API only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key Complete owner-qualified schedule identity.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a schedule dispatch-now failure must be handled, not dropped' )]
	public function dispatch_now_under_lease( string $registration_key ): AbstractResult {
		$parts = WorkIdentity::parts( $registration_key );
		if ( null === $parts ) {
			return new Failure( new EngineError( 'Schedule identity is invalid; pass one canonical {owner}:{name} identity.', reason: EngineErrorReason::PayloadRejected, ) );
		}

		[ $owner, $name ] = $parts;
		$lease_claim      = $this->lease->claim( $registration_key );
		if ( OccurrenceLeaseOutcome::Held === $lease_claim->outcome ) {
			return new Failure(
				new EngineError(
					\sprintf( 'Schedule "%1$s" for owner "%2$s" already has an occurrence decision in flight; retry after that dispatch persists its state.', $name, $owner ),
					reason: EngineErrorReason::OverlapHeld,
					context: array(
						'owner'    => $owner,
						'schedule' => $name,
					),
				)
			);
		}
		if ( OccurrenceLeaseOutcome::Indeterminate === $lease_claim->outcome ) {
			return new Failure(
				new EngineError(
					\sprintf( 'Schedule "%1$s" for owner "%2$s" could not establish its occurrence lease because storage could not be read or written; repair WordPress option reads and writes, then retry.', $name, $owner ),
					reason: EngineErrorReason::StorageFailure,
					context: array(
						'owner'             => $owner,
						'schedule'          => $name,
						'storage_operation' => $lease_claim->storage_operation,
					),
				)
			);
		}

		$lease_handle = $lease_claim->claimed_lease();

		try {
			return $this->dispatch_now( $registration_key, $owner, $name, $lease_handle );
		} finally {
			$lease_handle->release();
		}
	}

	/**
	 * Executes one leased occurrence decision against freshly read registration state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string       $registration_key `{owner}:{name}` schedule identity.
	 * @param   ClaimedLease $lease_handle     Claimed occurrence-lease handle.
	 *
	 * @return  void
	 */
	private function handle_occurrence( string $registration_key, ClaimedLease $lease_handle ): void {
		$registration_read = $this->registry->registration( $registration_key );
		if ( $registration_read->is_failure() ) {
			$this->logger->warning(
				'Schedule occurrence registration could not be read: {error}',
				array(
					'registration_key' => $registration_key,
					'error'            => $registration_read->error->message,
				)
			);

			return;
		}

		$registration = $registration_read->value;
		if ( null === $registration ) {
			$this->cleanup_intents->record_intent( $registration_key );
			$converged = $this->cleanup_intents->converge_unknown_chain( $registration_key );
			$context   = array(
				'registration_key' => $registration_key,
				'converged'        => $converged,
			);

			$this->logger->warning( \sprintf( 'Unknown schedule registration "%s" was delivered; re-declare the schedule or remove the leftover occurrence.', $registration_key ), $context );

			return;
		}

		$declaration = $this->registry->declaration( $registration_key );
		if ( null === $declaration ) {
			$this->logger->debug( 'Schedule registration is inactive in this request; leave its recurring occurrence unchanged.', array( 'registration_key' => $registration_key ) );

			return;
		}

		$schedule = $declaration['schedule'];

		if ( $registration['fingerprint'] !== $schedule->fingerprint() ) {
			$this->logger->debug( 'Stale request schedule declaration does not match the persisted registration; leave the occurrence for a current request.', array( 'registration_key' => $registration_key ) );

			return;
		}

		$parts = WorkIdentity::parts( $registration_key );
		if ( null === $parts ) {
			return;
		}
		$owner = $parts[0];
		$name  = $parts[1];
		$now   = $this->clock->now()->getTimestamp();
		if ( $now < $registration['next_due'] ) {
			$this->logger->debug(
				'Stale schedule occurrence redelivery dropped after its next-due token advanced.',
				array(
					'owner'    => $owner,
					'name'     => $registration_key,
					'next_due' => $registration['next_due'],
					'fired_at' => $now,
				)
			);

			return;
		}

		$interval = $schedule->recurrence->interval();

		/**
		 * Filters the grace window for one schedule occurrence.
		 *
		 * The dynamic portion of the hook name, `$identity`, is the complete owner-qualified
		 * schedule identity.
		 *
		 * @since   1.0.0
		 * @version 1.0.0
		 *
		 * @param   int    $interval         Default grace window in seconds.
		 * @param   string $owner            Stable client identifier.
		 * @param   string $identity         Complete owner-qualified schedule identity.
		 */
		$grace = \apply_filters( 'a8csp_background_tasks/misfire_grace/' . $registration_key, $interval, $owner, $registration_key );
		if ( ! \is_int( $grace ) || 0 > $grace ) {
			$this->logger->warning(
				'Misfire grace filter returned an invalid value; return a non-negative integer to override the recurrence interval.',
				array(
					'owner'         => $owner,
					'name'          => $registration_key,
					'returned_type' => \get_debug_type( $grace ),
					'default_grace' => $interval,
				)
			);
			$grace = $interval;
		}

		$misfired = $registration['next_due'] <= \PHP_INT_MAX - $grace
			&& $now > $registration['next_due'] + $grace;
		$next_due = self::realigned_next_due( $registration['next_due'], $interval, $now );
		if ( null === $next_due ) {
			$this->logger->error(
				'Schedule recurrence cannot advance beyond the current timestamp; correct the system clock or synchronize a smaller interval.',
				array(
					'owner'    => $owner,
					'name'     => $registration_key,
					'next_due' => $registration['next_due'],
					'fired_at' => $now,
				)
			);

			return;
		}

		if ( $misfired && CatchUpPolicy::Skip === $schedule->catch_up ) {
			$misfired_due                  = $registration['next_due'];
			$registration['next_due']      = $next_due;
			$registration['misfire_skips'] = self::increment_counter( $registration['misfire_skips'] );
			$this->persist_delivery_state( $registration_key, $owner, $registration );
			try {
				try {
					/**
					 * Fires when a Skip schedule drops one beyond-grace occurrence.
					 *
					 * The dynamic portion of the hook name, `$identity`, is the complete
					 * owner-qualified schedule identity.
					 *
					 * @since   1.0.0
					 * @version 1.0.0
					 *
					 * @param   string $owner        Stable client identifier.
					 * @param   int    $misfired_due Dropped occurrence due timestamp.
					 * @param   int    $now          Occurrence observation timestamp.
					 */
					\do_action( 'a8csp_background_tasks/misfire_skipped/' . $registration_key, $owner, $misfired_due, $now );
				} finally {
					/**
					 * Fires after the identity-specific misfire-skipped schedule hook.
					 *
					 * @since   1.0.0
					 * @version 1.0.0
					 *
					 * @param   string $identity         Complete owner-qualified schedule identity.
					 * @param   string $owner            Stable client identifier.
					 * @param   int    $misfired_due     Dropped occurrence due timestamp.
					 * @param   int    $now              Occurrence observation timestamp.
					 */
					\do_action( 'a8csp_background_tasks/misfire_skipped', $registration_key, $owner, $misfired_due, $now );
				}
			} catch ( \Throwable $throwable ) {
				$this->logger->error(
					'Misfire-skipped schedule listener failed after the occurrence state was persisted; fix the hook listener.',
					array(
						'owner'     => $owner,
						'name'      => $registration_key,
						'exception' => $throwable,
					)
				);
			}
			$this->logger->info(
				'Misfired schedule occurrence skipped and realigned to its recurrence.',
				array(
					'owner'    => $owner,
					'name'     => $registration_key,
					'next_due' => $next_due,
					'fired_at' => $now,
				)
			);

			return;
		}

		$accepted_registration               = $registration;
		$accepted_registration['next_due']   = $next_due;
		$accepted_registration['last_fired'] = $now;
		$dispatched                          = $this->dispatcher->dispatch_scheduled_task(
			$declaration['task'],
			$schedule->args,
			$schedule->overlap,
			$schedule->priority,
			function () use ( $registration_key, $owner, $accepted_registration, $lease_handle ): void {
				try {
					$this->persist_delivery_state( $registration_key, $owner, $accepted_registration );
				} finally {
					$lease_handle->release();
				}
			}
		);
		if ( $dispatched->is_failure() ) {
			$this->logger->error(
				'Schedule occurrence could not enqueue its target task: {error}',
				array(
					'owner' => $owner,
					'name'  => $registration_key,
					'error' => $dispatched->error->message,
				)
			);

			return;
		}

		$registration['next_due'] = $next_due;
		if ( $dispatched->value instanceof SkippedTaskDispatch ) {
			// RunOnce makes the occurrence up, so it is not recorded as a misfire; `misfire_skips` counts Skip-policy drops, `overlap_skips` counts overlap skips.
			$registration['overlap_skips'] = self::increment_counter( $registration['overlap_skips'] );
			$this->persist_delivery_state( $registration_key, $owner, $registration );
			$this->logger->info(
				'Schedule occurrence skipped because the target task lock is held.',
				array(
					'owner'          => $owner,
					'name'           => $registration_key,
					'running_run_id' => $dispatched->value->running_run_id,
				)
			);

			return;
		}
	}

	/**
	 * Dispatches one manual schedule run against freshly read registration state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string       $registration_key Complete owner-qualified schedule identity.
	 * @param   string       $owner            Stable client identifier.
	 * @param   string       $name             Stable schedule name.
	 * @param   ClaimedLease $lease_handle     Claimed occurrence-lease handle.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	private function dispatch_now( string $registration_key, string $owner, string $name, ClaimedLease $lease_handle ): AbstractResult {
		$registration_read = $this->registry->registration( $registration_key );
		if ( $registration_read->is_failure() ) {
			return new Failure( $registration_read->error );
		}

		$registration = $registration_read->value;
		if ( null === $registration ) {
			return new Failure(
				new EngineError(
					\sprintf( 'Schedule "%1$s" for owner "%2$s" is not synchronized; declare it with sync() before running it now.', $name, $owner ),
					reason: EngineErrorReason::UnknownSchedule,
					context: array(
						'owner'    => $owner,
						'schedule' => $name,
					),
				)
			);
		}

		$declaration = $this->registry->declaration( $registration_key );
		if ( null === $declaration ) {
			return new Failure(
				new EngineError(
					\sprintf( 'Schedule "%1$s" for owner "%2$s" is inactive in this request; synchronize its declaration before running it now.', $name, $owner ),
					reason: EngineErrorReason::UnknownSchedule,
					context: array(
						'owner'    => $owner,
						'schedule' => $name,
					),
				)
			);
		}

		$schedule = $declaration['schedule'];

		if ( $registration['fingerprint'] !== $schedule->fingerprint() ) {
			return new Failure(
				new EngineError(
					\sprintf( 'Schedule "%1$s" for owner "%2$s" changed after this request synchronized; synchronize its current declaration before running it now.', $name, $owner ),
					reason: EngineErrorReason::UnknownSchedule,
					context: array(
						'owner'    => $owner,
						'schedule' => $name,
					),
				)
			);
		}

		$accepted_registration               = $registration;
		$accepted_registration['last_fired'] = $this->clock->now()->getTimestamp();
		$dispatched                          = $this->dispatcher->dispatch_scheduled_task(
			$declaration['task'],
			$schedule->args,
			$schedule->overlap,
			$schedule->priority,
			function () use ( $registration_key, $owner, $accepted_registration, $lease_handle ): void {
				try {
					$this->persist_delivery_state( $registration_key, $owner, $accepted_registration );
				} finally {
					$lease_handle->release();
				}
			}
		);
		if ( $dispatched->is_failure() ) {
			return $dispatched;
		}

		if ( $dispatched->value instanceof SkippedTaskDispatch ) {
			return new Failure( $dispatched->value->error );
		}

		return new Success( $dispatched->value );
	}

	// endregion

	// region HELPERS

	/**
	 * Persists one occurrence transition or logs the retryable registry failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int} $registration
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 * @param   string $owner            Stable client identifier.
	 * @param   array  $registration     Complete registration timing state.
	 *
	 * @return  void
	 */
	private function persist_delivery_state( string $registration_key, string $owner, array $registration ): void {
		$outcome = $this->registry->update_registration( $registration_key, $registration['fingerprint'], $registration );
		if ( RegistrationUpdateOutcome::Updated === $outcome ) {
			return;
		}
		if ( RegistrationUpdateOutcome::Pruned === $outcome ) {
			$this->logger->debug(
				'Schedule registration pruned concurrently; delivery state discarded.',
				array(
					'owner'            => $owner,
					'registration_key' => $registration_key,
				)
			);

			return;
		}
		if ( RegistrationUpdateOutcome::Superseded === $outcome ) {
			$this->logger->debug(
				'Schedule registration superseded concurrently; delivery state discarded.',
				array(
					'owner'            => $owner,
					'registration_key' => $registration_key,
				)
			);

			return;
		}

		$failure = new Failure( SchedulingError::registry_persist_failure( $owner ) );
		$this->logger->error(
			'Schedule occurrence state could not be persisted: {error}',
			array(
				'owner' => $owner,
				'error' => $failure->error->message,
			)
		);
	}

	/**
	 * Advances a due instant by whole intervals until it is strictly in the future.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $next_due Current due instant.
	 * @param   int $interval Positive recurrence interval.
	 * @param   int $now      Occurrence delivery timestamp.
	 *
	 * @return  int|null Future due instant, or null when positive Unix seconds overflow.
	 */
	private static function realigned_next_due( int $next_due, int $interval, int $now ): ?int {
		if ( $next_due > $now ) {
			return $next_due;
		}

		$steps = \intdiv( $now - $next_due, $interval ) + 1;
		if ( $steps > \intdiv( \PHP_INT_MAX - $next_due, $interval ) ) {
			return null;
		}

		return $next_due + $steps * $interval;
	}

	/**
	 * Increments an operational counter without overflowing persisted integer state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $counter Current non-negative count.
	 *
	 * @return  int
	 */
	private static function increment_counter( int $counter ): int {
		return \PHP_INT_MAX === $counter ? $counter : $counter + 1;
	}

	// endregion
}

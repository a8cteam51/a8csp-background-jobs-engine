<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Schedules;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\TaskDispatchSkipped;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
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
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ScheduleRegistry $registry   Owner-scoped schedule registry.
	 * @param   Dispatcher       $dispatcher Policy-aware target-task dispatcher.
	 * @param   OccurrenceLease  $lease      Per-registration occurrence decision lease.
	 * @param   BackendInterface $scheduler  Scheduling backend facade.
	 * @param   ClockInterface   $clock      Current-time source.
	 * @param   LoggerInterface  $logger     Log event sink.
	 */
	public function __construct(
		private ScheduleRegistry $registry,
		private Dispatcher $dispatcher,
		private OccurrenceLease $lease,
		private BackendInterface $scheduler,
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
		\add_action(
			'a8csp/background_tasks/schedule_due',
			array( $this, 'handle_schedule_due' ),
			10,
			2
		);
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
	 * @param   string $delivery         Delivery kind; cleanup singles carry `cleanup`.
	 *
	 * @return  void
	 */
	public function handle_schedule_due( string $registration_key, string $delivery = 'occurrence' ): void {
		if ( 'cleanup' === $delivery ) {
			$this->handle_cleanup_delivery( $registration_key );

			return;
		}

		$lease_raw = $this->lease->claim( $registration_key );
		if ( null === $lease_raw ) {
			$this->logger->debug(
				'Schedule occurrence skipped because its decision lease is held by a concurrent delivery.',
				array( 'registration_key' => $registration_key )
			);

			return;
		}

		$lease_released = false;
		try {
			$this->handle_occurrence( $registration_key, $lease_raw, $lease_released );
		} finally {
			if ( ! $lease_released ) {
				$this->lease->release( $registration_key, $lease_raw );
			}
		}
	}

	/**
	 * Dispatches one manual schedule run while retaining occurrence-lease custody.
	 *
	 * @internal Schedule consumer API only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Stable consumer identifier.
	 * @param   string $name  Stable schedule name.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a schedule run-now failure must be handled, not dropped' )]
	public function run_now_under_lease( string $owner, string $name ): AbstractResult {
		$registration_key = $owner . ':' . $name;
		$lease_raw        = $this->lease->claim( $registration_key );
		if ( null === $lease_raw ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Schedule "%1$s" for owner "%2$s" already has an occurrence decision in flight; retry after that dispatch persists its state.',
						$name,
						$owner
					)
				)
			);
		}

		try {
			return $this->dispatch_run_now( $owner, $name, $lease_raw );
		} finally {
			$this->lease->release( $registration_key, $lease_raw );
		}
	}

	/**
	 * Executes one leased occurrence decision against freshly read registration state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 * @param   string $lease_raw        Exact occurrence-lease row claimed by this delivery.
	 * @param   bool   $lease_released   Whether the accepted callback released the occurrence lease.
	 *
	 * @return  void
	 */
	private function handle_occurrence( string $registration_key, string $lease_raw, bool &$lease_released ): void {
		$registration = $this->registry->registration( $registration_key );
		if ( null === $registration ) {
			$removed = $this->scheduler->unschedule(
				'a8csp/background_tasks/schedule_due',
				array( $registration_key ),
				$registration_key
			);
			$cleanup = $this->scheduler->schedule_single(
				'a8csp/background_tasks/schedule_due',
				$this->clock->now()->getTimestamp(),
				array( $registration_key, 'cleanup' ),
				$registration_key
			);
			$context = array(
				'registration_key'  => $registration_key,
				'unscheduled'       => $removed->is_success(),
				'cleanup_scheduled' => $cleanup->is_success(),
			);
			if ( $removed->is_failure() ) {
				$context['unschedule_error'] = $removed->error->message;
			}
			if ( $cleanup->is_failure() ) {
				$context['cleanup_error'] = $cleanup->error->message;
			}

			$this->logger->warning(
				\sprintf(
					'Unknown schedule registration "%s" was delivered; re-declare the schedule or remove the leftover occurrence.',
					$registration_key
				),
				$context
			);

			return;
		}

		$schedule = $this->registry->get( $registration_key );
		if ( null === $schedule ) {
			$this->logger->debug(
				'Schedule registration is inactive in this request; leave its recurring occurrence unchanged.',
				array( 'registration_key' => $registration_key )
			);

			return;
		}

		if ( $registration['fingerprint'] !== $schedule->fingerprint() ) {
			$this->logger->debug(
				'Stale request schedule declaration does not match the persisted registration; leave the occurrence for a current request.',
				array( 'registration_key' => $registration_key )
			);

			return;
		}

		$parts = \explode( ':', $registration_key, 2 );
		$owner = $parts[0];
		$name  = $parts[1];
		$now   = $this->clock->now()->getTimestamp();
		if ( $now < $registration['next_due'] ) {
			$this->logger->debug(
				'Stale schedule occurrence redelivery dropped after its next-due token advanced.',
				array(
					'owner'    => $owner,
					'name'     => $name,
					'next_due' => $registration['next_due'],
					'fired_at' => $now,
				)
			);

			return;
		}

		$interval = $schedule->recurrence->interval();
		if ( null === $interval ) {
			$this->logger->error(
				'Schedule occurrence cannot resolve a fixed interval; synchronize the schedule with Recurrence::every().',
				array(
					'owner' => $owner,
					'name'  => $name,
				)
			);

			return;
		}

		$grace = \apply_filters(
			'a8csp/background_tasks/misfire_grace/' . $name,
			$interval,
			$owner,
			$name
		);
		if ( ! \is_int( $grace ) || 0 > $grace ) {
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
					'name'     => $name,
					'next_due' => $registration['next_due'],
					'fired_at' => $now,
				)
			);

			return;
		}

		if ( $misfired && CatchUpPolicy::Skip === $schedule->catch_up ) {
			$misfired_due             = $registration['next_due'];
			$registration['next_due'] = $next_due;
			$registration['misfires'] = self::increment_counter( $registration['misfires'] );
			$this->persist_delivery_state( $registration_key, $owner, $registration );
			try {
				try {
					\do_action(
						'a8csp/background_tasks/misfired/' . $name,
						$owner,
						$misfired_due,
						$now
					);
				} finally {
					\do_action(
						'a8csp/background_tasks/misfired',
						$name,
						$owner,
						$misfired_due,
						$now
					);
				}
			} catch ( \Throwable $throwable ) {
				$this->logger->error(
					'Misfired schedule listener failed after the occurrence state was persisted; fix the hook listener.',
					array(
						'owner'             => $owner,
						'name'              => $name,
						'exception_class'   => $throwable::class,
						'exception_message' => $throwable->getMessage(),
					)
				);
			}
			$this->logger->info(
				'Misfired schedule occurrence skipped and realigned to its recurrence.',
				array(
					'owner'    => $owner,
					'name'     => $name,
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
			$schedule->task,
			$schedule->args,
			$schedule->overlap,
			$schedule->priority,
			function () use ( $registration_key, $owner, $accepted_registration, $lease_raw, &$lease_released ): void {
				try {
					$this->persist_delivery_state( $registration_key, $owner, $accepted_registration );
				} finally {
					$this->lease->release( $registration_key, $lease_raw );
					$lease_released = true;
				}
			}
		);
		if ( $dispatched->is_failure() ) {
			$this->logger->error(
				'Schedule occurrence could not enqueue its target task: {error}',
				array(
					'owner' => $owner,
					'name'  => $name,
					'error' => $dispatched->error->message,
				)
			);

			return;
		}

		$registration['next_due'] = $next_due;
		if ( $dispatched->value instanceof TaskDispatchSkipped ) {
			// RunOnce makes the occurrence up, so it is not recorded as a misfire; `misfires` counts Skip-policy drops, `skips` counts overlap skips.
			$registration['skips'] = self::increment_counter( $registration['skips'] );
			$this->persist_delivery_state( $registration_key, $owner, $registration );
			$this->logger->info(
				'Schedule occurrence skipped because the target task lock is held.',
				array(
					'owner'          => $owner,
					'name'           => $name,
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
	 * @param   string $owner     Stable consumer identifier.
	 * @param   string $name      Stable schedule name.
	 * @param   string $lease_raw Exact occurrence-lease row claimed by this dispatch.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	private function dispatch_run_now( string $owner, string $name, string $lease_raw ): AbstractResult {
		$registration_key = $owner . ':' . $name;
		$registration     = $this->registry->registration( $registration_key );
		if ( null === $registration ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Schedule "%1$s" for owner "%2$s" is not synchronized; declare it with sync() before running it now.',
						$name,
						$owner
					)
				)
			);
		}

		$schedule = $this->registry->get( $registration_key );
		if ( null === $schedule ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Schedule "%1$s" for owner "%2$s" is inactive in this request; synchronize its declaration before running it now.',
						$name,
						$owner
					)
				)
			);
		}

		if ( $registration['fingerprint'] !== $schedule->fingerprint() ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Schedule "%1$s" for owner "%2$s" changed after this request synchronized; synchronize its current declaration before running it now.',
						$name,
						$owner
					)
				)
			);
		}

		$accepted_registration               = $registration;
		$accepted_registration['last_fired'] = $this->clock->now()->getTimestamp();
		$dispatched                          = $this->dispatcher->dispatch_scheduled_task(
			$schedule->task,
			$schedule->args,
			$schedule->overlap,
			$schedule->priority,
			function () use ( $registration_key, $owner, $accepted_registration, $lease_raw ): void {
				try {
					$this->persist_delivery_state( $registration_key, $owner, $accepted_registration );
				} finally {
					$this->lease->release( $registration_key, $lease_raw );
				}
			}
		);
		if ( $dispatched->is_failure() ) {
			return $dispatched;
		}

		if ( $dispatched->value instanceof TaskDispatchSkipped ) {
			return new Failure( $dispatched->value->error );
		}

		return new Success( $dispatched->value );
	}

	// endregion

	// region HELPERS

	/**
	 * Clears an unknown recurring chain after its backend has created any successor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @return  void
	 */
	private function handle_cleanup_delivery( string $registration_key ): void {
		$removed = $this->scheduler->unschedule(
			'a8csp/background_tasks/schedule_due',
			array( $registration_key ),
			$registration_key
		);
		if ( $removed->is_success() ) {
			return;
		}

		$this->logger->warning(
			\sprintf(
				'Unknown schedule registration "%s" cleanup could not clear its recurring occurrence; repair the scheduler store or re-declare the schedule.',
				$registration_key
			),
			array(
				'registration_key' => $registration_key,
				'error'            => $removed->error->message,
			)
		);
	}

	/**
	 * Persists one occurrence transition or logs the retryable registry failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int} $registration
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 * @param   string $owner            Stable consumer identifier.
	 * @param   array  $registration     Complete registration timing state.
	 *
	 * @return  void
	 */
	private function persist_delivery_state( string $registration_key, string $owner, array $registration ): void {
		$outcome = $this->registry->update_registration( $registration_key, $registration );
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

		$failure = new Failure( SchedulingError::registry_read( $owner ) );
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

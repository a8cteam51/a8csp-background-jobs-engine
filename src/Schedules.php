<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Orchestrator;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\TaskDispatchSkipped;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\Cadence;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\RegistrationUpdateOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\MaintenanceTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\SchedulingErrorReason;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Consumer API for declarative recurring task schedules.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Schedules {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ScheduleRegistry $registry     Owner-scoped schedule registry.
	 * @param   BackendInterface $scheduler    Scheduling backend facade.
	 * @param   ClockInterface   $clock        Current-time source.
	 * @param   Orchestrator     $orchestrator Policy-aware target-task dispatcher.
	 * @param   OccurrenceLease  $lease        Per-registration occurrence decision lease.
	 * @param   LoggerInterface  $logger       Log event sink.
	 */
	public function __construct(
		private ScheduleRegistry $registry,
		private BackendInterface $scheduler,
		private ClockInterface $clock,
		private Orchestrator $orchestrator,
		private OccurrenceLease $lease,
		private LoggerInterface $logger,
	) {}

	// endregion

	// region METHODS

	/**
	 * Synchronizes one owner's complete declared schedule set.
	 *
	 * Synchronization runs on every initialization and converges every observable disagreement among
	 * declared schedules, persisted registrations, and backend occurrences. Transient failures remain
	 * result data because the next initialization retries. An occurrence stored in a backend that is
	 * not ready during sync outlives the registration until its next delivery self-removes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string          $owner     Stable consumer identifier.
	 * @param   array<Schedule> $schedules Complete schedule declaration for the owner.
	 *
	 * @throws  \InvalidArgumentException When the owner, an entry, a registration key, or declaration uniqueness is invalid.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\NoDiscard( 'a schedule-sync failure must be handled, not dropped' )]
	public function sync( string $owner, array $schedules ): AbstractResult {
		if ( 1 !== \preg_match( '/\A[a-z0-9_-]+\z/', $owner ) ) {
			throw new \InvalidArgumentException(
				'Schedule owner is invalid; pass a non-empty identifier containing only lowercase letters, digits, underscores, and hyphens.'
			);
		}

		if ( 'a8csp-bgte' === $owner ) {
			throw new \InvalidArgumentException(
				'Schedule owner "a8csp-bgte" is reserved for engine maintenance; choose a consumer-specific owner identifier.'
			);
		}

		return $this->sync_owner( $owner, $schedules );
	}

	/**
	 * Synchronizes one validated owner, including the engine-reserved maintenance owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string          $owner     Stable consumer or engine identifier.
	 * @param   array<Schedule> $schedules Complete schedule declaration for the owner.
	 *
	 * @throws  \InvalidArgumentException When an entry, registration key, or declaration uniqueness is invalid.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	private function sync_owner( string $owner, array $schedules ): AbstractResult {

		$declared = array();
		foreach ( $schedules as $schedule ) {
			if ( ! $schedule instanceof Schedule ) {
				throw new \InvalidArgumentException(
					'Schedule sync accepts only Schedule value objects; construct each declaration with new Schedule(...).'
				);
			}

			$registration_key_length = \strlen( $owner . ':' . $schedule->name );
			if ( 255 < $registration_key_length ) {
				// Exception values are diagnostic data, not rendered output.
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new \InvalidArgumentException(
					\sprintf(
						'Schedule registration key is %d bytes; shorten the owner or schedule name so the combined "{owner}:{name}" identity is at most 255 bytes.',
						$registration_key_length
					)
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			if ( isset( $declared[ $schedule->name ] ) ) {
				// Exception values are diagnostic data, not rendered output.
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new \InvalidArgumentException(
					\sprintf(
						'Schedule "%s" is declared more than once; pass each schedule name exactly once per owner.',
						$schedule->name
					)
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			$declared[ $schedule->name ] = $schedule;
		}

		$existing         = $this->registry->registrations_for( $owner );
		$interval_by_name = array();
		$next_due_by_name = array();
		foreach ( $declared as $name => $schedule ) {
			$interval = $schedule->cadence->interval();
			if ( null === $interval ) {
				return $this->cron_failure( $schedule );
			}

			$interval_by_name[ $name ] = $interval;
			$current                   = $existing[ $name ] ?? null;
			if ( null !== $current && $schedule->fingerprint() === $current['fingerprint'] ) {
				continue;
			}

			$now      = $this->clock->now()->getTimestamp();
			$next_due = $now > \PHP_INT_MAX - $interval ? null : $now + $interval;
			if ( null === $next_due || 1 > $next_due ) {
				return new Failure(
					new SchedulingError(
						SchedulingErrorReason::InvalidInterval,
						\sprintf(
							'Schedule "%s" first occurrence falls outside positive supported Unix seconds; correct the system clock or pass a smaller Cadence::every() value.',
							$schedule->name
						),
						array(
							'current_timestamp' => $now,
							'interval'          => $interval,
						),
					)
				);
			}

			$next_due_by_name[ $name ] = $next_due;
		}

		$next = $existing;
		foreach ( $declared as $name => $schedule ) {
			$current          = $existing[ $name ] ?? null;
			$registration_key = $owner . ':' . $name;
			if ( null !== $current && $schedule->fingerprint() === $current['fingerprint'] ) {
				if ( $this->scheduler->is_scheduled(
					'a8csp/background_tasks/schedule_due',
					array( $registration_key ),
					$registration_key
				) ) {
					continue;
				}

				$recreated = $this->scheduler->schedule_recurring(
					'a8csp/background_tasks/schedule_due',
					$interval_by_name[ $name ],
					array( $registration_key ),
					$current['next_due'],
					$registration_key,
					unique: true,
					priority: $schedule->priority
				);
				if ( $recreated->is_failure() ) {
					return $recreated;
				}

				continue;
			}

			$backend_occurrence_exists = null === $current && $this->scheduler->is_scheduled(
				'a8csp/background_tasks/schedule_due',
				array( $registration_key ),
				$registration_key
			);
			if ( null !== $current || $backend_occurrence_exists ) {
				$removed = $this->scheduler->unschedule(
					'a8csp/background_tasks/schedule_due',
					array( $registration_key ),
					$registration_key
				);
				if ( $removed->is_failure() ) {
					return $this->replacement_clear_failure( $schedule );
				}
			}

			if ( null !== $current ) {
				unset( $next[ $name ] );
				if ( ! $this->registry->replace_owner( $owner, $declared, $next ) ) {
					return $this->registry_failure( $owner );
				}
			}

			$interval  = $interval_by_name[ $name ];
			$next_due  = $next_due_by_name[ $name ];
			$scheduled = $this->scheduler->schedule_recurring(
				'a8csp/background_tasks/schedule_due',
				$interval,
				array( $registration_key ),
				$next_due,
				$registration_key,
				unique: true,
				priority: $schedule->priority
			);
			if ( $scheduled->is_failure() ) {
				return $scheduled;
			}

			$next[ $name ] = array(
				'fingerprint' => $schedule->fingerprint(),
				'next_due'    => $next_due,
				'last_fired'  => null,
				'misfires'    => 0,
				'skips'       => 0,
			);
			if ( ! $this->registry->replace_owner( $owner, $declared, $next ) ) {
				$rollback = $this->scheduler->unschedule(
					'a8csp/background_tasks/schedule_due',
					array( $registration_key ),
					$registration_key
				);
				if ( $rollback->is_failure() ) {
					return $rollback;
				}

				return $this->registry_failure( $owner );
			}
		}

		foreach ( \array_keys( \array_diff_key( $existing, $declared ) ) as $name ) {
			$registration_key = $owner . ':' . $name;
			$removed          = $this->scheduler->unschedule(
				'a8csp/background_tasks/schedule_due',
				array( $registration_key ),
				$registration_key
			);
			if ( $removed->is_failure() ) {
				return $removed;
			}

			unset( $next[ $name ] );
			if ( ! $this->registry->replace_owner( $owner, $declared, $next ) ) {
				return $this->registry_failure( $owner );
			}
		}

		if ( ! $this->registry->replace_owner( $owner, $declared, $next ) ) {
			return $this->registry_failure( $owner );
		}

		return new Success( true );
	}

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

		// Action Scheduler becomes ready at init:1, while WP_Hook does not visit callbacks appended
		// to the priority bucket it is currently traversing.
		if ( 0 < \did_action( 'init' ) && ! \doing_action( 'init' ) ) {
			$this->sync_maintenance_schedule();
			return;
		}

		// The deferred sync fires on whichever site is selected when its lifecycle hook runs; the
		// registration belongs to the boot-time site.
		$boot_blog_id = \get_current_blog_id();
		$hook_name    = \doing_action( 'init' ) ? 'wp_loaded' : 'init';
		\add_action(
			$hook_name,
			function () use ( $boot_blog_id ): void {
				$this->sync_maintenance_schedule( $boot_blog_id );
			}
		);
	}

	/**
	 * Synchronizes the engine's own maintenance registration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int|null $blog_id Site the registration belongs to; null keeps the current site.
	 *
	 * @return  void
	 */
	private function sync_maintenance_schedule( ?int $blog_id = null ): void {
		$switched = null !== $blog_id && \is_multisite() && \get_current_blog_id() !== $blog_id;
		if ( $switched ) {
			\switch_to_blog( $blog_id );
		}

		try {
			$result = $this->sync_owner(
				'a8csp-bgte',
				array(
					new Schedule(
						'maintenance',
						Cadence::every( \HOUR_IN_SECONDS ),
						MaintenanceTask::NAME,
						array(),
						OverlapPolicy::Skip,
						CatchUpPolicy::RunOnce
					),
				)
			);
			if ( $result->is_failure() ) {
				$this->logger->error(
					'Engine maintenance schedule could not be synchronized: {error}',
					array( 'error' => $result->error->message )
				);
			}
		} finally {
			if ( $switched ) {
				\restore_current_blog();
			}
		}
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

		try {
			$this->handle_occurrence( $registration_key, $lease_raw );
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
	 *
	 * @return  void
	 */
	private function handle_occurrence( string $registration_key, string $lease_raw ): void {
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

		$interval = $schedule->cadence->interval();
		if ( null === $interval ) {
			$this->logger->error(
				'Schedule occurrence cannot resolve a fixed interval; synchronize the schedule with Cadence::every().',
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
				'Schedule cadence cannot advance beyond the current timestamp; correct the system clock or synchronize a smaller interval.',
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
				'Misfired schedule occurrence skipped and realigned to its cadence.',
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
		$dispatched                          = $this->orchestrator->dispatch_scheduled_task(
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
	 * Immediately dispatches one declared schedule target without changing its cadence.
	 *
	 * Schedule-driven tasks must be idempotent because manual dispatch uses the same overlap and
	 * at-least-once execution machinery as recurring occurrences.
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
	public function run_now( string $owner, string $name ): AbstractResult {
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
			return $this->run_now_under_lease( $owner, $name, $lease_raw );
		} finally {
			$this->lease->release( $registration_key, $lease_raw );
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
	private function run_now_under_lease( string $owner, string $name, string $lease_raw ): AbstractResult {
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
		$dispatched                          = $this->orchestrator->dispatch_scheduled_task(
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

		$failure = $this->registry_failure( $owner );
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
	 * @param   int $next_due Current cadence instant.
	 * @param   int $interval Positive cadence interval.
	 * @param   int $now      Occurrence delivery timestamp.
	 *
	 * @return  int|null Future cadence instant, or null when positive Unix seconds overflow.
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

	/**
	 * Returns the unsupported-cadence failure for a cron declaration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Schedule $schedule Cron schedule.
	 *
	 * @return  Failure<SchedulingError>
	 */
	private function cron_failure( Schedule $schedule ): Failure {
		$supported = $this->scheduler->supports_cron_expressions();
		$message   = $supported
			? \sprintf(
				'Schedule "%s" uses a cron expression unavailable through the v1 recurring-interval port; use Cadence::every().',
				$schedule->name
			)
			: \sprintf(
				'Schedule "%s" uses a cron expression unsupported by every ready backend; use Cadence::every() or configure a backend that supports cron expressions.',
				$schedule->name
			);

		return new Failure(
			new SchedulingError(
				SchedulingErrorReason::UnsupportedCadence,
				$message,
				array(
					'schedule'   => $schedule->name,
					'expression' => $schedule->cadence->expression(),
				),
			)
		);
	}

	/**
	 * Returns the failed verified-clear result for a schedule replacement.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Schedule $schedule Schedule being replaced.
	 *
	 * @return  Failure<SchedulingError>
	 */
	private function replacement_clear_failure( Schedule $schedule ): Failure {
		return new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				\sprintf(
					'Schedule "%s" cannot be replaced; retry the sync; the previous occurrence could not be confirmed removed.',
					$schedule->name
				),
				array( 'schedule' => $schedule->name ),
			)
		);
	}

	/**
	 * Returns the failed registry-postcondition result for one owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Stable consumer identifier.
	 *
	 * @return  Failure<SchedulingError>
	 */
	private function registry_failure( string $owner ): Failure {
		return new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				\sprintf(
					'Schedule registry for owner "%s" could not be persisted; repair WordPress option writes and retry synchronization.',
					$owner
				),
				array( 'owner' => $owner ),
			)
		);
	}

	// endregion
}

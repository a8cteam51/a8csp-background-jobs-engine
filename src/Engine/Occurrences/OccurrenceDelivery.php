<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\EngineErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\TaskDispatchSkipped;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\WorkIdentity;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulerFacade;
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
	 * Prefix for durable unknown-chain cleanup intents.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const INTENT_PREFIX = 'a8csp_bgte_cleanup_';

	/**
	 * Internal recurring-occurrence delivery hook.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const SCHEDULE_HOOK = 'a8csp_background_tasks/schedule_due';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ScheduleRegistry $registry    Owner-scoped schedule registry.
	 * @param   Dispatcher       $dispatcher  Policy-aware target-task dispatcher.
	 * @param   OccurrenceLease  $lease       Per-registration occurrence decision lease.
	 * @param   SchedulerFacade  $scheduler   Scheduling backend facade.
	 * @param   OptionRows       $option_rows Authoritative cleanup-intent row I/O.
	 * @param   ClockInterface   $clock       Current-time source.
	 * @param   LoggerInterface  $logger      Log event sink.
	 */
	public function __construct(
		private ScheduleRegistry $registry,
		private Dispatcher $dispatcher,
		private OccurrenceLease $lease,
		private SchedulerFacade $scheduler,
		private OptionRows $option_rows,
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
			self::SCHEDULE_HOOK,
			array( $this, 'handle_schedule_due' ),
			10,
			1
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
	 *
	 * @return  void
	 */
	public function handle_schedule_due( string $registration_key ): void {
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
	 * Converges every well-formed durable unknown-chain cleanup intent.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function converge_pending_intents(): void {
		try {
			$registration_keys = $this->intent_keys();
		} catch ( \Throwable $throwable ) {
			$this->log_pending_intent(
				'Unknown schedule cleanup intents could not be enumerated during maintenance; retry on the next sweep.',
				array( 'exception' => $throwable )
			);

			return;
		}

		foreach ( $registration_keys as $registration_key ) {
			try {
				$this->converge_unknown_chain( $registration_key );
			} catch ( \Throwable $throwable ) {
				$this->log_pending_intent(
					'Unknown schedule cleanup intent could not converge during maintenance; retry on the next sweep.',
					array(
						'registration_key' => $registration_key,
						'exception'        => $throwable,
					)
				);
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
	 * @param   string $registration_key Complete owner-qualified schedule identity.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a schedule run-now failure must be handled, not dropped' )]
	public function run_now_under_lease( string $registration_key ): AbstractResult {
		$parts = WorkIdentity::parts( $registration_key );
		if ( null === $parts ) {
			return new Failure(
				new EngineError(
					'Schedule identity is invalid; pass one canonical {owner}:{name} identity.',
					reason: EngineErrorReason::PayloadRejected,
				)
			);
		}

		[ $owner, $name ] = $parts;
		$lease_raw        = $this->lease->claim( $registration_key );
		if ( null === $lease_raw ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Schedule "%1$s" for owner "%2$s" already has an occurrence decision in flight; retry after that dispatch persists its state.',
						$name,
						$owner
					),
					reason: EngineErrorReason::OverlapHeld,
					context: array(
						'owner'    => $owner,
						'schedule' => $name,
					),
				)
			);
		}

		try {
			return $this->dispatch_run_now( $registration_key, $owner, $name, $lease_raw );
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
		$registration_read = $this->registry->registration( $registration_key );
		if ( $registration_read->is_failure() ) {
			return;
		}

		$registration = $registration_read->value;
		if ( null === $registration ) {
			$this->record_intent( $registration_key );
			$converged = $this->converge_unknown_chain( $registration_key );
			$context   = array(
				'registration_key' => $registration_key,
				'converged'        => $converged,
			);

			$this->logger->warning(
				\sprintf(
					'Unknown schedule registration "%s" was delivered; re-declare the schedule or remove the leftover occurrence.',
					$registration_key
				),
				$context
			);

			return;
		}

		$declaration = $this->registry->get( $registration_key );
		if ( null === $declaration ) {
			$this->logger->debug(
				'Schedule registration is inactive in this request; leave its recurring occurrence unchanged.',
				array( 'registration_key' => $registration_key )
			);

			return;
		}

		$schedule = $declaration['schedule'];

		if ( $registration['fingerprint'] !== $schedule->fingerprint() ) {
			$this->logger->debug(
				'Stale request schedule declaration does not match the persisted registration; leave the occurrence for a current request.',
				array( 'registration_key' => $registration_key )
			);

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

		/**
		 * Filters the grace window for one schedule occurrence.
		 *
		 * The dynamic portion of the hook name, `$registration_key`, is the complete owner-qualified
		 * schedule identity.
		 *
		 * @since   1.0.0
		 * @version 1.0.0
		 *
		 * @param   int    $interval         Default grace window in seconds.
		 * @param   string $owner            Stable consumer identifier.
		 * @param   string $registration_key Complete owner-qualified schedule identity.
		 */
		$grace = \apply_filters(
			'a8csp_background_tasks/misfire_grace/' . $registration_key,
			$interval,
			$owner,
			$registration_key
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
					/**
					 * Fires when a Skip schedule drops one beyond-grace occurrence.
					 *
					 * The dynamic portion of the hook name, `$registration_key`, is the complete
					 * owner-qualified schedule identity.
					 *
					 * @since   1.0.0
					 * @version 1.0.0
					 *
					 * @param   string $owner        Stable consumer identifier.
					 * @param   int    $misfired_due Dropped occurrence due timestamp.
					 * @param   int    $now          Occurrence observation timestamp.
					 */
					\do_action(
						'a8csp_background_tasks/misfired/' . $registration_key,
						$owner,
						$misfired_due,
						$now
					);
				} finally {
					/**
					 * Fires after the name-specific misfired schedule hook.
					 *
					 * @since   1.0.0
					 * @version 1.0.0
					 *
					 * @param   string $registration_key Complete owner-qualified schedule identity.
					 * @param   string $owner            Stable consumer identifier.
					 * @param   int    $misfired_due     Dropped occurrence due timestamp.
					 * @param   int    $now              Occurrence observation timestamp.
					 */
					\do_action(
						'a8csp_background_tasks/misfired',
						$registration_key,
						$owner,
						$misfired_due,
						$now
					);
				}
			} catch ( \Throwable $throwable ) {
				$this->logger->error(
					'Misfired schedule listener failed after the occurrence state was persisted; fix the hook listener.',
					array(
						'owner'     => $owner,
						'name'      => $name,
						'exception' => $throwable,
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
			$declaration['task'],
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
	 * @param   string $registration_key Complete owner-qualified schedule identity.
	 * @param   string $owner            Stable consumer identifier.
	 * @param   string $name             Stable schedule name.
	 * @param   string $lease_raw        Exact occurrence-lease row claimed by this dispatch.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	private function dispatch_run_now( string $registration_key, string $owner, string $name, string $lease_raw ): AbstractResult {
		$registration_read = $this->registry->registration( $registration_key );
		if ( $registration_read->is_failure() ) {
			return new Failure( $registration_read->error );
		}

		$registration = $registration_read->value;
		if ( null === $registration ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Schedule "%1$s" for owner "%2$s" is not synchronized; declare it with sync() before running it now.',
						$name,
						$owner
					),
					reason: EngineErrorReason::UnknownSchedule,
					context: array(
						'owner'    => $owner,
						'schedule' => $name,
					),
				)
			);
		}

		$declaration = $this->registry->get( $registration_key );
		if ( null === $declaration ) {
			return new Failure(
				new EngineError(
					\sprintf(
						'Schedule "%1$s" for owner "%2$s" is inactive in this request; synchronize its declaration before running it now.',
						$name,
						$owner
					),
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
					\sprintf(
						'Schedule "%1$s" for owner "%2$s" changed after this request synchronized; synchronize its current declaration before running it now.',
						$name,
						$owner
					),
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
	 * Records one durable cleanup intent while preserving an existing generation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @throws  \LogicException When WordPress does not serialize the intent to a string.
	 *
	 * @return  void
	 */
	private function record_intent( string $registration_key ): void {
		$raw = \maybe_serialize(
			array(
				'key'        => $registration_key,
				'created_at' => $this->clock->now()->getTimestamp(),
			)
		);
		if ( ! \is_string( $raw ) ) {
			throw new \LogicException( 'WordPress must serialize an unknown-schedule cleanup intent to a string.' );
		}

		$this->option_rows->insert( self::intent_option_name( $registration_key ), $raw );
	}

	/**
	 * Reads one intent generation as exact persisted bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @return  AbstractResult<string|null, EngineError>
	 */
	private function read_intent( string $registration_key ): AbstractResult {
		return $this->option_rows->read( self::intent_option_name( $registration_key ) );
	}

	/**
	 * Deletes the observed cleanup-intent generation or confirms the row is absent.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 * @param   string $expected_raw     Exact selected intent value.
	 *
	 * @return  bool
	 */
	private function clear_intent( string $registration_key, string $expected_raw ): bool {
		if ( $this->option_rows->delete( self::intent_option_name( $registration_key ), $expected_raw ) ) {
			return true;
		}

		$selected = $this->read_intent( $registration_key );
		if ( $selected->is_failure() ) {
			return false;
		}

		return null === $selected->value;
	}

	/**
	 * Returns registration keys carried by well-formed cleanup-intent rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<string>
	 */
	private function intent_keys(): array {
		$keys  = array();
		$names = $this->option_rows->option_names( self::INTENT_PREFIX );
		if ( $names->is_failure() ) {
			return $keys;
		}

		foreach ( $names->value as $option_name ) {
			$selected = $this->option_rows->read( $option_name );
			if ( $selected->is_failure() ) {
				continue;
			}

			$raw = $selected->value;
			if ( null === $raw ) {
				continue;
			}

			$value = RawOptionDecoder::decode( $raw );
			if (
				! \is_array( $value )
				|| 2 !== \count( $value )
				|| ! \is_string( $value['key'] ?? null )
				|| ! \is_int( $value['created_at'] ?? null )
				|| self::intent_option_name( $value['key'] ) !== $option_name
			) {
				continue;
			}

			$keys[] = $value['key'];
		}

		return $keys;
	}

	/**
	 * Resolves one observed cleanup intent against current registry and scheduler state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @return  bool Whether the observed intent no longer needs convergence.
	 */
	private function converge_unknown_chain( string $registration_key ): bool {
		$selected = $this->read_intent( $registration_key );
		if ( $selected->is_failure() ) {
			return false;
		}

		$expected_raw = $selected->value;
		if ( null === $expected_raw ) {
			return true;
		}

		$registration = $this->registry->registration( $registration_key );
		if ( $registration->is_failure() ) {
			return false;
		}

		if ( null !== $registration->value ) {
			return $this->clear_intent( $registration_key, $expected_raw );
		}

		$clearance = $this->scheduler->unschedule_for_convergence(
			self::SCHEDULE_HOOK,
			array( $registration_key ),
			$registration_key
		);
		$removed   = $clearance->result;
		if ( $removed->is_failure() ) {
			$this->log_pending_intent(
				'Unknown schedule cleanup intent remains pending because verified clearance failed.',
				array(
					'registration_key' => $registration_key,
					'error'            => $removed->error->message,
				)
			);

			return false;
		}

		if ( ! $clearance->authoritative ) {
			$this->log_pending_intent(
				'Unknown schedule cleanup intent remains pending until every scheduler backend is ready or absent.',
				array( 'registration_key' => $registration_key )
			);

			return false;
		}

		return $this->clear_intent( $registration_key, $expected_raw );
	}

	/**
	 * Returns the fixed-size option identity for one registration key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @return  string
	 */
	private static function intent_option_name( string $registration_key ): string {
		return self::INTENT_PREFIX . \hash( 'sha256', $registration_key );
	}

	/**
	 * Emits a maintenance diagnostic without allowing the diagnostic sink to abort the sweep.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string               $message Log message.
	 * @param   array<string, mixed> $context Log context.
	 *
	 * @return  void
	 */
	private function log_pending_intent( string $message, array $context ): void {
		try {
			$this->logger->debug( $message, $context );
		} catch ( \Throwable ) {
			// Maintenance convergence remains retryable even when diagnostics are unavailable.
			return;
		}
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
		$outcome = $this->registry->update_registration(
			$registration_key,
			$registration['fingerprint'],
			$registration
		);
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

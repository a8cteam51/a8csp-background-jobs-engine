<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\BackendInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;
use Psr\Clock\ClockInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Client API for declarative recurring job schedules.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ScheduleOperations {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ScheduleRegistry   $registry            Owner-scoped schedule registry.
	 * @param   BackendInterface   $scheduler           Scheduling backend facade.
	 * @param   ClockInterface     $clock               Current-time source.
	 * @param   OccurrenceDelivery $occurrence_delivery Schedule occurrence delivery service.
	 */
	public function __construct(
		private ScheduleRegistry $registry,
		private BackendInterface $scheduler,
		private ClockInterface $clock,
		private OccurrenceDelivery $occurrence_delivery,
	) {}

	// endregion

	// region METHODS

	/**
	 * Synchronizes one owner's complete declared schedule set.
	 *
	 * Synchronization runs on every initialization and converges every observable disagreement among
	 * declared schedules, persisted registrations, and pending backend occurrences, including surplus
	 * chains for one identity. Transient failures remain result data because the next initialization
	 * retries. An occurrence stored in a backend that is not ready during sync outlives the registration
	 * until its next delivery self-removes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param  array<string, array{schedule: Schedule, job: Identity}> $declarations
	 * @phpstan-return AbstractResult<true, SchedulingError>
	 *
	 * @param   string $owner        Stable client identifier captured by the owner-bound facade.
	 * @param   array  $declarations Complete schedule declaration keyed by owner-qualified identity.
	 *
	 * @throws  \InvalidArgumentException When the owner, declaration, schedule identity, or target identity is invalid.
	 *
	 * @return  AbstractResult
	 */
	#[\NoDiscard( 'a schedule-sync failure must be handled, not dropped' )]
	public function sync( string $owner, array $declarations ): AbstractResult {
		Identity::validate_owner( $owner );

		return $this->sync_owner( $owner, $declarations );
	}

	/**
	 * Synchronizes one validated owner, including the engine-reserved maintenance owner.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param  array<string, array{schedule: Schedule, job: Identity}> $declarations
	 * @phpstan-return AbstractResult<true, SchedulingError>
	 *
	 * @param   string $owner        Stable client or engine identifier.
	 * @param   array  $declarations Complete schedule declaration keyed by owner-qualified identity.
	 *
	 * @throws  \InvalidArgumentException When the owner, declaration, schedule identity, or target identity is invalid.
	 *
	 * @return  AbstractResult
	 */
	public function sync_owner( string $owner, array $declarations ): AbstractResult {
		Identity::validate_owner( $owner, true );

		$declared = array();
		foreach ( $declarations as $schedule_identity => $declaration ) {
			if ( ! \is_string( $schedule_identity ) ) {
				throw new \InvalidArgumentException( 'Schedule sync declaration keys must be canonical owner-qualified schedule identities.' );
			}

			$identity = Identity::tryFrom( $schedule_identity );
			if ( null === $identity || $owner !== $identity->owner() ) {
				throw new \InvalidArgumentException( 'Schedule sync declaration identities must be canonical and belong to the bound owner.' );
			}

			$schedule = \is_array( $declaration ) ? ( $declaration['schedule'] ?? null ) : null;
			if ( ! $schedule instanceof Schedule ) {
				throw new \InvalidArgumentException( 'Schedule sync accepts only Schedule value objects; construct each declaration with new Schedule(...).' );
			}

			if ( $schedule->name !== $identity->name() ) {
				throw new \InvalidArgumentException( 'Schedule sync declaration identities must match their Schedule value-object names.' );
			}

			$job = $declaration['job'] ?? null;
			if ( ! $job instanceof Identity ) {
				throw new \InvalidArgumentException( 'Schedule sync target identities must be canonical owner-qualified job identities.' );
			}

			if ( $owner !== $job->owner() || $schedule->job !== $job->name() ) {
				throw new \InvalidArgumentException( 'Schedule sync target identities must be canonical, belong to the bound owner, and match their Schedule value-object job names.' );
			}

			$declared[ $schedule_identity ] = array(
				'schedule' => $schedule,
				'job'      => $job,
			);
		}

		$registrations = $this->registry->registrations_for( $owner );
		if ( $registrations->is_failure() ) {
			if ( $registrations->error instanceof SchedulingError ) {
				return new Failure( $registrations->error );
			}

			return $this->registry_read_failure( $owner );
		}

		$existing             = $registrations->value;
		$interval_by_identity = array();
		$next_due_by_identity = array();
		foreach ( $declared as $schedule_identity => $declaration ) {
			$schedule = $declaration['schedule'];
			$interval = $schedule->recurrence->interval;

			$interval_by_identity[ $schedule_identity ] = $interval;
			$current                                    = $existing[ $schedule_identity ] ?? null;
			if ( null !== $current && $schedule->fingerprint() === $current['fingerprint'] ) {
				continue;
			}

			$now      = $this->clock->now()->getTimestamp();
			$next_due = self::next_anchored_due( $now, $interval, $schedule->recurrence->anchor );
			if ( null === $next_due || 1 > $next_due ) {
				return new Failure(
					new SchedulingError(
						SchedulingErrorReason::InvalidTimeInput,
						\sprintf( 'Schedule "%s" first occurrence falls outside positive supported Unix seconds; correct the system clock or pass a smaller Recurrence::every()/every_anchored() interval.', $schedule->name ),
						array(
							'current_timestamp' => $now,
							'interval'          => $interval,
						),
					)
				);
			}

			$next_due_by_identity[ $schedule_identity ] = $next_due;
		}

		$next             = $existing;
		$scheduled_counts = $this->scheduler->scheduled_counts( OccurrenceDelivery::SCHEDULE_HOOK, \array_keys( $declared ) );
		foreach ( $declared as $schedule_identity => $declaration ) {
			$schedule = $declaration['schedule'];
			$current  = $existing[ $schedule_identity ] ?? null;
			if ( null !== $current && $schedule->fingerprint() === $current['fingerprint'] ) {
				$scheduled_count = $scheduled_counts[ $schedule_identity ] ?? 0;
				if ( 1 === $scheduled_count ) {
					continue;
				}
				if ( 1 < $scheduled_count ) {
					$removed = $this->scheduler->unschedule( OccurrenceDelivery::SCHEDULE_HOOK, array( $schedule_identity ), $schedule_identity );
					if ( $removed->is_failure() ) {
						return $this->replacement_clear_failure( $schedule );
					}
				}

				$recreated = $this->scheduler->schedule_recurring( OccurrenceDelivery::SCHEDULE_HOOK, $interval_by_identity[ $schedule_identity ], array( $schedule_identity ), $current['next_due'], $schedule_identity, priority: $schedule->priority );
				if ( $recreated->is_failure() ) {
					return $recreated;
				}

				continue;
			}

			$backend_occurrence_exists = null === $current && $this->scheduler->is_scheduled( OccurrenceDelivery::SCHEDULE_HOOK, array( $schedule_identity ), $schedule_identity );
			if ( null !== $current || $backend_occurrence_exists ) {
				$removed = $this->scheduler->unschedule( OccurrenceDelivery::SCHEDULE_HOOK, array( $schedule_identity ), $schedule_identity );
				if ( $removed->is_failure() ) {
					return $this->replacement_clear_failure( $schedule );
				}
			}

			$interval                   = $interval_by_identity[ $schedule_identity ];
			$next_due                   = $next_due_by_identity[ $schedule_identity ];
			$next[ $schedule_identity ] = array(
				'fingerprint'            => $schedule->fingerprint(),
				'next_due'               => $next_due,
				'last_fired'             => null,
				'misfire_skips'          => 0,
				'overlap_skips'          => 0,
				'undeclared_occurrences' => 0,
				'undeclared_escalated'   => false,
			);
			$replacement                = $this->registry->replace_owner( $owner, $declared, $next );
			if ( OwnerReplacementOutcome::Persisted !== $replacement ) {
				return $this->registry_replacement_failure( $owner, $replacement );
			}

			$scheduled = $this->scheduler->schedule_recurring( OccurrenceDelivery::SCHEDULE_HOOK, $interval, array( $schedule_identity ), $next_due, $schedule_identity, priority: $schedule->priority );
			if ( $scheduled->is_failure() ) {
				// A scheduling failure leaves the benign registration-without-chain that the fingerprint-match fast path
				// recreates; rolling back can race a delivery and manufacture chain-without-registration, the exact orphan
				// the intent protocol cleans.
				return $scheduled;
			}
		}

		foreach ( \array_keys( \array_diff_key( $existing, $declared ) ) as $schedule_identity ) {
			$removed = $this->scheduler->unschedule( OccurrenceDelivery::SCHEDULE_HOOK, array( $schedule_identity ), $schedule_identity );
			if ( $removed->is_failure() ) {
				return $removed;
			}

			unset( $next[ $schedule_identity ] );
			$replacement = $this->registry->replace_owner( $owner, $declared, $next );
			if ( OwnerReplacementOutcome::Persisted !== $replacement ) {
				return $this->registry_replacement_failure( $owner, $replacement );
			}
		}

		// Backend convergence precedes marker reset so only a successful declaration refresh ends the zombie episode.
		$replacement = $this->registry->replace_owner( $owner, $declared, $next, reset_undeclared_episodes: true );
		if ( OwnerReplacementOutcome::Persisted !== $replacement ) {
			return $this->registry_replacement_failure( $owner, $replacement );
		}

		return new Success( true );
	}

	/**
	 * Immediately dispatches one declared schedule target without changing its recurrence.
	 *
	 * Schedule-driven jobs must be idempotent because manual dispatch uses the same overlap and
	 * at-least-once execution machinery as recurring occurrences.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete owner-qualified schedule identity.
	 *
	 * @return  AbstractResult<array{identity: Identity, run_id: string}, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a schedule dispatch-now failure must be handled, not dropped' )]
	public function dispatch_now( Identity $identity ): AbstractResult {
		return $this->occurrence_delivery->dispatch_now_under_lease( $identity );
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the first strictly future instant on the recurrence's UTC phase grid.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int      $now      Current UTC Unix timestamp.
	 * @param   int      $interval Positive recurrence interval.
	 * @param   int|null $anchor   Canonical UTC phase offset, or null when unanchored.
	 *
	 * @return  int|null Future due instant, or null when positive Unix seconds overflow.
	 */
	private static function next_anchored_due( int $now, int $interval, ?int $anchor ): ?int {
		if ( $now > \PHP_INT_MAX - $interval ) {
			return null;
		}
		if ( null === $anchor ) {
			return $now + $interval;
		}

		$phase     = $anchor % $interval;
		$candidate = $now - ( $now % $interval ) + $phase;
		while ( $candidate <= $now ) {
			$candidate += $interval;
		}

		return $candidate;
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
		return new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, \sprintf( 'Schedule "%s" cannot be replaced; retry the sync; the previous occurrence could not be confirmed removed.', $schedule->name ), array( 'schedule' => $schedule->name ), ) );
	}

	/**
	 * Returns a failed registry-read result for one owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Stable client identifier.
	 *
	 * @return  Failure<SchedulingError>
	 */
	private function registry_read_failure( string $owner ): Failure {
		return new Failure( SchedulingError::registry_read_failure( $owner ) );
	}

	/**
	 * Returns the public failure for one classified owner-row replacement.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $owner   Stable client identifier.
	 * @param   OwnerReplacementOutcome $outcome Classified failed replacement.
	 *
	 * @return  Failure<SchedulingError>
	 */
	private function registry_replacement_failure( string $owner, OwnerReplacementOutcome $outcome ): Failure {
		if ( OwnerReplacementOutcome::Corrupt === $outcome ) {
			return new Failure( SchedulingError::registry_corrupt( $owner, ScheduleRegistry::option_name( $owner ) ) );
		}

		return new Failure( SchedulingError::registry_persist_failure( $owner ) );
	}

	// endregion
}

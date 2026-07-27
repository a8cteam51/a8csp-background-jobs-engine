<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

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
	// region FIELDS AND CONSTANTS

	/**
	 * Recurring ticks run admission machinery at the most urgent supported priority.
	 *
	 * A tick performs admission only; the work itself runs on the delivery row the tick creates.
	 * Carrying the consumer's priority here would apply it twice — once delaying admission and again
	 * delaying the delivery — so the tick is engine-owned and the consumer's value reaches only the
	 * delivery row. Zero keeps admission ahead of the work it admits, so a queue saturated with
	 * consumer jobs cannot starve the step that enqueues them.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int TICK_PRIORITY = 0;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ScheduleRegistry   $registry            Per-scope schedule registry.
	 * @param   SchedulerFacade    $scheduler           Scheduling backend facade.
	 * @param   ClockInterface     $clock               Current-time source.
	 * @param   OccurrenceDelivery $occurrence_delivery Schedule occurrence delivery service.
	 * @param   LoggerInterface    $logger              Engine logger.
	 */
	public function __construct(
		private ScheduleRegistry $registry,
		private SchedulerFacade $scheduler,
		private ClockInterface $clock,
		private OccurrenceDelivery $occurrence_delivery,
		private LoggerInterface $logger,
	) {}

	// endregion

	// region METHODS

	/**
	 * Synchronizes one scope's complete declared schedule set.
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
	 * @param   string $scope        Stable client identifier captured by the scope-bound facade.
	 * @param   array  $declarations Complete schedule declaration keyed by scope-qualified identity.
	 *
	 * @throws  \InvalidArgumentException When the scope, declaration, schedule identity, or target identity is invalid.
	 *
	 * @return  AbstractResult
	 */
	#[\NoDiscard( 'a schedule-sync failure must be handled, not dropped' )]
	public function sync( string $scope, array $declarations ): AbstractResult {
		Identity::validate_scope( $scope );

		$has_dormant_candidate = $this->scheduler->has_dormant_candidate();
		$result                = $this->sync_scope( $scope, $declarations );
		if ( $result->is_success() && $has_dormant_candidate ) {
			$this->logger->warning( 'Schedule synchronization ran while a scheduling backend was not ready; initialize it and synchronize this scope again to converge dormant recurring occurrences.', array( 'scope' => $scope ) );
		}

		return $result;
	}

	/**
	 * Synchronizes one validated scope, including the engine-reserved maintenance scope.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param  array<string, array{schedule: Schedule, job: Identity}> $declarations
	 * @phpstan-return AbstractResult<true, SchedulingError>
	 *
	 * @param   string $scope        Stable client or engine identifier.
	 * @param   array  $declarations Complete schedule declaration keyed by scope-qualified identity.
	 *
	 * @throws  \InvalidArgumentException When the scope, declaration, schedule identity, or target identity is invalid.
	 *
	 * @return  AbstractResult
	 */
	public function sync_scope( string $scope, array $declarations ): AbstractResult {
		Identity::validate_scope( $scope, true );

		$declared = array();
		foreach ( $declarations as $schedule_identity => $declaration ) {
			if ( ! \is_string( $schedule_identity ) ) {
				throw new \InvalidArgumentException( 'Schedule sync declaration keys must be canonical scope-qualified schedule identities.' );
			}

			$identity = Identity::tryFrom( $schedule_identity );
			if ( null === $identity || $scope !== $identity->scope() ) {
				throw new \InvalidArgumentException( 'Schedule sync declaration identities must be canonical and belong to the bound scope.' );
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
				throw new \InvalidArgumentException( 'Schedule sync target identities must be canonical scope-qualified job identities.' );
			}

			if ( $scope !== $job->scope() || $schedule->job !== $job->name() ) {
				throw new \InvalidArgumentException( 'Schedule sync target identities must be canonical, belong to the bound scope, and match their Schedule value-object job names.' );
			}

			$declared[ $schedule_identity ] = array(
				'schedule' => $schedule,
				'job'      => $job,
			);
		}

		$registrations = $this->registry->registrations_for( $scope );
		if ( $registrations->is_failure() ) {
			if ( $registrations->error instanceof SchedulingError ) {
				return new Failure( $registrations->error );
			}

			return $this->registry_read_failure( $scope );
		}

		$existing                        = $registrations->value;
		$fingerprint_matching_identities = array();
		$interval_by_identity            = array();
		$next_due_by_identity            = array();
		foreach ( $declared as $schedule_identity => $declaration ) {
			$schedule = $declaration['schedule'];
			$interval = $schedule->recurrence->interval;

			$interval_by_identity[ $schedule_identity ] = $interval;
			$current                                    = $existing[ $schedule_identity ] ?? null;
			if ( null !== $current && $schedule->fingerprint() === $current['fingerprint'] ) {
				$fingerprint_matching_identities[] = $schedule_identity;
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
		$scheduled_counts = $this->scheduler->scheduled_counts( OccurrenceDelivery::SCHEDULE_HOOK, $fingerprint_matching_identities );
		foreach ( $declared as $schedule_identity => $declaration ) {
			$schedule = $declaration['schedule'];
			$current  = $existing[ $schedule_identity ] ?? null;
			if ( null !== $current && $schedule->fingerprint() === $current['fingerprint'] ) {
				$scheduled_count = $scheduled_counts[ $schedule_identity ];
				if ( 1 === $scheduled_count ) {
					continue;
				}
				if ( 1 < $scheduled_count ) {
					$removed = $this->scheduler->unschedule( OccurrenceDelivery::SCHEDULE_HOOK, array( $schedule_identity ), $schedule_identity );
					if ( $removed->is_failure() ) {
						return $this->replacement_clear_failure( $schedule );
					}
				}

				$recreated = $this->scheduler->schedule_recurring( OccurrenceDelivery::SCHEDULE_HOOK, $interval_by_identity[ $schedule_identity ], array( $schedule_identity ), $current['next_due'], $schedule_identity, priority: self::TICK_PRIORITY );
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
			$replacement                = $this->registry->replace_scope( $scope, $declared, $next );
			if ( ScopeReplacementOutcome::Persisted !== $replacement ) {
				return $this->registry_replacement_failure( $scope, $replacement );
			}

			$scheduled = $this->scheduler->schedule_recurring( OccurrenceDelivery::SCHEDULE_HOOK, $interval, array( $schedule_identity ), $next_due, $schedule_identity, priority: self::TICK_PRIORITY );
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
			$replacement = $this->registry->replace_scope( $scope, $declared, $next );
			if ( ScopeReplacementOutcome::Persisted !== $replacement ) {
				return $this->registry_replacement_failure( $scope, $replacement );
			}
		}

		// Backend convergence precedes marker reset so only a successful declaration refresh ends the zombie episode.
		$replacement = $this->registry->replace_scope( $scope, $declared, $next, reset_undeclared_episodes: true );
		if ( ScopeReplacementOutcome::Persisted !== $replacement ) {
			return $this->registry_replacement_failure( $scope, $replacement );
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
	 * @param   Identity $identity Complete scope-qualified schedule identity.
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
	 * Returns a failed registry-read result for one scope.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scope Stable client identifier.
	 *
	 * @return  Failure<SchedulingError>
	 */
	private function registry_read_failure( string $scope ): Failure {
		return new Failure( SchedulingError::registry_read_failure( $scope ) );
	}

	/**
	 * Returns the public failure for one classified scope-row replacement.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $scope   Stable client identifier.
	 * @param   ScopeReplacementOutcome $outcome Classified failed replacement.
	 *
	 * @return  Failure<SchedulingError>
	 */
	private function registry_replacement_failure( string $scope, ScopeReplacementOutcome $outcome ): Failure {
		if ( ScopeReplacementOutcome::Corrupt === $outcome ) {
			return new Failure( SchedulingError::registry_corrupt( $scope, ScheduleRegistry::option_name( $scope ) ) );
		}

		return new Failure( SchedulingError::registry_persist_failure( $scope ) );
	}

	// endregion
}

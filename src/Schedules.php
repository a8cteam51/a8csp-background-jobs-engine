<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine;

use A8C\SpecialProjects\BackgroundTasksEngine\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\SchedulingErrorReason;
use Psr\Clock\ClockInterface;

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
	 * @param   ScheduleRegistry $registry  Owner-scoped schedule registry.
	 * @param   BackendInterface $scheduler Scheduling backend facade.
	 * @param   ClockInterface   $clock     Current-time source.
	 */
	public function __construct(
		private ScheduleRegistry $registry,
		private BackendInterface $scheduler,
		private ClockInterface $clock,
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

	// endregion

	// region HELPERS

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

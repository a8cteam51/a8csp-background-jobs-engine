<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\SchedulingErrorReason;
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
	 * @param   ScheduleRegistry   $registry            Owner-scoped schedule registry.
	 * @param   BackendInterface   $scheduler           Scheduling backend facade.
	 * @param   ClockInterface     $clock               Current-time source.
	 * @param   OccurrenceDelivery $occurrence_delivery Schedule occurrence delivery service.
	 */
	public function __construct( private ScheduleRegistry $registry, private BackendInterface $scheduler, private ClockInterface $clock, private OccurrenceDelivery $occurrence_delivery ) {}

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
	 * @internal Engine maintenance only.
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
	public function sync_owner( string $owner, array $schedules ): AbstractResult {
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
			$interval = $schedule->recurrence->interval();
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
							'Schedule "%s" first occurrence falls outside positive supported Unix seconds; correct the system clock or pass a smaller Recurrence::every() value.',
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
					'a8csp_background_tasks/schedule_due',
					array( $registration_key ),
					$registration_key
				) ) {
					continue;
				}

				$recreated = $this->scheduler->schedule_recurring(
					'a8csp_background_tasks/schedule_due',
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
				'a8csp_background_tasks/schedule_due',
				array( $registration_key ),
				$registration_key
			);
			if ( null !== $current || $backend_occurrence_exists ) {
				$removed = $this->scheduler->unschedule(
					'a8csp_background_tasks/schedule_due',
					array( $registration_key ),
					$registration_key
				);
				if ( $removed->is_failure() ) {
					return $this->replacement_clear_failure( $schedule );
				}
			}

			$interval      = $interval_by_name[ $name ];
			$next_due      = $next_due_by_name[ $name ];
			$next[ $name ] = array(
				'fingerprint' => $schedule->fingerprint(),
				'next_due'    => $next_due,
				'last_fired'  => null,
				'misfires'    => 0,
				'skips'       => 0,
			);
			if ( ! $this->registry->replace_owner( $owner, $declared, $next ) ) {
				return $this->registry_failure( $owner );
			}

			$scheduled = $this->scheduler->schedule_recurring(
				'a8csp_background_tasks/schedule_due',
				$interval,
				array( $registration_key ),
				$next_due,
				$registration_key,
				unique: true,
				priority: $schedule->priority
			);
			if ( $scheduled->is_failure() ) {
				// A scheduling failure leaves the benign registration-without-chain that the fingerprint-match fast path
				// recreates; rolling back can race a delivery and manufacture chain-without-registration, the exact orphan
				// the intent protocol cleans.
				return $scheduled;
			}
		}

		foreach ( \array_keys( \array_diff_key( $existing, $declared ) ) as $name ) {
			$registration_key = $owner . ':' . $name;
			$removed          = $this->scheduler->unschedule(
				'a8csp_background_tasks/schedule_due',
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
	 * Immediately dispatches one declared schedule target without changing its recurrence.
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
		return $this->occurrence_delivery->run_now_under_lease( $owner, $name );
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the unsupported-recurrence failure for a cron declaration.
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
				'Schedule "%s" uses a cron expression unavailable through the v1 recurring-interval port; use Recurrence::every().',
				$schedule->name
			)
			: \sprintf(
				'Schedule "%s" uses a cron expression unsupported by every ready backend; use Recurrence::every() or configure a backend that supports cron expressions.',
				$schedule->name
			);

		return new Failure(
			new SchedulingError(
				SchedulingErrorReason::UnsupportedRecurrence,
				$message,
				array(
					'schedule'   => $schedule->name,
					'expression' => $schedule->recurrence->expression(),
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
			SchedulingError::registry_read( $owner )
		);
	}

	// endregion
}

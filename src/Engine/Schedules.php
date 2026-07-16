<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkIdentity;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Consumer API for declarative recurring task schedules.
 *
 * @internal
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
	 * declared schedules, persisted registrations, and backend occurrences. Transient failures remain
	 * result data because the next initialization retries. An occurrence stored in a backend that is
	 * not ready during sync outlives the registration until its next delivery self-removes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param  array<string, array{schedule: Schedule, task: string}> $declarations
	 * @phpstan-return AbstractResult<true, SchedulingError>
	 *
	 * @param   string $owner        Stable consumer identifier captured by the owner-bound facade.
	 * @param   array  $declarations Complete schedule declaration keyed by owner-qualified identity.
	 *
	 * @throws  \InvalidArgumentException When the owner, declaration, schedule identity, or target identity is invalid.
	 *
	 * @return  AbstractResult
	 */
	#[\NoDiscard( 'a schedule-sync failure must be handled, not dropped' )]
	public function sync( string $owner, array $declarations ): AbstractResult {
		WorkIdentity::validate_owner( $owner );

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
	 * @phpstan-param  array<string, array{schedule: Schedule, task: string}> $declarations
	 * @phpstan-return AbstractResult<true, SchedulingError>
	 *
	 * @param   string $owner        Stable consumer or engine identifier.
	 * @param   array  $declarations Complete schedule declaration keyed by owner-qualified identity.
	 *
	 * @throws  \InvalidArgumentException When the owner, declaration, schedule identity, or target identity is invalid.
	 *
	 * @return  AbstractResult
	 */
	public function sync_owner( string $owner, array $declarations ): AbstractResult {
		WorkIdentity::validate_owner( $owner, true );

		$declared = array();
		foreach ( $declarations as $schedule_identity => $declaration ) {
			if ( ! \is_string( $schedule_identity ) ) {
				throw new \InvalidArgumentException( 'Schedule sync declaration keys must be canonical owner-qualified schedule identities.' );
			}

			$registration_parts = WorkIdentity::parts( $schedule_identity );
			if ( null === $registration_parts || $owner !== $registration_parts[0] ) {
				throw new \InvalidArgumentException( 'Schedule sync declaration identities must be canonical and belong to the bound owner.' );
			}

			$schedule = \is_array( $declaration ) ? ( $declaration['schedule'] ?? null ) : null;
			if ( ! $schedule instanceof Schedule ) {
				throw new \InvalidArgumentException( 'Schedule sync accepts only Schedule value objects; construct each declaration with new Schedule(...).' );
			}

			if ( $schedule->name !== $registration_parts[1] ) {
				throw new \InvalidArgumentException( 'Schedule sync declaration identities must match their Schedule value-object names.' );
			}

			$task = $declaration['task'] ?? null;
			if ( ! \is_string( $task ) ) {
				throw new \InvalidArgumentException( 'Schedule sync target identities must be canonical owner-qualified task identities.' );
			}

			$task_parts = WorkIdentity::parts( $task );
			if ( null === $task_parts || $owner !== $task_parts[0] || $schedule->task !== $task_parts[1] ) {
				throw new \InvalidArgumentException( 'Schedule sync target identities must be canonical, belong to the bound owner, and match their Schedule value-object task names.' );
			}

			$declared[ $schedule_identity ] = array(
				'schedule' => $schedule,
				'task'     => $task,
			);
		}

		$registrations = $this->registry->registrations_for( $owner );
		if ( $registrations->is_failure() ) {
			return $this->registry_read_failure( $owner );
		}

		$existing             = $registrations->value;
		$interval_by_identity = array();
		$next_due_by_identity = array();
		foreach ( $declared as $schedule_identity => $declaration ) {
			$schedule = $declaration['schedule'];
			$interval = $schedule->recurrence->interval();
			if ( null === $interval ) {
				return $this->cron_failure( $schedule );
			}

			$interval_by_identity[ $schedule_identity ] = $interval;
			$current                                    = $existing[ $schedule_identity ] ?? null;
			if ( null !== $current && $schedule->fingerprint() === $current['fingerprint'] ) {
				continue;
			}

			$now      = $this->clock->now()->getTimestamp();
			$next_due = $now > \PHP_INT_MAX - $interval ? null : $now + $interval;
			if ( null === $next_due || 1 > $next_due ) {
				return new Failure(
					new SchedulingError(
						SchedulingErrorReason::InvalidTimeInput,
						\sprintf( 'Schedule "%s" first occurrence falls outside positive supported Unix seconds; correct the system clock or pass a smaller Recurrence::every() value.', $schedule->name ),
						array(
							'current_timestamp' => $now,
							'interval'          => $interval,
						),
					)
				);
			}

			$next_due_by_identity[ $schedule_identity ] = $next_due;
		}

		$next = $existing;
		foreach ( $declared as $schedule_identity => $declaration ) {
			$schedule = $declaration['schedule'];
			$current  = $existing[ $schedule_identity ] ?? null;
			if ( null !== $current && $schedule->fingerprint() === $current['fingerprint'] ) {
				if ( $this->scheduler->is_scheduled( OccurrenceDelivery::SCHEDULE_HOOK, array( $schedule_identity ), $schedule_identity ) ) {
					continue;
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
				'fingerprint' => $schedule->fingerprint(),
				'next_due'    => $next_due,
				'last_fired'  => null,
				'misfires'    => 0,
				'skips'       => 0,
			);
			if ( ! $this->registry->replace_owner( $owner, $declared, $next ) ) {
				return $this->registry_persist_failure( $owner );
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
			if ( ! $this->registry->replace_owner( $owner, $declared, $next ) ) {
				return $this->registry_persist_failure( $owner );
			}
		}

		if ( ! $this->registry->replace_owner( $owner, $declared, $next ) ) {
			return $this->registry_persist_failure( $owner );
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
	 * @param   string $registration_key Complete owner-qualified schedule identity.
	 *
	 * @throws  \InvalidArgumentException When the schedule identity is not canonical.
	 *
	 * @return  AbstractResult<string, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a schedule dispatch-now failure must be handled, not dropped' )]
	public function dispatch_now( string $registration_key ): AbstractResult {
		if ( null === WorkIdentity::parts( $registration_key ) ) {
			throw new \InvalidArgumentException( 'Schedule identity is invalid; pass one canonical {owner}:{name} identity.' );
		}

		return $this->occurrence_delivery->dispatch_now_under_lease( $registration_key );
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
			? \sprintf( 'Schedule "%s" uses a cron expression unavailable through the v1 recurring-interval port; use Recurrence::every().', $schedule->name )
			: \sprintf( 'Schedule "%s" uses a cron expression unsupported by every ready backend; use Recurrence::every() or configure a backend that supports cron expressions.', $schedule->name );

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
		return new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, \sprintf( 'Schedule "%s" cannot be replaced; retry the sync; the previous occurrence could not be confirmed removed.', $schedule->name ), array( 'schedule' => $schedule->name ), ) );
	}

	/**
	 * Returns a failed registry-read result for one owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Stable consumer identifier.
	 *
	 * @return  Failure<SchedulingError>
	 */
	private function registry_read_failure( string $owner ): Failure {
		return new Failure( SchedulingError::registry_read_failure( $owner ) );
	}

	/**
	 * Returns a failed registry-persist result for one owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Stable consumer identifier.
	 *
	 * @return  Failure<SchedulingError>
	 */
	private function registry_persist_failure( string $owner ): Failure {
		return new Failure( SchedulingError::registry_persist_failure( $owner ) );
	}

	// endregion
}

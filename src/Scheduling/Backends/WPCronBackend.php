<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Backends;

use A8C\SpecialProjects\BackgroundTasksEngine\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\SchedulingErrorReason;

\defined( 'ABSPATH' ) || exit;

/**
 * Scheduling backend over WordPress cron.
 *
 * WP-Cron addresses recurring events through named schedules, so each interval receives a
 * synthetic name. The schedule filter combines intervals registered during this request with
 * names reconstructed from stored cron events so recurring events remain resolvable later.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class WPCronBackend implements BackendInterface {
	// region FIELDS AND CONSTANTS

	private const SCHEDULE_PREFIX  = 'a8csp_bgte_every_';
	private const SCHEDULE_PATTERN = '/^a8csp_bgte_every_([1-9]\d*)s$/';
	private const SCHEDULES_FILTER = 'cron_schedules';

	/**
	 * Intervals registered during this request, used as a set.
	 *
	 * @var array<int, true>
	 */
	private array $registered_intervals = array();

	/**
	 * Whether this instance has registered its schedule filter.
	 *
	 * @var bool
	 */
	private bool $schedules_filter_registered = false;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function schedule_recurring( string $hook, int $interval, array $args = array(), ?int $first_run_timestamp = null, string $group = '', int $priority = 10 ): AbstractResult {
		$group_failure = $this->reject_group( $group );
		if ( null !== $group_failure ) {
			return $group_failure;
		}

		if ( 1 > $interval ) {
			return new Failure(
				new SchedulingError(
					SchedulingErrorReason::InvalidInterval,
					'WP-Cron requires recurring intervals greater than zero; use at least one second.',
					array( 'interval' => $interval ),
				)
			);
		}

		$schedule = $this->ensure_schedule( $interval );
		$result   = \wp_schedule_event( $first_run_timestamp ?? \time(), $schedule, $hook, $args, true );

		return $this->result_for_wp_write( $result, $hook, 'schedule' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function schedule_single( string $hook, int $timestamp, array $args = array(), string $group = '', int $priority = 10 ): AbstractResult {
		$group_failure = $this->reject_group( $group );
		if ( null !== $group_failure ) {
			return $group_failure;
		}

		$result = \wp_schedule_single_event( $timestamp, $hook, $args, true );

		return $this->result_for_wp_write( $result, $hook, 'schedule' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function enqueue_async( string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): AbstractResult {
		$group_failure = $this->reject_group( $group );
		if ( null !== $group_failure ) {
			return $group_failure;
		}

		if ( $unique && false !== \wp_next_scheduled( $hook, $args ) ) {
			return new Success( true );
		}

		return $this->schedule_single( $hook, \time(), $args, priority: $priority );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule( string $hook, array $args = array(), string $group = '' ): AbstractResult {
		$group_failure = $this->reject_group( $group );
		if ( null !== $group_failure ) {
			return $group_failure;
		}

		$wp_error = null;
		foreach ( $this->matching_timestamps( $hook, $args ) as $timestamp ) {
			$result = \wp_unschedule_event( $timestamp, $hook, $args, true );
			if ( $result instanceof \WP_Error ) {
				$wp_error ??= $result;
			}
		}

		if ( array() === $this->matching_timestamps( $hook, $args ) ) {
			return new Success( true );
		}

		if ( null !== $wp_error ) {
			return $this->result_for_wp_write( $wp_error, $hook, 'unschedule' );
		}

		return new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				\sprintf(
					'WP-Cron still has hook "%s" scheduled; repair the WordPress cron event and retry unscheduling.',
					$hook
				),
				array( 'hook' => $hook ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function is_scheduled( string $hook, array $args = array(), string $group = '' ): bool {
		return false !== \wp_next_scheduled( $hook, $args );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function get_next_scheduled( string $hook, array $args = array(), string $group = '' ): ?int {
		$next = \wp_next_scheduled( $hook, $args );

		return false === $next ? null : $next;
	}

	/**
	 * {@inheritDoc}
	 *
	 * WP-Cron remains consultable whenever WordPress core is loaded.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function is_ready(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function register_hooks(): void {
		if ( $this->schedules_filter_registered ) {
			return;
		}

		\add_filter( self::SCHEDULES_FILTER, array( $this, 'register_synthetic_schedules' ), 10, 1 );
		$this->schedules_filter_registered = true;
	}

	// endregion

	// region HOOKS

	/**
	 * Adds every active synthetic interval to WordPress's schedules.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 *
	 * @return  array<string, array{interval: int, display: string}>
	 */
	public function register_synthetic_schedules( array $schedules ): array {
		foreach ( $this->active_intervals() as $interval ) {
			$schedules[ $this->schedule_name( $interval ) ] = array(
				'interval' => $interval,
				'display'  => \sprintf(
					/* translators: %d: interval in seconds. */
					\__( 'Every %d seconds', 'a8csp-background-tasks-engine' ),
					$interval
				),
			);
		}

		return $schedules;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns a failure when WP-Cron cannot honor the requested group.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $group Requested group.
	 *
	 * @return  Failure<SchedulingError>|null
	 */
	private function reject_group( string $group ): ?Failure {
		if ( '' === $group ) {
			return null;
		}

		return new Failure(
			new SchedulingError(
				SchedulingErrorReason::UnsupportedGroup,
				'WP-Cron has no groups; use the Action Scheduler backend or drop the group.',
				array( 'group' => $group ),
			)
		);
	}

	/**
	 * Registers an interval before WordPress validates the recurrence name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $interval Positive interval in seconds.
	 *
	 * @return  string
	 */
	private function ensure_schedule( int $interval ): string {
		$this->registered_intervals[ $interval ] = true;
		$this->register_hooks();

		return $this->schedule_name( $interval );
	}

	/**
	 * Returns the synthetic name for an interval.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $interval Positive interval in seconds.
	 *
	 * @return  string
	 */
	private function schedule_name( int $interval ): string {
		return self::SCHEDULE_PREFIX . $interval . 's';
	}

	/**
	 * Rebuilds the distinct active interval set from request and persisted cron state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<int>
	 */
	private function active_intervals(): array {
		$intervals = $this->registered_intervals;

		foreach ( $this->cron_array() as $hooks ) {
			if ( ! \is_array( $hooks ) ) {
				continue;
			}

			foreach ( $hooks as $events ) {
				if ( ! \is_array( $events ) ) {
					continue;
				}

				foreach ( $events as $event ) {
					if ( ! \is_array( $event ) ) {
						continue;
					}

					$schedule = $event['schedule'] ?? null;
					if ( ! \is_string( $schedule ) || 1 !== \preg_match( self::SCHEDULE_PATTERN, $schedule, $matches ) ) {
						continue;
					}

					$intervals[ (int) $matches[1] ] = true;
				}
			}
		}

		$active = \array_keys( $intervals );
		\sort( $active, SORT_NUMERIC );

		return $active;
	}

	/**
	 * Returns the current persisted cron array.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<mixed>
	 */
	private function cron_array(): array {
		// WP-Cron persists its schedule in the cron option, so snapshots read that public contract.
		$cron_array = \get_option( 'cron', array() );

		return \is_array( $cron_array ) ? $cron_array : array();
	}

	/**
	 * Returns stored timestamps matching a hook and its serialized arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $hook Hook to match.
	 * @param   list<mixed> $args Arguments identifying the event.
	 *
	 * @return  list<int>
	 */
	private function matching_timestamps( string $hook, array $args ): array {
		$timestamps      = array();
		$serialized_args = \maybe_serialize( $args );

		foreach ( $this->cron_array() as $timestamp => $hooks ) {
			if ( ! \is_int( $timestamp ) || ! \is_array( $hooks ) ) {
				continue;
			}

			$events = $hooks[ $hook ] ?? null;
			if ( ! \is_array( $events ) ) {
				continue;
			}

			foreach ( $events as $event ) {
				if ( ! \is_array( $event ) ) {
					continue;
				}

				$event_args = $event['args'] ?? null;
				if ( ! \is_array( $event_args ) || \maybe_serialize( $event_args ) !== $serialized_args ) {
					continue;
				}

				$timestamps[] = $timestamp;
			}
		}

		\sort( $timestamps, SORT_NUMERIC );

		return $timestamps;
	}

	/**
	 * Maps a WordPress write result into the backend's result contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   bool|\WP_Error          $result    WordPress write result.
	 * @param   string                  $hook      Hook being changed.
	 * @param   'schedule'|'unschedule' $operation Requested operation.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	private function result_for_wp_write( bool|\WP_Error $result, string $hook, string $operation ): AbstractResult {
		if ( true === $result ) {
			return new Success( true );
		}

		if ( false === $result ) {
			return new Failure(
				new SchedulingError(
					SchedulingErrorReason::ScheduleFailed,
					\sprintf(
						'WP-Cron could not %1$s hook "%2$s"; repair the WordPress cron event and retry.',
						$operation,
						$hook
					),
					array( 'hook' => $hook ),
				)
			);
		}

		$message = match ( $result->get_error_code() ) {
			'duplicate_event' => \sprintf(
				'WP-Cron could not %1$s hook "%2$s": an identical hook+args event exists within WP-Cron\'s ten-minute duplicate window; make the args unique or use unique: true to accept deduplication.',
				$operation,
				$hook
			),
			'invalid_schedule' => \sprintf(
				'WP-Cron could not %1$s hook "%2$s": the recurrence is not registered; ensure register_hooks() ran on this request.',
				$operation,
				$hook
			),
			default => \sprintf(
				'WP-Cron could not %1$s hook "%2$s"; fix the WordPress cron error and retry: %3$s',
				$operation,
				$hook,
				$result->get_error_message()
			),
		};

		return new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				$message,
				array(
					'hook'     => $hook,
					'wp_error' => $result->get_error_message(),
				)
			)
		);
	}

	// endregion
}

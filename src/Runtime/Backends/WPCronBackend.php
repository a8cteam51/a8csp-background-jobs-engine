<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;

\defined( 'ABSPATH' ) || exit;

/**
 * Scheduling backend over WordPress cron.
 *
 * WP-Cron addresses recurring events through named schedules, so each interval receives a
 * synthetic name. The schedule filter combines intervals registered during this request with
 * names reconstructed from stored cron events so recurring events remain resolvable later.
 * WP-Cron's complete event identity is the hook plus serialized arguments. Groups and priorities
 * are advisory dimensions: backends honor each where their stores can express it, while this
 * backend accepts and ignores both because rejecting a group breaks facade failover. The backend
 * operates on WordPress's default cron-option store; replacements wired through Core's pre_* cron
 * filters sit outside its clearance guarantee.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class WPCronBackend implements BackendInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * Prefix for synthetic recurrence schedule names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const string SCHEDULE_PREFIX = 'a8csp_bgje_every_';

	/**
	 * Pattern matching valid synthetic recurrence schedule names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const string SCHEDULE_PATTERN = '/^a8csp_bgje_every_([1-9]\d*)s$/';

	/**
	 * WordPress filter that supplies registered recurrence schedules.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const string SCHEDULES_FILTER = 'cron_schedules';

	/**
	 * Intervals registered during this request, used as a set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<int, true>
	 */
	private array $registered_intervals = array();

	/**
	 * Request-local active interval snapshot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<int>|null
	 */
	private ?array $active_intervals_cache = null;

	/**
	 * Whether this instance has registered its schedule filter.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     bool
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
		if ( 1 > $interval ) {
			return new Failure( new SchedulingError( SchedulingErrorReason::InvalidTimeInput, 'WP-Cron requires recurring intervals greater than zero; use at least one second.', array( 'interval' => $interval ), ) );
		}

		$next_scheduled = \wp_next_scheduled( $hook, $args );
		if ( false !== $next_scheduled ) {
			return new Success( true );
		}

		$schedule = $this->ensure_schedule( $interval );
		$result   = \wp_schedule_event( $first_run_timestamp ?? \time(), $schedule, $hook, $args, true );

		return $this->result_for_wp_write( $result, $hook, 'schedule' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The identity precheck spans every timestamp, which is broader than the window WordPress scans for
	 * a duplicate, so a duplicate error means another writer landed the identical event after that
	 * check. Accepting it matches the asynchronous write: the delivery exists either way, and reporting
	 * a failure would terminalize a run whose next delivery is already scheduled.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function schedule_single( string $hook, int $timestamp, array $args = array(), string $group = '', int $priority = 10 ): AbstractResult {
		$next_scheduled = \wp_next_scheduled( $hook, $args );
		if ( false !== $next_scheduled ) {
			return new Success( true );
		}

		$result = \wp_schedule_single_event( $timestamp, $hook, $args, true );
		if ( $result instanceof \WP_Error && 'duplicate_event' === $result->get_error_code() ) {
			return new Success( true );
		}

		return $this->result_for_wp_write( $result, $hook, 'schedule' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * WP-Cron suppresses identical single events inside its ten-minute window, so a duplicate error
	 * accepts the pending event when another writer wins after the identity precheck.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function enqueue_async( string $hook, array $args = array(), string $group = '', int $priority = 10 ): AbstractResult {
		if ( false !== \wp_next_scheduled( $hook, $args ) ) {
			return new Success( true );
		}

		$result = \wp_schedule_single_event( \time(), $hook, $args, true );
		if ( $result instanceof \WP_Error && 'duplicate_event' === $result->get_error_code() ) {
			return new Success( true );
		}

		return $this->result_for_wp_write( $result, $hook, 'schedule' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * WP-Cron stores no groups, so the hook plus serialized arguments forms the complete event
	 * identity and the group dimension is a no-op.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule( string $hook, array $args = array(), string $group = '' ): AbstractResult {
		// WP-Cron has no group dimension, so a group-only clear cannot identify an event safely.
		if ( '' === $hook && array() === $args && '' !== $group ) {
			return new Success( true );
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

		return new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, \sprintf( 'WP-Cron still has hook "%s" scheduled; repair the WordPress cron event and retry unscheduling.', $hook ), array( 'hook' => $hook ), ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * WP-Cron stores no groups, so the work identity and run ID are selected from each delivery's
	 * persisted arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $hook     Delivery hook.
	 * @param   string $identity Complete work identity.
	 * @param   string $run_id   Run identifier.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule_run( string $hook, string $identity, string $run_id ): AbstractResult {
		$wp_error = null;
		foreach ( $this->matching_run_events( $hook, $identity, $run_id ) as $event ) {
			$result = \wp_unschedule_event( $event['timestamp'], $hook, $event['args'], true );
			if ( $result instanceof \WP_Error ) {
				$wp_error ??= $result;
			}
		}

		if ( array() === $this->matching_run_events( $hook, $identity, $run_id ) ) {
			return new Success( true );
		}

		if ( null !== $wp_error ) {
			return $this->result_for_wp_write( $wp_error, $hook, 'unschedule' );
		}

		return new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				\sprintf( 'WP-Cron still has a pending delivery for run "%1$s" on hook "%2$s"; repair the WordPress cron event and retry unscheduling.', $run_id, $hook ),
				array(
					'hook'     => $hook,
					'identity' => $identity,
					'run_id'   => $run_id,
				),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<int, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule_hooks( array $hooks ): AbstractResult {
		$count = 0;
		foreach ( \array_values( \array_unique( $hooks ) ) as $hook ) {
			$result = \wp_unschedule_hook( $hook, true );
			if ( $result instanceof \WP_Error ) {
				return $this->failure_for_wp_error( $result, $hook, 'unschedule' );
			}
			if ( ! \is_int( $result ) || 0 !== $this->hook_occurrence_count( $hook ) ) {
				return new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, \sprintf( 'WP-Cron still has hook "%s" scheduled; repair the WordPress cron event and retry unscheduling.', $hook ), array( 'hook' => $hook ), ) );
			}

			$count += $result;
		}

		return new Success( $count );
	}

	/**
	 * {@inheritDoc}
	 *
	 * WP-Cron stores no groups, so one cron-option snapshot can bucket every exact serialized
	 * one-identity argument list.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{count: int<0, max>, interval: positive-int|null}>
	 */
	#[\Override]
	public function scheduled_chains( string $hook, array $identities ): array {
		$counts                      = array();
		$intervals                   = array();
		$identity_by_serialized_args = array();
		foreach ( $identities as $identity ) {
			$counts[ $identity ]    = 0;
			$intervals[ $identity ] = null;
			$serialized_args        = \maybe_serialize( array( $identity ) );
			if ( \is_string( $serialized_args ) ) {
				$identity_by_serialized_args[ $serialized_args ] = $identity;
			}
		}

		if ( array() === $counts ) {
			return array();
		}

		foreach ( $this->events_for_hook( $hook ) as $stored_event ) {
			$event_args = $stored_event['event']['args'] ?? null;
			if ( ! \is_array( $event_args ) ) {
				continue;
			}

			$serialized_args = \maybe_serialize( $event_args );
			if ( ! \is_string( $serialized_args ) || ! \array_key_exists( $serialized_args, $identity_by_serialized_args ) ) {
				continue;
			}

			$identity = $identity_by_serialized_args[ $serialized_args ];
			++$counts[ $identity ];

			$interval               = $stored_event['event']['interval'] ?? null;
			$intervals[ $identity ] = \is_int( $interval ) && 0 < $interval ? $interval : null;
		}

		$chains = array();
		foreach ( $counts as $counted_identity => $count ) {
			$chains[ $counted_identity ] = array(
				'count'    => $count,
				// Several events are the surplus the caller already replaces, so no single cadence represents the identity.
				'interval' => 1 === $count ? $intervals[ $counted_identity ] : null,
			);
		}

		return $chains;
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
	 *
	 * @return  bool
	 */
	#[\Override]
	public function is_absent(): bool {
		return false;
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
				'display'  => \sprintf( /* translators: %d: interval in seconds. */ \__( 'Every %d seconds', 'a8csp-background-jobs-engine' ), $interval ),
			);
		}

		return $schedules;
	}

	// endregion

	// region HELPERS

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
		if ( ! isset( $this->registered_intervals[ $interval ] ) ) {
			$this->registered_intervals[ $interval ] = true;
			$this->active_intervals_cache            = null;
		}
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
		if ( null !== $this->active_intervals_cache ) {
			return $this->active_intervals_cache;
		}

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

		$this->active_intervals_cache = $active;

		return $this->active_intervals_cache;
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
	 * Returns readable stored events for one hook with their timestamps.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $hook Hook to match.
	 *
	 * @return  list<array{timestamp: int, event: array<mixed>}>
	 */
	private function events_for_hook( string $hook ): array {
		$stored_events = array();
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

				$stored_events[] = array(
					'timestamp' => $timestamp,
					'event'     => $event,
				);
			}
		}

		return $stored_events;
	}

	/**
	 * Returns stored delivery events belonging to one run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $hook     Delivery hook.
	 * @param   string $identity Complete work identity.
	 * @param   string $run_id   Run identifier.
	 *
	 * @return  list<array{timestamp: int, args: list<mixed>}>
	 */
	private function matching_run_events( string $hook, string $identity, string $run_id ): array {
		$matching = array();
		foreach ( $this->events_for_hook( $hook ) as $stored_event ) {
			$args = $stored_event['event']['args'] ?? null;
			if ( ! \is_array( $args ) || ! \array_is_list( $args ) ) {
				continue;
			}

			$delivery_identity = $args[0] ?? null;
			$delivery_run_id   = $args[1] ?? null;
			if ( $identity !== $delivery_identity || $run_id !== $delivery_run_id ) {
				continue;
			}

			$matching[] = array(
				'timestamp' => $stored_event['timestamp'],
				'args'      => $args,
			);
		}

		\usort( $matching, static fn ( array $left, array $right ): int => $left['timestamp'] <=> $right['timestamp'] );

		return $matching;
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

		foreach ( $this->events_for_hook( $hook ) as $stored_event ) {
			$event_args = $stored_event['event']['args'] ?? null;
			if ( ! \is_array( $event_args ) || \maybe_serialize( $event_args ) !== $serialized_args ) {
				continue;
			}

			$timestamps[] = $stored_event['timestamp'];
		}

		\sort( $timestamps, SORT_NUMERIC );

		return $timestamps;
	}

	/**
	 * Counts every stored occurrence of one hook regardless of arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $hook Hook to count.
	 *
	 * @return  int
	 */
	private function hook_occurrence_count( string $hook ): int {
		$count = 0;
		foreach ( $this->cron_array() as $hooks ) {
			if ( ! \is_array( $hooks ) ) {
				continue;
			}

			$events = $hooks[ $hook ] ?? null;
			if ( \is_array( $events ) ) {
				$count += \count( $events );
			}
		}

		return $count;
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
			return new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, \sprintf( 'WP-Cron could not %1$s hook "%2$s"; repair the WordPress cron event and retry.', $operation, $hook ), array( 'hook' => $hook ), ) );
		}

		return $this->failure_for_wp_error( $result, $hook, $operation );
	}

	/**
	 * Maps one WordPress cron write error to the engine scheduling error contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \WP_Error $result    WordPress cron error.
	 * @param   string    $hook      Hook being written.
	 * @param   string    $operation Write operation.
	 *
	 * @return  Failure<SchedulingError>
	 */
	private function failure_for_wp_error( \WP_Error $result, string $hook, string $operation ): Failure {
		$message = match ( $result->get_error_code() ) {
			'duplicate_event' => \sprintf( 'WP-Cron could not %1$s hook "%2$s": an identical hook+args event exists within WP-Cron\'s ten-minute duplicate window; use arguments that identify a distinct event or retry after the existing event clears.', $operation, $hook ),
			'invalid_schedule' => \sprintf( 'WP-Cron could not %1$s hook "%2$s": the recurrence is not registered; ensure register_hooks() ran on this request.', $operation, $hook ),
			default => \sprintf( 'WP-Cron could not %1$s hook "%2$s"; inspect the WordPress cron error, correct the rejected event, and retry.', $operation, $hook ),
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

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;

\defined( 'ABSPATH' ) || exit;

/**
 * Backend-agnostic scheduling facade over backends in declaration order.
 *
 * Writes target the first ready backend, while reads and clears span every currently ready
 * backend. Hook registration remains unconditional so persisted work keeps resolving when backend
 * preference changes. Before action_scheduler_init, writes fall through to WP-Cron even when
 * Action Scheduler is installed because routing follows per-request readiness.
 *
 * Action Scheduler treats an empty group as unconstrained in queries but exact in unique inserts.
 * For unscheduling, empty arguments plus only a hook clear that hook, while empty arguments plus
 * only a group clear that group. This facade preserves those native empty-value semantics.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class SchedulerFacade implements BackendInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * The guard accepts only portable arguments whose JSON form is no larger than 8,000 bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_ARGUMENTS_JSON_LENGTH = 8_000;

	/**
	 * Matching the JSON encoder's depth keeps recursive shape validation finite.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_ARGUMENTS_JSON_DEPTH = 512;

	/**
	 * Backends in declaration order for write preference, consultation, and failure precedence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     non-empty-list<BackendInterface>
	 */
	private array $backends;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<BackendInterface> $backends Backends in preference order; values are reindexed and keys are ignored.
	 *
	 * @throws  \InvalidArgumentException When no scheduling backend is supplied.
	 */
	public function __construct( array $backends ) {
		if ( array() === $backends ) {
			throw new \InvalidArgumentException( 'SchedulerFacade requires at least one backend; pass the WP-Cron backend as the final fallback.' );
		}

		$this->backends = \array_values( $backends );
	}

	// endregion

	// region METHODS

	/**
	 * Clears matching hooks and reports the authority of the same readiness snapshot.
	 *
	 * @internal Unknown-schedule convergence only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $hook  Hook to unschedule.
	 * @param   list<mixed> $args  Arguments identifying the scheduled hook.
	 * @param   string      $group Backend grouping label.
	 *
	 * @return  BackendClearance
	 */
	#[\NoDiscard( 'a convergence clear result must be handled, not dropped' )]
	public function unschedule_for_convergence( string $hook, array $args = array(), string $group = '' ): BackendClearance {
		$ready_backends = $this->ready_backends();

		return new BackendClearance( $this->unschedule_snapshot( $ready_backends, $hook, $args, $group ), $this->snapshot_is_authoritative( $ready_backends ) );
	}

	/**
	 * Clears every pending action in one backend group.
	 *
	 * @internal Engine run cancellation only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $group Backend grouping label.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule_group( string $group ): AbstractResult {
		return $this->unschedule_snapshot( $this->ready_backends(), '', array(), $group );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Hook-wide clearance requires every present backend to be ready because reset callers cannot
	 * retain dormant pending work safely.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<int, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule_hooks( array $hooks ): AbstractResult {
		$ready_backends = $this->ready_backends();
		if ( ! $this->snapshot_is_authoritative( $ready_backends ) ) {
			return new Failure( new SchedulingError( SchedulingErrorReason::BackendNotReady, 'Every present scheduling backend must be ready before hook-wide clearance; initialize the dormant backend and retry.' ) );
		}

		$count         = 0;
		$first_failure = null;
		foreach ( $ready_backends as $backend ) {
			$result = $backend->unschedule_hooks( $hooks );
			if ( $result->is_failure() ) {
				$first_failure ??= $result;
				continue;
			}

			$count += $result->value;
		}

		return $first_failure ?? new Success( $count );
	}

	/**
	 * Returns whether any configured backend is present but unavailable for reads.
	 *
	 * @internal Read-only engine inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	public function has_dormant_candidate(): bool {
		return ! $this->snapshot_is_authoritative( $this->ready_backends() );
	}

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
		if ( null !== $first_run_timestamp && 1 > $first_run_timestamp ) {
			return $this->timestamp_failure( $hook, 'first_run_timestamp', $first_run_timestamp );
		}

		$payload_failure = $this->payload_failure( $hook, $args );
		if ( null !== $payload_failure ) {
			return $payload_failure;
		}

		return $this->write( static fn ( BackendInterface $backend ): AbstractResult => $backend->schedule_recurring( $hook, $interval, $args, $first_run_timestamp, $group, $priority ) );
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
		if ( 1 > $timestamp ) {
			return $this->timestamp_failure( $hook, 'timestamp', $timestamp );
		}

		$payload_failure = $this->payload_failure( $hook, $args );
		if ( null !== $payload_failure ) {
			return $payload_failure;
		}

		return $this->write( static fn ( BackendInterface $backend ): AbstractResult => $backend->schedule_single( $hook, $timestamp, $args, $group, $priority ) );
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
	public function enqueue_async( string $hook, array $args = array(), string $group = '', int $priority = 10 ): AbstractResult {
		$payload_failure = $this->payload_failure( $hook, $args );
		if ( null !== $payload_failure ) {
			return $payload_failure;
		}

		return $this->write( static fn ( BackendInterface $backend ): AbstractResult => $backend->enqueue_async( $hook, $args, $group, $priority ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Success confirms absence across the currently-ready backends.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule( string $hook, array $args = array(), string $group = '' ): AbstractResult {
		return $this->unschedule_snapshot( $this->ready_backends(), $hook, $args, $group );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The total spans every currently ready backend so same-backend and cross-backend surpluses share
	 * one convergence signal.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int<0, max>
	 */
	#[\Override]
	public function scheduled_count( string $hook, array $args = array(), string $group = '' ): int {
		$count = 0;
		foreach ( $this->ready_backends() as $backend ) {
			$count += $backend->scheduled_count( $hook, $args, $group );
		}

		return $count;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The per-identity totals span every currently ready backend so same-backend and cross-backend
	 * surpluses retain the scalar convergence semantics.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, int<0, max>>
	 */
	#[\Override]
	public function scheduled_counts( string $hook, array $identities ): array {
		$counts = array();
		foreach ( $identities as $requested_identity ) {
			$counts[ $requested_identity ] = 0;
		}

		if ( array() === $counts ) {
			return $counts;
		}

		foreach ( $this->ready_backends() as $backend ) {
			$backend_counts = $backend->scheduled_counts( $hook, $identities );
			foreach ( $counts as $identity => $count ) {
				$counts[ $identity ] = $count + ( $backend_counts[ $identity ] ?? 0 );
			}
		}

		return $counts;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function is_scheduled( string $hook, array $args = array(), string $group = '' ): bool {
		return \array_any( $this->ready_backends(), static fn ( BackendInterface $backend ): bool => $backend->is_scheduled( $hook, $args, $group ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function get_next_scheduled( string $hook, array $args = array(), string $group = '' ): ?int {
		$timestamps = array();
		foreach ( $this->ready_backends() as $backend ) {
			$next = $backend->get_next_scheduled( $hook, $args, $group );
			if ( null !== $next ) {
				$timestamps[] = $next;
			}
		}

		return array() === $timestamps ? null : \min( $timestamps );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function is_ready(): bool {
		return array() !== $this->ready_backends();
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
		return \array_all( $this->backends, static fn ( BackendInterface $backend ): bool => $backend->is_absent() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function register_hooks(): void {
		foreach ( $this->backends as $backend ) {
			$backend->register_hooks();
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Executes a scheduling write against the first backend that remains ready.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \Closure(BackendInterface): AbstractResult<true, SchedulingError> $write
	 *
	 * @param   \Closure $write Backend write.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	private function write( \Closure $write ): AbstractResult {
		$last_not_ready = null;

		foreach ( $this->backends as $backend ) {
			if ( ! $backend->is_ready() ) {
				continue;
			}

			$result = $this->write_to_backend( $write, $backend );
			if ( ! $result->is_failure() || SchedulingErrorReason::BackendNotReady !== $result->error->reason ) {
				return $result;
			}

			$last_not_ready = $result;
		}

		return null === $last_not_ready ? $this->write_to_backend( $write, $this->fallback_backend() ) : $last_not_ready;
	}

	/**
	 * Converts an unexpected backend write throwable into the checked scheduling contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \Closure(BackendInterface): AbstractResult<true, SchedulingError> $write
	 *
	 * @param   \Closure         $write   Backend write.
	 * @param   BackendInterface $backend Selected backend.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	private function write_to_backend( \Closure $write, BackendInterface $backend ): AbstractResult {
		try {
			return $write( $backend );
		} catch ( \Throwable $throwable ) {
			// Result failures let callers compensate state admitted before the scheduler boundary.
			return new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, \sprintf( 'The scheduling backend could not accept the write because %s was thrown; repair the backend and retry.', \get_debug_type( $throwable ) ) ) );
		}
	}

	/**
	 * Returns the configured baseline backend.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  BackendInterface
	 */
	private function fallback_backend(): BackendInterface {
		// The last backend is the WP-Cron baseline whose corrective diagnostics a facade failure would hide.
		return \array_last( $this->backends );
	}

	/**
	 * Returns the backends currently safe to query or clear, in declaration order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<BackendInterface>
	 */
	private function ready_backends(): array {
		return \array_values( \array_filter( $this->backends, static fn ( BackendInterface $backend ): bool => $backend->is_ready() ) );
	}

	/**
	 * Clears across one captured readiness snapshot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<BackendInterface> $ready_backends Backends selected for the clear.
	 * @param   string                 $hook           Hook to unschedule.
	 * @param   list<mixed>            $args           Arguments identifying the scheduled hook.
	 * @param   string                 $group          Backend grouping label.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	private function unschedule_snapshot( array $ready_backends, string $hook, array $args, string $group ): AbstractResult {
		if ( array() === $ready_backends ) {
			return $this->fallback_backend()->unschedule( $hook, $args, $group );
		}

		$first_failure = null;
		foreach ( $ready_backends as $backend ) {
			$result = $backend->unschedule( $hook, $args, $group );
			if ( $result->is_failure() ) {
				$first_failure ??= $result;
			}
		}

		return $first_failure ?? new Success( true );
	}

	/**
	 * Returns whether one readiness snapshot covers every configured backend still present at runtime.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<BackendInterface> $ready_backends Captured clear targets.
	 *
	 * @return  bool
	 */
	private function snapshot_is_authoritative( array $ready_backends ): bool {
		return \array_all( $this->backends, static fn ( BackendInterface $backend ): bool => \in_array( $backend, $ready_backends, true ) || $backend->is_absent() );
	}

	/**
	 * Returns a corrective failure for a timestamp outside positive UNIX seconds.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                            $hook      Hook being scheduled.
	 * @param   'first_run_timestamp'|'timestamp' $field     Timestamp field.
	 * @param   int                               $timestamp Rejected timestamp.
	 *
	 * @return  Failure<SchedulingError>
	 */
	private function timestamp_failure( string $hook, string $field, int $timestamp ): Failure {
		return new Failure( new SchedulingError( SchedulingErrorReason::InvalidTimeInput, \sprintf( 'Scheduling hook "%1$s" requires %2$s in positive UNIX seconds; pass a timestamp of at least 1.', $hook, 'first_run_timestamp' === $field ? 'the first-run timestamp' : 'the run timestamp' ), array( $field => $timestamp ), ) );
	}

	/**
	 * Returns a corrective failure when hook arguments cannot fit backend storage.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $hook Hook being scheduled.
	 * @param   list<mixed> $args Arguments passed to the hook.
	 *
	 * @return  Failure<SchedulingError>|null
	 */
	private function payload_failure( string $hook, array $args ): ?Failure {
		if ( ! PortableArguments::is_valid( $args, self::MAX_ARGUMENTS_JSON_DEPTH ) ) {
			return new Failure(
				new SchedulingError(
					SchedulingErrorReason::InvalidPayload,
					\sprintf( 'Scheduling hook "%1$s" arguments must be a tree of scalars and arrays; store objects by identifier and keep nesting within %2$d levels.', $hook, self::MAX_ARGUMENTS_JSON_DEPTH ),
					array(
						'hook'          => $hook,
						'maximum_depth' => self::MAX_ARGUMENTS_JSON_DEPTH,
					),
				)
			);
		}

		$encoded_args = \wp_json_encode( $args, 0, self::MAX_ARGUMENTS_JSON_DEPTH );
		if ( \is_string( $encoded_args ) && self::MAX_ARGUMENTS_JSON_LENGTH >= \strlen( $encoded_args ) ) {
			return null;
		}

		return new Failure(
			new SchedulingError(
				SchedulingErrorReason::InvalidPayload,
				\sprintf( 'Scheduling hook "%1$s" has arguments that cannot be JSON-encoded within the %2$d-byte limit; pass identifying keys and load bulk data from storage inside the handler.', $hook, self::MAX_ARGUMENTS_JSON_LENGTH ),
				array(
					'hook'                => $hook,
					'maximum_json_length' => self::MAX_ARGUMENTS_JSON_LENGTH,
				),
			)
		);
	}

	// endregion
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Scheduling;

use A8C\SpecialProjects\BackgroundTasksEngine\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Support\ScalarTree;

\defined( 'ABSPATH' ) || exit;

/**
 * Backend-agnostic scheduling facade over backends in declaration order.
 *
 * Writes target the first ready backend, while reads and clears span every currently ready
 * backend. Hook registration remains unconditional so persisted work keeps resolving when backend
 * preference changes. Before action_scheduler_init, writes fall through to WP-Cron even when
 * Action Scheduler is installed because routing follows per-request readiness.
 *
 * Action Scheduler treats an empty group as unconstrained in queries but exact in unique inserts,
 * and unscheduling with both empty arguments and an empty group clears every action for the hook.
 * Engine callers provide per-run groups and identifying arguments; this facade preserves those
 * native semantics instead of compensating for empty values.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class SchedulerFacade implements BackendInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * The guard accepts only scalar trees whose JSON form fits the incumbent-proven 8000-character ceiling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const MAX_ARGUMENTS_JSON_LENGTH = 8_000;

	/**
	 * Matching the JSON encoder's depth keeps recursive shape validation finite.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const MAX_ARGUMENTS_JSON_DEPTH = 512;

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
			throw new \InvalidArgumentException(
				'SchedulerFacade requires at least one backend; pass the WP-Cron backend as the final fallback.'
			);
		}

		$this->backends = \array_values( $backends );
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
	public function schedule_recurring( string $hook, int $interval, array $args = array(), ?int $first_run_timestamp = null, string $group = '', bool $unique = false, int $priority = 10 ): AbstractResult {
		if ( null !== $first_run_timestamp && 1 > $first_run_timestamp ) {
			return $this->timestamp_failure( $hook, 'first_run_timestamp', $first_run_timestamp );
		}

		$payload_failure = $this->payload_failure( $hook, $args );
		if ( null !== $payload_failure ) {
			return $payload_failure;
		}

		return $this->write(
			static fn ( BackendInterface $backend ): AbstractResult => $backend->schedule_recurring(
				$hook,
				$interval,
				$args,
				$first_run_timestamp,
				$group,
				$unique,
				$priority
			)
		);
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

		return $this->write(
			static fn ( BackendInterface $backend ): AbstractResult => $backend->schedule_single(
				$hook,
				$timestamp,
				$args,
				$group,
				$priority
			)
		);
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
		$payload_failure = $this->payload_failure( $hook, $args );
		if ( null !== $payload_failure ) {
			return $payload_failure;
		}

		return $this->write(
			static fn ( BackendInterface $backend ): AbstractResult => $backend->enqueue_async(
				$hook,
				$args,
				$group,
				$unique,
				$priority
			)
		);
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
		$ready_backends = $this->ready_backends();
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
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function is_scheduled( string $hook, array $args = array(), string $group = '' ): bool {
		foreach ( $this->ready_backends() as $backend ) {
			if ( $backend->is_scheduled( $hook, $args, $group ) ) {
				return true;
			}
		}

		return false;
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
	public function supports_cron_expressions(): bool {
		foreach ( $this->backends as $backend ) {
			if ( $backend->is_ready() && $backend->supports_cron_expressions() ) {
				return true;
			}
		}

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

			$result = $write( $backend );
			if ( ! $result->is_failure() || SchedulingErrorReason::BackendNotReady !== $result->error->reason ) {
				return $result;
			}

			$last_not_ready = $result;
		}

		return null === $last_not_ready ? $write( $this->fallback_backend() ) : $last_not_ready;
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
		return $this->backends[ \count( $this->backends ) - 1 ];
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
		$ready = array();
		foreach ( $this->backends as $backend ) {
			if ( $backend->is_ready() ) {
				$ready[] = $backend;
			}
		}

		return $ready;
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
		return new Failure(
			new SchedulingError(
				SchedulingErrorReason::InvalidInterval,
				\sprintf(
					'Scheduling hook "%1$s" requires %2$s in positive UNIX seconds; pass a timestamp of at least 1.',
					$hook,
					'first_run_timestamp' === $field ? 'the first-run timestamp' : 'the run timestamp'
				),
				array( $field => $timestamp ),
			)
		);
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
		if ( ! ScalarTree::is_valid( $args, self::MAX_ARGUMENTS_JSON_DEPTH ) ) {
			return new Failure(
				new SchedulingError(
					SchedulingErrorReason::PayloadTooLarge,
					\sprintf(
						'Scheduling hook "%1$s" arguments must be a tree of scalars and arrays; store objects by identifier and keep nesting within %2$d levels.',
						$hook,
						self::MAX_ARGUMENTS_JSON_DEPTH
					),
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
				SchedulingErrorReason::PayloadTooLarge,
				\sprintf(
					'Scheduling hook "%1$s" has arguments that cannot be JSON-encoded within the %2$d-character limit; pass identifying keys and load bulk data from storage inside the handler.',
					$hook,
					self::MAX_ARGUMENTS_JSON_LENGTH
				),
				array(
					'hook'                => $hook,
					'maximum_json_length' => self::MAX_ARGUMENTS_JSON_LENGTH,
				),
			)
		);
	}

	// endregion
}

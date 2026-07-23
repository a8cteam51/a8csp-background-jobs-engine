<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\BoundaryErrorMapper;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound adapter from supported operations to internal engine services.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class OwnerOperations {
	// region FIELDS AND CONSTANTS

	/**
	 * Maximum encoded JSON bytes accepted for persisted start arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_ARGUMENTS_BYTES = 8_192;

	/**
	 * Highest scheduler priority accepted by admission contracts.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_PRIORITY = 255;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string             $owner      Client plugin owner.
	 * @param   ScheduleOperations $schedules  Schedule engine operations.
	 * @param   Dispatcher         $dispatcher Background-work admission coordinator.
	 * @param   Inspection         $inspection Read-only run inspection.
	 */
	public function __construct(
		private string $owner,
		private ScheduleOperations $schedules,
		private Dispatcher $dispatcher,
		private Inspection $inspection,
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers one definition under the bound owner and its declared local name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   JobDefinition $definition Job definition to register.
	 *
	 * @throws  \InvalidArgumentException When the identity, kind, or execution role is invalid.
	 * @throws  \LogicException           When the job identity is already registered.
	 *
	 * @return  void
	 */
	public function register( JobDefinition $definition ): void {
		$this->dispatcher->register( JobIdentity::compose( $this->owner, $definition->name ), $definition );
	}

	/**
	 * Creates and schedules one run for registered background work.
	 *
	 * The registered definition supplies its overlap policy and argument-aware collision identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name       Owner-local background-work name.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   int                     $delay      Scheduling delay in seconds.
	 * @param   int|null                $priority   Advisory priority from 0 through 255, or null for the engine default.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity, delay, or priority is invalid, or arguments are not portable.
	 *
	 * @return  AbstractResult<string, BoundaryError>
	 */
	#[\NoDiscard( 'a job-dispatch failure must be handled, not dropped' )]
	public function dispatch( string $name, array $start_args = array(), int $delay = 0, ?int $priority = null ): AbstractResult {
		$identity = JobIdentity::compose( $this->owner, $name );
		if ( 0 > $delay ) {
			// Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( 'Background-work "%1$s" delay %2$d is invalid; pass a non-negative number of seconds.', $name, $delay ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( null !== $priority ) {
			self::assert_priority( $priority, \sprintf( 'Background-work "%s"', $name ) );
		}

		$payload_error = self::assert_portable_args( $start_args, \sprintf( 'Background-work "%s"', $name ) );
		if ( null !== $payload_error ) {
			return new Failure( $payload_error );
		}

		return BoundaryErrorMapper::map( $this->dispatcher->dispatch( $identity, $start_args, $delay, $priority ) );
	}

	/**
	 * Synchronizes the bound owner's complete declared schedule set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<Schedule> $schedules Complete schedule declaration for the bound owner.
	 *
	 * @throws  \InvalidArgumentException When an entry, owner/name identity, owner/target identity, or declaration uniqueness is invalid.
	 *
	 * @return  AbstractResult<true, BoundaryError>
	 */
	#[\NoDiscard( 'a schedule-sync failure must be handled, not dropped' )]
	public function sync( array $schedules ): AbstractResult {
		$declarations = array();
		foreach ( $schedules as $schedule ) {
			if ( ! $schedule instanceof Schedule ) {
				throw new \InvalidArgumentException( 'Schedule sync accepts only Schedule value objects; construct each declaration with new Schedule(...).' );
			}

			$identity = JobIdentity::compose( $this->owner, $schedule->name );
			if ( isset( $declarations[ $identity ] ) ) {
				throw new \InvalidArgumentException( 'Schedule sync accepts each owner-local schedule name exactly once.' );
			}

			$declarations[ $identity ] = array(
				'schedule' => $schedule,
				'job'      => JobIdentity::compose( $this->owner, $schedule->job ),
			);
		}

		return BoundaryErrorMapper::map( $this->schedules->sync( $this->owner, $declarations ) );
	}

	/**
	 * Immediately dispatches one declared schedule target without changing its recurrence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Owner-local schedule name.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity is invalid.
	 *
	 * @return  AbstractResult<array{identity: string, run_id: string}, BoundaryError>
	 */
	#[\NoDiscard( 'a schedule dispatch-now failure must be handled, not dropped' )]
	public function dispatch_now( string $name ): AbstractResult {
		return BoundaryErrorMapper::map( $this->schedules->dispatch_now( JobIdentity::compose( $this->owner, $name ) ) );
	}

	/**
	 * Returns one retained run's observable lifecycle status.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Owner-local job or chunked job name.
	 * @param   string $run_id Retained run identifier.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity is invalid or the run_id is malformed.
	 *
	 * @return  AbstractResult<\A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus|null, BoundaryError>
	 */
	#[\NoDiscard( 'a run-inspection result must be handled, not dropped' )]
	public function inspect( string $name, string $run_id ): AbstractResult {
		return BoundaryErrorMapper::map( $this->inspection->run_status( JobIdentity::compose( $this->owner, $name ), $run_id ) );
	}

	/**
	 * Returns the most recently recorded completed run ID retained for one background-work name.
	 *
	 * The lookup covers only the retained history window. Each history buffer retains at most the
	 * positive `a8csp_jobs_engine/history_size` filter value, 30 by default. A completed run
	 * older than that window returns `Success(null)` as if absent. Clients needing indefinite
	 * retention keep their own pointer from the completed lifecycle hook. History
	 * is recorded after those notifications, so a lookup inside either observes the previous retained
	 * completion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Owner-local job or chunked job name.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity is invalid.
	 *
	 * @return  AbstractResult<string|null, BoundaryError>
	 */
	#[\NoDiscard( 'a last-completed-run result must be handled, not dropped' )]
	public function last_completed_run_id( string $name ): AbstractResult {
		return BoundaryErrorMapper::map( $this->inspection->last_completed_run_id( JobIdentity::compose( $this->owner, $name ) ) );
	}

	/**
	 * Starts a fresh run from one retained failed run's original arguments.
	 *
	 * The registered Job recomputes its argument-aware overlap key, while retry always rejects a
	 * matching live run regardless of the Job's declared overlap policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Owner-local job or chunked job name.
	 * @param   string $run_id Retained failed-run identifier.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity is invalid or the run_id is malformed.
	 *
	 * @return  AbstractResult<string, BoundaryError>
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed( string $name, string $run_id ): AbstractResult {
		return BoundaryErrorMapper::map( $this->dispatcher->retry_failed( JobIdentity::compose( $this->owner, $name ), $run_id ) );
	}

	/**
	 * Cancels one retained run that is not executing or pending chunked job cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Owner-local job or chunked job name.
	 * @param   string $run_id Retained run identifier.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity is invalid or the run_id is malformed.
	 *
	 * @return  AbstractResult<string, BoundaryError>
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	public function cancel( string $name, string $run_id ): AbstractResult {
		return BoundaryErrorMapper::map( $this->dispatcher->cancel( JobIdentity::compose( $this->owner, $name ), $run_id ) );
	}

	// endregion

	// region HELPERS

	/**
	 * Asserts that a scheduler priority fits the supported byte range.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int    $priority Priority to validate.
	 * @param   string $context  Concept-specific exception context.
	 *
	 * @throws  \InvalidArgumentException When the priority is outside the supported range.
	 *
	 * @return  void
	 */
	private static function assert_priority( int $priority, string $context ): void {
		if ( 0 <= $priority && self::MAX_PRIORITY >= $priority ) {
			return;
		}

		// Exception values are diagnostic data, not rendered output.
		throw new \InvalidArgumentException( \sprintf( '%1$s priority %2$d is invalid; pass a value from 0 through %3$d.', $context, $priority, self::MAX_PRIORITY ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Asserts that arguments form a portable JSON-encodable tree.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $args    Arguments to validate.
	 * @param   string                  $context Concept-specific exception context.
	 *
	 * @throws  \InvalidArgumentException When the arguments are not portable and JSON-encodable.
	 *
	 * @return  BoundaryError|null Payload rejection when the portable arguments exceed the persisted byte limit.
	 */
	private static function assert_portable_args( array $args, string $context ): ?BoundaryError {
		try {
			$encoded_args = \wp_json_encode( $args, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION );
		} catch ( \JsonException ) {
			$encoded_args = false;
		}

		if ( ! \is_string( $encoded_args ) || ! PortableArguments::is_valid( $args ) ) {
			// Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( '%s arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.', $context ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$actual_bytes = \strlen( $encoded_args );
		if ( self::MAX_ARGUMENTS_BYTES >= $actual_bytes ) {
			return null;
		}

		return new BoundaryError( ErrorCode::PayloadRejected, \sprintf( '%1$s arguments contain %2$d JSON bytes; the limit is %3$d bytes.', $context, $actual_bytes, self::MAX_ARGUMENTS_BYTES ) );
	}

	// endregion
}

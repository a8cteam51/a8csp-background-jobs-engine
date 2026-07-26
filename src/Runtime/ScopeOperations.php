<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\BoundaryErrorMapper;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule;

\defined( 'ABSPATH' ) || exit;

/**
 * Scope-bound adapter from supported operations to internal engine services.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ScopeOperations {
	// region FIELDS AND CONSTANTS

	/**
	 * Maximum encoded JSON bytes accepted for persisted start arguments.
	 *
	 * `Schedule::MAX_ARGUMENTS_BYTES` mirrors this scope-boundary limit because the frozen
	 * public model keeps its constant private.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_ARGUMENTS_BYTES = 8_192;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string             $scope      Client plugin scope.
	 * @param   ScheduleOperations $schedules  Schedule engine operations.
	 * @param   Dispatcher         $dispatcher Background-work admission coordinator.
	 * @param   Inspection         $inspection Read-only run inspection.
	 */
	public function __construct(
		private string $scope,
		private ScheduleOperations $schedules,
		private Dispatcher $dispatcher,
		private Inspection $inspection,
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers one definition under the bound scope and its declared local name.
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
		$this->dispatcher->register( Identity::compose( $this->scope, $definition->name ), $definition );
	}

	/**
	 * Creates and schedules one run for registered background work.
	 *
	 * The registered definition supplies its overlap policy and argument-aware collision identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name       Scope-local background-work name.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   int|null                $fire_at    Absolute first-delivery timestamp, or null for asynchronous admission.
	 * @param   int|null                $priority   Advisory priority from 0 through 255, or null for the engine default.
	 *
	 * @throws  \InvalidArgumentException When the scope/name identity or priority is invalid, or arguments are not portable.
	 * @throws  \ValueError               When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  AbstractResult<Run, BoundaryError>
	 */
	#[\NoDiscard( 'a job-dispatch failure must be handled, not dropped' )]
	public function dispatch( string $name, array $start_args = array(), ?int $fire_at = null, ?int $priority = null ): AbstractResult {
		$identity = Identity::compose( $this->scope, $name );
		if ( null !== $priority ) {
			self::assert_priority( $priority, \sprintf( 'Background-work "%s"', $name ) );
		}

		$payload_error = self::assert_portable_args( $start_args, \sprintf( 'Background-work "%s"', $name ) );
		if ( null !== $payload_error ) {
			return new Failure( $payload_error );
		}

		$result = BoundaryErrorMapper::map( $this->dispatcher->dispatch( $identity, $start_args, $fire_at, $priority ) );

		return $result->is_failure() ? $result : new Success( self::run( $identity, $result->value, RunStatus::Running ) );
	}

	/**
	 * Synchronizes the bound scope's complete declared schedule set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<Schedule> $schedules Complete schedule declaration for the bound scope.
	 *
	 * @throws  \InvalidArgumentException When an entry, scope/name identity, scope/target identity, or declaration uniqueness is invalid.
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

			$identity = Identity::compose( $this->scope, $schedule->name );
			if ( isset( $declarations[ (string) $identity ] ) ) {
				throw new \InvalidArgumentException( 'Schedule sync accepts each scope-local schedule name exactly once.' );
			}

			$declarations[ (string) $identity ] = array(
				'schedule' => $schedule,
				'job'      => Identity::compose( $this->scope, $schedule->job ),
			);
		}

		return BoundaryErrorMapper::map( $this->schedules->sync( $this->scope, $declarations ) );
	}

	/**
	 * Immediately dispatches one declared schedule target without changing its recurrence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Scope-local schedule name.
	 *
	 * @throws  \InvalidArgumentException When the scope/name identity is invalid.
	 * @throws  \ValueError               When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  AbstractResult<Run, BoundaryError>
	 */
	#[\NoDiscard( 'a schedule dispatch-now failure must be handled, not dropped' )]
	public function dispatch_now( string $name ): AbstractResult {
		$result = BoundaryErrorMapper::map( $this->schedules->dispatch_now( Identity::compose( $this->scope, $name ) ) );

		return $result->is_failure() ? $result : new Success( self::run( $result->value['identity'], $result->value['run_id'], RunStatus::Running ) );
	}

	/**
	 * Returns one retained run's observable lifecycle status.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Scope-local job or chunked job name.
	 * @param   string $run_id Retained run identifier.
	 *
	 * @throws  \InvalidArgumentException When the scope/name identity is invalid or the run_id is malformed.
	 * @throws  \ValueError               When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  AbstractResult<Run|null, BoundaryError>
	 */
	#[\NoDiscard( 'a run-inspection result must be handled, not dropped' )]
	public function inspect( string $name, string $run_id ): AbstractResult {
		$identity = Identity::compose( $this->scope, $name );
		$result   = BoundaryErrorMapper::map( $this->inspection->run_status( $identity, $run_id ) );
		if ( $result->is_failure() ) {
			return $result;
		}
		if ( null === $result->value ) {
			return new Success( null );
		}

		// The public projection covers every internal run status, so from() always resolves here.
		return new Success( self::run( $identity, $run_id, RunStatus::from( $result->value->value ) ) );
	}

	/**
	 * Returns the most recently recorded completed run retained for one background-work name.
	 *
	 * The lookup covers only the retained history window. Each history buffer retains at most the
	 * positive `a8csp_bgje/history_size` filter value, 30 by default. A completed run
	 * older than that window returns `Success(null)` as if absent. Clients needing indefinite
	 * retention keep their own pointer from the completed lifecycle hook. History
	 * is recorded after those notifications, so a lookup inside either observes the previous retained
	 * completion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Scope-local job or chunked job name.
	 *
	 * @throws  \InvalidArgumentException When the scope/name identity is invalid.
	 * @throws  \ValueError               When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  AbstractResult<Run|null, BoundaryError>
	 */
	#[\NoDiscard( 'a last-completed-run result must be handled, not dropped' )]
	public function last_completed_run( string $name ): AbstractResult {
		$identity = Identity::compose( $this->scope, $name );
		$result   = BoundaryErrorMapper::map( $this->inspection->last_completed_run_id( $identity ) );
		if ( $result->is_failure() ) {
			return $result;
		}
		if ( null === $result->value ) {
			return new Success( null );
		}

		return new Success( self::run( $identity, $result->value, RunStatus::Completed ) );
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
	 * @param   string $name   Scope-local job or chunked job name.
	 * @param   string $run_id Retained failed-run identifier.
	 *
	 * @throws  \InvalidArgumentException When the scope/name identity is invalid or the run_id is malformed.
	 * @throws  \ValueError               When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  AbstractResult<Run, BoundaryError>
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed( string $name, string $run_id ): AbstractResult {
		$identity = Identity::compose( $this->scope, $name );
		$result   = BoundaryErrorMapper::map( $this->dispatcher->retry_failed( $identity, $run_id ) );

		return $result->is_failure() ? $result : new Success( self::run( $identity, $result->value, RunStatus::Running ) );
	}

	/**
	 * Cancels one retained run that is not executing or pending chunked job cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Scope-local job or chunked job name.
	 * @param   string $run_id Retained run identifier.
	 *
	 * @throws  \InvalidArgumentException When the scope/name identity is invalid or the run_id is malformed.
	 * @throws  \ValueError               When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  AbstractResult<Run, BoundaryError>
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	public function cancel( string $name, string $run_id ): AbstractResult {
		$identity = Identity::compose( $this->scope, $name );
		$result   = BoundaryErrorMapper::map( $this->dispatcher->cancel( $identity, $run_id ) );

		return $result->is_failure() ? $result : new Success( self::run( $identity, $result->value, RunStatus::Cancelled ) );
	}

	// endregion

	// region HELPERS

	/**
	 * Projects one admitted run into the public boundary value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity  $identity Complete scope-qualified job or chunked job identity.
	 * @param   string    $run_id   Run identifier.
	 * @param   RunStatus $status   Public lifecycle state.
	 *
	 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  Run
	 */
	private static function run( Identity $identity, string $run_id, RunStatus $status ): Run {
		return new Run( (string) $identity, RunId::from( $run_id ), $status );
	}

	/**
	 * Asserts that a scheduler priority fits the dispatch-owned supported range.
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
		if ( 0 <= $priority && Dispatcher::MAX_PRIORITY >= $priority ) {
			return;
		}

		// Exception values are diagnostic data, not rendered output.
		throw new \InvalidArgumentException( \sprintf( '%1$s priority %2$d is invalid; pass a value from 0 through %3$d.', $context, $priority, Dispatcher::MAX_PRIORITY ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
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

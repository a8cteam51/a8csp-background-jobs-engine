<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunCompletionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\BoundaryErrorMapper;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunDataStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
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
	 * Maximum encoded JSON bytes accepted for persisted job start arguments and declared schedule arguments.
	 *
	 * `Runs\Stores\RunStore::ROW_ENVELOPE_RESERVE_BYTES` budgets twice this limit for PHP-serialized
	 * start arguments and fixed lifecycle metadata; its complete-row boundary remains authoritative
	 * for higher-overhead shapes.
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
	 * @param   StoreFactory       $stores     Name-bound run stores.
	 */
	public function __construct(
		private string $scope,
		private ScheduleOperations $schedules,
		private Dispatcher $dispatcher,
		private Inspection $inspection,
		private StoreFactory $stores,
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers one definition under the bound scope and its declared local name.
	 *
	 * An execution object declaring {@see RunCompletionInterface} is subscribed to this identity's
	 * completed hook here, because registration is where the engine is handed the object and the
	 * identity in the same call.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   JobDefinition $definition Job definition to register.
	 *
	 * @throws  \InvalidArgumentException When the identity, job-default priority, crash-reclamation window, kind, or execution role is invalid.
	 * @throws  \LogicException           When the job identity is already registered.
	 *
	 * @return  void
	 */
	public function register( JobDefinition $definition ): void {
		$identity = Identity::compose( $this->scope, $definition->name );
		$context  = \sprintf( 'Background-work "%s"', $definition->name );
		if ( null !== $definition->options->max_runtime && 1 > $definition->options->max_runtime ) {
			// Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( '%1$s max_runtime %2$d is invalid; the crash-reclamation window must be at least one second, or pass null for the engine default.', $context, $definition->options->max_runtime ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( null !== $definition->options->priority ) {
			self::assert_priority( $definition->options->priority, $context );
		}

		$this->dispatcher->register( $identity, $definition );

		if ( $definition->execution instanceof RunCompletionInterface ) {
			self::subscribe_completion_role( $identity, $definition->execution );
		}
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
	 * @param   int|null                $priority   Advisory priority from 0 through 255, or null to defer to the job default.
	 *
	 * @throws  \InvalidArgumentException When the scope/name identity or priority is invalid, or arguments are not portable.
	 * @throws  \ValueError               When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a job-dispatch failure must be handled, not dropped' )]
	public function dispatch( string $name, array $start_args = array(), ?int $fire_at = null, ?int $priority = null ): Run|\WP_Error {
		$identity = Identity::compose( $this->scope, $name );
		if ( null !== $priority ) {
			self::assert_priority( $priority, \sprintf( 'Background-work "%s"', $name ) );
		}

		$payload_error = self::assert_portable_args( $start_args, \sprintf( 'Background-work "%s"', $name ) );
		if ( null !== $payload_error ) {
			return $payload_error;
		}

		$result = BoundaryErrorMapper::map( $this->dispatcher->dispatch_until_admitted( $identity, $start_args, $fire_at, $priority ) );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return self::run( $identity, $result, RunStatus::Running );
	}

	/**
	 * Synchronizes the bound scope's complete declared schedule set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, Schedule> $schedules Complete schedule declaration for the bound scope.
	 *
	 * @throws  \InvalidArgumentException When a scope/name identity, scope/target identity, declaration uniqueness, or schedule priority is invalid.
	 *
	 * @return  true|\WP_Error
	 */
	#[\NoDiscard( 'a schedule-sync failure must be handled, not dropped' )]
	public function sync( array $schedules ): true|\WP_Error {
		$declarations = array();
		foreach ( $schedules as $schedule ) {
			$context  = \sprintf( 'Schedule "%s"', $schedule->name );
			$identity = self::compose_declared( $this->scope, $schedule->name, $context );
			if ( isset( $declarations[ (string) $identity ] ) ) {
				throw new \InvalidArgumentException( \sprintf( '%s is declared more than once; schedule sync accepts each scope-local schedule name exactly once.', $context ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			// Both identities resolve before any policy check so a declaration reports every naming defect first.
			$job_identity = self::compose_declared( $this->scope, $schedule->job, $context . ' target job' );
			if ( null !== $schedule->priority ) {
				self::assert_priority( $schedule->priority, $context );
			}
			$payload_error = self::assert_portable_args( $schedule->args, $context );
			if ( null !== $payload_error ) {
				return $payload_error;
			}

			$declarations[ (string) $identity ] = array(
				'schedule' => $schedule,
				'job'      => $job_identity,
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
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a schedule dispatch-now failure must be handled, not dropped' )]
	public function dispatch_now( string $name ): Run|\WP_Error {
		$result = BoundaryErrorMapper::map( $this->schedules->dispatch_now( Identity::compose( $this->scope, $name ) ) );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return self::run( $result['identity'], $result['run_id'], RunStatus::Running );
	}

	/**
	 * Returns the bound scope's persisted schedule registrations with their observable live state.
	 *
	 * The projection reports facts and draws no conclusion from them: whether a next_due in the past
	 * or an invisible occurrence is a problem depends on what the caller declared and how late is
	 * late, neither of which the engine knows. Execution-overlap lock state is deliberately absent —
	 * it describes a run rather than a registration, and `wp a8csp-bgje schedules list` renders it
	 * for an operator who needs it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-return array{observed_at: int, dormant_backend: bool, schedules: list<array{name: string, identity: string, recurrence: int|null, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int, occurrence_visible: bool}>}|\WP_Error
	 *
	 * @return  array|\WP_Error
	 */
	#[\NoDiscard( 'a schedule-registration inspection result must be handled, not dropped' )]
	public function inspect_schedules(): array|\WP_Error {
		// Lock state is not projected here, so it is not resolved either — resolving it would run the
		// consumer's overlap-key resolver and read a lock row per registration for a value this verb
		// discards, which a read-only query has no business doing.
		$inspected = $this->inspection->schedules( $this->scope, with_lock: false );
		if ( null === $inspected ) {
			return new \WP_Error( ErrorCode::StorageFailed->value, 'The schedule registry could not be read; repair WordPress option reads and retry.' );
		}

		$schedules = array();
		foreach ( $inspected['entries'] as $entry ) {
			$identity = Identity::tryFrom( $entry['identity'] );
			if ( null === $identity ) {
				continue;
			}

			$schedules[] = array(
				'name'               => $identity->name(),
				'identity'           => $entry['identity'],
				'recurrence'         => $entry['recurrence'],
				'next_due'           => $entry['next_due'],
				'last_fired'         => $entry['last_fired'],
				'misfire_skips'      => $entry['misfire_skips'],
				'overlap_skips'      => $entry['overlap_skips'],
				'occurrence_visible' => $entry['occurrence_visible'],
			);
		}

		return array(
			'observed_at'     => $inspected['observed_at'],
			'dormant_backend' => $inspected['dormant_candidate'],
			'schedules'       => $schedules,
		);
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
	 * @return  Run|null|\WP_Error
	 */
	#[\NoDiscard( 'a run-inspection result must be handled, not dropped' )]
	public function inspect( string $name, string $run_id ): Run|null|\WP_Error {
		$identity = Identity::compose( $this->scope, $name );
		$result   = BoundaryErrorMapper::map( $this->inspection->run_status( $identity, $run_id ) );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		if ( null === $result ) {
			return null;
		}

		return self::run( $identity, $run_id, $result );
	}

	/**
	 * Returns the last completed run for one background-work name.
	 *
	 * Read from the non-evicting slot rather than the capped history buffers, so the answer outlives
	 * any number of later outcomes; {@see RunHistory::last_completed()} owns that guarantee and the
	 * order it records in. History is written after the terminal notifications, so a lookup from
	 * inside one observes the previous completion rather than the run being notified about.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Scope-local job or chunked job name.
	 *
	 * @throws  \InvalidArgumentException When the scope/name identity is invalid.
	 * @throws  \ValueError               When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  Run|null|\WP_Error
	 */
	#[\NoDiscard( 'a last-completed-run result must be handled, not dropped' )]
	public function last_completed_run( string $name ): Run|null|\WP_Error {
		$identity = Identity::compose( $this->scope, $name );
		$result   = BoundaryErrorMapper::map( $this->inspection->last_completed_run( $identity ) );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		if ( null === $result ) {
			return null;
		}

		return self::run( $identity, $result['run_id'], RunStatus::Completed, $result['at'] );
	}

	/**
	 * Stores one consumer value for the duration of one run.
	 *
	 * Values must survive `serialize()`/`unserialize()` without materializing an object, which is the
	 * same portability contract start arguments carry. {@see Runs\Stores\RunDataStore} owns the
	 * run-scoped lifetime this writes into.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name   Scope-local job or chunked job name.
	 * @param   string                  $run_id Run identifier.
	 * @param   string                  $key    Run data key.
	 * @param   array<array-key, mixed> $value  Portable value to store.
	 *
	 * @throws  \InvalidArgumentException When the identity, key, or value is invalid.
	 *
	 * @return  true|\WP_Error
	 */
	#[\NoDiscard( 'a data write failure must be handled, not dropped' )]
	public function set_run_data( string $name, string $run_id, string $key, array $value ): true|\WP_Error {
		$identity = Identity::compose( $this->scope, $name );
		$context  = \sprintf( 'Background-work "%1$s" data key "%2$s"', $name, $key );
		self::assert_data_key( $key, $name );

		// Only portability here: the run's complete data row is the byte ceiling that applies.
		self::assert_portable_tree( $value, $context . ' value' );

		$stored = $this->stores->run_data( $identity )->remember( $run_id, $key, $value );
		if ( true === $stored ) {
			return true;
		}
		if ( null === $stored ) {
			return new \WP_Error( ErrorCode::PayloadRejected->value, \sprintf( '%1$s does not fit; the run\'s complete data row is limited to %2$d serialized bytes.', $context, RunDataStore::MAX_ROW_BYTES ) );
		}

		return new \WP_Error( ErrorCode::StorageFailed->value, \sprintf( '%s could not be stored; repair WordPress option writes and retry.', $context ) );
	}

	/**
	 * Returns one consumer value stored for the duration of one run.
	 *
	 * Null separates a key the run never stored from one holding an empty array.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Scope-local job or chunked job name.
	 * @param   string $run_id Run identifier.
	 * @param   string $key    Run data key.
	 *
	 * @throws  \InvalidArgumentException When the identity or key is invalid.
	 *
	 * @return  array<array-key, mixed>|null|\WP_Error
	 */
	#[\NoDiscard( 'a data read result must be handled, not dropped' )]
	public function get_run_data( string $name, string $run_id, string $key ): array|null|\WP_Error {
		$identity = Identity::compose( $this->scope, $name );
		self::assert_data_key( $key, $name );

		return BoundaryErrorMapper::map( $this->stores->run_data( $identity )->recall( $run_id, $key ) );
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
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed( string $name, string $run_id ): Run|\WP_Error {
		$identity = Identity::compose( $this->scope, $name );
		$result   = BoundaryErrorMapper::map( $this->dispatcher->retry_failed( $identity, $run_id ) );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return self::run( $identity, $result, RunStatus::Running );
	}

	/**
	 * Cancels one retained run that is not executing or pending drained-queue completion.
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
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	public function cancel( string $name, string $run_id ): Run|\WP_Error {
		$identity = Identity::compose( $this->scope, $name );
		$result   = BoundaryErrorMapper::map( $this->dispatcher->cancel( $identity, $run_id ) );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return self::run( $identity, $result, RunStatus::Cancelled );
	}

	// endregion

	// region HELPERS

	/**
	 * Subscribes one execution object's completion role to its identity's completed hook.
	 *
	 * The subscription is a listener on the published hook rather than a private call site, so the
	 * role inherits the hook's payload, its ordering against other listeners, and its at-least-once
	 * delivery instead of acquiring a second set of guarantees to document. It is attached from a
	 * registration verb rather than a component's `register_hooks()` because the object and its
	 * identity meet only here; a component attaches before any scope has declared anything.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity               $identity  Complete scope-qualified work identity.
	 * @param   RunCompletionInterface $execution Registered execution object declaring the completion role.
	 *
	 * @return  void
	 */
	private static function subscribe_completion_role( Identity $identity, RunCompletionInterface $execution ): void {
		// The role's signature is the hook's, so the object's own method is the listener.
		\add_action( 'a8csp_bgje/completed/' . (string) $identity, array( $execution, 'on_completed' ), 10, 3 );
	}

	/**
	 * Returns one value tree's encoded form, rejecting anything that would not survive storage.
	 *
	 * Portability is separate from any byte ceiling because the ceilings differ per surface: start
	 * arguments are capped in JSON bytes, while data is capped by its complete serialized row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $values  Value tree to check.
	 * @param   string                  $subject Complete subject phrase for the rejection message.
	 *
	 * @throws  \InvalidArgumentException When the tree is not a portable, JSON-encodable value.
	 *
	 * @return  string
	 */
	private static function assert_portable_tree( array $values, string $subject ): string {
		try {
			$encoded = \wp_json_encode( $values, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION );
		} catch ( \JsonException ) {
			$encoded = false;
		}

		if ( ! \is_string( $encoded ) || ! PortableArguments::is_valid( $values ) ) {
			// Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( '%s must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.', $subject ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $encoded;
	}

	/**
	 * Rejects a data key that violates the key contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $key  Run data key.
	 * @param   string $name Scope-local job or chunked job name.
	 *
	 * @throws  \InvalidArgumentException When the key violates its grammar or byte ceiling.
	 *
	 * @return  void
	 */
	private static function assert_data_key( string $key, string $name ): void {
		if ( ! RunDataStore::is_valid_key( $key ) ) {
			// Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( 'Background-work "%1$s" data key "%2$s" is invalid; use 1 to %3$d bytes matching [a-z0-9_-]+.', $name, $key, RunDataStore::MAX_KEY_BYTES ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Projects one admitted run into the public boundary value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity  $identity Complete scope-qualified job or chunked job identity.
	 * @param   string    $run_id   Run identifier.
	 * @param   RunStatus $status   Public lifecycle state.
	 * @param   int|null  $ended_at Terminalization timestamp, or null when the projection carries none.
	 *
	 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  Run
	 */
	private static function run( Identity $identity, string $run_id, RunStatus $status, ?int $ended_at = null ): Run {
		return new Run( (string) $identity, RunId::from( $run_id ), $status, $ended_at );
	}

	/**
	 * Composes one declared identity, naming the declaration that carries the invalid name.
	 *
	 * Sync accepts a whole declaration set, so an identity rejection that does not say which entry
	 * failed leaves a consumer bisecting its own array.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scope   Bound scope slug.
	 * @param   string $name    Declared scope-local name.
	 * @param   string $context Concept-specific exception context.
	 *
	 * @throws  \InvalidArgumentException When the scope/name identity is invalid.
	 *
	 * @return  Identity
	 */
	private static function compose_declared( string $scope, string $name, string $context ): Identity {
		try {
			return Identity::compose( $scope, $name );
		} catch ( \InvalidArgumentException $exception ) {
			// Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( '%1$s: %2$s', $context, $exception->getMessage() ), previous: $exception ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
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
	 * @return  \WP_Error|null Payload rejection when the portable arguments exceed the persisted byte limit.
	 */
	private static function assert_portable_args( array $args, string $context ): ?\WP_Error {
		$encoded_args = self::assert_portable_tree( $args, $context . ' arguments' );

		$actual_bytes = \strlen( $encoded_args );
		if ( self::MAX_ARGUMENTS_BYTES >= $actual_bytes ) {
			return null;
		}

		return new \WP_Error( ErrorCode::PayloadRejected->value, \sprintf( '%1$s arguments contain %2$d JSON bytes; the limit is %3$d bytes.', $context, $actual_bytes, self::MAX_ARGUMENTS_BYTES ), array() );
	}

	// endregion
}

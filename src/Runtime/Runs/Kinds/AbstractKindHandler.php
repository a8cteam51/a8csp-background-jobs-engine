<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\KindExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Provides shared registration, lifecycle, and failure defaults for persisted run kinds.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
abstract readonly class AbstractKindHandler implements KindHandlerInterface {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   JobRegistry     $registry             Registered work definitions.
	 * @param   LoggerInterface $logger               Log event sink.
	 * @param   ClockInterface  $clock                Timestamp source.
	 * @param   LockWindows     $lock_windows         Filterable run-lock timing policy.
	 * @param   RunTransitions  $terminal_transitions Fenced terminal-write coordinator.
	 */
	public function __construct(
		private JobRegistry $registry,
		protected LoggerInterface $logger,
		protected ClockInterface $clock,
		protected LockWindows $lock_windows,
		protected RunTransitions $terminal_transitions,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns the opaque persisted kind key.
	 *
	 * Every default that resolves a registration looks it up under this key, so a kind declares it
	 * here rather than in a constant the compiler cannot require.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	#[\Override]
	abstract public function key(): string;

	/**
	 * Validates and registers one definition owned by this kind.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity      $identity   Complete scope-qualified work identity.
	 * @param   JobDefinition $definition Definition resolved to this handler.
	 *
	 * @throws  \InvalidArgumentException When the execution object does not implement this kind's execution role.
	 *
	 * @return  void
	 */
	#[\Override]
	public function register( Identity $identity, JobDefinition $definition ): void {
		$execution_role = $this->execution_role();
		if ( ! $definition->execution instanceof $execution_role ) {
			throw new \InvalidArgumentException( \sprintf( 'Job kind "%1$s" requires execution implementing %2$s; %3$s given.', $this->key(), $execution_role, \get_debug_type( $definition->execution ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
		}

		$this->registry->register( $identity, $definition );
	}

	/**
	 * Returns the registered execution object owned by this kind.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified work identity.
	 *
	 * @return  object|null
	 */
	#[\Override]
	public function execution( Identity $identity ): ?object {
		$execution      = $this->registry->definition_for_kind( $identity, $this->key() )?->execution;
		$execution_role = $this->execution_role();

		return $execution instanceof $execution_role ? $execution : null;
	}

	/**
	 * Returns the registered policy declaration owned by this kind.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified work identity.
	 *
	 * @return  JobOptions|null
	 */
	#[\Override]
	public function options( Identity $identity ): ?JobOptions {
		return $this->registry->definition_for_kind( $identity, $this->key() )?->options;
	}

	/**
	 * Returns whether this handler owns one persisted lifecycle stage.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $stage Persisted lifecycle stage, or null.
	 *
	 * @return  bool
	 */
	#[\Override]
	public function owns_stage( ?string $stage ): bool {
		return \in_array( $stage, $this->stages(), true );
	}

	/**
	 * Returns the initial opaque state for an admitted run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 *
	 * @return  array<array-key, mixed>
	 */
	#[\Override]
	public function initial_kind_state( array $start_args ): array {
		return array();
	}

	/**
	 * Returns the first durable lifecycle action for an admitted run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int|null $fire_at  Absolute first-delivery timestamp, or null for asynchronous admission.
	 * @param   int      $now      Admission timestamp.
	 * @param   int      $priority Scheduler priority.
	 *
	 * @return  PendingAction
	 */
	#[\Override]
	public function initial_pending( ?int $fire_at, int $now, int $priority ): PendingAction {
		$stage = $this->stages()[0];

		return null === $fire_at || $fire_at <= $now
			? PendingAction::async( $stage, $priority )
			: PendingAction::single( $stage, $fire_at, $priority );
	}

	/**
	 * Runs kind-owned admission effects before the first scheduler action is accepted.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified work identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Persisted running state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  EngineError|null Failure returned to the admission caller, or null.
	 */
	#[\Override]
	public function after_dispatch( Identity $identity, string $run_id, RunState $state, RunStore $run_store ): ?EngineError {
		return null;
	}

	/**
	 * Returns a kind-specific cancellation refusal for the current state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $run_id Run identifier.
	 * @param   RunState $state  Current running state.
	 *
	 * @return  EngineError|null
	 */
	#[\Override]
	public function cancellation_error( string $run_id, RunState $state ): ?EngineError {
		return null;
	}

	/**
	 * Returns a bounded future liveness timestamp when registered execution is available.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified work identity.
	 * @param   string   $run_id   Run identifier.
	 * @param   RunState $state    Persisted state admitted for delivery.
	 *
	 * @return  int|null Null when no registered definition can declare an execution lease.
	 */
	#[\Override]
	public function delivery_liveness_at( Identity $identity, string $run_id, RunState $state ): ?int {
		$options = $this->options( $identity );
		if ( null === $options ) {
			return null;
		}

		return $this->execution_lease_at( $options );
	}

	/**
	 * Returns the kind-owned state used to build a completed terminal transition.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunState $state Fenced executing state.
	 *
	 * @return  RunState
	 */
	#[\Override]
	public function completion_state( RunState $state ): RunState {
		return $state;
	}

	/**
	 * Executes the kind-owned persisted lifecycle stage after shared delivery admission.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified work identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Fenced executing state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  void
	 */
	#[\Override]
	abstract public function deliver( Identity $identity, string $run_id, RunState $state, RunStore $run_store ): void;

	/**
	 * Converts an execution throwable to kind-owned failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Throwable $throwable Execution failure.
	 *
	 * @return  EngineError
	 */
	#[\Override]
	public function failure_error( \Throwable $throwable ): EngineError {
		return EngineError::from_throwable( $throwable );
	}

	/**
	 * Classifies an execution failure for this kind's retry policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Throwable $throwable Execution failure.
	 *
	 * @return  bool
	 */
	#[\Override]
	public function is_failure_retryable( \Throwable $throwable ): bool {
		return true;
	}

	/**
	 * Returns kind-specific diagnostic details for the current failure state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunState $state Run state at terminalization.
	 *
	 * @return  array<array-key, mixed>|null
	 */
	#[\Override]
	public function failure_details( RunState $state ): ?array {
		return null;
	}

	/**
	 * Returns the observable queue depth for this kind.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunState $state Live run state.
	 *
	 * @return  int|null
	 */
	#[\Override]
	public function queue_depth( RunState $state ): ?int {
		return null;
	}

	/**
	 * Returns the execution role a definition must implement to register under this kind.
	 *
	 * PHP has no abstract class constant, so a kind declares its role as a method and the compiler
	 * refuses a subclass that omits it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  class-string<KindExecutionInterface>
	 */
	abstract protected function execution_role(): string;

	/**
	 * Returns the lifecycle stages owned by this kind.
	 *
	 * The first stage is the admission stage used by `initial_pending()`. A kind whose admission
	 * stage is not its first declared stage overrides `initial_pending()`. Every stage listed here
	 * needs a `deliver()` arm: a stage this predicate claims and delivery ignores leaves its run
	 * marked executing until the staleness window expires.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  non-empty-list<string>
	 */
	abstract protected function stages(): array;

	// endregion

	// region HELPERS

	/**
	 * Fails a live run whose required execution definition is no longer registered.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified work identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Fenced running state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  void
	 */
	final protected function fail_orphaned_run( Identity $identity, string $run_id, RunState $state, RunStore $run_store ): void {
		$error = new EngineError( \sprintf( '%1$s identity "%2$s" has no registered %1$s implementation for run "%3$s"; register that %1$s or purge the run.', $this->key(), $identity, $run_id ) );
		$this->terminal_transitions->fail_unregistered_run( $this, $identity, $run_id, $state, $run_store, $error );
	}

	/**
	 * Returns the bounded future liveness timestamp for one execution invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   JobOptions $options Registered policy declaration.
	 *
	 * @return  int
	 */
	final protected function execution_lease_at( JobOptions $options ): int {
		$lease = $this->lock_windows->execution_lease( $options->max_runtime );
		$now   = $this->clock->now()->getTimestamp();

		return $now > ( \PHP_INT_MAX - $lease ) ? \PHP_INT_MAX : ( $now + $lease );
	}

	// endregion
}

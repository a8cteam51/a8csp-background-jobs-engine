<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;

\defined( 'ABSPATH' ) || exit;

/**
 * Defines the internal behavior owned by one persisted run kind.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface KindHandlerInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * Lexical grammar for persisted kind and lifecycle stage keys.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string KEY_PATTERN = '/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?\z/';

	// endregion

	// region METHODS

	/**
	 * Returns the opaque persisted kind key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	public function key(): string;

	/**
	 * Validates and registers one definition owned by this kind.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string        $identity   Complete owner-qualified work identity.
	 * @param   JobDefinition $definition Definition resolved to this handler.
	 *
	 * @throws  \InvalidArgumentException When the execution object does not implement this kind's execution role.
	 *
	 * @return  void
	 */
	public function register( string $identity, JobDefinition $definition ): void;

	/**
	 * Returns the registered execution object owned by this kind.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified work identity.
	 *
	 * @return  object|null
	 */
	public function execution( string $identity ): ?object;

	/**
	 * Returns the registered policy declaration owned by this kind.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified work identity.
	 *
	 * @return  JobOptions|null
	 */
	public function options( string $identity ): ?JobOptions;

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
	public function owns_stage( ?string $stage ): bool;

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
	public function initial_kind_state( array $start_args ): array;

	/**
	 * Returns the first durable lifecycle action for an admitted run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $scheduled_at Delivery timestamp.
	 * @param   int $delay        Requested delay in seconds.
	 * @param   int $priority     Scheduler priority.
	 *
	 * @return  PendingAction
	 */
	public function initial_pending( int $scheduled_at, int $delay, int $priority ): PendingAction;

	/**
	 * Returns the admission verb rendered in corrective diagnostics.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	public function dispatch_verb(): string;

	/**
	 * Runs kind-owned effects after the first scheduler action is accepted.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $identity Complete owner-qualified work identity.
	 * @param   string   $run_id   Run identifier.
	 * @param   RunState $state    Persisted running state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  EngineError|null Failure returned to the admission caller, or null.
	 */
	public function after_dispatch( string $identity, string $run_id, RunState $state, RunStore $run_store ): ?EngineError;

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
	public function cancellation_error( string $run_id, RunState $state ): ?EngineError;

	/**
	 * Returns a bounded future liveness timestamp when registered execution is available.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $identity Complete owner-qualified work identity.
	 * @param   string   $run_id   Run identifier.
	 * @param   RunState $state    Persisted state admitted for delivery.
	 *
	 * @return  int|null Null when no registered definition can declare an execution lease.
	 */
	public function delivery_liveness_at( string $identity, string $run_id, RunState $state ): ?int;

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
	public function completion_state( RunState $state ): RunState;

	/**
	 * Executes the kind-owned persisted lifecycle stage after shared delivery admission.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $identity Complete owner-qualified work identity.
	 * @param   string   $run_id   Run identifier.
	 * @param   RunState $state    Fenced executing state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  void
	 */
	public function deliver( string $identity, string $run_id, RunState $state, RunStore $run_store ): void;

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
	public function failure_error( \Throwable $throwable ): EngineError;

	/**
	 * Returns kind-specific diagnostic details for the current failure state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunState $state Run state at terminalization.
	 *
	 * @return  array<array-key, mixed>|null Generic diagnostic payload, or null when no details are available.
	 */
	public function failure_details( RunState $state ): ?array;

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
	public function queue_depth( RunState $state ): ?int;

	// endregion
}

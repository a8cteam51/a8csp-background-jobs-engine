<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\KindHandlerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Typed state persisted for one active run.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class RunState {
	// region FIELDS AND CONSTANTS

	/**
	 * Sequence number of the newest scheduled lifecycle action, which is the only delivery allowed to act.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public int $action_sequence;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{class: string|null, message: string, stage: string, code: string, details?: array<array-key, mixed>}|null $error
	 * @phpstan-param list<string> $effects
	 *
	 * @param   RunStatus               $status                    Lifecycle state.
	 * @param   string                  $kind                      Opaque admitted kind key.
	 * @param   bool                    $executing                 Whether one lifecycle action is executing.
	 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
	 * @param   string                  $args_hash                 Stable single-flight identity derived from arguments or a Job overlap key.
	 * @param   array<array-key, mixed> $kind_state                Opaque payload owned by the kind handler, or an empty array.
	 * @param   int                     $failed_attempts           Failed attempts consumed by the current retry stage: queue
	 *                                                             generation or the current chunk for a chunked job, and handle()
	 *                                                             for a job.
	 * @param   int                     $action_sequence           Newest scheduled lifecycle action sequence.
	 * @param   int                     $created_at                Creation timestamp.
	 * @param   int                     $heartbeat_at              Latest liveness timestamp.
	 * @param   PendingAction|null      $pending                   Durable successor delivery, or null when none exists.
	 * @param   array|null              $error                     Durable terminal failure detail, or null for non-failed runs.
	 * @param   string|null             $previous_completed_run_id Previous completed run identifier frozen for completion delivery, or null.
	 * @param   array                   $effects                   Completed terminal effect keys in execution order.
	 *
	 * @throws  \InvalidArgumentException When the kind key is lexically malformed.
	 */
	public function __construct(
		public RunStatus $status,
		public string $kind,
		public bool $executing,
		public array $start_args,
		public string $args_hash,
		public array $kind_state,
		public int $failed_attempts,
		int $action_sequence,
		public int $created_at,
		public int $heartbeat_at,
		public ?PendingAction $pending = null,
		public ?array $error = null,
		public ?string $previous_completed_run_id = null,
		public array $effects = array(),
	) {
		if ( 1 !== \preg_match( KindHandlerInterface::KEY_PATTERN, $kind ) ) {
			throw new \InvalidArgumentException( 'Run state requires a grammar-valid kind key.' );
		}

		$this->action_sequence = $action_sequence;
	}

	// endregion

	// region METHODS

	/**
	 * Increments an attempt count without overflowing schema-valid integer state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $failed_attempts Failed attempts already consumed.
	 *
	 * @return  int
	 */
	public static function increment_attempts_safely( int $failed_attempts ): int {
		return \PHP_INT_MAX === $failed_attempts
			? \PHP_INT_MAX
			: \max( 1, $failed_attempts + 1 );
	}

	/**
	 * Returns a copy with the supplied lifecycle state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunStatus $status Lifecycle state.
	 *
	 * @return  self
	 */
	public function with_status( RunStatus $status ): self {
		return clone( $this, array( 'status' => $status ) );
	}

	/**
	 * Returns a copy with the supplied lifecycle-action execution marker.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   bool $executing Whether one lifecycle action is executing.
	 *
	 * @return  self
	 */
	public function with_executing( bool $executing ): self {
		return clone( $this, array( 'executing' => $executing ) );
	}

	/**
	 * Returns a copy with the supplied opaque kind-owned payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $kind_state Opaque payload owned by the kind handler, or an empty array.
	 *
	 * @return  self
	 */
	public function with_kind_state( array $kind_state ): self {
		return clone( $this, array( 'kind_state' => $kind_state ) );
	}

	/**
	 * Returns a copy with the supplied failed-attempt count.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $failed_attempts Failed attempts consumed by the current retry stage: queue generation or the current
	 *                                chunk for a chunked job, and handle() for a job.
	 *
	 * @return  self
	 */
	public function with_failed_attempts( int $failed_attempts ): self {
		return clone( $this, array( 'failed_attempts' => $failed_attempts ) );
	}

	/**
	 * Returns a copy with the supplied newest scheduled lifecycle action sequence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $action_sequence Newest scheduled lifecycle action sequence.
	 *
	 * @return  self
	 */
	public function with_action_sequence( int $action_sequence ): self {
		return clone( $this, array( 'action_sequence' => $action_sequence ) );
	}

	/**
	 * Returns a copy with the supplied durable successor delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   PendingAction|null $pending Durable successor delivery, or null when none exists.
	 *
	 * @return  self
	 */
	public function with_pending( ?PendingAction $pending ): self {
		return clone( $this, array( 'pending' => $pending ) );
	}

	/**
	 * Returns a copy with the supplied durable terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{class: string|null, message: string, stage: string, code: string, details?: array<array-key, mixed>}|null $error
	 *
	 * @param   array|null $error Durable terminal failure detail, or null for non-failed runs.
	 *
	 * @return  self
	 */
	public function with_error( ?array $error ): self {
		return clone( $this, array( 'error' => $error ) );
	}

	/**
	 * Returns a copy with the predecessor frozen for completed-run delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $previous_completed_run_id Previous completed run identifier for this identity, or null.
	 *
	 * @return  self
	 */
	public function with_previous_completed_run_id( ?string $previous_completed_run_id ): self {
		return clone( $this, array( 'previous_completed_run_id' => $previous_completed_run_id ) );
	}

	/**
	 * Returns a copy with the supplied completed terminal effect keys.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<string> $effects
	 *
	 * @param   array $effects Completed terminal effect keys in execution order.
	 *
	 * @return  self
	 */
	public function with_effects( array $effects ): self {
		return clone( $this, array( 'effects' => $effects ) );
	}

	/**
	 * Returns a copy with the supplied liveness timestamp.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $heartbeat_at Latest liveness timestamp.
	 *
	 * @return  self
	 */
	public function with_heartbeat_at( int $heartbeat_at ): self {
		return clone( $this, array( 'heartbeat_at' => $heartbeat_at ) );
	}

	// endregion
}

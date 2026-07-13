<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Orchestration;

\defined( 'ABSPATH' ) || exit;

/**
 * Typed state persisted for one active run.
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
	public int $action_seq;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunStatus                     $status          Lifecycle state.
	 * @param   array<array-key, mixed>       $start_args      Arguments supplied when the run started.
	 * @param   string                        $args_hash       Stable identity of the start arguments.
	 * @param   list<array<array-key, mixed>> $queue           Chunks awaiting processing, oldest first.
	 * @param   int                           $chunk_retries   Failed attempts consumed by the current batch chunk; for
	 *                                                         a task, failed handle() attempts in this run.
	 * @param   int                           $action_seq      Newest scheduled lifecycle action sequence.
	 * @param   int                           $created_at      Creation timestamp.
	 * @param   int                           $heartbeat_at    Latest liveness timestamp.
	 */
	public function __construct(
		public RunStatus $status,
		public array $start_args,
		public string $args_hash,
		public array $queue,
		public int $chunk_retries,
		int $action_seq,
		public int $created_at,
		public int $heartbeat_at,
	) {
		$this->action_seq = $action_seq;
	}

	// endregion

	// region METHODS

	/**
	 * Increments an attempt count without overflowing schema-valid integer state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $chunk_retries Failed attempts already consumed.
	 *
	 * @return  int
	 */
	public static function increment_attempts_safely( int $chunk_retries ): int {
		return \PHP_INT_MAX === $chunk_retries
			? \PHP_INT_MAX
			: \max( 1, $chunk_retries + 1 );
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
		return new self(
			status: $status,
			start_args: $this->start_args,
			args_hash: $this->args_hash,
			queue: $this->queue,
			chunk_retries: $this->chunk_retries,
			action_seq: $this->action_seq,
			created_at: $this->created_at,
			heartbeat_at: $this->heartbeat_at,
		);
	}

	/**
	 * Returns a copy with the supplied pending chunks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<array<array-key, mixed>> $queue Chunks awaiting processing, oldest first.
	 *
	 * @return  self
	 */
	public function with_queue( array $queue ): self {
		return new self(
			status: $this->status,
			start_args: $this->start_args,
			args_hash: $this->args_hash,
			queue: $queue,
			chunk_retries: $this->chunk_retries,
			action_seq: $this->action_seq,
			created_at: $this->created_at,
			heartbeat_at: $this->heartbeat_at,
		);
	}

	/**
	 * Returns a copy with the supplied failed-attempt count.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $chunk_retries Failed attempts consumed by the current batch chunk; for a task, failed handle()
	 *                              attempts in this run.
	 *
	 * @return  self
	 */
	public function with_chunk_retries( int $chunk_retries ): self {
		return new self(
			status: $this->status,
			start_args: $this->start_args,
			args_hash: $this->args_hash,
			queue: $this->queue,
			chunk_retries: $chunk_retries,
			action_seq: $this->action_seq,
			created_at: $this->created_at,
			heartbeat_at: $this->heartbeat_at,
		);
	}

	/**
	 * Returns a copy with the supplied newest scheduled lifecycle action sequence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $action_seq Newest scheduled lifecycle action sequence.
	 *
	 * @return  self
	 */
	public function with_action_seq( int $action_seq ): self {
		return new self(
			status: $this->status,
			start_args: $this->start_args,
			args_hash: $this->args_hash,
			queue: $this->queue,
			chunk_retries: $this->chunk_retries,
			action_seq: $action_seq,
			created_at: $this->created_at,
			heartbeat_at: $this->heartbeat_at,
		);
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
		return new self(
			status: $this->status,
			start_args: $this->start_args,
			args_hash: $this->args_hash,
			queue: $this->queue,
			chunk_retries: $this->chunk_retries,
			action_seq: $this->action_seq,
			created_at: $this->created_at,
			heartbeat_at: $heartbeat_at,
		);
	}

	// endregion
}

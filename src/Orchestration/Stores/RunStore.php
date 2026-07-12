<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\ClockInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\RunStatus;

\defined( 'ABSPATH' ) || exit;

/**
 * Persists each active run in one consolidated WordPress option.
 *
 * Queue changes use read-modify-write and remain safe while each run's processing chain is serial.
 * Concurrent processors for one run can overwrite each other's queue changes, so per-run
 * concurrency remains one.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class RunStore {
	// region FIELDS AND CONSTANTS

	private const OPTION_PREFIX = 'a8csp_bgte_run_';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $name  Stable task or batch name.
	 * @param   ClockInterface $clock Timestamp source.
	 */
	public function __construct(
		private string $name,
		private ClockInterface $clock,
	) {}

	// endregion

	// region METHODS

	/**
	 * Creates and returns the initial running state when its option is added.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                        $run_id     Run identifier.
	 * @param   array<array-key, mixed>       $start_args Arguments supplied when the run starts.
	 * @param   string                        $args_hash  Stable identity of the start arguments.
	 * @param   list<array<array-key, mixed>> $queue      Initial chunks in processing order.
	 *
	 * @return  RunState|null Null when the run option cannot be added.
	 */
	public function create( string $run_id, array $start_args, string $args_hash, array $queue ): ?RunState {
		$now   = $this->clock->now();
		$state = new RunState(
			status: RunStatus::Running,
			start_args: $start_args,
			args_hash: $args_hash,
			queue: $queue,
			chunk_retries: 0,
			created_at: $now,
			heartbeat_at: $now,
		);

		if ( ! \add_option( $this->option_name( $run_id ), self::to_option( $state ), '', false ) ) {
			return null;
		}

		return $state;
	}

	/**
	 * Returns the typed state for a recoverable run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @return  RunState|null
	 */
	public function get( string $run_id ): ?RunState {
		$state = self::from_option( \get_option( $this->option_name( $run_id ), null ) );
		if ( null === $state ) {
			// A vanished or corrupted run is unrecoverable, so callers treat it as no run.
			return null;
		}

		return $state;
	}

	/**
	 * Saves the complete state for an active run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $run_id Run identifier.
	 * @param   RunState $state  Complete state to persist.
	 *
	 * @return  void
	 */
	public function save( string $run_id, RunState $state ): void {
		\update_option( $this->option_name( $run_id ), self::to_option( $state ), false );
	}

	/**
	 * Refreshes and saves a recoverable run's heartbeat.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @return  RunState|null Null when no recoverable run exists.
	 */
	public function refresh_heartbeat( string $run_id ): ?RunState {
		$state = $this->get( $run_id );
		if ( null === $state ) {
			return null;
		}

		$state = $state->with_heartbeat_at( $this->clock->now() );
		$this->save( $run_id, $state );

		return $state;
	}

	/**
	 * Deletes a run's consolidated option.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @return  void
	 */
	public function delete( string $run_id ): void {
		\delete_option( $this->option_name( $run_id ) );
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the option name for a run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @return  string
	 */
	private function option_name( string $run_id ): string {
		return self::OPTION_PREFIX . $this->name . '_' . $run_id;
	}

	/**
	 * Converts typed state to its persisted option shape.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunState $state Typed run state.
	 *
	 * @return  array{
	 *     status: string,
	 *     start_args: array<array-key, mixed>,
	 *     args_hash: string,
	 *     queue: list<array<array-key, mixed>>,
	 *     chunk_retries: int,
	 *     created_at: int,
	 *     heartbeat_at: int
	 * }
	 */
	private static function to_option( RunState $state ): array {
		return array(
			'status'        => $state->status->value,
			'start_args'    => $state->start_args,
			'args_hash'     => $state->args_hash,
			'queue'         => $state->queue,
			'chunk_retries' => $state->chunk_retries,
			'created_at'    => $state->created_at,
			'heartbeat_at'  => $state->heartbeat_at,
		);
	}

	/**
	 * Hydrates typed state from a valid persisted shape.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $value Persisted option value.
	 *
	 * @return  RunState|null
	 */
	private static function from_option( mixed $value ): ?RunState {
		if ( ! self::is_stored_state( $value ) ) {
			return null;
		}

		$status = RunStatus::tryFrom( $value['status'] );
		if ( null === $status ) {
			return null;
		}

		return new RunState(
			status: $status,
			start_args: $value['start_args'],
			args_hash: $value['args_hash'],
			queue: $value['queue'],
			chunk_retries: $value['chunk_retries'],
			created_at: $value['created_at'],
			heartbeat_at: $value['heartbeat_at'],
		);
	}

	/**
	 * Returns whether a value carries every persisted field with its required type.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-assert-if-true array{
	 *     status: string,
	 *     start_args: array<array-key, mixed>,
	 *     args_hash: string,
	 *     queue: list<array<array-key, mixed>>,
	 *     chunk_retries: int,
	 *     created_at: int,
	 *     heartbeat_at: int
	 * } $value
	 *
	 * @param   mixed $value Persisted option value.
	 *
	 * @return  bool
	 */
	private static function is_stored_state( mixed $value ): bool {
		if (
			! \is_array( $value )
			|| ! \is_string( $value['status'] ?? null )
			|| ! \is_array( $value['start_args'] ?? null )
			|| ! \is_string( $value['args_hash'] ?? null )
			|| ! \is_array( $value['queue'] ?? null )
			|| ! \array_is_list( $value['queue'] )
			|| ! \is_int( $value['chunk_retries'] ?? null )
			|| ! \is_int( $value['created_at'] ?? null )
			|| ! \is_int( $value['heartbeat_at'] ?? null )
		) {
			return false;
		}

		foreach ( $value['queue'] as $chunk ) {
			if ( ! \is_array( $chunk ) ) {
				return false;
			}
		}

		return true;
	}

	// endregion
}

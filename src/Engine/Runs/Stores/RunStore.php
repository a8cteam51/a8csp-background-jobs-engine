<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;
use Psr\Clock\ClockInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Persists each active run in one consolidated WordPress option.
 *
 * Live mutations use complete-state compare-and-swap transitions. A concurrent processor that
 * observes an older state loses its transition instead of overwriting or recreating the run.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class RunStore {
	// region FIELDS AND CONSTANTS

	/**
	 * Prefix for active-run option names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
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
	 * @param   OptionRows     $rows  Authoritative raw option-row I/O.
	 */
	public function __construct(
		private string $name,
		private ClockInterface $clock,
		private OptionRows $rows,
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
		// Run options persist Unix-second integers.
		$now   = $this->clock->now()->getTimestamp();
		$state = new RunState(
			status: RunStatus::Running,
			executing: false,
			start_args: $start_args,
			args_hash: $args_hash,
			queue: $queue,
			chunk_retries: 0,
			action_seq: 1,
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
	 * Returns one authoritative raw run snapshot with its optional typed state.
	 *
	 * @internal Engine fencing and maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @return  AbstractResult<array{raw: string, state: RunState|null}|null, EngineError>
	 */
	#[\NoDiscard( 'a run-state read outcome must be handled, not dropped' )]
	public function inspect( string $run_id ): AbstractResult {
		$selected = $this->rows->read( $this->option_name( $run_id ) );
		if ( $selected->is_failure() ) {
			return $selected;
		}

		$raw = $selected->value;
		if ( null === $raw ) {
			return new Success( null );
		}

		return new Success(
			array(
				'raw'   => $raw,
				'state' => self::from_option( RawOptionDecoder::decode( $raw ) ),
			)
		);
	}

	/**
	 * Transitions a run only while its exact observed raw state still matches.
	 *
	 * @internal Engine terminalization only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $run_id      Run identifier.
	 * @param   string   $expected_raw Exact observed state.
	 * @param   RunState $replacement Replacement state.
	 *
	 * @return  string|null Exact replacement bytes on success, or null after a lost transition.
	 */
	public function transition( string $run_id, string $expected_raw, RunState $replacement ): ?string {
		$replacement_raw = self::serialize_state( $replacement );
		if ( ! $this->rows->replace( $this->option_name( $run_id ), $expected_raw, $replacement_raw ) ) {
			return null;
		}

		return $replacement_raw;
	}

	/**
	 * Replaces a run only while its complete typed state still matches.
	 *
	 * @internal Engine live-state and terminal transitions only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $run_id      Run identifier.
	 * @param   RunState $expected    Complete state observed before the transition.
	 * @param   RunState $replacement Complete replacement state.
	 *
	 * @return  string|null Exact replacement bytes on success, or null after a lost transition.
	 */
	public function transition_state( string $run_id, RunState $expected, RunState $replacement ): ?string {
		$replacement_raw = self::serialize_state( $replacement );
		if ( ! $this->rows->replace(
			$this->option_name( $run_id ),
			self::serialize_state( $expected ),
			$replacement_raw
		) ) {
			return null;
		}

		return $replacement_raw;
	}

	/**
	 * Deletes a run only while its exact terminal snapshot still matches.
	 *
	 * @internal Engine terminalization and maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id      Run identifier.
	 * @param   string $expected_raw Exact terminal or corrupt snapshot.
	 *
	 * @return  bool Whether this caller deleted the exact row.
	 */
	public function delete_exact( string $run_id, string $expected_raw ): bool {
		return $this->rows->delete( $this->option_name( $run_id ), $expected_raw );
	}

	/**
	 * Refreshes a recoverable run's heartbeat and marks its lifecycle action executing.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string        $run_id   Run identifier.
	 * @param   RunState|null $expected Complete state already observed by the caller, or null to inspect it here.
	 * @param   int|null      $at       Liveness timestamp, or null to use the current clock time.
	 *
	 * @return  RunState|null Null when the run is absent, invalid, or changed concurrently.
	 */
	public function refresh_heartbeat( string $run_id, ?RunState $expected = null, ?int $at = null ): ?RunState {
		$raw = null;
		if ( null === $expected ) {
			$inspected = $this->inspect( $run_id );
			if ( $inspected->is_failure() ) {
				return null;
			}

			$snapshot = $inspected->value;
			if ( null === $snapshot || null === $snapshot['state'] ) {
				return null;
			}

			$raw      = $snapshot['raw'];
			$expected = $snapshot['state'];
		}

		$replacement     = $expected
			->with_heartbeat_at( $at ?? $this->clock->now()->getTimestamp() )
			->with_executing( true );
		$replacement_raw = null === $raw
			? $this->transition_state( $run_id, $expected, $replacement )
			: $this->transition( $run_id, $raw, $replacement );

		return null !== $replacement_raw ? $replacement : null;
	}

	/**
	 * Deletes a run's consolidated option.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @return  bool True when the run option is confirmed absent.
	 */
	public function delete( string $run_id ): bool {
		\delete_option( $this->option_name( $run_id ) );
		$missing = new \stdClass();

		return \get_option( $this->option_name( $run_id ), $missing ) === $missing;
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
	 *     executing: bool,
	 *     start_args: array<array-key, mixed>,
	 *     args_hash: string,
	 *     queue: list<array<array-key, mixed>>,
	 *     chunk_retries: int,
	 *     action_seq: int,
	 *     created_at: int,
	 *     heartbeat_at: int
	 * }
	 */
	private static function to_option( RunState $state ): array {
		return array(
			'status'        => $state->status->value,
			'executing'     => $state->executing,
			'start_args'    => $state->start_args,
			'args_hash'     => $state->args_hash,
			'queue'         => $state->queue,
			'chunk_retries' => $state->chunk_retries,
			'action_seq'    => $state->action_seq,
			'created_at'    => $state->created_at,
			'heartbeat_at'  => $state->heartbeat_at,
		);
	}

	/**
	 * Returns a run state's exact WordPress option representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunState $state Typed run state.
	 *
	 * @throws  \LogicException When WordPress does not serialize the run state to a string.
	 *
	 * @return  string
	 */
	private static function serialize_state( RunState $state ): string {
		$raw = \maybe_serialize( self::to_option( $state ) );
		if ( ! \is_string( $raw ) ) {
			throw new \LogicException( 'WordPress must serialize a consolidated run state to a string.' );
		}

		return $raw;
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
			executing: $value['executing'],
			start_args: $value['start_args'],
			args_hash: $value['args_hash'],
			queue: $value['queue'],
			chunk_retries: $value['chunk_retries'],
			action_seq: $value['action_seq'],
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
	 *     executing: bool,
	 *     start_args: array<array-key, mixed>,
	 *     args_hash: string,
	 *     queue: list<array<array-key, mixed>>,
	 *     chunk_retries: int,
	 *     action_seq: int,
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
			|| ! \is_bool( $value['executing'] ?? null )
			|| ! \is_array( $value['start_args'] ?? null )
			|| ! \is_string( $value['args_hash'] ?? null )
			|| ! \is_array( $value['queue'] ?? null )
			|| ! \array_is_list( $value['queue'] )
			|| ! \is_int( $value['chunk_retries'] ?? null )
			|| ! \is_int( $value['action_seq'] ?? null )
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

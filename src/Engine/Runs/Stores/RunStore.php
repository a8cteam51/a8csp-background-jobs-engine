<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RowDeleteOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\PortableArguments;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use Psr\Clock\ClockInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Persists each active run in one consolidated WordPress option.
 *
 * Live mutations use complete-state compare-and-swap transitions. A concurrent processor that
 * observes an older state loses its transition instead of overwriting or recreating the run.
 *
 * @internal
 *
 * @phpstan-type StoredPendingAction = array{stage: 'start'|'run'|'continue'|'cleanup', mode: 'async', fire_at: null, priority: int}|array{stage: 'run'|'continue', mode: 'single', fire_at: int, priority: int}
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
	public const string OPTION_PREFIX = 'a8csp_bgte_run_';

	/**
	 * Maximum exact-row attempts before a contended terminal effect append fails safely.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const TERMINAL_EFFECT_ATTEMPTS = 5;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $identity Complete owner-qualified task or batch identity.
	 * @param   ClockInterface $clock    Timestamp source.
	 * @param   OptionRows     $rows     Authoritative raw option-row I/O.
	 */
	public function __construct(
		private string $identity,
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
	 * @param   string                        $args_hash  Stable single-flight identity.
	 * @param   list<array<array-key, mixed>> $queue      Initial chunks in processing order.
	 * @param   PendingAction|null            $pending    Durable successor delivery, or null when none exists.
	 *
	 * @return  RunState|null Null when the run option cannot be added.
	 */
	public function create( string $run_id, array $start_args, string $args_hash, array $queue, ?PendingAction $pending = null ): ?RunState {
		// The second-granularity integer invariant keeps caller timestamp bounds such as PHP_INT_MAX - $now overflow-safe.
		$now   = $this->clock->now()->getTimestamp();
		$state = new RunState( status: RunStatus::Running, executing: false, start_args: $start_args, args_hash: $args_hash, queue: $queue, failed_attempts: 0, action_seq: 1, created_at: $now, heartbeat_at: $now, pending: $pending, );

		if ( ! \add_option( RunIdentity::option_name( $this->identity, $run_id ), self::to_option( $state ), '', false ) ) {
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
		$selected = $this->rows->read( RunIdentity::option_name( $this->identity, $run_id ) );
		if ( $selected->is_failure() ) {
			return null;
		}

		$raw = $selected->value;
		if ( null === $raw ) {
			return null;
		}

		$state = self::from_option( RawOptionDecoder::decode( $raw ) );
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
		$selected = $this->rows->read( RunIdentity::option_name( $this->identity, $run_id ) );
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
	 * @throws  \LogicException When the current site differs from the bound site or WordPress does
	 *                          not serialize the run state to a string.
	 *
	 * @return  string|null Exact replacement bytes on success, or null after a lost transition.
	 */
	public function replace_if_raw_matches( string $run_id, string $expected_raw, RunState $replacement ): ?string {
		$replacement_raw = self::serialize_state( $replacement );
		if ( ! $this->rows->compare_and_swap( RunIdentity::option_name( $this->identity, $run_id ), $expected_raw, $replacement_raw ) ) {
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
	 * @throws  \LogicException When the current site differs from the bound site or WordPress does
	 *                          not serialize the run state to a string.
	 *
	 * @return  string|null Exact replacement bytes on success, or null after a lost transition.
	 */
	public function replace_if_state_matches( string $run_id, RunState $expected, RunState $replacement ): ?string {
		$replacement_raw = self::serialize_state( $replacement );
		if ( ! $this->rows->compare_and_swap( RunIdentity::option_name( $this->identity, $run_id ), self::serialize_state( $expected ), $replacement_raw ) ) {
			return null;
		}

		return $replacement_raw;
	}

	/**
	 * Appends one terminal effect key without losing concurrently persisted progress.
	 *
	 * The supplied raw snapshot is the first exact compare-and-swap precondition. A lost comparison
	 * retries from authoritative bytes, while a rival append of the same key satisfies this request.
	 *
	 * @internal Engine terminal-effect coordination only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $run_id      Run identifier.
	 * @param   RunState $expected    Typed state decoded from the supplied raw snapshot.
	 * @param   string   $expected_raw Exact observed state.
	 * @param   string   $effect      Non-empty terminal effect key.
	 *
	 * @throws  \InvalidArgumentException When the effect key is empty.
	 * @throws  \LogicException           When the current site differs from the bound site or WordPress
	 *                                     does not serialize the run state to a string.
	 *
	 * @return  array{raw: string, state: RunState}|null Caller-supplied snapshot when it already contains the key, which can omit concurrent effects; otherwise a persisted snapshot containing the key, or null when the row is absent, invalid, unreadable, or remains contended.
	 */
	#[\NoDiscard( 'a terminal effect persistence outcome must be handled, not dropped' )]
	public function append_terminal_effect( string $run_id, RunState $expected, string $expected_raw, string $effect ): ?array {
		if ( '' === $effect ) {
			throw new \InvalidArgumentException( 'A terminal effect key cannot be empty.' );
		}

		$state = $expected;
		$raw   = $expected_raw;
		for ( $attempt = 0; $attempt < self::TERMINAL_EFFECT_ATTEMPTS; ++$attempt ) {
			if ( \in_array( $effect, $state->effects, true ) ) {
				return array(
					'raw'   => $raw,
					'state' => $state,
				);
			}

			$effects         = $state->effects;
			$effects[]       = $effect;
			$replacement     = $state->with_effects( $effects );
			$replacement_raw = $this->replace_if_raw_matches( $run_id, $raw, $replacement );
			if ( null !== $replacement_raw ) {
				return array(
					'raw'   => $replacement_raw,
					'state' => $replacement,
				);
			}

			$inspected = $this->inspect( $run_id );
			if ( $inspected->is_failure() ) {
				return null;
			}

			$snapshot = $inspected->value;
			if ( null === $snapshot || null === $snapshot['state'] ) {
				return null;
			}
			if ( \in_array( $effect, $snapshot['state']->effects, true ) ) {
				return $snapshot;
			}

			$raw   = $snapshot['raw'];
			$state = $snapshot['state'];
		}

		return null;
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
		return RowDeleteOutcome::Deleted === $this->rows->delete_if_value_matches( RunIdentity::option_name( $this->identity, $run_id ), $expected_raw );
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
	public function mark_executing_with_heartbeat( string $run_id, ?RunState $expected = null, ?int $at = null ): ?RunState {
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

		$replacement     = $expected->with_heartbeat_at( $at ?? $this->clock->now()->getTimestamp() )->with_executing( true );
		$replacement_raw = null === $raw
			? $this->replace_if_state_matches( $run_id, $expected, $replacement )
			: $this->replace_if_raw_matches( $run_id, $raw, $replacement );

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
		\delete_option( RunIdentity::option_name( $this->identity, $run_id ) );
		$selected = $this->rows->read( RunIdentity::option_name( $this->identity, $run_id ) );
		if ( $selected->is_failure() ) {
			return false;
		}

		return null === $selected->value;
	}

	// endregion

	// region HELPERS

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
	 *     failed_attempts: int,
	 *     action_seq: int,
	 *     created_at: int,
	 *     heartbeat_at: int,
	 *     pending?: array{stage: string, mode: 'async'|'single', fire_at: int|null, priority: int},
	 *     error?: array{class: string|null, message: string, stage: string, code: string, failed_chunk?: array<array-key, mixed>},
	 *     effects?: non-empty-list<string>
	 * }
	 */
	private static function to_option( RunState $state ): array {
		$option = array(
			'status'          => $state->status->value,
			'executing'       => $state->executing,
			'start_args'      => $state->start_args,
			'args_hash'       => $state->args_hash,
			'queue'           => $state->queue,
			'failed_attempts' => $state->failed_attempts,
			'action_seq'      => $state->action_seq,
			'created_at'      => $state->created_at,
			'heartbeat_at'    => $state->heartbeat_at,
		);
		if ( null !== $state->pending ) {
			$option['pending'] = array(
				'stage'    => $state->pending->stage,
				'mode'     => $state->pending->mode,
				'fire_at'  => $state->pending->fire_at,
				'priority' => $state->pending->priority,
			);
		}
		if ( null !== $state->error ) {
			$option['error'] = $state->error;
		}
		if ( array() !== $state->effects ) {
			$option['effects'] = $state->effects;
		}

		return $option;
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
		$error   = $value['error'] ?? null;
		$effects = $value['effects'] ?? array();
		if (
			( RunStatus::Failed !== $status && null !== $error )
			|| ( RunStatus::Running === $status && array() !== $effects )
		) {
			return null;
		}
		$stored_pending = $value['pending'] ?? null;
		$pending        = null;
		if ( null !== $stored_pending ) {
			$pending = 'async' === $stored_pending['mode']
				? PendingAction::async( $stored_pending['stage'], $stored_pending['priority'] )
				: PendingAction::single( $stored_pending['stage'], $stored_pending['fire_at'], $stored_pending['priority'] );
		}

		return new RunState( status: $status, executing: $value['executing'], start_args: $value['start_args'], args_hash: $value['args_hash'], queue: $value['queue'], failed_attempts: $value['failed_attempts'], action_seq: $value['action_seq'], created_at: $value['created_at'], heartbeat_at: $value['heartbeat_at'], pending: $pending, error: $error, effects: $effects, );
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
	 *     failed_attempts: int,
	 *     action_seq: int,
	 *     created_at: int,
	 *     heartbeat_at: int,
	 *     pending?: StoredPendingAction,
	 *     error?: array{class: string|null, message: string, stage: string, code: string, failed_chunk?: array<array-key, mixed>},
	 *     effects?: non-empty-list<string>
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
			|| ! PortableArguments::is_valid( $value['start_args'] )
			|| ! \is_string( $value['args_hash'] ?? null )
			|| ! \is_array( $value['queue'] ?? null )
			|| ! \array_is_list( $value['queue'] )
			|| ! \is_int( $value['failed_attempts'] ?? null )
			|| ! \is_int( $value['action_seq'] ?? null )
			|| ! \is_int( $value['created_at'] ?? null )
			|| ! \is_int( $value['heartbeat_at'] ?? null )
			|| ( \array_key_exists( 'pending', $value ) && ! self::is_stored_pending( $value['pending'] ) )
			|| ( \array_key_exists( 'error', $value ) && ! self::is_stored_error( $value['error'] ) )
			|| ( \array_key_exists( 'effects', $value ) && ! self::is_stored_effects( $value['effects'] ) )
		) {
			return false;
		}

		return \array_all( $value['queue'], static fn ( mixed $chunk ): bool => \is_array( $chunk ) && PortableArguments::is_valid( $chunk ) );
	}

	/**
	 * Returns whether a value is one complete deterministic successor descriptor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-assert-if-true StoredPendingAction $value
	 *
	 * @param   mixed $value Persisted pending-action descriptor.
	 *
	 * @return  bool
	 */
	private static function is_stored_pending( mixed $value ): bool {
		if (
			! \is_array( $value )
			|| 4 !== \count( $value )
			|| ! \is_string( $value['stage'] ?? null )
			|| ! \in_array( $value['stage'], array( 'start', 'run', 'continue', 'cleanup' ), true )
			|| ! \is_string( $value['mode'] ?? null )
			|| ! \in_array( $value['mode'], array( 'async', 'single' ), true )
			|| ! \array_key_exists( 'fire_at', $value )
			|| ( null !== $value['fire_at'] && ! \is_int( $value['fire_at'] ) )
			|| ! \is_int( $value['priority'] ?? null )
		) {
			return false;
		}

		// The acceptance set is exactly PendingAction's six factory combinations: async pairs with every
		// guard-permitted stage, while single pairs only with run and continue — no writer has ever
		// produced another pairing, so anything else is a corrupt row rather than a hydratable state.
		return 'async' === $value['mode']
			? null === $value['fire_at']
			: \is_int( $value['fire_at'] ) && \in_array( $value['stage'], array( 'run', 'continue' ), true );
	}

	/**
	 * Returns whether a value is complete durable terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-assert-if-true array{class: string|null, message: string, stage: string, code: string, failed_chunk?: array<array-key, mixed>} $value
	 *
	 * @param   mixed $value Persisted terminal failure detail.
	 *
	 * @return  bool
	 */
	private static function is_stored_error( mixed $value ): bool {
		if (
			! \is_array( $value )
			|| ( 4 !== \count( $value ) && 5 !== \count( $value ) )
			|| ! \array_key_exists( 'class', $value )
			|| ( null !== $value['class'] && ! \is_string( $value['class'] ) )
			|| ! \is_string( $value['message'] ?? null )
			|| ! \is_string( $value['stage'] ?? null )
			|| null === RunFailureStage::tryFrom( $value['stage'] )
			|| ! \is_string( $value['code'] ?? null )
			|| null === ApiErrorCode::tryFrom( $value['code'] )
		) {
			return false;
		}

		return ! \array_key_exists( 'failed_chunk', $value )
			|| ( \is_array( $value['failed_chunk'] ) && PortableArguments::is_valid( $value['failed_chunk'] ) );
	}

	/**
	 * Returns whether a value is a non-empty unique list of terminal effect keys.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-assert-if-true non-empty-list<string> $value
	 *
	 * @param   mixed $value Persisted completed terminal effect keys.
	 *
	 * @return  bool
	 */
	private static function is_stored_effects( mixed $value ): bool {
		if ( ! \is_array( $value ) || array() === $value || ! \array_is_list( $value ) ) {
			return false;
		}

		$seen = array();
		foreach ( $value as $effect ) {
			if ( ! \is_string( $effect ) || '' === $effect || isset( $seen[ $effect ] ) ) {
				return false;
			}

			$seen[ $effect ] = true;
		}

		return true;
	}

	// endregion
}

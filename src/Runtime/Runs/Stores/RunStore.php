<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\KindHandlerInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RowDeleteOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RowWriteOutcome;
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
 * @phpstan-type StoredError = array{class: string|null, message: string, stage: string, code: string, details?: array<array-key, mixed>, ...<array-key, mixed>}
 * @phpstan-type StoredPendingAction = array{stage: string, mode: 'async', fire_at: null, priority: int}|array{stage: string, mode: 'single', fire_at: int, priority: int}
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
	public const string OPTION_PREFIX = 'a8csp_bgje_active_run_';

	/**
	 * Maximum persisted serialization bytes accepted for one kind-owned state payload.
	 *
	 * The producer budget subtracts the variable row envelope reserve from the authoritative
	 * full-row ceiling so ordinary oversized payloads fail before complete-row serialization.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int MAX_KIND_STATE_BYTES = self::MAX_ROW_BYTES - self::ROW_ENVELOPE_RESERVE_BYTES;

	/**
	 * Maximum persisted serialization bytes accepted for one complete active-run row.
	 *
	 * A decimal megabyte stays below Memcached's default 1 MiB item ceiling, leaving 48,576 bytes
	 * for the cache key, item metadata, and object-cache serialization wrappers. Every engine-owned
	 * option row shares that substrate, so `MAX_KIND_STATE_BYTES` and `FailedRunStore::MAX_ROW_BYTES`
	 * derive from this value and follow a change to it on their own.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int MAX_ROW_BYTES = 1_000_000;

	/**
	 * Bytes reserved for variable active-run fields outside the kind-owned state.
	 *
	 * Twice `Runtime\ScopeOperations::MAX_ARGUMENTS_BYTES` budgets the JSON-bounded start arguments,
	 * PHP-serialization expansion, and fixed lifecycle metadata. The complete serialized row remains
	 * authoritative for higher-overhead shapes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int ROW_ENVELOPE_RESERVE_BYTES = 16_384;

	/**
	 * Maximum exact-row attempts before a contended terminal effect append fails safely.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int TERMINAL_EFFECT_ATTEMPTS = 5;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $identity Complete scope-qualified job or chunked job identity.
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
	 * @param   string                  $run_id     Run identifier.
	 * @param   string                  $kind       Opaque admitted kind key.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   string                  $args_hash  Stable single-flight identity.
	 * @param   array<array-key, mixed> $kind_state Initial opaque kind-owned state payload.
	 * @param   PendingAction|null      $pending    Durable successor delivery, or null when none exists.
	 * @param   int|null                $priority   Admitted scheduler priority, or null to derive it from the pending descriptor
	 *                                               or engine default.
	 *
	 * @throws  \InvalidArgumentException When the kind key or priority is invalid.
	 * @throws  \LogicException           When the current site differs from the bound site or WordPress does not serialize
	 *                                    the kind-owned or complete run state to a string.
	 *
	 * @return  RunState|Failure<EngineError>|null Payload rejection when the kind-owned or complete run state cannot cross the persistence boundary, or null when the run option cannot be added.
	 */
	public function create( string $run_id, string $kind, array $start_args, string $args_hash, array $kind_state, ?PendingAction $pending = null, ?int $priority = null ): RunState|Failure|null {
		// The second-granularity integer invariant keeps caller timestamp bounds such as PHP_INT_MAX - $now overflow-safe.
		$now   = $this->clock->now()->getTimestamp();
		$state = new RunState( status: RunStatus::Running, kind: $kind, executing: false, start_args: $start_args, args_hash: $args_hash, kind_state: $kind_state, failed_attempts: 0, action_sequence: 1, created_at: $now, heartbeat_at: $now, pending: $pending, priority: $priority, );

		$rejected = self::kind_state_failure( $state->kind_state );
		if ( null !== $rejected ) {
			return $rejected;
		}

		$raw      = self::serialize_state( $state );
		$rejected = self::serialized_row_failure( $raw );
		if ( null !== $rejected ) {
			return $rejected;
		}

		if ( RowWriteOutcome::Won !== $this->rows->insert_if_absent( RunIdentity::raw_option_name( $this->identity, $run_id ), $raw ) ) {
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
		$selected = $this->rows->read( RunIdentity::raw_option_name( $this->identity, $run_id ) );
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
		$selected = $this->rows->read( RunIdentity::raw_option_name( $this->identity, $run_id ) );
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
	 * Returns every canonical active-run snapshot for the bound identity.
	 *
	 * @internal Explicit lock repair only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<list<array{run_id: string, raw: string, state: RunState|null}>, EngineError>
	 */
	#[\NoDiscard( 'an exhaustive run-state read outcome must be handled, not dropped' )]
	public function inspect_all(): AbstractResult {
		$names = $this->rows->option_names( RunIdentity::raw_option_name_prefix( $this->identity ) );
		if ( $names->is_failure() ) {
			return $names;
		}

		$snapshots = array();
		foreach ( $names->value as $option_name ) {
			$run_identity = RunIdentity::from_option_name( $option_name );
			if ( null === $run_identity || $this->identity !== (string) $run_identity['identity'] ) {
				continue;
			}

			$run_id    = $run_identity['run_id'];
			$inspected = $this->inspect( $run_id );
			if ( $inspected->is_failure() ) {
				return $inspected;
			}
			if ( null === $inspected->value ) {
				continue;
			}

			$snapshots[] = array(
				'run_id' => $run_id,
				'raw'    => $inspected->value['raw'],
				'state'  => $inspected->value['state'],
			);
		}

		return new Success( $snapshots );
	}

	/**
	 * Transitions a run only while its exact observed raw state still matches.
	 *
	 * @internal Engine terminalization only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $run_id       Run identifier.
	 * @param   string   $expected_raw Exact observed state.
	 * @param   RunState $replacement  Replacement state.
	 *
	 * @throws  \LogicException When the current site differs from the bound site or WordPress does
	 *                          not serialize the run state to a string.
	 *
	 * @return  string|Failure<EngineError>|null Exact replacement bytes when the write wins, payload rejection when the kind-owned or complete run state cannot cross the persistence boundary, otherwise null.
	 */
	public function replace_if_raw_matches( string $run_id, string $expected_raw, RunState $replacement ): string|Failure|null {
		$write = $this->replace_if_raw_matches_classified( $run_id, $expected_raw, $replacement );
		if ( $write instanceof Failure ) {
			return $write;
		}

		return RowWriteOutcome::Won === $write['outcome'] ? $write['raw'] : null;
	}

	/**
	 * Classifies an exact raw-state replacement without collapsing write failure into contention.
	 *
	 * @internal Engine terminalization only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $run_id       Run identifier.
	 * @param   string   $expected_raw Exact observed state.
	 * @param   RunState $replacement  Replacement state.
	 *
	 * @throws  \LogicException When the current site differs from the bound site or WordPress does
	 *                          not serialize the run state to a string.
	 *
	 * @return  array{outcome: RowWriteOutcome, raw: string}|Failure<EngineError> Exact write classification and replacement bytes, or payload rejection when the kind-owned or complete run state cannot cross the persistence boundary.
	 */
	public function replace_if_raw_matches_classified( string $run_id, string $expected_raw, RunState $replacement ): array|Failure {
		return $this->replace_if_matches_classified( $run_id, $expected_raw, $replacement );
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
	 * @return  string|Failure<EngineError>|null Exact replacement bytes when the write wins, payload rejection when the kind-owned or complete run state cannot cross the persistence boundary, otherwise null.
	 */
	public function replace_if_state_matches( string $run_id, RunState $expected, RunState $replacement ): string|Failure|null {
		$write = $this->replace_if_state_matches_classified( $run_id, $expected, $replacement );
		if ( $write instanceof Failure ) {
			return $write;
		}

		return RowWriteOutcome::Won === $write['outcome'] ? $write['raw'] : null;
	}

	/**
	 * Classifies a typed-state replacement without collapsing write failure into contention.
	 *
	 * @internal Engine terminalization only.
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
	 * @return  array{outcome: RowWriteOutcome, raw: string}|Failure<EngineError> Exact write classification and replacement bytes, or payload rejection when the kind-owned or complete run state cannot cross the persistence boundary.
	 */
	public function replace_if_state_matches_classified( string $run_id, RunState $expected, RunState $replacement ): array|Failure {
		return $this->replace_if_matches_classified( $run_id, $expected, $replacement );
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
	 * @param   string   $run_id       Run identifier.
	 * @param   RunState $expected     Typed state decoded from the supplied raw snapshot.
	 * @param   string   $expected_raw Exact observed state.
	 * @param   string   $effect       Non-empty terminal effect key.
	 *
	 * @throws  \InvalidArgumentException When the effect key is empty.
	 * @throws  \LogicException           When the current site differs from the bound site or WordPress
	 *                                    does not serialize the run state to a string.
	 *
	 * @return  array{raw: string, state: RunState}|Failure<EngineError>|null Caller-supplied snapshot when it already contains the key, which can omit concurrent effects; otherwise a persisted snapshot containing the key, payload rejection when the kind-owned state cannot cross the persistence boundary, or null when the row is absent, invalid, unreadable, or remains contended.
	 */
	#[\NoDiscard( 'a terminal effect persistence outcome must be handled, not dropped' )]
	public function append_terminal_effect( string $run_id, RunState $expected, string $expected_raw, string $effect ): array|Failure|null {
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
			if ( $replacement_raw instanceof Failure ) {
				return $replacement_raw;
			}
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
	 * @param   string $run_id       Run identifier.
	 * @param   string $expected_raw Exact terminal or corrupt snapshot.
	 *
	 * @return  bool Whether this caller deleted the exact row.
	 */
	public function delete_exact( string $run_id, string $expected_raw ): bool {
		return RowDeleteOutcome::Deleted === $this->rows->delete_if_value_matches( RunIdentity::raw_option_name( $this->identity, $run_id ), $expected_raw );
	}

	/**
	 * Deletes a run only while its complete typed state still matches.
	 *
	 * @internal Engine provisional-state compensation only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $run_id   Run identifier.
	 * @param   RunState $expected Complete state written before compensation.
	 *
	 * @throws  \LogicException When the current site differs from the bound site or WordPress does
	 *                          not serialize the run state to a string.
	 *
	 * @return  bool Whether this caller deleted the exact row.
	 */
	public function delete_if_unchanged( string $run_id, RunState $expected ): bool {
		return RowDeleteOutcome::Deleted === $this->rows->delete_if_value_matches( RunIdentity::raw_option_name( $this->identity, $run_id ), self::serialize_state( $expected ) );
	}

	/**
	 * Refreshes a recoverable run's heartbeat and marks its lifecycle action executing.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string        $run_id       Run identifier.
	 * @param   RunState|null $expected     Complete state already observed by the caller, or null to inspect it here.
	 * @param   int|null      $at           Liveness timestamp, or null to use the current clock time.
	 * @param   string|null   $expected_raw Exact state bytes observed with the supplied complete state, or null to derive them.
	 *
	 * @throws  \LogicException When the current site differs from the bound site or WordPress does
	 *                          not serialize the run state to a string.
	 *
	 * @return  RunState|Failure<EngineError>|null Payload rejection when the kind-owned or complete run state cannot cross the persistence boundary, or null when the run is absent, invalid, or changed concurrently.
	 */
	public function mark_executing_with_heartbeat( string $run_id, ?RunState $expected = null, ?int $at = null, ?string $expected_raw = null ): RunState|Failure|null {
		$raw = $expected_raw;
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
		if ( $replacement_raw instanceof Failure ) {
			return $replacement_raw;
		}

		return null !== $replacement_raw ? $replacement : null;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns one classified exact replacement and its deterministic replacement bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string          $run_id      Run identifier.
	 * @param   string|RunState $expected    Exact raw or complete typed state observed before the transition.
	 * @param   RunState        $replacement Complete replacement state.
	 *
	 * @throws  \LogicException When the current site differs from the bound site or WordPress does
	 *                          not serialize the run state to a string.
	 *
	 * @return  array{outcome: RowWriteOutcome, raw: string}|Failure<EngineError> Exact write classification and replacement bytes, or payload rejection when the kind-owned or complete run state cannot cross the persistence boundary.
	 */
	private function replace_if_matches_classified( string $run_id, string|RunState $expected, RunState $replacement ): array|Failure {
		$is_running = RunStatus::Running === $replacement->status;
		$rejected   = self::kind_state_failure( $replacement->kind_state, $is_running );
		if ( null !== $rejected ) {
			return $rejected;
		}

		$replacement_raw = self::serialize_state( $replacement );

		// A terminal row is exempt from the byte ceilings, including one carrying a payload admitted under an
		// earlier producer budget. It stays durable in the database, object-cache refusal only creates a
		// persistent cache miss, and settled effects delete the row. Rejecting the write instead wedges the run
		// and its overlap lock permanently.
		if ( $is_running ) {
			$rejected = self::serialized_row_failure( $replacement_raw );
			if ( null !== $rejected ) {
				return $rejected;
			}
		}

		$expected_raw = $expected instanceof RunState ? self::serialize_state( $expected ) : $expected;

		return array(
			'outcome' => $this->rows->compare_and_swap( RunIdentity::raw_option_name( $this->identity, $run_id ), $expected_raw, $replacement_raw ),
			'raw'     => $replacement_raw,
		);
	}

	/**
	 * Converts typed state to its persisted option shape.
	 *
	 * The `kind` field contains the opaque handler key admitted for the run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunState $state Typed run state.
	 *
	 * @return  array{
	 *     status: string,
	 *     kind: string,
	 *     executing: bool,
	 *     start_args: array<array-key, mixed>,
	 *     args_hash: string,
	 *     kind_state: array<array-key, mixed>,
	 *     failed_attempts: int,
	 *     action_sequence: int,
	 *     created_at: int,
	 *     heartbeat_at: int,
	 *     priority?: int,
	 *     pending?: array{stage: string, mode: 'async'|'single', fire_at: int|null, priority: int},
	 *     error?: StoredError,
	 *     previous_completed_run_id?: string,
	 *     effects?: non-empty-list<string>
	 * }
	 */
	private static function to_option( RunState $state ): array {
		$option = array(
			'status'          => $state->status->value,
			'kind'            => $state->kind,
			'executing'       => $state->executing,
			'start_args'      => $state->start_args,
			'args_hash'       => $state->args_hash,
			'kind_state'      => $state->kind_state,
			'failed_attempts' => $state->failed_attempts,
			'action_sequence' => $state->action_sequence,
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
		} else {
			// A retained descriptor owns wire priority; pendingless runs carry admitted priority at the top level.
			$option['priority'] = $state->priority;
		}
		if ( null !== $state->error ) {
			$option['error'] = $state->error;
		}
		if ( null !== $state->previous_completed_run_id ) {
			$option['previous_completed_run_id'] = $state->previous_completed_run_id;
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
		$error                     = $value['error'] ?? null;
		$previous_completed_run_id = $value['previous_completed_run_id'] ?? null;
		$effects                   = $value['effects'] ?? array();
		if (
			( RunStatus::Failed !== $status && null !== $error )
			|| ( RunStatus::Completed !== $status && null !== $previous_completed_run_id )
			|| ( RunStatus::Running === $status && array() !== $effects )
		) {
			return null;
		}
		if ( null !== $previous_completed_run_id && null === RunIdentity::parse( $previous_completed_run_id ) ) {
			$previous_completed_run_id = null;
		}
		$stored_pending = $value['pending'] ?? null;
		$pending        = null;
		if ( null !== $stored_pending ) {
			$pending = 'async' === $stored_pending['mode']
				? PendingAction::async( $stored_pending['stage'], $stored_pending['priority'] )
				: PendingAction::single( $stored_pending['stage'], $stored_pending['fire_at'], $stored_pending['priority'] );
		}

		return new RunState( status: $status, kind: $value['kind'], executing: $value['executing'], start_args: $value['start_args'], args_hash: $value['args_hash'], kind_state: $value['kind_state'], failed_attempts: $value['failed_attempts'], action_sequence: $value['action_sequence'], created_at: $value['created_at'], heartbeat_at: $value['heartbeat_at'], pending: $pending, error: $error, previous_completed_run_id: $previous_completed_run_id, effects: $effects, priority: $value['priority'] ?? null, );
	}

	/**
	 * Returns whether a value carries every persisted field with its required type.
	 *
	 * The `kind` field contains a grammar-valid opaque handler key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-assert-if-true array{
	 *     status: string,
	 *     kind: string,
	 *     executing: bool,
	 *     start_args: array<array-key, mixed>,
	 *     args_hash: string,
	 *     kind_state: array<array-key, mixed>,
	 *     failed_attempts: int,
	 *     action_sequence: int,
	 *     created_at: int,
	 *     heartbeat_at: int,
	 *     priority?: int,
	 *     pending?: StoredPendingAction,
	 *     error?: StoredError,
	 *     previous_completed_run_id?: string,
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
			|| ! \is_string( $value['kind'] ?? null )
			|| 1 !== \preg_match( KindHandlerInterface::KEY_PATTERN, $value['kind'] )
			|| ! \is_bool( $value['executing'] ?? null )
			|| ! \is_array( $value['start_args'] ?? null )
			|| ! PortableArguments::is_valid( $value['start_args'] )
			|| ! \is_string( $value['args_hash'] ?? null )
			|| ! \is_array( $value['kind_state'] ?? null )
			|| ! PortableArguments::is_valid( $value['kind_state'] )
			|| ! \is_int( $value['failed_attempts'] ?? null )
			|| ! \is_int( $value['action_sequence'] ?? null )
			|| ! \is_int( $value['created_at'] ?? null )
			|| ! \is_int( $value['heartbeat_at'] ?? null )
			|| (
				\array_key_exists( 'priority', $value )
				&& (
					! \is_int( $value['priority'] )
					|| 0 > $value['priority']
					|| Dispatcher::MAX_PRIORITY < $value['priority']
					|| \array_key_exists( 'pending', $value )
				)
			)
			|| ( \array_key_exists( 'pending', $value ) && ! self::is_stored_pending( $value['pending'] ) )
			|| ( \array_key_exists( 'error', $value ) && ! self::is_stored_error( $value['error'] ) )
			|| ( \array_key_exists( 'previous_completed_run_id', $value ) && ! \is_string( $value['previous_completed_run_id'] ) )
			|| ( \array_key_exists( 'effects', $value ) && ! self::is_stored_effects( $value['effects'] ) )
		) {
			return false;
		}

		return true;
	}

	/**
	 * Returns a payload rejection when kind-owned state cannot cross the persistence boundary safely.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $kind_state        Opaque kind-owned state payload.
	 * @param   bool                    $enforce_byte_limit Whether the producer budget applies to this write.
	 *
	 * @throws  \LogicException When WordPress does not serialize the kind-owned state to a string.
	 *
	 * @return  Failure<EngineError>|null
	 */
	private static function kind_state_failure( array $kind_state, bool $enforce_byte_limit = true ): ?Failure {
		if ( ! PortableArguments::is_valid( $kind_state ) ) {
			return new Failure( new EngineError( 'Run kind state must contain only null, scalar, or nested array values.', reason: EngineErrorReason::PayloadRejected, ) );
		}
		if ( ! $enforce_byte_limit ) {
			return null;
		}

		$serialized = \maybe_serialize( $kind_state );
		if ( ! \is_string( $serialized ) ) {
			throw new \LogicException( 'WordPress must serialize kind-owned run state to a string.' );
		}

		$actual_bytes = \strlen( $serialized );
		if ( self::MAX_KIND_STATE_BYTES >= $actual_bytes ) {
			return null;
		}

		return new Failure(
			new EngineError(
				\sprintf( 'Run kind state contains %1$d persisted serialization bytes; the limit is %2$d bytes.', $actual_bytes, self::MAX_KIND_STATE_BYTES ),
				reason: EngineErrorReason::PayloadRejected,
				context: array(
					'actual_bytes' => $actual_bytes,
					'limit_bytes'  => self::MAX_KIND_STATE_BYTES,
				),
			)
		);
	}

	/**
	 * Returns a payload rejection when a complete serialized row exceeds the storage budget.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $serialized_row Complete WordPress serialization for the pending write.
	 *
	 * @return  Failure<EngineError>|null
	 */
	private static function serialized_row_failure( string $serialized_row ): ?Failure {
		$actual_bytes = \strlen( $serialized_row );
		if ( self::MAX_ROW_BYTES >= $actual_bytes ) {
			return null;
		}

		return new Failure(
			new EngineError(
				\sprintf( 'Run state contains %1$d persisted serialization bytes; the limit is %2$d bytes.', $actual_bytes, self::MAX_ROW_BYTES ),
				reason: EngineErrorReason::PayloadRejected,
				context: array(
					'actual_bytes' => $actual_bytes,
					'limit_bytes'  => self::MAX_ROW_BYTES,
				),
			)
		);
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
			|| 1 !== \preg_match( KindHandlerInterface::KEY_PATTERN, $value['stage'] )
			|| ! \is_string( $value['mode'] ?? null )
			|| ! \in_array( $value['mode'], array( 'async', 'single' ), true )
			|| ! \array_key_exists( 'fire_at', $value )
			|| ( null !== $value['fire_at'] && ! \is_int( $value['fire_at'] ) )
			|| ! \is_int( $value['priority'] ?? null )
			|| 0 > $value['priority']
			|| Dispatcher::MAX_PRIORITY < $value['priority']
		) {
			return false;
		}

		return 'async' === $value['mode']
			? null === $value['fire_at']
			: \is_int( $value['fire_at'] );
	}

	/**
	 * Returns whether a value is complete durable terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-assert-if-true StoredError $value
	 *
	 * @param   mixed $value Persisted terminal failure detail.
	 *
	 * @return  bool
	 */
	private static function is_stored_error( mixed $value ): bool {
		$has_details = \is_array( $value ) && \array_key_exists( 'details', $value );
		if (
			! \is_array( $value )
			|| ! \array_key_exists( 'class', $value )
			|| ( null !== $value['class'] && ! \is_string( $value['class'] ) )
			|| ! \is_string( $value['message'] ?? null )
			|| ! \is_string( $value['stage'] ?? null )
			|| null === RunFailureStage::tryFrom( $value['stage'] )
			|| ! \is_string( $value['code'] ?? null )
			|| null === ErrorCode::tryFrom( $value['code'] )
			|| ( $has_details && ! \is_array( $value['details'] ) )
			// Verbatim pass-through persists additive metadata, so the whole record is portable. The record occupies one array level
			// itself, so its budget runs one deeper than the payload default to leave details their full depth.
			|| ! PortableArguments::is_valid( $value, PortableArguments::MAX_ARGUMENTS_JSON_DEPTH + 1 )
		) {
			return false;
		}

		return true;
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

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Orchestration;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Owns execution-overlap locks stored as WordPress options.
 *
 * Nobody releases a crashed run's lock; the next claimant replaces it after its heartbeat age
 * exceeds the caller-resolved staleness window. The stale row is deleted only while its exact raw
 * value still matches, so a losing claimant cannot clobber the winner. Reclaim can double-fire only
 * when a crashed process revives after its lock has been reclaimed, so tasks must be idempotent.
 * Malformed rows are not held and follow the same value-conditioned reclaim path.
 *
 * The orchestrator resolves the 15-minute default, lock-staleness filter, and
 * twice-the-continue-delay floor; this guard enforces lock mechanics with the supplied window.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class OverlapGuard {
	// region FIELDS AND CONSTANTS

	private const MALFORMED_RAW_BYTES = 200;
	private const OPTION_PREFIX       = 'a8csp_bgte_lock_';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ClockInterface  $clock  Timestamp source.
	 * @param   LoggerInterface $logger Log event sink.
	 * @param   LockRows        $rows   Authoritative lock-row I/O.
	 */
	public function __construct(
		private ClockInterface $clock,
		private LoggerInterface $logger,
		private LockRows $rows,
	) {}

	// endregion

	// region METHODS

	/**
	 * Claims an absent lock, refreshes a fresh owned lock, or replaces a stale or malformed row.
	 *
	 * Re-claiming a fresh lock with the same run identifier is idempotent: it refreshes the
	 * heartbeat and returns Claimed without changing the original claim timestamp.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name             Stable task or batch name.
	 * @param   string $args_hash        Stable identity of the start arguments.
	 * @param   string $run_id           Claiming run identifier.
	 * @param   int    $staleness_window Caller-resolved staleness window in seconds.
	 *
	 * @return  ClaimResult
	 */
	public function claim(
		string $name,
		string $args_hash,
		string $run_id,
		int $staleness_window
	): ClaimResult {
		$key      = $this->option_name( $name, $args_hash );
		$now      = $this->clock->now()->getTimestamp();
		$new_lock = self::new_lock( $run_id, $now );

		if ( $this->rows->insert( $key, $new_lock ) ) {
			return ClaimResult::Claimed;
		}

		$raw = $this->rows->select( $key );
		if ( null === $raw ) {
			return ClaimResult::Held;
		}

		$lock = self::parse( $raw );
		if ( null === $lock ) {
			return $this->reclaim( $key, $raw, null, $new_lock, $name, $args_hash, $run_id );
		}

		if ( self::is_stale( $lock, $now, $staleness_window ) ) {
			return $this->reclaim( $key, $raw, $lock, $new_lock, $name, $args_hash, $run_id );
		}

		if ( $run_id !== $lock['run_id'] ) {
			return ClaimResult::Held;
		}

		$lock['heartbeat_at'] = $now;

		return $this->rows->replace( $key, $raw, $lock )
			? ClaimResult::Claimed
			: ClaimResult::Held;
	}

	/**
	 * Refreshes liveness only while the run still owns the exact selected lock row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name      Stable task or batch name.
	 * @param   string $args_hash Stable identity of the start arguments.
	 * @param   string $run_id    Owning run identifier.
	 *
	 * @return  bool Whether the heartbeat confirmed continued ownership.
	 */
	public function heartbeat( string $name, string $args_hash, string $run_id ): bool {
		$key = $this->option_name( $name, $args_hash );
		$raw = $this->rows->select( $key );
		if ( null === $raw ) {
			return false;
		}

		$lock = self::parse( $raw );
		if ( null === $lock || $run_id !== $lock['run_id'] ) {
			return false;
		}

		$lock['heartbeat_at'] = $this->clock->now()->getTimestamp();

		// A lost CAS means ownership moved after selection, so execution cannot continue under this lock.
		return $this->rows->replace( $key, $raw, $lock );
	}

	/**
	 * Deletes a lock only while the terminating run owns the exact selected row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name      Stable task or batch name.
	 * @param   string $args_hash Stable identity of the start arguments.
	 * @param   string $run_id    Owning run identifier.
	 *
	 * @return  void
	 */
	public function release( string $name, string $args_hash, string $run_id ): void {
		$key = $this->option_name( $name, $args_hash );
		$raw = $this->rows->select( $key );
		if ( null === $raw ) {
			return;
		}

		$lock = self::parse( $raw );
		if ( null === $lock || $run_id !== $lock['run_id'] ) {
			return;
		}

		$this->rows->delete( $key, $raw );
	}

	/**
	 * Returns whether a complete lock exists without exceeding the supplied staleness window.
	 *
	 * A heartbeat exactly one window old remains fresh; only a greater age is stale. Malformed rows
	 * are not held, so a subsequent claim can reclaim them.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name             Stable task or batch name.
	 * @param   string $args_hash        Stable identity of the start arguments.
	 * @param   int    $staleness_window Caller-resolved staleness window in seconds.
	 *
	 * @return  bool
	 */
	public function is_held( string $name, string $args_hash, int $staleness_window ): bool {
		$raw = $this->rows->select( $this->option_name( $name, $args_hash ) );
		if ( null === $raw ) {
			return false;
		}

		$lock = self::parse( $raw );

		return null !== $lock && ! self::is_stale(
			$lock,
			$this->clock->now()->getTimestamp(),
			$staleness_window
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Replaces the exact stale or malformed row selected by a losing insert.
	 *
	 * The delete predicate prevents this claimant from removing a winner that changes the row after
	 * selection; a rival that fills the absent row before insertion also wins normally.
	 *
	 * @param   string                                                         $key       Lock option name.
	 * @param   string                                                         $raw       Exact selected value.
	 * @param   array{run_id: string, claimed_at: int, heartbeat_at: int}|null $old_lock  Parsed stale row, or null when malformed.
	 * @param   array{run_id: string, claimed_at: int, heartbeat_at: int}      $new_lock  Replacement row.
	 * @param   string                                                         $name      Stable task or batch name.
	 * @param   string                                                         $args_hash Stable identity of the start arguments.
	 * @param   string                                                         $run_id    Claiming run identifier.
	 *
	 * @return  ClaimResult
	 */
	private function reclaim(
		string $key,
		string $raw,
		?array $old_lock,
		array $new_lock,
		string $name,
		string $args_hash,
		string $run_id
	): ClaimResult {
		if ( ! $this->rows->delete( $key, $raw ) || ! $this->rows->insert( $key, $new_lock ) ) {
			return ClaimResult::Held;
		}

		if ( null === $old_lock ) {
			$this->logger->warning(
				'Reclaimed malformed execution-overlap lock.',
				array(
					'name'      => $name,
					'args_hash' => $args_hash,
					'malformed' => true,
					'raw_row'   => \substr( $raw, 0, self::MALFORMED_RAW_BYTES ),
					'run_id'    => $run_id,
				)
			);
		} else {
			$this->logger->warning(
				'Reclaimed stale execution-overlap lock.',
				array(
					'name'        => $name,
					'args_hash'   => $args_hash,
					'dead_run_id' => $old_lock['run_id'],
					'run_id'      => $run_id,
				)
			);
		}

		return ClaimResult::Reclaimed;
	}

	/**
	 * Returns the execution-overlap option name.
	 *
	 * @param   string $name      Stable task or batch name.
	 * @param   string $args_hash Stable identity of the start arguments.
	 *
	 * @return  string
	 */
	private function option_name( string $name, string $args_hash ): string {
		return self::OPTION_PREFIX . $name . '_' . $args_hash;
	}

	/**
	 * Returns a newly claimed lock row.
	 *
	 * @param   string $run_id Claiming run identifier.
	 * @param   int    $now    Claim timestamp.
	 *
	 * @return  array{run_id: string, claimed_at: int, heartbeat_at: int}
	 */
	private static function new_lock( string $run_id, int $now ): array {
		return array(
			'run_id'       => $run_id,
			'claimed_at'   => $now,
			'heartbeat_at' => $now,
		);
	}

	/**
	 * Parses only the exact three-field persisted lock shape.
	 *
	 * @param   string $raw Exact persisted option value.
	 *
	 * @return  array{run_id: string, claimed_at: int, heartbeat_at: int}|null
	 */
	private static function parse( string $raw ): ?array {
		$value = self::decode( $raw );
		if (
			! \is_array( $value )
			|| 3 !== \count( $value )
			|| ! \is_string( $value['run_id'] ?? null )
			|| ! \is_int( $value['claimed_at'] ?? null )
			|| ! \is_int( $value['heartbeat_at'] ?? null )
		) {
			return null;
		}

		return array(
			'run_id'       => $value['run_id'],
			'claimed_at'   => $value['claimed_at'],
			'heartbeat_at' => $value['heartbeat_at'],
		);
	}

	/**
	 * Decodes a raw row without allowing serialized objects to construct classes.
	 *
	 * @param   string $raw Exact persisted option value.
	 *
	 * @return  mixed
	 */
	private static function decode( string $raw ): mixed {
		\call_user_func( 'set_error_handler', static fn (): bool => true );

		try {
			// Lock rows contain only scalars and arrays, so class construction is never valid during decoding.
			return \call_user_func( 'unserialize', $raw, array( 'allowed_classes' => false ) );
		} catch ( \Throwable ) {
			return null;
		} finally {
			\call_user_func( 'restore_error_handler' );
		}
	}

	/**
	 * Returns whether the heartbeat age is strictly greater than the supplied window.
	 *
	 * @param   array{run_id: string, claimed_at: int, heartbeat_at: int} $lock             Lock row.
	 * @param   int                                                       $now              Current timestamp.
	 * @param   int                                                       $staleness_window Staleness window in seconds.
	 *
	 * @return  bool
	 */
	private static function is_stale( array $lock, int $now, int $staleness_window ): bool {
		return $now - $lock['heartbeat_at'] > $staleness_window;
	}

	// endregion
}

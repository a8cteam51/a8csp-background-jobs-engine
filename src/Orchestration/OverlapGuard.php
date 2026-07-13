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
 * value still matches, so a losing claimant cannot clobber the winner. Reclaim can double-fire when
 * a crashed process revives after its lock has been reclaimed. Replace takeover has the same residual
 * while an incumbent is inside a callback: PHP cannot abort it, so it finishes that callback and then
 * fences. Consumers' idempotency contract covers both windows. Malformed rows are not held and follow
 * the same value-conditioned reclaim path.
 *
 * The orchestrator resolves the 15-minute default, lock-staleness filter, and
 * twice-the-continue-delay floor; this guard enforces lock mechanics with the supplied window.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class OverlapGuard {
	// region FIELDS AND CONSTANTS

	/**
	 * Maximum malformed lock bytes included in diagnostic context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const MALFORMED_RAW_BYTES = 200;

	/**
	 * Prefix for execution-overlap lock option names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const OPTION_PREFIX = 'a8csp_bgte_lock_';

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
	 * Returns the owner named by the current complete lock row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name      Stable task or batch name.
	 * @param   string $args_hash Stable identity of the start arguments.
	 *
	 * @return  string|null
	 */
	public function owner_run_id( string $name, string $args_hash ): ?string {
		$raw = $this->rows->select( $this->option_name( $name, $args_hash ) );
		if ( null === $raw ) {
			return null;
		}

		$lock = self::parse( $raw );

		return $lock['run_id'] ?? null;
	}

	/**
	 * Replaces the currently selected lock only while its exact row is unchanged.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name               Stable task or batch name.
	 * @param   string $args_hash          Stable identity of the start arguments.
	 * @param   string $replacement_run_id Replacement owner.
	 *
	 * @return  bool Whether ownership moved to the replacement run.
	 */
	public function replace(
		string $name,
		string $args_hash,
		string $replacement_run_id
	): bool {
		$key = $this->option_name( $name, $args_hash );
		$raw = $this->rows->select( $key );
		if ( null === $raw ) {
			return false;
		}

		$now = $this->clock->now()->getTimestamp();

		return $this->rows->replace( $key, $raw, self::new_lock( $replacement_run_id, $now ) );
	}

	/**
	 * Refreshes liveness only while the run still owns the exact selected lock row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $name      Stable task or batch name.
	 * @param   string   $args_hash Stable identity of the start arguments.
	 * @param   string   $run_id    Owning run identifier.
	 * @param   int|null $at        Liveness timestamp, or null to use the current clock time. A future value marks
	 *                              the next expected retry fire as the run's legitimate sign of life.
	 *
	 * @return  bool Whether execution may continue under the current fence.
	 */
	public function heartbeat( string $name, string $args_hash, string $run_id, ?int $at = null ): bool {
		$key = $this->option_name( $name, $args_hash );
		$raw = $this->rows->select( $key );
		if ( null === $raw ) {
			if ( $this->rows->last_select_failed() ) {
				$this->logger->debug(
					'Skipped execution-overlap lock heartbeat refresh after an authoritative read failure.',
					array(
						'key'       => $key,
						'name'      => $name,
						'args_hash' => $args_hash,
						'run_id'    => $run_id,
					)
				);

				return true;
			}

			return false;
		}

		$lock = self::parse( $raw );
		if ( null === $lock || $run_id !== $lock['run_id'] ) {
			return false;
		}

		$lock['heartbeat_at'] = $at ?? $this->clock->now()->getTimestamp();

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
			if ( $this->rows->last_select_failed() ) {
				$this->logger->warning(
					'Execution-overlap lock release could not read the lock row; the staleness sweep reclaims the leaked key.',
					array(
						'key'       => $key,
						'name'      => $name,
						'args_hash' => $args_hash,
						'run_id'    => $run_id,
					)
				);
			}

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
	 * are not held, so a subsequent claim can reclaim them. An authoritative read failure reports
	 * held so callers fail closed.
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
			return $this->rows->last_select_failed();
		}

		$lock = self::parse( $raw );

		return null !== $lock && ! self::is_stale(
			$lock,
			$this->clock->now()->getTimestamp(),
			$staleness_window
		);
	}

	/**
	 * Returns one exact raw lock snapshot and its validated schema for maintenance.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name      Stable task or batch name.
	 * @param   string $args_hash Stable identity of the start arguments.
	 *
	 * @return  array{raw: string, lock: array{run_id: string, claimed_at: int, heartbeat_at: int}|null}|null
	 */
	public function inspect_persisted_lock( string $name, string $args_hash ): ?array {
		$raw = $this->rows->select( $this->option_name( $name, $args_hash ) );
		if ( null === $raw ) {
			return null;
		}

		return array(
			'raw'  => $raw,
			'lock' => self::parse( $raw ),
		);
	}

	/**
	 * Deletes one inspected lock only while its exact raw row is unchanged.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name         Stable task or batch name.
	 * @param   string $args_hash    Stable identity of the start arguments.
	 * @param   string $expected_raw Exact inspected row value.
	 *
	 * @return  bool Whether the inspected row was deleted.
	 */
	public function delete_persisted_lock( string $name, string $args_hash, string $expected_raw ): bool {
		return $this->rows->delete( $this->option_name( $name, $args_hash ), $expected_raw );
	}

	/**
	 * Deletes a stale owned lock after rechecking its exact row and staleness boundary.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name             Stable task or batch name.
	 * @param   string $args_hash        Stable identity of the start arguments.
	 * @param   string $run_id           Expected lock owner.
	 * @param   int    $staleness_window Resolved staleness window in seconds.
	 *
	 * @return  bool Whether the exact stale row was deleted.
	 */
	public function delete_stale_owned_lock(
		string $name,
		string $args_hash,
		string $run_id,
		int $staleness_window
	): bool {
		$snapshot = $this->inspect_persisted_lock( $name, $args_hash );
		if ( null === $snapshot || null === $snapshot['lock'] ) {
			return false;
		}

		$lock = $snapshot['lock'];
		if (
			$run_id !== $lock['run_id']
			|| ! self::is_stale( $lock, $this->clock->now()->getTimestamp(), $staleness_window )
		) {
			return false;
		}

		return $this->delete_persisted_lock( $name, $args_hash, $snapshot['raw'] );
	}

	/**
	 * Fences a running run when its owned lock is missing, transferred, or can be stale-deleted exactly.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name             Stable task or batch name.
	 * @param   string $args_hash        Stable identity of the start arguments.
	 * @param   string $run_id           Expected lock owner.
	 * @param   int    $staleness_window Resolved staleness window in seconds.
	 *
	 * @return  MaintenanceFenceOutcome Typed ownership classification.
	 */
	public function fence_abandoned_run(
		string $name,
		string $args_hash,
		string $run_id,
		int $staleness_window
	): MaintenanceFenceOutcome {
		$snapshot = $this->inspect_persisted_lock( $name, $args_hash );
		if ( null === $snapshot ) {
			return $this->rows->last_select_failed()
				? MaintenanceFenceOutcome::Indeterminate
				: MaintenanceFenceOutcome::Abandoned;
		}

		$lock = $snapshot['lock'];
		if ( null === $lock ) {
			return MaintenanceFenceOutcome::Indeterminate;
		}

		if ( $run_id !== $lock['run_id'] ) {
			return MaintenanceFenceOutcome::Transferred;
		}

		if ( ! self::is_stale( $lock, $this->clock->now()->getTimestamp(), $staleness_window ) ) {
			return MaintenanceFenceOutcome::Owned;
		}

		return $this->delete_persisted_lock( $name, $args_hash, $snapshot['raw'] )
			? MaintenanceFenceOutcome::Abandoned
			: MaintenanceFenceOutcome::Indeterminate;
	}

	// endregion

	// region HELPERS

	/**
	 * Replaces the exact stale or malformed row selected by a losing insert.
	 *
	 * The delete predicate prevents this claimant from removing a winner that changes the row after
	 * selection; a rival that fills the absent row before insertion also wins normally.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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

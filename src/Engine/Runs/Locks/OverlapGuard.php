<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\ClaimResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;
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
 * fences. Consumers' idempotency contract covers both windows. A leaked lock carrying a pre-credited
 * execution lease reclaims only after the credited runtime plus the staleness window elapses.
 * Malformed rows are not held and follow the same value-conditioned reclaim path.
 *
 * LockWindows resolves the 15-minute default, lock-staleness filter, and
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
	 * @param   OptionRows      $rows   Authoritative raw lock-row I/O.
	 */
	public function __construct(
		private ClockInterface $clock,
		private LoggerInterface $logger,
		private OptionRows $rows,
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
	public function claim( string $name, string $args_hash, string $run_id, int $staleness_window ): ClaimResult {
		$key      = $this->option_name( $name, $args_hash );
		$now      = $this->clock->now()->getTimestamp();
		$new_lock = self::new_lock( $run_id, $now );

		if ( $this->rows->insert( $key, self::serialize( $new_lock ) ) ) {
			return ClaimResult::Claimed;
		}

		$selected = $this->rows->read( $key );
		if ( $selected->is_failure() ) {
			return ClaimResult::Held;
		}

		$raw = $selected->value;
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

		return $this->rows->replace( $key, $raw, self::serialize( $lock ) )
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
	 * @return  AbstractResult<string|null, EngineError>
	 */
	#[\NoDiscard( 'a lock-owner read outcome must be handled, not dropped' )]
	public function owner_run_id( string $name, string $args_hash ): AbstractResult {
		$selected = $this->rows->read( $this->option_name( $name, $args_hash ) );
		if ( $selected->is_failure() ) {
			return $selected;
		}

		$raw = $selected->value;
		if ( null === $raw ) {
			return new Success( null );
		}

		$lock = self::parse( $raw );

		return new Success( $lock['run_id'] ?? null );
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
	public function replace( string $name, string $args_hash, string $replacement_run_id ): bool {
		$key      = $this->option_name( $name, $args_hash );
		$selected = $this->rows->read( $key );
		if ( $selected->is_failure() ) {
			return false;
		}

		$raw = $selected->value;
		if ( null === $raw ) {
			return false;
		}

		$now = $this->clock->now()->getTimestamp();

		return $this->rows->replace( $key, $raw, self::serialize( self::new_lock( $replacement_run_id, $now ) ) );
	}

	/**
	 * Refreshes liveness only while the run still owns the exact selected lock row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $name                  Stable task or batch name.
	 * @param   string   $args_hash             Stable identity of the start arguments.
	 * @param   string   $run_id                Owning run identifier.
	 * @param   int|null $at                    Liveness timestamp, or null to use the current clock time. A future value marks
	 *                                          expected callback work or retry fire as the run's legitimate sign of life.
	 * @param   int|null $expected_heartbeat_at Expected heartbeat for one delivery generation, or null to accept any owned generation.
	 *
	 * @return  HeartbeatOutcome Ownership classification after the heartbeat attempt.
	 */
	#[\NoDiscard( 'a lock-heartbeat outcome must be handled, not dropped' )]
	public function heartbeat( string $name, string $args_hash, string $run_id, ?int $at = null, ?int $expected_heartbeat_at = null ): HeartbeatOutcome {
		$key      = $this->option_name( $name, $args_hash );
		$selected = $this->rows->read( $key );
		if ( $selected->is_failure() ) {
			$this->logger->warning(
				'Execution-overlap lock heartbeat could not read the authoritative lock row; ownership is indeterminate and the caller aborts without a terminal claim.',
				array(
					'key'       => $key,
					'name'      => $name,
					'args_hash' => $args_hash,
					'run_id'    => $run_id,
				)
			);

			return HeartbeatOutcome::Indeterminate;
		}

		$raw = $selected->value;
		if ( null === $raw ) {
			return HeartbeatOutcome::Lost;
		}

		$lock = self::parse( $raw );
		if ( null === $lock || $run_id !== $lock['run_id'] ) {
			return HeartbeatOutcome::Lost;
		}
		if ( null !== $expected_heartbeat_at && $expected_heartbeat_at !== $lock['heartbeat_at'] ) {
			return HeartbeatOutcome::Stale;
		}

		$lock['heartbeat_at'] = $at ?? $this->clock->now()->getTimestamp();

		// A lost CAS means ownership moved after selection, so execution cannot continue under this lock.
		if ( $this->rows->replace( $key, $raw, self::serialize( $lock ) ) ) {
			return HeartbeatOutcome::Owned;
		}

		return null !== $expected_heartbeat_at ? HeartbeatOutcome::Stale : HeartbeatOutcome::Lost;
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
		$key      = $this->option_name( $name, $args_hash );
		$selected = $this->rows->read( $key );
		if ( $selected->is_failure() ) {
			$this->logger->warning(
				'Execution-overlap lock release could not read the lock row; the staleness sweep reclaims the leaked key.',
				array(
					'key'       => $key,
					'name'      => $name,
					'args_hash' => $args_hash,
					'run_id'    => $run_id,
				)
			);

			return;
		}

		$raw = $selected->value;
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
		$selected = $this->rows->read( $this->option_name( $name, $args_hash ) );
		if ( $selected->is_failure() ) {
			return true;
		}

		$raw = $selected->value;
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
	 * @return  AbstractResult<array{raw: string, lock: array{run_id: string, claimed_at: int, heartbeat_at: int}|null}|null, EngineError>
	 */
	#[\NoDiscard( 'a persisted-lock read outcome must be handled, not dropped' )]
	public function inspect_persisted_lock( string $name, string $args_hash ): AbstractResult {
		$selected = $this->rows->read( $this->option_name( $name, $args_hash ) );
		if ( $selected->is_failure() ) {
			return $selected;
		}

		$raw = $selected->value;
		if ( null === $raw ) {
			return new Success( null );
		}

		return new Success(
			array(
				'raw'  => $raw,
				'lock' => self::parse( $raw ),
			)
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
	public function delete_stale_owned_lock( string $name, string $args_hash, string $run_id, int $staleness_window ): bool {
		$inspected = $this->inspect_persisted_lock( $name, $args_hash );
		if ( $inspected->is_failure() ) {
			return false;
		}

		$snapshot = $inspected->value;
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
	public function fence_abandoned_run( string $name, string $args_hash, string $run_id, int $staleness_window ): MaintenanceFenceOutcome {
		$inspected = $this->inspect_persisted_lock( $name, $args_hash );
		if ( $inspected->is_failure() ) {
			return MaintenanceFenceOutcome::Indeterminate;
		}

		$snapshot = $inspected->value;
		if ( null === $snapshot ) {
			return MaintenanceFenceOutcome::Abandoned;
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

	/**
	 * Classifies run ownership without deleting a stale lock needed by a redriven delivery.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name      Stable task or batch name.
	 * @param   string $args_hash Stable identity of the start arguments.
	 * @param   string $run_id    Expected lock owner.
	 *
	 * @return  MaintenanceFenceOutcome Typed ownership classification.
	 */
	public function classify_run_fence( string $name, string $args_hash, string $run_id ): MaintenanceFenceOutcome {
		$inspected = $this->inspect_persisted_lock( $name, $args_hash );
		if ( $inspected->is_failure() ) {
			return MaintenanceFenceOutcome::Indeterminate;
		}

		$snapshot = $inspected->value;
		if ( null === $snapshot ) {
			return MaintenanceFenceOutcome::Abandoned;
		}

		$lock = $snapshot['lock'];
		if ( null === $lock ) {
			return MaintenanceFenceOutcome::Indeterminate;
		}

		return $run_id === $lock['run_id']
			? MaintenanceFenceOutcome::Owned
			: MaintenanceFenceOutcome::Transferred;
	}

	/**
	 * Prepares the exact lock generation expected by a redriven delivery.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name         Stable task or batch name.
	 * @param   string $args_hash    Stable identity of the start arguments.
	 * @param   string $run_id       Expected lock owner.
	 * @param   int    $claimed_at   Original run claim timestamp.
	 * @param   int    $heartbeat_at Delivery-generation heartbeat.
	 * @param   int    $staleness    Resolved lock-staleness window.
	 *
	 * @return  RedriveFenceOutcome Typed readiness after the preparation attempt.
	 */
	public function prepare_run_redrive_fence( string $name, string $args_hash, string $run_id, int $claimed_at, int $heartbeat_at, int $staleness ): RedriveFenceOutcome {
		$key         = $this->option_name( $name, $args_hash );
		$replacement = array(
			'run_id'       => $run_id,
			'claimed_at'   => $claimed_at,
			'heartbeat_at' => $heartbeat_at,
		);
		$inspected   = $this->inspect_persisted_lock( $name, $args_hash );
		if ( $inspected->is_failure() ) {
			return RedriveFenceOutcome::Indeterminate;
		}

		$snapshot = $inspected->value;
		if ( null === $snapshot ) {
			if ( $this->rows->insert( $key, self::serialize( $replacement ) ) ) {
				return RedriveFenceOutcome::Ready;
			}

			return $this->classify_redrive_fence( $name, $args_hash, $run_id, $heartbeat_at, $staleness );
		}

		$lock = $snapshot['lock'];
		if ( null === $lock ) {
			if ( $this->rows->replace( $key, $snapshot['raw'], self::serialize( $replacement ) ) ) {
				return RedriveFenceOutcome::Ready;
			}

			return $this->classify_redrive_fence( $name, $args_hash, $run_id, $heartbeat_at, $staleness );
		}
		if ( $run_id !== $lock['run_id'] ) {
			return RedriveFenceOutcome::Transferred;
		}
		if ( $heartbeat_at === $lock['heartbeat_at'] ) {
			return RedriveFenceOutcome::Ready;
		}
		if ( ! self::is_stale( $lock, $this->clock->now()->getTimestamp(), $staleness ) ) {
			return RedriveFenceOutcome::Live;
		}

		$replacement['claimed_at'] = $lock['claimed_at'];
		if ( $this->rows->replace( $key, $snapshot['raw'], self::serialize( $replacement ) ) ) {
			return RedriveFenceOutcome::Ready;
		}

		return $this->classify_redrive_fence( $name, $args_hash, $run_id, $heartbeat_at, $staleness );
	}

	// endregion

	// region HELPERS

	/**
	 * Reclassifies a redrive fence after an exact lock write loses its race.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name         Stable task or batch name.
	 * @param   string $args_hash    Stable identity of the start arguments.
	 * @param   string $run_id       Expected lock owner.
	 * @param   int    $heartbeat_at Delivery-generation heartbeat.
	 * @param   int    $staleness    Resolved lock-staleness window.
	 *
	 * @return  RedriveFenceOutcome Typed readiness after the lost write.
	 */
	private function classify_redrive_fence( string $name, string $args_hash, string $run_id, int $heartbeat_at, int $staleness ): RedriveFenceOutcome {
		$inspected = $this->inspect_persisted_lock( $name, $args_hash );
		if ( $inspected->is_failure() ) {
			return RedriveFenceOutcome::Indeterminate;
		}

		$snapshot = $inspected->value;
		if ( null === $snapshot || null === $snapshot['lock'] ) {
			return RedriveFenceOutcome::Indeterminate;
		}

		$lock = $snapshot['lock'];
		if ( $run_id !== $lock['run_id'] ) {
			return RedriveFenceOutcome::Transferred;
		}
		if ( $heartbeat_at === $lock['heartbeat_at'] ) {
			return RedriveFenceOutcome::Ready;
		}

		return self::is_stale( $lock, $this->clock->now()->getTimestamp(), $staleness )
			? RedriveFenceOutcome::Indeterminate
			: RedriveFenceOutcome::Live;
	}

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
	private function reclaim( string $key, string $raw, ?array $old_lock, array $new_lock, string $name, string $args_hash, string $run_id ): ClaimResult {
		if ( ! $this->rows->delete( $key, $raw ) || ! $this->rows->insert( $key, self::serialize( $new_lock ) ) ) {
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
	 * Returns a lock row's exact persisted representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{run_id: string, claimed_at: int, heartbeat_at: int} $row Complete lock row.
	 *
	 * @throws  \LogicException When WordPress does not serialize the row to a string.
	 *
	 * @return  string
	 */
	private static function serialize( array $row ): string {
		$value = \maybe_serialize( $row );
		if ( ! \is_string( $value ) ) {
			throw new \LogicException( 'WordPress must serialize an execution-overlap lock row to a string.' );
		}

		return $value;
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

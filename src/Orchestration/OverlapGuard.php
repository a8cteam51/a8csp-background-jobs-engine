<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Orchestration;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Owns execution-overlap locks stored as WordPress options.
 *
 * Nobody releases a crashed run's lock; the next claimant deletes and replaces it after its
 * heartbeat age exceeds the caller-resolved staleness window. Reclaim after a crash-then-revival
 * can double-fire once, so tasks must be idempotent.
 *
 * The orchestrator resolves the 15-minute default, lock-staleness filter, and
 * twice-the-continue-delay floor; this guard enforces lock mechanics with the supplied window.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class OverlapGuard {
	// region FIELDS AND CONSTANTS

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
	 */
	public function __construct(
		private ClockInterface $clock,
		private LoggerInterface $logger,
	) {}

	// endregion

	// region METHODS

	/**
	 * Claims an absent lock, refreshes a fresh owned lock, or replaces a stale lock.
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
		$option_name = $this->option_name( $name, $args_hash );
		$now         = $this->clock->now()->getTimestamp();
		$new_lock    = self::new_lock( $run_id, $now );

		if ( \add_option( $option_name, $new_lock, '', false ) ) {
			return ClaimResult::Claimed;
		}

		$lock = self::read_lock( $option_name );
		if ( null === $lock ) {
			return ClaimResult::Held;
		}

		if ( ! self::is_stale( $lock, $now, $staleness_window ) ) {
			if ( $run_id !== $lock['run_id'] ) {
				return ClaimResult::Held;
			}

			return $this->refresh_owned_lock( $option_name, $run_id, $now )
				? ClaimResult::Claimed
				: ClaimResult::Held;
		}

		\delete_option( $option_name );

		if ( ! \add_option( $option_name, $new_lock, '', false ) ) {
			// A rival can fill the row between deletion and insertion, so losing this race is a
			// normal held result.
			return ClaimResult::Held;
		}

		$this->logger->warning(
			'Reclaimed stale execution-overlap lock.',
			array(
				'name'        => $name,
				'args_hash'   => $args_hash,
				'dead_run_id' => $lock['run_id'],
				'run_id'      => $run_id,
			)
		);

		return ClaimResult::Reclaimed;
	}

	/**
	 * Refreshes liveness only while the run still owns the lock.
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
	public function heartbeat( string $name, string $args_hash, string $run_id ): void {
		$this->refresh_owned_lock(
			$this->option_name( $name, $args_hash ),
			$run_id,
			$this->clock->now()->getTimestamp()
		);
	}

	/**
	 * Deletes a lock only while the terminating run still owns it.
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
		$option_name = $this->option_name( $name, $args_hash );
		$lock        = self::read_lock( $option_name );

		if ( null === $lock || $run_id !== $lock['run_id'] ) {
			return;
		}

		\delete_option( $option_name );
	}

	/**
	 * Returns whether a valid lock exists without exceeding the supplied staleness window.
	 *
	 * A heartbeat exactly one window old remains fresh; only a greater age is stale.
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
		$lock = self::read_lock( $this->option_name( $name, $args_hash ) );

		return null !== $lock && ! self::is_stale(
			$lock,
			$this->clock->now()->getTimestamp(),
			$staleness_window
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Refreshes an owned lock and reports whether ownership still matches.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $option_name Lock option name.
	 * @param   string $run_id      Owning run identifier.
	 * @param   int    $heartbeat_at Latest liveness timestamp.
	 *
	 * @return  bool
	 */
	private function refresh_owned_lock( string $option_name, string $run_id, int $heartbeat_at ): bool {
		$lock = self::read_lock( $option_name );

		// A revived stale run must not extend the replacement lock after another claimant takes
		// ownership.
		if ( null === $lock || $run_id !== $lock['run_id'] ) {
			return false;
		}

		$lock['heartbeat_at'] = $heartbeat_at;
		\update_option( $option_name, $lock, false );

		return true;
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
	 * Returns a complete lock row from its persisted option.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $option_name Lock option name.
	 *
	 * @return  array{run_id: string, claimed_at: int, heartbeat_at: int}|null
	 */
	private static function read_lock( string $option_name ): ?array {
		$value = \get_option( $option_name, null );
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

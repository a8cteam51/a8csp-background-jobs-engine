<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\Randomization\RandomizerInterface;
use Psr\Clock\ClockInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Serializes one schedule occurrence's read-decide-persist critical section.
 *
 * The sixty-second stale window bounds crash recovery around scheduler acceptance and the registry
 * CAS; accepted dispatches release before consumer hooks, and asynchronous task execution is never leased.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class OccurrenceLease {
	// region FIELDS AND CONSTANTS

	/**
	 * Prefix for bounded hashed occurrence-lease option names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const OPTION_PREFIX = 'a8csp_bgte_lease_';

	/**
	 * Maximum lease age in seconds before a new occurrence may reclaim it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const STALENESS = 60;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OptionRows          $rows       Authoritative raw lease-row I/O.
	 * @param   ClockInterface      $clock      Current-time source.
	 * @param   RandomizerInterface $randomizer Per-claim identity source.
	 */
	public function __construct(
		private OptionRows $rows,
		private ClockInterface $clock,
		private RandomizerInterface $randomizer,
	) {}

	// endregion

	// region METHODS

	/**
	 * Claims one occurrence identity or reports a fresh concurrent holder.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @return  string|null Exact claimed row bytes for release, or null when another holder wins.
	 */
	public function claim( string $registration_key ): ?string {
		$key = self::option_name( $registration_key );
		$now = $this->clock->now()->getTimestamp();
		$row = array(
			'run_id'       => \sprintf( '%019d', $this->randomizer->int( 0, \PHP_INT_MAX ) ),
			'claimed_at'   => $now,
			'heartbeat_at' => $now,
		);
		$raw = self::serialize( $row );

		if ( $this->rows->insert( $key, $raw ) ) {
			$selected = $this->rows->read( $key );
			if ( $selected->is_failure() ) {
				return null;
			}

			return $raw === $selected->value ? $raw : null;
		}

		$selected = $this->rows->read( $key );
		if ( $selected->is_failure() ) {
			return null;
		}

		$expected_raw = $selected->value;
		if ( null === $expected_raw ) {
			return null;
		}

		$incumbent = self::parse( $expected_raw );
		if ( null !== $incumbent && ! self::is_stale( $incumbent['heartbeat_at'], $now ) ) {
			return null;
		}

		return $this->rows->replace( $key, $expected_raw, $raw ) ? $raw : null;
	}

	/**
	 * Releases only the exact lease row returned to this claimant.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 * @param   string $expected_raw     Exact claimed row bytes.
	 *
	 * @return  void
	 */
	public function release( string $registration_key, string $expected_raw ): void {
		$this->rows->delete( self::option_name( $registration_key ), $expected_raw );
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the fixed-size option identity for one registration key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @return  string
	 */
	private static function option_name( string $registration_key ): string {
		return self::OPTION_PREFIX . \hash( 'sha256', $registration_key );
	}

	/**
	 * Returns whether a heartbeat age is strictly greater than sixty seconds.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $heartbeat_at Last confirmed holder activity.
	 * @param   int $now          Current timestamp.
	 *
	 * @return  bool
	 */
	private static function is_stale( int $heartbeat_at, int $now ): bool {
		return $now > \PHP_INT_MIN + self::STALENESS
			&& $heartbeat_at < $now - self::STALENESS;
	}

	/**
	 * Returns a lease row's exact WordPress option representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{run_id: string, claimed_at: int, heartbeat_at: int} $row
	 *
	 * @param   array $row Complete occurrence-lease state.
	 *
	 * @throws  \LogicException When WordPress does not serialize the lease row to a string.
	 *
	 * @return  string
	 */
	private static function serialize( array $row ): string {
		$raw = \maybe_serialize( $row );
		if ( ! \is_string( $raw ) ) {
			throw new \LogicException( 'WordPress must serialize an occurrence lease row to a string.' );
		}

		return $raw;
	}

	/**
	 * Decodes only the exact scalar lease-row schema.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $raw Exact persisted option value.
	 *
	 * @return  array{run_id: string, claimed_at: int, heartbeat_at: int}|null
	 */
	private static function parse( string $raw ): ?array {
		$value = RawOptionDecoder::decode( $raw );

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

	// endregion
}

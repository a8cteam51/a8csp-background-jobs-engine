<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\RandomizerInterface;
use Psr\Clock\ClockInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Serializes one schedule occurrence's read-decide-persist critical section.
 *
 * The sixty-second stale window bounds crash recovery around scheduler acceptance and the registry
 * CAS; accepted dispatches release before client hooks, and asynchronous task execution is never leased.
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
	public const string OPTION_PREFIX = 'a8csp_bgte_occurrence_lease_';

	/**
	 * Maximum lease age in seconds before a new occurrence may reclaim it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int STALENESS = 60;

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
	 * Claims one occurrence identity and classifies contention separately from storage uncertainty.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @return  OccurrenceLeaseClaim Classified claim with a handle only after confirmed ownership.
	 */
	public function claim( string $registration_key ): OccurrenceLeaseClaim {
		$key = self::option_name( $registration_key );
		$now = $this->clock->now()->getTimestamp();
		$row = array(
			'claim_token' => \sprintf( '%019d', $this->randomizer->int( 0, \PHP_INT_MAX ) ),
			'claimed_at'  => $now,
		);
		$raw = self::serialize( $row );

		if ( $this->rows->insert_if_absent( $key, $raw ) ) {
			$selected = $this->rows->read( $key );
			if ( $selected->is_failure() ) {
				return OccurrenceLeaseClaim::indeterminate_read();
			}

			return $raw === $selected->value
				? OccurrenceLeaseClaim::claimed( new ClaimedLease( $this->rows, $key, $raw ) )
				: OccurrenceLeaseClaim::held();
		}

		$selected = $this->rows->read( $key );
		if ( $selected->is_failure() ) {
			return OccurrenceLeaseClaim::indeterminate_read();
		}

		$expected_raw = $selected->value;
		if ( null === $expected_raw ) {
			return OccurrenceLeaseClaim::indeterminate_write();
		}

		$incumbent = self::parse( $expected_raw );
		if ( null !== $incumbent && ! self::is_stale( $incumbent['claimed_at'], $now ) ) {
			return OccurrenceLeaseClaim::held();
		}

		if ( $this->rows->compare_and_swap( $key, $expected_raw, $raw ) ) {
			return OccurrenceLeaseClaim::claimed( new ClaimedLease( $this->rows, $key, $raw ) );
		}

		$current = $this->rows->read( $key );
		if ( $current->is_failure() ) {
			return OccurrenceLeaseClaim::indeterminate_read();
		}

		return $expected_raw === $current->value
			? OccurrenceLeaseClaim::indeterminate_write()
			: OccurrenceLeaseClaim::held();
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
	 * Returns whether a claim age is strictly greater than sixty seconds.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $claimed_at Claim timestamp.
	 * @param   int $now        Current timestamp.
	 *
	 * @return  bool
	 */
	private static function is_stale( int $claimed_at, int $now ): bool {
		return $now > \PHP_INT_MIN + self::STALENESS
			&& $claimed_at < $now - self::STALENESS;
	}

	/**
	 * Returns a lease row's exact WordPress option representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{claim_token: string, claimed_at: int} $row
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
	 * @return  array{claim_token: string, claimed_at: int}|null
	 */
	private static function parse( string $raw ): ?array {
		$value = RawOptionDecoder::decode( $raw );

		if (
			! \is_array( $value )
			|| 2 !== \count( $value )
			|| ! \is_string( $value['claim_token'] ?? null )
			|| ! \is_int( $value['claimed_at'] ?? null )
		) {
			return null;
		}

		return array(
			'claim_token' => $value['claim_token'],
			'claimed_at'  => $value['claimed_at'],
		);
	}

	// endregion
}

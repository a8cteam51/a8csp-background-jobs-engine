<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RowWriteOutcome;

\defined( 'ABSPATH' ) || exit;

/**
 * Persists the latest discoverable run globally and for each single-flight identity.
 *
 * Execution-overlap lock ownership fences active work. These bounded pointers provide discovery
 * metadata and are repaired by an owner when eviction or a concurrent start commit makes them lag.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class LatestRunPointer {
	// region FIELDS AND CONSTANTS

	/**
	 * Maximum number of single-flight identities retained with latest-run pointers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int HASH_LIMIT = 20;

	/**
	 * Prefix for latest-run pointer option names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string OPTION_PREFIX = 'a8csp_bgje_latest_run_';

	/**
	 * Maximum compare-and-swap attempts before a contended write fails safely.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int UPDATE_ATTEMPTS = 5;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string     $identity Complete scope-qualified job or chunked job identity.
	 * @param   OptionRows $rows     Authoritative raw pointer-row I/O.
	 */
	public function __construct(
		private string $identity,
		private OptionRows $rows,
	) {}

	// endregion

	// region METHODS

	/**
	 * Records a run as the latest for its single-flight identity.
	 *
	 * Identities beyond the bounded LRU cap re-record on every action, which causes write churn
	 * without weakening lock-authoritative safety.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id    Run identifier.
	 * @param   string $args_hash Stable single-flight identity.
	 *
	 * @throws  \LogicException When the current site differs from the bound site or WordPress does
	 *                          not serialize the pointer to a string.
	 *
	 * @return  bool True when the requested pointer state is confirmed persisted.
	 */
	#[\NoDiscard( 'a latest-run pointer persistence failure must be handled, not dropped' )]
	public function record( string $run_id, string $args_hash ): bool {
		return $this->persist( $run_id, $args_hash );
	}

	/**
	 * Returns the latest run for one single-flight identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $args_hash Stable single-flight identity.
	 *
	 * @return  string|null
	 */
	public function get_latest_for_hash( string $args_hash ): ?string {
		$by_hash = self::by_hash_from_option( $this->stored_pointer() );

		return $by_hash[ $args_hash ] ?? null;
	}

	// endregion

	// region HELPERS

	/**
	 * Moves one identity to the LRU tail using bounded exact-row compare-and-swap attempts.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id    Run identifier for the moved identity.
	 * @param   string $args_hash Stable single-flight identity.
	 *
	 * @return  bool True when the requested pointer state is confirmed persisted.
	 */
	private function persist( string $run_id, string $args_hash ): bool {
		$option_name = $this->option_name();

		for ( $attempt = 0; $attempt < self::UPDATE_ATTEMPTS; ++$attempt ) {
			$selected = $this->rows->read( $option_name );
			if ( $selected->is_failure() ) {
				return false;
			}

			$expected_raw = $selected->value;
			$value        = null === $expected_raw ? null : RawOptionDecoder::decode( $expected_raw );

			$by_hash = self::by_hash_from_option( $value );

			unset( $by_hash[ $args_hash ] );
			$by_hash[ $args_hash ] = $run_id;
			if ( self::HASH_LIMIT < \count( $by_hash ) ) {
				$by_hash = \array_slice( $by_hash, -self::HASH_LIMIT, null, true );
			}

			$replacement_raw = self::serialize_pointer( array( 'by_hash' => $by_hash ) );
			if ( $replacement_raw === $expected_raw ) {
				return true;
			}

			if ( null === $expected_raw ) {
				if ( RowWriteOutcome::Won === $this->rows->insert_if_absent( $option_name, $replacement_raw ) ) {
					return true;
				}

				continue;
			}

			$write = $this->rows->compare_and_swap( $option_name, $expected_raw, $replacement_raw );
			if ( RowWriteOutcome::Won === $write ) {
				return true;
			}
			if ( RowWriteOutcome::WriteFailed === $write ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Returns the decoded authoritative pointer row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>|null
	 */
	private function stored_pointer(): ?array {
		$selected = $this->rows->read( $this->option_name() );
		if ( $selected->is_failure() || null === $selected->value ) {
			return null;
		}

		$value = RawOptionDecoder::decode( $selected->value );

		return \is_array( $value ) ? $value : null;
	}

	/**
	 * Returns the pointer option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function option_name(): string {
		return self::OPTION_PREFIX . $this->identity;
	}

	/**
	 * Returns valid per-hash pointers from a persisted option.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $value Persisted option value.
	 *
	 * @return  array<array-key, string>
	 */
	private static function by_hash_from_option( mixed $value ): array {
		if ( ! \is_array( $value ) || ! \is_array( $value['by_hash'] ?? null ) ) {
			return array();
		}

		return \array_filter( $value['by_hash'], static fn ( mixed $run_id ): bool => \is_string( $run_id ) );
	}

	/**
	 * Returns a pointer's exact WordPress option representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $pointer Complete pointer state.
	 *
	 * @throws  \LogicException When WordPress does not serialize the pointer to a string.
	 *
	 * @return  string
	 */
	private static function serialize_pointer( array $pointer ): string {
		$raw = \maybe_serialize( $pointer );
		if ( ! \is_string( $raw ) ) {
			throw new \LogicException( 'WordPress must serialize the latest-run pointer to a string.' );
		}

		return $raw;
	}

	// endregion
}

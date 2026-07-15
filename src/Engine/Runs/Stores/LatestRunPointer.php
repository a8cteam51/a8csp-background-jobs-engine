<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;

\defined( 'ABSPATH' ) || exit;

/**
 * Persists the latest discoverable run globally and for each argument identity.
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
	 * Maximum number of argument identities retained with latest-run pointers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const HASH_LIMIT = 20;

	/**
	 * Prefix for latest-run pointer option names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const OPTION_PREFIX = 'a8csp_bgte_latest_';

	/**
	 * Maximum compare-and-swap attempts before a contended write fails safely.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const UPDATE_ATTEMPTS = 5;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string     $name Stable task or batch name.
	 * @param   OptionRows $rows Authoritative raw pointer-row I/O.
	 */
	public function __construct(
		private string $name,
		private OptionRows $rows,
	) {}

	// endregion

	// region METHODS

	/**
	 * Records a run as latest globally and for its argument identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id    Run identifier.
	 * @param   string $args_hash Stable identity of the start arguments.
	 *
	 * @return  bool True when the requested pointer state is confirmed persisted.
	 */
	#[\NoDiscard( 'a latest-run pointer persistence failure must be handled, not dropped' )]
	public function record( string $run_id, string $args_hash ): bool {
		return $this->persist( $run_id, $args_hash, false );
	}

	/**
	 * Repairs one owner pointer without displacing an unrelated global latest run.
	 *
	 * The global pointer moves only when absent or when it names the displaced same-identity value.
	 * Identities beyond the bounded LRU cap repair on every action, which causes write churn without
	 * weakening lock-authoritative safety.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id    Authoritative lock owner.
	 * @param   string $args_hash Stable identity of the owner's start arguments.
	 *
	 * @return  bool True when the requested pointer state is confirmed persisted.
	 */
	#[\NoDiscard( 'a latest-run pointer persistence failure must be handled, not dropped' )]
	public function repair_for_hash( string $run_id, string $args_hash ): bool {
		return $this->persist( $run_id, $args_hash, true );
	}

	/**
	 * Returns the latest run across all argument identities.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string|null
	 */
	public function get_latest(): ?string {
		$value = $this->stored_pointer();

		return \is_string( $value['all'] ?? null ) ? $value['all'] : null;
	}

	/**
	 * Returns the latest run for one argument identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $args_hash Stable identity of the start arguments.
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
	 * @param   string $run_id        Run identifier for the moved identity.
	 * @param   string $args_hash     Stable identity of the start arguments.
	 * @param   bool   $repair_global Whether to preserve an unrelated global pointer.
	 *
	 * @return  bool True when the requested pointer state is confirmed persisted.
	 */
	private function persist( string $run_id, string $args_hash, bool $repair_global ): bool {
		$option_name = $this->option_name();

		for ( $attempt = 0; $attempt < self::UPDATE_ATTEMPTS; ++$attempt ) {
			$selected = $this->rows->read( $option_name );
			if ( $selected->is_failure() ) {
				return false;
			}

			$expected_raw = $selected->value;
			$value        = null === $expected_raw ? null : RawOptionDecoder::decode( $expected_raw );

			$by_hash       = self::by_hash_from_option( $value );
			$previous_run  = $by_hash[ $args_hash ] ?? null;
			$global_run_id = \is_array( $value ) && \is_string( $value['all'] ?? null ) ? $value['all'] : null;
			if ( ! $repair_global || null === $global_run_id || $global_run_id === $previous_run ) {
				$global_run_id = $run_id;
			}

			unset( $by_hash[ $args_hash ] );
			$by_hash[ $args_hash ] = $run_id;
			if ( self::HASH_LIMIT < \count( $by_hash ) ) {
				$by_hash = \array_slice( $by_hash, -self::HASH_LIMIT, null, true );
			}

			$replacement_raw = self::serialize_pointer(
				array(
					'all'     => $global_run_id,
					'by_hash' => $by_hash,
				)
			);
			if ( $replacement_raw === $expected_raw ) {
				return true;
			}

			if ( null === $expected_raw ) {
				if ( $this->rows->insert( $option_name, $replacement_raw ) ) {
					return true;
				}

				continue;
			}

			if ( $this->rows->replace( $option_name, $expected_raw, $replacement_raw ) ) {
				return true;
			}

			$current = $this->rows->read( $option_name );
			if ( $current->is_failure() ) {
				return false;
			}
			if ( $current->value === $expected_raw ) {
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
		return self::OPTION_PREFIX . $this->name;
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

		$by_hash = array();
		foreach ( $value['by_hash'] as $args_hash => $run_id ) {
			if ( \is_string( $run_id ) ) {
				$by_hash[ $args_hash ] = $run_id;
			}
		}

		return $by_hash;
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

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores;

\defined( 'ABSPATH' ) || exit;

/**
 * Persists the latest run globally and for each argument identity.
 *
 * These pointers fence active work: a run whose identifier is no longer latest for its identity
 * is superseded and must stop.
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
	private const OPTION_PREFIX = 'a8csp_bgte_latest_';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Stable task or batch name.
	 */
	public function __construct( private string $name ) {}

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
	 * @return  void
	 */
	public function record( string $run_id, string $args_hash ): void {
		$by_hash = self::by_hash_from_option( \get_option( $this->option_name(), null ) );

		unset( $by_hash[ $args_hash ] );
		$by_hash[ $args_hash ] = $run_id;

		if ( self::HASH_LIMIT < \count( $by_hash ) ) {
			$by_hash = \array_slice( $by_hash, -self::HASH_LIMIT, null, true );
		}

		\update_option(
			$this->option_name(),
			array(
				'all'     => $run_id,
				'by_hash' => $by_hash,
			),
			false
		);
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
		$value = \get_option( $this->option_name(), null );

		return \is_array( $value ) && \is_string( $value['all'] ?? null ) ? $value['all'] : null;
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
		$by_hash = self::by_hash_from_option( \get_option( $this->option_name(), null ) );

		return $by_hash[ $args_hash ] ?? null;
	}

	// endregion

	// region HELPERS

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

	// endregion
}

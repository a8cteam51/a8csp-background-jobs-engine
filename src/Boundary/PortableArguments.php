<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Boundary;

\defined( 'ABSPATH' ) || exit;

/**
 * Enforces the portable-arguments rule for engine-owned storage.
 *
 * Portable arguments contain only scalars, null, or nested arrays of such values. Work arguments
 * persist in engine-owned storage, while scheduling backends carry only the delivery token; handlers
 * resolve the persisted values byte-faithfully after delivery.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class PortableArguments {
	// region FIELDS AND CONSTANTS

	/**
	 * Maximum array depth accepted by the portable-arguments rule.
	 *
	 * The cross-layer portability rule applies before backend selection, and matching the JSON
	 * encoder's default keeps recursive validation finite. `Schedule::MAX_ARGUMENTS_JSON_DEPTH` is the
	 * one copy a change here must reach by hand, because a public model cannot import this constant;
	 * `Runtime\Backends\SchedulerFacade::MAX_ARGUMENTS_JSON_DEPTH` aliases it and follows on its own.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int MAX_ARGUMENTS_JSON_DEPTH = 512;

	// endregion

	// region METHODS

	/**
	 * Returns whether values satisfy the portability rule within the permitted array depth.
	 *
	 * The remaining-depth bound terminates traversal regardless of caller preprocessing.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $values          Values to inspect.
	 * @param   int                     $remaining_depth Array levels still permitted.
	 *
	 * @return  bool
	 */
	public static function is_valid( array $values, int $remaining_depth = self::MAX_ARGUMENTS_JSON_DEPTH ): bool {
		if ( 1 > $remaining_depth ) {
			return false;
		}

		return \array_all( $values, static fn ( mixed $value ): bool => \is_array( $value ) ? self::is_valid( $value, $remaining_depth - 1 ) : ( null === $value || \is_scalar( $value ) ) );
	}

	/**
	 * Returns the canonical insertion-ordered argument hash when values are portable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $values Values to encode.
	 *
	 * @throws  \JsonException When JSON encoding rejects the argument tree.
	 *
	 * @return  string|null
	 */
	public static function hash( array $values ): ?string {
		$encoded = \wp_json_encode( $values, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION );

		return \is_string( $encoded ) && self::is_valid( $values )
			? \hash( 'sha256', $encoded )
			: null;
	}

	/**
	 * Rebuilds an argument tree by value so retained values share no PHP reference containers.
	 *
	 * @internal Engine-owned argument snapshots only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $values Values to rebuild.
	 *
	 * @return  array<array-key, mixed>
	 */
	public static function without_references( array $values ): array {
		$snapshot = array();
		foreach ( $values as $key => $value ) {
			$snapshot[ $key ] = \is_array( $value ) ? self::without_references( $value ) : $value;
		}

		return $snapshot;
	}

	// endregion
}

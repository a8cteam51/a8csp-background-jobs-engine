<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support;

\defined( 'ABSPATH' ) || exit;

/**
 * Enforces the portable-arguments rule for backend round-trips.
 *
 * Portable arguments contain only scalars, null, or nested arrays of such values and must survive
 * backend serialization round-trips byte-faithfully.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class PortableArguments {
	// region METHODS

	/**
	 * Returns whether values satisfy the portability rule within the permitted array depth.
	 *
	 * JSON encoding runs before unbounded callers use this traversal so recursive arrays do not
	 * reach it. The optional depth bound rejects excessive nesting independently of encoding.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $values          Values to inspect.
	 * @param   int                     $remaining_depth Array levels still permitted.
	 *
	 * @return  bool
	 */
	public static function is_valid( array $values, int $remaining_depth = 512 ): bool {
		if ( 1 > $remaining_depth ) {
			return false;
		}

		foreach ( $values as $value ) {
			if ( \is_array( $value ) ) {
				if ( ! self::is_valid( $value, $remaining_depth - 1 ) ) {
					return false;
				}

				continue;
			}

			if ( null !== $value && ! \is_scalar( $value ) ) {
				return false;
			}
		}

		return true;
	}

	// endregion
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Helpers;

\defined( 'ABSPATH' ) || exit;

/**
 * Recognizes arrays whose leaves remain portable through JSON and option storage.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class ScalarTree {
	// region METHODS

	/**
	 * Returns whether every leaf is null or scalar within the permitted array depth.
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

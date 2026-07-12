<?php declare( strict_types=1 );

if ( ! \function_exists( 'wp_json_encode' ) ) {
	/**
	 * Mirrors the WordPress wrapper where Core is absent from the unit process.
	 *
	 * @param   mixed $value Value to encode.
	 * @param   int   $flags JSON encoding flags.
	 * @param   int   $depth Maximum depth.
	 *
	 * @phpstan-param int<1, max> $depth
	 *
	 * @return  string|false
	 */
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This stub provides the preferred wrapper when WordPress is absent.
		return \json_encode( $value, $flags, $depth );
	}
}

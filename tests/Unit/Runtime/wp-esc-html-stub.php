<?php declare( strict_types=1 );

if ( ! \function_exists( 'esc_html' ) ) {
	/**
	 * Mirrors the WordPress escaper where Core is absent from the unit process.
	 *
	 * Two flags matter. Core passes ENT_QUOTES, so single quotes encode, and the rendering under test
	 * reproduces Action Scheduler's var_export() output, which quotes every string. Core also leaves
	 * _wp_specialchars()'s $double_encode at false where htmlspecialchars() defaults it to true, so an
	 * entity already present in a value encodes once rather than twice.
	 *
	 * @param   string $text Text to escape.
	 *
	 * @return  string
	 */
	function esc_html( string $text ): string {
		return \htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
	}
}

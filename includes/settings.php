<?php declare( strict_types=1 );

\defined( 'ABSPATH' ) || exit;

/**
 * Returns the example option's value. Each typed option reader names its option, applies its
 * default, and casts the return so callers never touch raw `get_option()` mixed values. The write
 * side — registration, sanitization, and rendering — lives in the `Settings` component in
 * `src/Settings.php`.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  string
 */
function a8csp_bgte_get_example_option(): string {
	return (string) get_option( 'a8csp_bgte_example_option', '' );
}

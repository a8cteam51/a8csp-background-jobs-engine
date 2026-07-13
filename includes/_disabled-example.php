<?php declare( strict_types=1 );

\defined( 'ABSPATH' ) || exit;

/**
 * The leading `_` in this filename opts it out of the `includes/` glob loader because
 * functions.php skips basenames that start with `_`. This convention lets code remain staged in
 * `includes/` without loading automatically; rename the file without the underscore when it
 * should load.
 *
 * @since   1.0.0
 * @version 1.0.0
 */

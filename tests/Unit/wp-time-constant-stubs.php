<?php declare( strict_types=1 );

/**
 * WordPress time constants for Unit tests that exercise default values outside WordPress.
 *
 * The guards keep this file inert wherever WordPress is loaded.
 *
 * @package A8C\SpecialProjects\BackgroundJobsEngine
 */

if ( ! \defined( 'MINUTE_IN_SECONDS' ) ) {
	\define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! \defined( 'HOUR_IN_SECONDS' ) ) {
	\define( 'HOUR_IN_SECONDS', 3600 );
}

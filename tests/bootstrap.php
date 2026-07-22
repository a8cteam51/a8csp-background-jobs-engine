<?php declare( strict_types=1 );

/**
 * PHPUnit bootstrap. Inside wp-env's `cli` container, also loads WP and the plugin
 * entry file — require_once is a no-op when WP already include_once'd the active plugin.
 *
 * @package A8C\SpecialProjects\BackgroundJobsEngine
 */

require_once __DIR__ . '/../vendor/autoload.php';

$a8csp_bgje_wp_load = '/var/www/html/wp-load.php';
if ( \file_exists( $a8csp_bgje_wp_load ) ) {
	require_once $a8csp_bgje_wp_load;
	require_once __DIR__ . '/../a8csp-background-jobs-engine.php';
} elseif ( ! \class_exists( 'wpdb' ) ) {
	if ( ! \defined( 'A8CSP_BGJE_DIR_PATH' ) ) {
		\define( 'A8CSP_BGJE_DIR_PATH', \dirname( __DIR__ ) . '/' );
	}

	\class_alias( \A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbRuntimeStub::class, 'wpdb' );
	\class_alias( \A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WPErrorStub::class, 'WP_Error' );
}

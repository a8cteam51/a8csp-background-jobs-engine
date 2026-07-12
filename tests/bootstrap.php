<?php declare( strict_types=1 );

/**
 * PHPUnit bootstrap. Inside wp-env's `cli` container, also loads WP and the plugin
 * entry file — require_once is a no-op when WP already include_once'd the active plugin.
 *
 * @package A8C\SpecialProjects\BackgroundTasksEngine
 */

require_once __DIR__ . '/../vendor/autoload.php';

$a8csp_bgte_wp_load = '/var/www/html/wp-load.php';
if ( \file_exists( $a8csp_bgte_wp_load ) ) {
	require_once $a8csp_bgte_wp_load;
	require_once __DIR__ . '/../a8csp-background-tasks-engine.php';
} elseif ( ! \class_exists( 'wpdb' ) ) {
	\class_alias( \A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbRuntimeStub::class, 'wpdb' );
}

<?php declare( strict_types=1 );

/**
 * Pre-test Action Scheduler migration step. A fresh environment starts Action Scheduler on its
 * hybrid store until the async data migration marks itself complete, and the integration rig
 * refuses every store except the custom-table one. Action Scheduler resolves its store singleton
 * while WordPress loads, so completion cannot happen inside the PHPUnit process that needs it —
 * this script runs first, in its own process, and mirrors Action Scheduler's own
 * `action-scheduler migrate` WP-CLI command: drain the migration runner, then mark completion.
 * Idempotent — a healthy environment exits immediately.
 *
 * @package A8C\SpecialProjects\BackgroundJobsEngine
 */

$a8csp_bgje_wp_load = '/var/www/html/wp-load.php';
if ( ! \file_exists( $a8csp_bgje_wp_load ) ) {
	exit( 0 );
}

require_once $a8csp_bgje_wp_load;

if (
	! \class_exists( \ActionScheduler_DataController::class )
	|| \ActionScheduler_DataController::is_migration_complete()
) {
	exit( 0 );
}

$a8csp_bgje_migration_controller = \Action_Scheduler\Migration\Controller::instance();
if ( ! $a8csp_bgje_migration_controller instanceof \Action_Scheduler\Migration\Controller ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- STDERR diagnostics in a CLI-only script; WP_Filesystem targets real files.
	\fwrite( \STDERR, 'Action Scheduler returned an unexpected migration controller; align this script with the installed Action Scheduler version.' . \PHP_EOL );
	exit( 1 );
}

if ( ! $a8csp_bgje_migration_controller->allow_migration() ) {
	exit( 0 );
}

$a8csp_bgje_migration_config = $a8csp_bgje_migration_controller->get_migration_config_object();
if ( ! $a8csp_bgje_migration_config instanceof \Action_Scheduler\Migration\Config ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- STDERR diagnostics in a CLI-only script; WP_Filesystem targets real files.
	\fwrite( \STDERR, 'Action Scheduler returned an unexpected migration config; align this script with the installed Action Scheduler version.' . \PHP_EOL );
	exit( 1 );
}

$a8csp_bgje_migration_runner = new \Action_Scheduler\Migration\Runner( $a8csp_bgje_migration_config );
$a8csp_bgje_migration_runner->init_destination();
do {
	$a8csp_bgje_migrated = $a8csp_bgje_migration_runner->run( 100 );
} while ( 0 < $a8csp_bgje_migrated );

( new \Action_Scheduler\Migration\Scheduler() )->mark_complete();

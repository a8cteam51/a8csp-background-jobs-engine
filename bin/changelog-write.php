<?php declare( strict_types=1 );
/**
 * Changelog writer that accepts an optional explicit initial version.
 *
 * @package A8C\SpecialProjects\BackgroundTasksEngine
 */

$a8csp_bgte_version = $argv[1] ?? null;
if ( 2 < \count( $argv ) ) {
	\fwrite( STDERR, "Usage: composer changelog:write -- [initial-version]\n" );
	exit( 2 );
}

$a8csp_bgte_arguments = array( 'changelogger', 'write' );
if ( null !== $a8csp_bgte_version ) {
	$a8csp_bgte_arguments[] = '--use-version=' . $a8csp_bgte_version;
}

require_once __DIR__ . '/../vendor/autoload.php';

$a8csp_bgte_application = new \Automattic\Jetpack\Changelogger\Application();
$a8csp_bgte_input       = new \Symfony\Component\Console\Input\ArgvInput( $a8csp_bgte_arguments );
exit( $a8csp_bgte_application->run( $a8csp_bgte_input ) );

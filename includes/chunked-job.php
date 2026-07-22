<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;

\defined( 'ABSPATH' ) || exit;

/**
 * Creates and schedules one run for a registered chunked job.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $owner      Client plugin owner.
 * @param   string                  $name       Owner-local chunked job name.
 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
 * @param   int                     $priority   Advisory priority from 0 through 255.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'a chunked-job-start failure must be handled, not dropped' )]
function a8csp_bgje_start_chunked_job( string $owner, string $name, array $start_args = array(), int $priority = 10 ): Run|\WP_Error {
	return a8csp_bgje( $owner )->jobs()->start( $name, $start_args, $priority );
}

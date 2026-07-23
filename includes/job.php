<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers one job or chunked job for an owner.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string        $owner      Client plugin owner.
 * @param   JobDefinition $definition Job definition to register.
 *
 * @return  true|\WP_Error
 */
#[\NoDiscard( 'a job-registration failure must be handled, not dropped' )]
function a8csp_bgje_register_job( string $owner, JobDefinition $definition ): true|\WP_Error {
	return a8csp_bgje( $owner )->jobs()->register( $definition );
}

/**
 * Creates and schedules one run for registered background work.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $owner         Client plugin owner.
 * @param   string                  $name          Owner-local background-work name.
 * @param   array<array-key, mixed> $start_args    Arguments supplied when the run starts.
 * @param   int                     $delay_seconds Scheduling delay in seconds.
 * @param   int|null                $priority      Advisory priority from 0 through 255, or null for the engine default.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'a job-dispatch failure must be handled, not dropped' )]
function a8csp_bgje_dispatch_job( string $owner, string $name, array $start_args = array(), int $delay_seconds = 0, ?int $priority = null ): Run|\WP_Error {
	return a8csp_bgje( $owner )->jobs()->dispatch( $name, $start_args, $delay_seconds, $priority );
}

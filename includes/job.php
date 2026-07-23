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
 * Creates and schedules one run for a registered job.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $owner         Client plugin owner.
 * @param   string                  $name          Owner-local job name.
 * @param   array<array-key, mixed> $args          Job arguments.
 * @param   int                     $delay_seconds Scheduling delay in seconds.
 * @param   int                     $priority      Advisory priority from 0 through 255.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
function a8csp_bgje_enqueue_job( string $owner, string $name, array $args = array(), int $delay_seconds = 0, int $priority = 10 ): Run|\WP_Error {
	return a8csp_bgje( $owner )->jobs()->enqueue( $name, $args, $delay_seconds, $priority );
}

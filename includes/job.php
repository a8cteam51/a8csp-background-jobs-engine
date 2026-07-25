<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers one job or chunked job for an owner.
 *
 * @api
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
 * Creates and asynchronously schedules one run for registered background work.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $owner      Client plugin owner.
 * @param   string                  $name       Owner-local background-work name.
 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
 * @param   int|null                $priority   Advisory priority from 0 through 255, or null for the engine default.
 *
 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'a job-dispatch failure must be handled, not dropped' )]
function a8csp_bgje_dispatch_job( string $owner, string $name, array $start_args = array(), ?int $priority = null ): Run|\WP_Error {
	return a8csp_bgje( $owner )->jobs()->dispatch( $name, $start_args, $priority );
}

/**
 * Creates and schedules one run for registered background work at an absolute Unix timestamp.
 *
 * A timestamp at or before admission time uses the asynchronous scheduling lane.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $owner      Client plugin owner.
 * @param   string                  $name       Owner-local background-work name.
 * @param   int                     $run_at     Absolute Unix timestamp for the first delivery.
 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
 * @param   int|null                $priority   Advisory priority from 0 through 255, or null for the engine default.
 *
 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'a timed job-dispatch failure must be handled, not dropped' )]
function a8csp_bgje_dispatch_job_at( string $owner, string $name, int $run_at, array $start_args = array(), ?int $priority = null ): Run|\WP_Error {
	return a8csp_bgje( $owner )->jobs()->dispatch_at( $name, $run_at, $start_args, $priority );
}

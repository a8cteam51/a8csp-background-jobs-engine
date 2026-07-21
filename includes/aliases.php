<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers one job or chunked job for an owner.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string       $owner Client plugin owner.
 * @param   JobInterface $job   Job to register.
 *
 * @return  true|\WP_Error
 */
#[\NoDiscard( 'a job-registration failure must be handled, not dropped' )]
function a8csp_bgje_register( string $owner, JobInterface $job ): true|\WP_Error {
	return a8csp_bgje( $owner )->jobs()->register( $job );
}

/**
 * Registers one callable-backed job for an owner.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $owner   Client plugin owner.
 * @param   string                  $name    Stable owner-local job name.
 * @param   callable                $handler Job handler.
 * @param   array<array-key, mixed> $options Optional policy and lifecycle-callback overrides.
 *
 * @return  true|\WP_Error
 */
#[\NoDiscard( 'a job-registration failure must be handled, not dropped' )]
function a8csp_bgje_register_callable( string $owner, string $name, callable $handler, array $options = array() ): true|\WP_Error {
	return a8csp_bgje( $owner )->jobs()->register_callable( $name, $handler, $options );
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
function a8csp_bgje_enqueue( string $owner, string $name, array $args = array(), int $delay_seconds = 0, int $priority = 10 ): Run|\WP_Error {
	return a8csp_bgje( $owner )->jobs()->enqueue( $name, $args, $delay_seconds, $priority );
}

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
function a8csp_bgje_start( string $owner, string $name, array $start_args = array(), int $priority = 10 ): Run|\WP_Error {
	return a8csp_bgje( $owner )->jobs()->start( $name, $start_args, $priority );
}

/**
 * Synchronizes an owner's complete declared schedule set.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $owner     Client plugin owner.
 * @param   array<array-key, mixed> $schedules Complete schedule specification set.
 *
 * @return  true|\WP_Error
 */
#[\NoDiscard( 'a schedule-sync failure must be handled, not dropped' )]
function a8csp_bgje_sync_schedules( string $owner, array $schedules ): true|\WP_Error {
	return a8csp_bgje( $owner )->schedules()->sync( $schedules );
}

/**
 * Immediately dispatches one declared schedule target.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner Client plugin owner.
 * @param   string $name  Owner-local schedule name.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'a schedule dispatch-now failure must be handled, not dropped' )]
function a8csp_bgje_dispatch_schedule( string $owner, string $name ): Run|\WP_Error {
	return a8csp_bgje( $owner )->schedules()->dispatch( $name );
}

/**
 * Returns the retained status of one run.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner  Client plugin owner.
 * @param   string $name   Owner-local job or chunked job name.
 * @param   string $run_id Run identifier.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'a run-inspection result must be handled, not dropped' )]
function a8csp_bgje_inspect_run( string $owner, string $name, string $run_id ): Run|\WP_Error {
	return a8csp_bgje( $owner )->runs()->inspect( $name, $run_id );
}

/**
 * Returns the most recently retained completed run for one background-work name.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner Client plugin owner.
 * @param   string $name  Owner-local job or chunked job name.
 *
 * @return  Run|null|\WP_Error
 */
#[\NoDiscard( 'a last-completed-run result must be handled, not dropped' )]
function a8csp_bgje_last_completed_run( string $owner, string $name ): Run|null|\WP_Error {
	return a8csp_bgje( $owner )->runs()->last_completed( $name );
}

/**
 * Starts a fresh run from one retained failed run's original arguments.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner  Client plugin owner.
 * @param   string $name   Owner-local job or chunked job name.
 * @param   string $run_id Retained failed-run identifier.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
function a8csp_bgje_retry_failed_run( string $owner, string $name, string $run_id ): Run|\WP_Error {
	return a8csp_bgje( $owner )->runs()->retry_failed( $name, $run_id );
}

/**
 * Cancels one retained run that has not passed its cancellation boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner  Client plugin owner.
 * @param   string $name   Owner-local job or chunked job name.
 * @param   string $run_id Retained run identifier.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
function a8csp_bgje_cancel_run( string $owner, string $name, string $run_id ): Run|\WP_Error {
	return a8csp_bgje( $owner )->runs()->cancel( $name, $run_id );
}

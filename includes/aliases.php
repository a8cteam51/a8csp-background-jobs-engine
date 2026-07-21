<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;

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
	try {
		$allowed = array( 'max_runtime', 'retry', 'overlap', 'overlap_key', 'on_completed', 'on_failed' );
		foreach ( $options as $option => $value ) {
			if ( ! \is_string( $option ) || ! \in_array( $option, $allowed, true ) ) {
				return new \WP_Error( 'invalid_argument', 'Callable job options accept only max_runtime, retry, overlap, overlap_key, on_completed, and on_failed.' );
			}
		}

		$max_runtime = null;
		if ( \array_key_exists( 'max_runtime', $options ) ) {
			if ( ! \is_int( $options['max_runtime'] ) ) {
				return new \WP_Error( 'invalid_argument', 'max_runtime must be an integer' );
			}

			$max_runtime = $options['max_runtime'];
		}

		$retry = null;
		if ( \array_key_exists( 'retry', $options ) ) {
			if ( ! \is_array( $options['retry'] ) ) {
				return new \WP_Error( 'invalid_argument', 'retry must be an array' );
			}

			$declaration = $options['retry'];
			foreach ( $declaration as $field => $value ) {
				if ( ! \is_string( $field ) || ! \in_array( $field, array( 'max_attempts', 'base_delay', 'multiplier', 'max_delay' ), true ) || ! \is_int( $value ) ) {
					return new \WP_Error( 'invalid_argument', 'Retry declarations accept only integer max_attempts, base_delay, multiplier, and max_delay fields.' );
				}
			}

			$defaults = new RetryPolicy();
			$retry    = new RetryPolicy( max_attempts: $declaration['max_attempts'] ?? $defaults->max_attempts, base_delay: $declaration['base_delay'] ?? $defaults->base_delay, multiplier: $declaration['multiplier'] ?? $defaults->multiplier, max_delay: $declaration['max_delay'] ?? $defaults->max_delay );
		}

		$overlap = null;
		if ( \array_key_exists( 'overlap', $options ) ) {
			if ( ! \is_string( $options['overlap'] ) ) {
				return new \WP_Error( 'invalid_argument', 'overlap must be a string' );
			}

			$overlap = OverlapPolicy::tryFrom( $options['overlap'] );
			if ( null === $overlap ) {
				return new \WP_Error( 'invalid_argument', 'Overlap policy accepts only allow, reject, or replace.' );
			}
		}

		$overlap_key = null;
		if ( \array_key_exists( 'overlap_key', $options ) ) {
			if ( ! \is_callable( $options['overlap_key'] ) ) {
				return new \WP_Error( 'invalid_argument', 'overlap_key must be callable' );
			}

			$overlap_key = \Closure::fromCallable( $options['overlap_key'] );
		}

		$on_completed = null;
		if ( \array_key_exists( 'on_completed', $options ) ) {
			if ( ! \is_callable( $options['on_completed'] ) ) {
				return new \WP_Error( 'invalid_argument', 'on_completed must be callable' );
			}

			$on_completed = \Closure::fromCallable( $options['on_completed'] );
		}

		$on_failed = null;
		if ( \array_key_exists( 'on_failed', $options ) ) {
			if ( ! \is_callable( $options['on_failed'] ) ) {
				return new \WP_Error( 'invalid_argument', 'on_failed must be callable' );
			}

			$on_failed = \Closure::fromCallable( $options['on_failed'] );
		}

		return a8csp_bgje( $owner )->jobs()->register_callable( name: $name, handler: $handler, max_runtime: $max_runtime, retry: $retry, overlap: $overlap, overlap_key: $overlap_key, on_completed: $on_completed, on_failed: $on_failed );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}
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
	try {
		$built = array();
		foreach ( $schedules as $specification ) {
			if ( ! \is_array( $specification ) ) {
				return new \WP_Error( 'invalid_argument', 'schedule entries must be arrays' );
			}

			if ( ! isset( $specification['name'], $specification['every'], $specification['job'] ) ) {
				return new \WP_Error( 'invalid_argument', 'schedule entries must include name, every, and job' );
			}

			$name  = $specification['name'];
			$every = $specification['every'];
			$job   = $specification['job'];
			if ( ! \is_string( $name ) || ! \is_string( $job ) ) {
				return new \WP_Error( 'invalid_argument', 'name and job must be strings' );
			}
			if ( ! \is_int( $every ) ) {
				return new \WP_Error( 'invalid_argument', 'every must be an integer number of seconds' );
			}

			$anchor = $specification['anchor'] ?? null;
			if ( null !== $anchor && ! \is_int( $anchor ) ) {
				return new \WP_Error( 'invalid_argument', 'anchor must be an integer number of seconds' );
			}

			$args = $specification['args'] ?? array();
			if ( ! \is_array( $args ) ) {
				return new \WP_Error( 'invalid_argument', 'args must be an array' );
			}

			$catch_up_value = $specification['catch_up'] ?? 'run_once';
			if ( ! \is_string( $catch_up_value ) ) {
				return new \WP_Error( 'invalid_argument', 'catch_up must be run_once or skip' );
			}
			$catch_up = CatchUpPolicy::tryFrom( $catch_up_value );
			if ( null === $catch_up ) {
				return new \WP_Error( 'invalid_argument', 'catch_up must be run_once or skip' );
			}

			$priority = $specification['priority'] ?? 10;
			if ( ! \is_int( $priority ) ) {
				return new \WP_Error( 'invalid_argument', 'priority must be an integer' );
			}

			$recurrence = null === $anchor ? Recurrence::every( $every ) : Recurrence::every_anchored( $every, $anchor );
			$built[]    = new Schedule( $name, $recurrence, $job, $args, $catch_up, $priority );
		}

		return a8csp_bgje( $owner )->schedules()->sync( ...$built );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}
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

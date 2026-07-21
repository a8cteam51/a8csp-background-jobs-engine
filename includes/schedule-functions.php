<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;

\defined( 'ABSPATH' ) || exit;

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
		$allowed = array( 'name', 'every', 'job', 'args', 'anchor', 'catch_up', 'priority' );
		$built   = array();
		foreach ( $schedules as $specification ) {
			if ( ! \is_array( $specification ) ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'schedule entries must be arrays' );
			}

			foreach ( $specification as $field => $value ) {
				if ( ! \is_string( $field ) || ! \in_array( $field, $allowed, true ) ) {
					return new \WP_Error( ErrorCode::InvalidArgument->value, 'Schedule specifications accept only name, every, job, args, anchor, catch_up, and priority.' );
				}
			}

			if ( ! isset( $specification['name'], $specification['every'], $specification['job'] ) ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'schedule entries must include name, every, and job' );
			}

			$name  = $specification['name'];
			$every = $specification['every'];
			$job   = $specification['job'];
			if ( ! \is_string( $name ) || ! \is_string( $job ) ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'name and job must be strings' );
			}
			if ( ! \is_int( $every ) ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'every must be an integer number of seconds' );
			}

			$anchor = $specification['anchor'] ?? null;
			if ( null !== $anchor && ! \is_int( $anchor ) ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'anchor must be an integer number of seconds' );
			}

			$args = $specification['args'] ?? array();
			if ( ! \is_array( $args ) ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'args must be an array' );
			}

			$catch_up_value = $specification['catch_up'] ?? 'run_once';
			if ( ! \is_string( $catch_up_value ) ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'catch_up must be run_once or skip' );
			}
			$catch_up = CatchUpPolicy::tryFrom( $catch_up_value );
			if ( null === $catch_up ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'catch_up must be run_once or skip' );
			}

			$priority = $specification['priority'] ?? 10;
			if ( ! \is_int( $priority ) ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'priority must be an integer' );
			}

			$recurrence = null === $anchor ? Recurrence::every( $every ) : Recurrence::every_anchored( $every, $anchor );
			$built[]    = new Schedule( $name, $recurrence, $job, $args, $catch_up, $priority );
		}

		return a8csp_bgje( $owner )->schedules()->sync( ...$built );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
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

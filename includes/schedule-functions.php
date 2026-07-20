<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Schedule;

\defined( 'ABSPATH' ) || exit;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- Local validation exceptions are translated to WP_Error before crossing the procedural boundary.
/**
 * Synchronizes an owner's complete declarative schedule set.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $owner     Client plugin owner.
 * @param   array<array-key, mixed> $schedules Complete schedule specification set.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  true|\WP_Error
 */
#[\NoDiscard( 'a schedule-sync failure must be handled, not dropped' )]
function a8csp_bgje_schedule_sync( string $owner, array $schedules ): true|\WP_Error {
	try {
		$declarations = array();
		foreach ( $schedules as $specification ) {
			if ( ! \is_array( $specification ) ) {
				throw new \InvalidArgumentException( 'schedule entries must be arrays' );
			}

			if ( ! isset( $specification['name'], $specification['every'], $specification['job'] ) ) {
				throw new \InvalidArgumentException( 'schedule entries must include name, every, and job' );
			}

			$name  = $specification['name'];
			$every = $specification['every'];
			$job   = $specification['job'];
			if ( ! \is_string( $name ) || ! \is_string( $job ) ) {
				throw new \InvalidArgumentException( 'name and job must be strings' );
			}
			if ( ! \is_int( $every ) ) {
				throw new \InvalidArgumentException( 'every must be an integer number of seconds' );
			}

			$args = $specification['args'] ?? array();
			if ( ! \is_array( $args ) ) {
				throw new \InvalidArgumentException( 'args must be an array' );
			}

			$catch_up_value = $specification['catch_up'] ?? 'run_once';
			if ( ! \is_string( $catch_up_value ) ) {
				throw new \InvalidArgumentException( 'catch_up must be run_once or skip' );
			}
			$catch_up = CatchUpPolicy::tryFrom( $catch_up_value );
			if ( null === $catch_up ) {
				throw new \InvalidArgumentException( 'catch_up must be run_once or skip' );
			}

			$priority = $specification['priority'] ?? 10;
			if ( ! \is_int( $priority ) ) {
				throw new \InvalidArgumentException( 'priority must be an integer' );
			}

			$declarations[] = new Schedule( $name, Recurrence::every( $every ), $job, $args, $catch_up, $priority );
		}

		$result = \a8csp_bgje( $owner )->schedules()->sync( $declarations );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	if ( $result->is_failure() ) {
		return new \WP_Error( $result->error->code->value, $result->error->message, $result->error->context );
	}

	return $result->value;
}
// phpcs:enable

/**
 * Immediately dispatches one declared schedule target.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner Client plugin owner.
 * @param   string $name  Owner-local schedule name.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  string|\WP_Error
 */
#[\NoDiscard( 'a schedule dispatch-now failure must be handled, not dropped' )]
function a8csp_bgje_schedule_dispatch( string $owner, string $name ): string|\WP_Error {
	try {
		$result = \a8csp_bgje( $owner )->schedules()->dispatch_now( $name );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	if ( $result->is_failure() ) {
		return new \WP_Error( $result->error->code->value, $result->error->message, $result->error->context );
	}

	return $result->value;
}

<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Component;

\defined( 'ABSPATH' ) || exit;

/**
 * Returns the most recently retained completed run ID for one work name.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner Client plugin owner.
 * @param   string $name  Owner-local job or chunked job name.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  string|null|\WP_Error
 */
#[\NoDiscard( 'a last-completed-run result must be handled, not dropped' )]
function a8csp_bgje_run_last_completed( string $owner, string $name ): string|null|\WP_Error {
	try {
		$result = Component::client( $owner )->runs()->last_completed_run_id( $name );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	if ( $result->is_failure() ) {
		return new \WP_Error( $result->error->code->value, $result->error->message, $result->error->context );
	}

	return $result->value;
}

/**
 * Starts a fresh run from one retained failed run's original arguments.
 *
 * The engine retains up to 20 failed runs per owner-qualified identity and evicts the oldest entry
 * when that limit is exceeded. A retry that successfully starts a fresh run consumes and removes
 * its retained source entry, making normal retry one-shot. Retention is best-effort: a failed
 * retention write is logged while failure callbacks, hooks, and history processing continue.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner  Client plugin owner.
 * @param   string $name   Owner-local job or chunked job name.
 * @param   string $run_id Retained failed-run identifier.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  string|\WP_Error
 */
#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
function a8csp_bgje_run_retry_failed( string $owner, string $name, string $run_id ): string|\WP_Error {
	try {
		$result = Component::client( $owner )->runs()->retry_failed( $name, $run_id );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	if ( $result->is_failure() ) {
		return new \WP_Error( $result->error->code->value, $result->error->message, $result->error->context );
	}

	return $result->value;
}

/**
 * Cancels one retained run that is not executing or pending chunked job cleanup.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner  Client plugin owner.
 * @param   string $name   Owner-local job or chunked job name.
 * @param   string $run_id Retained run identifier.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  string|\WP_Error
 */
#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
function a8csp_bgje_run_cancel( string $owner, string $name, string $run_id ): string|\WP_Error {
	try {
		$result = Component::client( $owner )->runs()->cancel( $name, $run_id );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	if ( $result->is_failure() ) {
		return new \WP_Error( $result->error->code->value, $result->error->message, $result->error->context );
	}

	return $result->value;
}

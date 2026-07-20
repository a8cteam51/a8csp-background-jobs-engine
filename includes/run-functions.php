<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailure;

use function A8C\SpecialProjects\BackgroundJobsEngine\Bridge\failure_to_array;

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
		$result = \a8csp_bgje( $owner )->runs()->last_completed_run_id( $name );
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
		$result = \a8csp_bgje( $owner )->runs()->retry_failed( $name, $run_id );
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
		$result = \a8csp_bgje( $owner )->runs()->cancel( $name, $run_id );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	if ( $result->is_failure() ) {
		return new \WP_Error( $result->error->code->value, $result->error->message, $result->error->context );
	}

	return $result->value;
}

/**
 * Registers a listener for one owner-qualified completed lifecycle hook. The listener fires
 * at-least-once — terminal delivery replays after a crash — so keep it idempotent, keyed on the run id.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @phpstan-param callable(string, array<array-key, mixed>, string|null): void $listener
 *
 * @param   string   $owner    Client plugin owner.
 * @param   string   $name     Owner-local job or chunked job name.
 * @param   callable $listener Completion listener.
 *
 * @return  void
 */
function a8csp_bgje_run_on_completed( string $owner, string $name, callable $listener ): void {
	\add_action( 'a8csp_jobs_engine/completed/' . $owner . ':' . $name, $listener, 10, 3 );
}

/**
 * Registers a listener for one owner-qualified failed lifecycle hook. The listener fires
 * at-least-once — terminal delivery replays after a crash — so keep it idempotent, keyed on the run id.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @phpstan-param callable(string, array<array-key, mixed>, array{run_id: string, attempts: int, stage: string, code: string, summary: string, failed_chunk: array<array-key, mixed>|null}): void $listener
 *
 * @param   string   $owner    Client plugin owner.
 * @param   string   $name     Owner-local job or chunked job name.
 * @param   callable $listener Failure listener.
 *
 * @return  void
 */
function a8csp_bgje_run_on_failed( string $owner, string $name, callable $listener ): void {
	\add_action(
		'a8csp_jobs_engine/failed/' . $owner . ':' . $name,
		static function ( string $run_id, array $args, mixed $failure ) use ( $listener ): void {
			if ( $failure instanceof RunFailure ) {
				$listener( $run_id, $args, failure_to_array( $failure ) );
			}
		},
		10,
		3
	);
}

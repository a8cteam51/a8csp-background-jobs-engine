<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;

\defined( 'ABSPATH' ) || exit;

/**
 * Returns the most recently retained completed run ID for one work name.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner Client plugin owner.
 * @param   string $name  Owner-local task or batch name.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  string|null|\WP_Error
 */
#[\NoDiscard( 'a last-completed-run result must be handled, not dropped' )]
function a8csp_bgte_run_last_completed( string $owner, string $name ): string|null|\WP_Error {
	try {
		$result = \a8csp_bgte( $owner )->runs()->last_completed_run_id( $name );
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
 * @param   string $name   Owner-local task or batch name.
 * @param   string $run_id Retained failed-run identifier.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  string|\WP_Error
 */
#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
function a8csp_bgte_run_retry_failed( string $owner, string $name, string $run_id ): string|\WP_Error {
	try {
		$result = \a8csp_bgte( $owner )->runs()->retry_failed( $name, $run_id );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	if ( $result->is_failure() ) {
		return new \WP_Error( $result->error->code->value, $result->error->message, $result->error->context );
	}

	return $result->value;
}

/**
 * Cancels one retained run that is not executing or pending batch cleanup.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner  Client plugin owner.
 * @param   string $name   Owner-local task or batch name.
 * @param   string $run_id Retained run identifier.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  string|\WP_Error
 */
#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
function a8csp_bgte_run_cancel( string $owner, string $name, string $run_id ): string|\WP_Error {
	try {
		$result = \a8csp_bgte( $owner )->runs()->cancel( $name, $run_id );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	if ( $result->is_failure() ) {
		return new \WP_Error( $result->error->code->value, $result->error->message, $result->error->context );
	}

	return $result->value;
}

/**
 * Registers a listener for one owner-qualified completed lifecycle hook.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @phpstan-param callable(string, array<array-key, mixed>): void $listener
 *
 * @param   string   $owner    Client plugin owner.
 * @param   string   $name     Owner-local task or batch name.
 * @param   callable $listener Completion listener.
 *
 * @return  void
 */
function a8csp_bgte_run_on_completed( string $owner, string $name, callable $listener ): void {
	\add_action( 'a8csp_background_tasks/completed/' . $owner . ':' . $name, $listener, 10, 2 );
}

/**
 * Registers a listener for one owner-qualified failed lifecycle hook.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @phpstan-param callable(string, array<array-key, mixed>, RunFailure): void $listener
 *
 * @param   string   $owner    Client plugin owner.
 * @param   string   $name     Owner-local task or batch name.
 * @param   callable $listener Failure listener.
 *
 * @return  void
 */
function a8csp_bgte_run_on_failed( string $owner, string $name, callable $listener ): void {
	\add_action( 'a8csp_background_tasks/failed/' . $owner . ':' . $name, $listener, 10, 3 );
}

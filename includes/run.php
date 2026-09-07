<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;

\defined( 'ABSPATH' ) || exit;

/**
 * Returns the retained status of one run.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $scope  Client plugin scope.
 * @param   string $name   Scope-local job or chunked job name.
 * @param   string $run_id Run identifier.
 *
 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'a run-inspection result must be handled, not dropped' )]
function a8csp_bgje_inspect_run( string $scope, string $name, string $run_id ): Run|\WP_Error {
	$id = RunId::tryFrom( $run_id );
	if ( null === $id ) {
		return new \WP_Error( ErrorCode::InvalidArgument->value, 'Run identifier is malformed; pass a run ID the engine returned.' );
	}

	return a8csp_bgje( $scope )->runs()->inspect( $name, $id );
}

/**
 * Returns the most recently retained completed run for one background-work name.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $scope Client plugin scope.
 * @param   string $name  Scope-local job or chunked job name.
 *
 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
 *
 * @return  Run|null|\WP_Error
 */
#[\NoDiscard( 'a last-completed-run result must be handled, not dropped' )]
function a8csp_bgje_last_completed_run( string $scope, string $name ): Run|null|\WP_Error {
	return a8csp_bgje( $scope )->runs()->last_completed( $name );
}

/**
 * Starts a fresh run from one retained failed run's arguments and priority.
 *
 * The retained priority is replayed directly without resolving the priority ladder.
 * A retained entry without a priority field uses engine default 10.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $scope  Client plugin scope.
 * @param   string $name   Scope-local job or chunked job name.
 * @param   string $run_id Retained failed-run identifier.
 *
 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
function a8csp_bgje_retry_failed_run( string $scope, string $name, string $run_id ): Run|\WP_Error {
	$id = RunId::tryFrom( $run_id );
	if ( null === $id ) {
		return new \WP_Error( ErrorCode::InvalidArgument->value, 'Run identifier is malformed; pass a run ID the engine returned.' );
	}

	return a8csp_bgje( $scope )->runs()->retry_failed( $name, $id );
}

/**
 * Cancels one retained run that has not passed its cancellation boundary.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $scope  Client plugin scope.
 * @param   string $name   Scope-local job or chunked job name.
 * @param   string $run_id Retained run identifier.
 *
 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
function a8csp_bgje_cancel_run( string $scope, string $name, string $run_id ): Run|\WP_Error {
	$id = RunId::tryFrom( $run_id );
	if ( null === $id ) {
		return new \WP_Error( ErrorCode::InvalidArgument->value, 'Run identifier is malformed; pass a run ID the engine returned.' );
	}

	return a8csp_bgje( $scope )->runs()->cancel( $name, $id );
}

/**
 * Stores one consumer value for the duration of one run.
 *
 * The engine drops the run's whole data row wherever it drops the run row, however that run
 * ended, so the value needs no cleanup of its own.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $scope  Client plugin scope.
 * @param   string                  $name   Scope-local job or chunked job name.
 * @param   string                  $run_id Run identifier.
 * @param   string                  $key    Run data key, 1 to 64 bytes matching `[a-z0-9_-]+`.
 * @param   array<array-key, mixed> $value  Portable value to store.
 *
 * @return  true|\WP_Error
 */
#[\NoDiscard( 'a data write failure must be handled, not dropped' )]
function a8csp_bgje_set_run_data( string $scope, string $name, string $run_id, string $key, array $value ): true|\WP_Error {
	$id = RunId::tryFrom( $run_id );
	if ( null === $id ) {
		return new \WP_Error( ErrorCode::InvalidArgument->value, 'Run identifier is malformed; pass a run ID the engine returned.' );
	}

	return a8csp_bgje( $scope )->runs()->set_data( $name, $id, $key, $value );
}

/**
 * Returns one consumer value stored for the duration of one run.
 *
 * Null separates a key the run never stored from one holding an empty array.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $scope  Client plugin scope.
 * @param   string $name   Scope-local job or chunked job name.
 * @param   string $run_id Run identifier.
 * @param   string $key    Run data key.
 *
 * @return  array<array-key, mixed>|null|\WP_Error
 */
#[\NoDiscard( 'a data read result must be handled, not dropped' )]
function a8csp_bgje_get_run_data( string $scope, string $name, string $run_id, string $key ): array|null|\WP_Error {
	$id = RunId::tryFrom( $run_id );
	if ( null === $id ) {
		return new \WP_Error( ErrorCode::InvalidArgument->value, 'Run identifier is malformed; pass a run ID the engine returned.' );
	}

	return a8csp_bgje( $scope )->runs()->get_data( $name, $id, $key );
}

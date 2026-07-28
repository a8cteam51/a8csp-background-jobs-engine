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

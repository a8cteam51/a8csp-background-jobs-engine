<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;

\defined( 'ABSPATH' ) || exit;

/**
 * Returns the retained status of one run.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner  Client plugin owner.
 * @param   string $name   Owner-local job or chunked job name.
 * @param   string $run_id Run identifier.
 *
 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'a run-inspection result must be handled, not dropped' )]
function a8csp_bgje_inspect_run( string $owner, string $name, string $run_id ): Run|\WP_Error {
	$id = RunId::tryFrom( $run_id );
	if ( null === $id ) {
		return new \WP_Error( ErrorCode::InvalidArgument->value, 'Run identifier is malformed; pass a run ID the engine returned.' );
	}

	return a8csp_bgje( $owner )->runs()->inspect( $name, $id );
}

/**
 * Returns the most recently retained completed run for one background-work name.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner Client plugin owner.
 * @param   string $name  Owner-local job or chunked job name.
 *
 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
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
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner  Client plugin owner.
 * @param   string $name   Owner-local job or chunked job name.
 * @param   string $run_id Retained failed-run identifier.
 *
 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
function a8csp_bgje_retry_failed_run( string $owner, string $name, string $run_id ): Run|\WP_Error {
	$id = RunId::tryFrom( $run_id );
	if ( null === $id ) {
		return new \WP_Error( ErrorCode::InvalidArgument->value, 'Run identifier is malformed; pass a run ID the engine returned.' );
	}

	return a8csp_bgje( $owner )->runs()->retry_failed( $name, $id );
}

/**
 * Cancels one retained run that has not passed its cancellation boundary.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner  Client plugin owner.
 * @param   string $name   Owner-local job or chunked job name.
 * @param   string $run_id Retained run identifier.
 *
 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
function a8csp_bgje_cancel_run( string $owner, string $name, string $run_id ): Run|\WP_Error {
	$id = RunId::tryFrom( $run_id );
	if ( null === $id ) {
		return new \WP_Error( ErrorCode::InvalidArgument->value, 'Run identifier is malformed; pass a run ID the engine returned.' );
	}

	return a8csp_bgje( $owner )->runs()->cancel( $name, $id );
}

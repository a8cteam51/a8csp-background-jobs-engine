<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;

\defined( 'ABSPATH' ) || exit;

/**
 * Synchronizes an owner's complete declared schedule set.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string   $owner        Client plugin owner.
 * @param   Schedule ...$schedules Complete declared schedule set.
 *
 * @return  true|\WP_Error
 */
#[\NoDiscard( 'a schedule-sync failure must be handled, not dropped' )]
function a8csp_bgje_sync_schedules( string $owner, Schedule ...$schedules ): true|\WP_Error {
	return a8csp_bgje( $owner )->schedules()->sync( ...$schedules );
}

/**
 * Immediately dispatches one declared schedule target.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner Client plugin owner.
 * @param   string $name  Owner-local schedule name.
 *
 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'a schedule dispatch-now failure must be handled, not dropped' )]
function a8csp_bgje_dispatch_schedule( string $owner, string $name ): Run|\WP_Error {
	return a8csp_bgje( $owner )->schedules()->dispatch( $name );
}

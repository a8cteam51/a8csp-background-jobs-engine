<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Errors\SchedulingError;

\defined( 'ABSPATH' ) || exit;

/**
 * Synchronizes one owner's complete declared schedule set.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string          $owner     Stable consumer identifier.
 * @param   array<Schedule> $schedules Complete schedule declaration for the owner.
 *
 * @throws  \InvalidArgumentException When the owner, an entry, a registration key, or declaration uniqueness is invalid.
 *
 * @return  AbstractResult<true, EngineError|SchedulingError>
 */
#[\NoDiscard( 'a schedule-sync failure must be handled, not dropped' )]
function a8csp_bgte_sync_schedules( string $owner, array $schedules ): AbstractResult {
	return a8csp_bgte_engine()?->schedules()->sync( $owner, $schedules )
		?? a8csp_bgte_engine_unavailable_failure();
}

/**
 * Immediately dispatches one declared schedule target without changing its recurrence.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $owner Stable consumer identifier.
 * @param   string $name  Stable schedule name.
 *
 * @return  AbstractResult<string, EngineError|SchedulingError>
 */
#[\NoDiscard( 'a schedule run-now failure must be handled, not dropped' )]
function a8csp_bgte_run_schedule_now( string $owner, string $name ): AbstractResult {
	return a8csp_bgte_engine()?->schedules()->run_now( $owner, $name )
		?? a8csp_bgte_engine_unavailable_failure();
}

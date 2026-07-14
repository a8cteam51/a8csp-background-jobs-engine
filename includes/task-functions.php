<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Errors\SchedulingError;

\defined( 'ABSPATH' ) || exit;

/**
 * Creates and schedules one run for a registered task.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $name     Stable task name.
 * @param   array<array-key, mixed> $args     Task arguments.
 * @param   int                     $delay    Scheduling delay in seconds.
 * @param   bool                    $unique   Whether the backend retains an identical async action.
 * @param   int                     $priority Advisory priority from 0 through 255.
 *
 * @return  AbstractResult<string, EngineError|SchedulingError>
 */
#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
function a8csp_bgte_enqueue_task(
	string $name,
	array $args = array(),
	int $delay = 0,
	bool $unique = false,
	int $priority = 10
): AbstractResult {
	return a8csp_bgte_engine()?->tasks()->enqueue( $name, $args, $delay, $unique, $priority )
		?? a8csp_bgte_engine_unavailable_failure();
}

/**
 * Starts a fresh run from one retained failed run's original arguments.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $name   Stable task or batch name.
 * @param   string $run_id Retained failed-run identifier.
 *
 * @return  AbstractResult<string, EngineError|SchedulingError>
 */
#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
function a8csp_bgte_retry_failed_run( string $name, string $run_id ): AbstractResult {
	return a8csp_bgte_engine()?->retry_failed( $name, $run_id )
		?? a8csp_bgte_engine_unavailable_failure();
}

/**
 * Cancels one retained run that is not executing or pending batch cleanup.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $name   Stable task or batch name.
 * @param   string $run_id Retained run identifier.
 *
 * @return  AbstractResult<string, EngineError|SchedulingError>
 */
#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
function a8csp_bgte_cancel_run( string $name, string $run_id ): AbstractResult {
	return a8csp_bgte_engine()?->cancel( $name, $run_id )
		?? a8csp_bgte_engine_unavailable_failure();
}

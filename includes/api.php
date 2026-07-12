<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundTasksEngine\Engine;
use A8C\SpecialProjects\BackgroundTasksEngine\EngineComponent;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;

\defined( 'ABSPATH' ) || exit;

/**
 * Returns the consumer engine after its component has initialized.
 *
 * Call after `plugins_loaded`; earlier access honestly returns null because the component has not
 * built its request-local graph yet.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  Engine|null
 */
function a8csp_bgte_engine(): ?Engine {
	return EngineComponent::get_engine();
}

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
		?? new Failure( new EngineError( 'The background tasks engine is unavailable; call after the engine boots on plugins_loaded.' ) );
}

/**
 * Creates and schedules one run for a registered batch.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $name       Stable batch name.
 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
 * @param   bool                    $unique     Whether the backend retains an identical start action.
 * @param   int                     $priority   Advisory priority from 0 through 255.
 *
 * @return  AbstractResult<string, EngineError|SchedulingError>
 */
#[\NoDiscard( 'a batch-start failure must be handled, not dropped' )]
function a8csp_bgte_start_batch(
	string $name,
	array $start_args = array(),
	bool $unique = false,
	int $priority = 10
): AbstractResult {
	return a8csp_bgte_engine()?->batches()->start( $name, $start_args, $unique, $priority )
		?? new Failure( new EngineError( 'The background tasks engine is unavailable; call after the engine boots on plugins_loaded.' ) );
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
	return a8csp_bgte_engine()?->tasks()->retry_failed( $name, $run_id )
		?? new Failure( new EngineError( 'The background tasks engine is unavailable; call after the engine boots on plugins_loaded.' ) );
}

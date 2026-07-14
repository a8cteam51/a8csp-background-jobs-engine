<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Errors\SchedulingError;

\defined( 'ABSPATH' ) || exit;

/**
 * Creates and schedules one run for a registered batch.
 *
 * A scheduling failure after replacement ownership transfers leaves the incumbent fenced; a
 * caller handles the returned failure by starting the batch again.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $name       Stable batch name.
 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
 * @param   bool                    $unique     Whether a fresh incumbent causes Failure instead of replacement and
 *                                              backend uniqueness is requested.
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
		?? a8csp_bgte_engine_unavailable_failure();
}

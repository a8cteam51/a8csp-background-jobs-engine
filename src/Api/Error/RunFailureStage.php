<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Error;

\defined( 'ABSPATH' ) || exit;

/**
 * Stable terminalization stage exposed by a client-visible run failure.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum RunFailureStage: string {
	// region FIELDS AND CONSTANTS

	/** Client work or a lifecycle effect failed during execution. */
	case Execution = 'execution';

	/** A batch queue could not be generated or admitted. */
	case QueueGeneration = 'queue_generation';

	/** Maintenance terminalized a run while reclaiming a crash. */
	case CrashReclaim = 'crash_reclaim';

	/** A required lifecycle action could not be prepared or scheduled. */
	case Scheduling = 'scheduling';

	// endregion
}

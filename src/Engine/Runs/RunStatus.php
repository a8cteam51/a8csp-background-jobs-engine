<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs;

\defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle state persisted for a task or batch run.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum RunStatus: string {
	// region FIELDS AND CONSTANTS

	case Running    = 'running';
	case Completed  = 'completed';
	case Failed     = 'failed';
	case Cancelled  = 'cancelled';
	case Superseded = 'superseded';

	// endregion
}

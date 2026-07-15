<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks;

\defined( 'ABSPATH' ) || exit;

/**
 * Reports the outcome of an execution-overlap lock heartbeat.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum HeartbeatOutcome: string {
	// region FIELDS AND CONSTANTS

	case Owned         = 'owned';
	case Lost          = 'lost';
	case Indeterminate = 'indeterminate';

	// endregion
}

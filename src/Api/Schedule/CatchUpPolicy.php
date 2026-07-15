<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule;

\defined( 'ABSPATH' ) || exit;

/**
 * Policy applied when a schedule occurrence is discovered after its due instant.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum CatchUpPolicy: string {
	// region FIELDS AND CONSTANTS

	case RunOnce = 'run_once';
	case Skip    = 'skip';

	// endregion
}

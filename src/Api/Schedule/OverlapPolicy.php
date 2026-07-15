<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule;

\defined( 'ABSPATH' ) || exit;

/**
 * Policy applied when a schedule occurrence overlaps matching task work.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum OverlapPolicy: string {
	// region FIELDS AND CONSTANTS

	case Allow   = 'allow';
	case Skip    = 'skip';
	case Replace = 'replace';

	// endregion
}

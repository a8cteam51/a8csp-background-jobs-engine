<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Policy applied when a schedule occurrence is discovered after its due instant.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum CatchUpPolicy: string {
	// region FIELDS AND CONSTANTS

	/** Attempts one occurrence after the schedule is discovered beyond its grace window. */
	case RunOnce = 'run_once';

	/** Drops a beyond-grace occurrence and advances to the next due instant. */
	case Skip = 'skip';

	// endregion
}

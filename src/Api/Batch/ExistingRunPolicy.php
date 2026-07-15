<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch;

\defined( 'ABSPATH' ) || exit;

/**
 * Policy applied when a batch start encounters a matching active run.
 *
 * Reject refuses admission while a fresh incumbent holds the overlap lock. Replace transfers that
 * lock to the new run, fencing the incumbent at its next ownership boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum ExistingRunPolicy: string {
	// region FIELDS AND CONSTANTS

	case Reject  = 'reject';
	case Replace = 'replace';

	// endregion
}

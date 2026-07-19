<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule;

\defined( 'ABSPATH' ) || exit;

/**
 * Policy applied when a schedule occurrence overlaps matching job work.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum OverlapPolicy: string {
	// region FIELDS AND CONSTANTS

	/** Admits the occurrence with a per-run identity even while matching work runs. */
	case Allow = 'allow';

	/** Leaves matching work running and records the occurrence as skipped. */
	case Skip = 'skip';

	/** Transfers overlap ownership to the occurrence and fences matching work. */
	case Replace = 'replace';

	// endregion
}

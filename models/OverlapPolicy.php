<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Policy applied when a job admission overlaps matching work.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum OverlapPolicy: string {
	// region FIELDS AND CONSTANTS

	/** Admits the run with a per-run identity even while matching work runs. */
	case Allow = 'allow';

	/** Refuses admission while matching work runs. */
	case Reject = 'reject';

	/** Transfers overlap ownership to the new run and fences matching work. */
	case Replace = 'replace';

	// endregion
}

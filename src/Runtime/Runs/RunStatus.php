<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

\defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle state persisted for a job or chunked job run.
 *
 * `Run\RunStatus` mirrors every case in this persisted vocabulary because the public ABI lives in
 * models, which do not import `src/` internals.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum RunStatus: string {
	// region FIELDS AND CONSTANTS

	/** The admitted run has not reached a terminal outcome. */
	case Running = 'running';

	/** The run finished all work successfully. */
	case Completed = 'completed';

	/** The run terminalized after an execution or delivery failure. */
	case Failed = 'failed';

	/** Cancellation ended the run before normal completion. */
	case Cancelled = 'cancelled';

	/** A replacement run took ownership and fenced this run. */
	case Superseded = 'superseded';

	// endregion
}

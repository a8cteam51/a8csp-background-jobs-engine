<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Public, stable vocabulary for a run's lifecycle state.
 *
 * The backing values are persisted in run rows and run history, so an existing value can never change.
 *
 * Minor releases may add cases; consumers treat an unknown value as a generic
 * non-terminal or terminal state, as appropriate.
 *
 * @api
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

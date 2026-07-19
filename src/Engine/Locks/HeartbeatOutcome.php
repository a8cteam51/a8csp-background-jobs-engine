<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks;

\defined( 'ABSPATH' ) || exit;

/**
 * Reports the outcome of an execution-overlap lock heartbeat.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum HeartbeatOutcome: string {
	// region FIELDS AND CONSTANTS

	case Owned = 'owned';
	case Lost  = 'lost';

	/**
	 * The lock generation differs from the delivery generation observed by the caller.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	case GenerationMismatch = 'generation_mismatch';

	case Indeterminate = 'indeterminate';

	// endregion
}

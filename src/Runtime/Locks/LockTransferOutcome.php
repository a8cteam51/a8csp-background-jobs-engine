<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks;

\defined( 'ABSPATH' ) || exit;

/**
 * Reports the outcome of an execution-overlap lock transfer.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum LockTransferOutcome: string {
	// region FIELDS AND CONSTANTS

	case Transferred = 'transferred';

	/**
	 * The authoritative row is absent or no longer satisfies the guarded replacement predicate.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	case Lost = 'lost';

	/**
	 * Authoritative storage could not complete the transfer write.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	case Indeterminate = 'indeterminate';

	// endregion
}

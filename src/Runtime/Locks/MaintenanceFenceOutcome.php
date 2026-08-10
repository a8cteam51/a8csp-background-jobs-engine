<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks;

\defined( 'ABSPATH' ) || exit;

/**
 * Ownership classification for one maintenance run fence.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum MaintenanceFenceOutcome: string {
	// region FIELDS AND CONSTANTS

	case Owned       = 'owned';
	case Abandoned   = 'abandoned';
	case Transferred = 'transferred';

	/**
	 * The persisted lock row exists but does not satisfy the lock schema.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	case Malformed = 'malformed';

	/**
	 * Authoritative storage could not classify or fence the lock row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	case Indeterminate = 'indeterminate';

	// endregion
}

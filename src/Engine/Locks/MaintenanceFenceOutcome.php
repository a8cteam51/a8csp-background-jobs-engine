<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks;

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
	case Owned         = 'owned';
	case Abandoned     = 'abandoned';
	case Transferred   = 'transferred';
	case Indeterminate = 'indeterminate';
}

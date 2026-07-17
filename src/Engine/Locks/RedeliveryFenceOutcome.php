<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks;

\defined( 'ABSPATH' ) || exit;

/**
 * Readiness classification for one pending-action redelivery fence.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum RedeliveryFenceOutcome: string {
	case Ready         = 'ready';
	case Live          = 'live';
	case Transferred   = 'transferred';
	case Indeterminate = 'indeterminate';
}

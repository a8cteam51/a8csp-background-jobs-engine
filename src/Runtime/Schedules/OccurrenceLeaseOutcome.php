<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules;

\defined( 'ABSPATH' ) || exit;

/**
 * Reports why an occurrence-decision lease claim yielded no handle.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum OccurrenceLeaseOutcome: string {
	// region FIELDS AND CONSTANTS

	case NotClaimed         = 'not_claimed';
	case IndeterminateRead  = 'indeterminate_read';
	case IndeterminateWrite = 'indeterminate_write';

	// endregion
}

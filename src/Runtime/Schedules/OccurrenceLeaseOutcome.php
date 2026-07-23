<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules;

\defined( 'ABSPATH' ) || exit;

/**
 * Reports the outcome of an occurrence-decision lease claim.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum OccurrenceLeaseOutcome: string {
	// region FIELDS AND CONSTANTS

	case Claimed       = 'claimed';
	case Held          = 'held';
	case Indeterminate = 'indeterminate';

	// endregion
}

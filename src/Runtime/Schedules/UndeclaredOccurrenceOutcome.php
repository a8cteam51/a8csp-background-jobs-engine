<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules;

\defined( 'ABSPATH' ) || exit;

/**
 * Outcome of classifying one undeclared recurring occurrence.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum UndeclaredOccurrenceOutcome: string {
	// region FIELDS AND CONSTANTS

	case Recorded         = 'recorded';
	case Escalated        = 'escalated';
	case AlreadyEscalated = 'already_escalated';
	case Pruned           = 'pruned';
	case Failed           = 'failed';

	// endregion
}

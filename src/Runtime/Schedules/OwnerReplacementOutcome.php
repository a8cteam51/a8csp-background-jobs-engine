<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules;

\defined( 'ABSPATH' ) || exit;

/**
 * Outcome of replacing one owner's complete schedule-registration row.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum OwnerReplacementOutcome: string {
	// region FIELDS AND CONSTANTS

	case Persisted  = 'persisted';
	case ReadFailed = 'read_failed';
	case Corrupt    = 'corrupt';
	case CasFailed  = 'cas_failed';

	// endregion
}

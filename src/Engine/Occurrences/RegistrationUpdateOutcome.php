<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences;

\defined( 'ABSPATH' ) || exit;

/**
 * Outcome of one fenced schedule-registration row update.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum RegistrationUpdateOutcome: string {
	// region FIELDS AND CONSTANTS

	case Updated    = 'updated';
	case Pruned     = 'pruned';
	case Superseded = 'superseded';
	case Failed     = 'failed';

	// endregion
}

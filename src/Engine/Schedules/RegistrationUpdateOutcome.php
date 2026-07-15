<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules;

\defined( 'ABSPATH' ) || exit;

/**
 * Outcome of one fenced schedule-registration row update.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum RegistrationUpdateOutcome: string {
	case Updated    = 'updated';
	case Pruned     = 'pruned';
	case Superseded = 'superseded';
	case Failed     = 'failed';
}

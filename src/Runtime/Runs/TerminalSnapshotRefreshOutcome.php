<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

\defined( 'ABSPATH' ) || exit;

/**
 * Reports the outcome of re-reading a claimed terminal run.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum TerminalSnapshotRefreshOutcome: string {
	// region FIELDS AND CONSTANTS

	case Refreshed       = 'refreshed';
	case Untrusted       = 'untrusted';
	case AlreadyFinished = 'already_finished';

	// endregion
}

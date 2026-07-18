<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage;

\defined( 'ABSPATH' ) || exit;

/**
 * Reports the outcome of an exact option-row write.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum RowWriteOutcome: string {
	// region FIELDS AND CONSTANTS

	case Won = 'won';

	/** The authoritative row state does not satisfy the guarded write predicate. */
	case Lost = 'lost';

	case WriteFailed = 'write_failed';

	// endregion
}

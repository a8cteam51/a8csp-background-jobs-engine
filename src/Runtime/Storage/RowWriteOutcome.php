<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage;

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

	/** The guarded write landed and this caller owns the resulting row state. */
	case Won = 'won';

	/** The authoritative row state does not satisfy the guarded write predicate. */
	case Lost = 'lost';

	/** Storage did not answer, so whether the row changed is unknown. */
	case WriteFailed = 'write_failed';

	// endregion
}

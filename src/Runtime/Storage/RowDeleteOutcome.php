<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage;

\defined( 'ABSPATH' ) || exit;

/**
 * Reports the outcome of an exact option-row delete.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum RowDeleteOutcome: string {
	// region FIELDS AND CONSTANTS

	case Deleted = 'deleted';

	/** The authoritative row no longer carries the selected value; an absent row also classifies here. */
	case ValueMismatch = 'value_mismatch';

	case DeleteFailed = 'delete_failed';

	// endregion
}

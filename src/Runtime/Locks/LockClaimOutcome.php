<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks;

\defined( 'ABSPATH' ) || exit;

/**
 * Reports the outcome of an execution-overlap lock claim.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum LockClaimOutcome: string {
	// region FIELDS AND CONSTANTS

	case Claimed   = 'claimed';
	case Reclaimed = 'reclaimed';
	case Held      = 'held';

	// endregion
}

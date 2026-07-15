<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Errors\SchedulingError;

\defined( 'ABSPATH' ) || exit;

/**
 * Couples one scheduler clear result to the backend coverage of its readiness snapshot.
 *
 * @internal Unknown-schedule convergence only.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ClearanceResult {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   AbstractResult<true, SchedulingError> $result        Backend clear result.
	 * @param   bool                                  $authoritative Whether the cleared snapshot covers every present backend.
	 */
	public function __construct(
		public AbstractResult $result,
		public bool $authoritative,
	) {}

	// endregion
}

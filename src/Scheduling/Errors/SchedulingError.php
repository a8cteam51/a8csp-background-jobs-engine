<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors;

use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\SchedulingErrorReason;

\defined( 'ABSPATH' ) || exit;

/**
 * Expected failure payload for a scheduling request.
 *
 * The reason is the machine-readable branch key; the message names the corrective action, and the
 * context carries structured detail for diagnostics without making callers parse prose.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class SchedulingError {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   SchedulingErrorReason $reason  Machine-readable cause of the failure.
	 * @param   string                $message Human-readable corrective action.
	 * @param   array<string, mixed>  $context Structured diagnostic detail.
	 */
	public function __construct(
		public SchedulingErrorReason $reason,
		public string $message,
		public array $context = array(),
	) {}

	// endregion
}

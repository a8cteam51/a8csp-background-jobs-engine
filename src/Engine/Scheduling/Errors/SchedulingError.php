<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Errors;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Error\ErrorInterface;

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
final readonly class SchedulingError implements ErrorInterface {
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
	public function __construct( public SchedulingErrorReason $reason, public string $message, public array $context = array() ) {}

	// endregion

	// region METHODS

	/**
	 * Returns the failed registry-postcondition detail for one owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Stable consumer identifier.
	 *
	 * @return  self
	 */
	public static function registry_read( string $owner ): self {
		return new self(
			SchedulingErrorReason::ScheduleFailed,
			\sprintf(
				'Schedule registry for owner "%s" could not be persisted; repair WordPress option writes and retry synchronization.',
				$owner
			),
			array( 'owner' => $owner ),
		);
	}

	// endregion
}

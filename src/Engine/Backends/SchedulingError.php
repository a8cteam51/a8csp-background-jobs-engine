<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ErrorInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Expected failure payload for a scheduling request.
 *
 * The reason is the machine-readable branch key; the message names the corrective action, and the
 * context carries structured detail for diagnostics without making callers parse prose.
 *
 * @internal
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
	public function __construct(
		public SchedulingErrorReason $reason,
		public string $message,
		public array $context = array(),
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns a registry-read failure for one owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Stable consumer identifier.
	 *
	 * @return  self
	 */
	public static function registry_read_failure( string $owner ): self {
		return new self(
			SchedulingErrorReason::StorageFailure,
			\sprintf(
				'Schedule registry state for owner "%s" could not be read; repair WordPress option reads and retry.',
				$owner
			),
			array( 'owner' => $owner ),
		);
	}

	/**
	 * Returns a registry-persist failure for one owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Stable consumer identifier.
	 *
	 * @return  self
	 */
	public static function registry_persist_failure( string $owner ): self {
		return new self(
			SchedulingErrorReason::StorageFailure,
			\sprintf(
				'Schedule registry state for owner "%s" could not be persisted; repair WordPress option writes and retry synchronization.',
				$owner
			),
			array( 'owner' => $owner ),
		);
	}

	// endregion
}

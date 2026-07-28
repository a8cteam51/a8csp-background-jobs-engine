<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\ErrorInterface;

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
	 * Returns a registry-read failure for one scope.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scope Stable client identifier.
	 *
	 * @return  self
	 */
	public static function registry_read_failure( string $scope ): self {
		return new self( SchedulingErrorReason::StorageFailure, \sprintf( 'Schedule registry state for scope "%s" could not be read; repair WordPress option reads and retry.', $scope ), array( 'scope' => $scope ), );
	}

	/**
	 * Returns a registry-persist failure for one scope.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scope Stable client identifier.
	 *
	 * @return  self
	 */
	public static function registry_persist_failure( string $scope ): self {
		return new self( SchedulingErrorReason::StorageFailure, \sprintf( 'Schedule registry state for scope "%s" could not be persisted; repair WordPress option writes and retry synchronization.', $scope ), array( 'scope' => $scope ), );
	}

	/**
	 * Returns a corrupt registry-row failure for one scope.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scope       Stable client identifier.
	 * @param   string $option_name Exact unreadable option row.
	 *
	 * @return  self
	 */
	public static function registry_corrupt( string $scope, string $option_name ): self {
		return new self(
			SchedulingErrorReason::StorageFailure,
			\sprintf( 'Schedule registry option row "%s" is unreadable; maintenance reclaims it, then re-declare schedules on the next init.', $option_name ),
			array(
				'scope'       => $scope,
				'option_name' => $option_name,
			)
		);
	}

	// endregion
}

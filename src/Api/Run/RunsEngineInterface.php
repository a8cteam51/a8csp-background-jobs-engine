<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Run;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;

\defined( 'ABSPATH' ) || exit;

/**
 * Engine operations required by the owner-bound run facade.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface RunsEngineInterface {
	// region METHODS

	/**
	 * Returns the most recently recorded completed run ID retained for one identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified task or batch identity.
	 *
	 * @return  AbstractResult<string|null, ApiError>
	 */
	public function last_completed_run_id( string $identity ): AbstractResult;

	/**
	 * Starts a fresh run from one retained failed run's original arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified task or batch identity.
	 * @param   string $run_id   Retained failed-run identifier.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	public function retry_failed( string $identity, string $run_id ): AbstractResult;

	/**
	 * Cancels one retained run that is not executing or pending batch cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified task or batch identity.
	 * @param   string $run_id   Retained run identifier.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	public function cancel( string $identity, string $run_id ): AbstractResult;

	// endregion
}

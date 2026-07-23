<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\DuplicateRegistrationException;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\OwnerOperations;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound public service for supported job operations.
 *
 * Owner validation and engine resolution remain lazy until a verb is invoked. Every expected
 * validation, registration, readiness, or engine failure crosses this boundary as a `WP_Error`.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Jobs {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Client plugin owner.
	 */
	public function __construct(
		private string $owner,
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers one job definition under the bound owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   JobDefinition $definition Job definition to register.
	 *
	 * @return  true|\WP_Error
	 */
	#[\NoDiscard( 'a job-registration failure must be handled, not dropped' )]
	public function register( JobDefinition $definition ): true|\WP_Error {
		try {
			$this->operations()->register( $definition );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( DuplicateRegistrationException $exception ) {
			return new \WP_Error( ErrorCode::AlreadyRegistered->value, $exception->getMessage() );
		} catch ( \LogicException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}

		return true;
	}

	/**
	 * Creates and schedules one run for registered background work.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name          Owner-local background-work name.
	 * @param   array<array-key, mixed> $start_args    Arguments supplied when the run starts.
	 * @param   int                     $delay_seconds Scheduling delay in seconds.
	 * @param   int|null                $priority      Advisory priority from 0 through 255, or null for the engine default.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a job-dispatch failure must be handled, not dropped' )]
	public function dispatch( string $name, array $start_args = array(), int $delay_seconds = 0, ?int $priority = null ): Run|\WP_Error {
		try {
			$result = $this->operations()->dispatch( $name, $start_args, $delay_seconds, $priority );
			if ( $result->is_failure() ) {
				return self::wp_error( $result->error );
			}

			return $this->run( $name, $result->value, RunStatus::Running );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( \LogicException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Resolves the owner operations adapter for the bound owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \InvalidArgumentException When the owner violates the owner contract.
	 * @throws  \LogicException           When the internal graph is unavailable.
	 *
	 * @return  OwnerOperations
	 */
	private function operations(): OwnerOperations {
		return Component::operations( $this->owner );
	}

	/**
	 * Projects one internal run identifier into the public value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string    $name   Owner-local job or chunked job name.
	 * @param   string    $run_id Run identifier.
	 * @param   RunStatus $status Public lifecycle state.
	 *
	 * @throws  \InvalidArgumentException When the owner or name violates the identity contract.
	 * @throws  \ValueError               When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  Run
	 */
	private function run( string $name, string $run_id, RunStatus $status ): Run {
		return new Run( JobIdentity::compose( $this->owner, $name ), RunId::from( $run_id ), $status );
	}

	/**
	 * Converts one boundary failure to the WordPress error boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   BoundaryError $error Boundary failure.
	 *
	 * @return  \WP_Error
	 */
	private static function wp_error( BoundaryError $error ): \WP_Error {
		return new \WP_Error( $error->code->value, $error->message, $error->context );
	}

	// endregion
}

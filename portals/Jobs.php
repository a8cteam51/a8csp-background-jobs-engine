<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\DuplicateRegistrationException;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\EngineUnavailableException;

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
final readonly class Jobs extends AbstractPortal {
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
		} catch ( EngineUnavailableException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}

		return true;
	}

	/**
	 * Creates and asynchronously schedules one run for registered background work.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name       Owner-local background-work name.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   int|null                $priority   Advisory priority from 0 through 255, or null for the engine default.
	 *
	 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a job-dispatch failure must be handled, not dropped' )]
	public function dispatch( string $name, array $start_args = array(), ?int $priority = null ): Run|\WP_Error {
		try {
			$result = $this->operations()->dispatch( $name, $start_args, priority: $priority );

			return $result->is_failure() ? self::wp_error( $result->error ) : $result->value;
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( EngineUnavailableException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	/**
	 * Creates and schedules one run for registered background work at an absolute Unix timestamp.
	 *
	 * A timestamp at or before admission time uses the asynchronous scheduling lane.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name       Owner-local background-work name.
	 * @param   int                     $run_at     Absolute Unix timestamp for the first delivery.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   int|null                $priority   Advisory priority from 0 through 255, or null for the engine default.
	 *
	 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a timed job-dispatch failure must be handled, not dropped' )]
	public function dispatch_at( string $name, int $run_at, array $start_args = array(), ?int $priority = null ): Run|\WP_Error {
		try {
			$result = $this->operations()->dispatch( $name, $start_args, $run_at, $priority );

			return $result->is_failure() ? self::wp_error( $result->error ) : $result->value;
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( EngineUnavailableException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	// endregion
}

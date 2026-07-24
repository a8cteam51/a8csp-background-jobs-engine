<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\EngineUnavailableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\OwnerOperations;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound public service for supported run operations.
 *
 * Owner validation and engine resolution remain lazy until a verb is invoked. Every expected
 * validation, readiness, or engine failure crosses this boundary as a `WP_Error`.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Runs {
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
	 * Returns the retained status of one run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Owner-local job or chunked job name.
	 * @param   RunId  $run_id Run identifier.
	 *
	 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a run-inspection result must be handled, not dropped' )]
	public function inspect( string $name, RunId $run_id ): Run|\WP_Error {
		try {
			$result = $this->operations()->inspect( $name, (string) $run_id );
			if ( $result->is_failure() ) {
				return self::wp_error( $result->error );
			}
			if ( null === $result->value ) {
				return new \WP_Error( ErrorCode::RunNotRetained->value, 'The requested run is not retained.' );
			}

			return $result->value;
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( EngineUnavailableException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	/**
	 * Returns the most recently retained completed run for one background-work name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Owner-local job or chunked job name.
	 *
	 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  Run|null|\WP_Error
	 */
	#[\NoDiscard( 'a last-completed-run result must be handled, not dropped' )]
	public function last_completed( string $name ): Run|null|\WP_Error {
		try {
			$result = $this->operations()->last_completed_run( $name );

			return $result->is_failure() ? self::wp_error( $result->error ) : $result->value;
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( EngineUnavailableException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	/**
	 * Starts a fresh run from one retained failed run's original arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Owner-local job or chunked job name.
	 * @param   RunId  $run_id Retained failed-run identifier.
	 *
	 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed( string $name, RunId $run_id ): Run|\WP_Error {
		try {
			$result = $this->operations()->retry_failed( $name, (string) $run_id );

			return $result->is_failure() ? self::wp_error( $result->error ) : $result->value;
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( EngineUnavailableException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	/**
	 * Cancels one retained run that has not passed its cancellation boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Owner-local job or chunked job name.
	 * @param   RunId  $run_id Retained run identifier.
	 *
	 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	public function cancel( string $name, RunId $run_id ): Run|\WP_Error {
		try {
			$result = $this->operations()->cancel( $name, (string) $run_id );

			return $result->is_failure() ? self::wp_error( $result->error ) : $result->value;
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( EngineUnavailableException $exception ) {
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
	 * @throws  \InvalidArgumentException  When the owner violates the owner contract.
	 * @throws  EngineUnavailableException When the internal graph is unavailable.
	 *
	 * @return  OwnerOperations
	 */
	private function operations(): OwnerOperations {
		return Component::operations( $this->owner );
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

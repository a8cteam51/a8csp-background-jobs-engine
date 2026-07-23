<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Client;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound public service for supported run operations.
 *
 * Owner validation and engine resolution remain lazy until a verb is invoked. Every expected
 * validation, readiness, or engine failure crosses this boundary as a `WP_Error`.
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
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a run-inspection result must be handled, not dropped' )]
	public function inspect( string $name, RunId $run_id ): Run|\WP_Error {
		try {
			$result = $this->client()->runs()->inspect( $name, (string) $run_id );
			if ( $result->is_failure() ) {
				return self::wp_error( $result->error );
			}
			if ( null === $result->value ) {
				return new \WP_Error( ErrorCode::RunNotRetained->value, 'The requested run is not retained.' );
			}

			// The public projection covers every internal run status, so from() always resolves here.
			return $this->run( $name, (string) $run_id, RunStatus::from( $result->value->value ) );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( \LogicException $exception ) {
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
	 * @return  Run|null|\WP_Error
	 */
	#[\NoDiscard( 'a last-completed-run result must be handled, not dropped' )]
	public function last_completed( string $name ): Run|null|\WP_Error {
		try {
			$result = $this->client()->runs()->last_completed_run_id( $name );
			if ( $result->is_failure() ) {
				return self::wp_error( $result->error );
			}
			if ( null === $result->value ) {
				return null;
			}

			return $this->run( $name, $result->value, RunStatus::Completed );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( \LogicException $exception ) {
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
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed( string $name, RunId $run_id ): Run|\WP_Error {
		try {
			$result = $this->client()->runs()->retry_failed( $name, (string) $run_id );
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

	/**
	 * Cancels one retained run that has not passed its cancellation boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Owner-local job or chunked job name.
	 * @param   RunId  $run_id Retained run identifier.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	public function cancel( string $name, RunId $run_id ): Run|\WP_Error {
		try {
			$result = $this->client()->runs()->cancel( $name, (string) $run_id );
			if ( $result->is_failure() ) {
				return self::wp_error( $result->error );
			}

			return $this->run( $name, (string) $run_id, RunStatus::Cancelled );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( \LogicException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Resolves the internal client for the bound owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \InvalidArgumentException When the owner violates the client-owner contract.
	 * @throws  \LogicException           When the internal graph is unavailable.
	 *
	 * @return  Client
	 */
	private function client(): Client {
		return Component::client( $this->owner );
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
	 *
	 * @return  Run
	 */
	private function run( string $name, string $run_id, RunStatus $status ): Run {
		return new Run( JobIdentity::compose( $this->owner, $name ), RunId::from( $run_id ), $status );
	}

	/**
	 * Converts one internal client failure to the WordPress error boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ApiError $error Internal client failure.
	 *
	 * @return  \WP_Error
	 */
	private static function wp_error( ApiError $error ): \WP_Error {
		return new \WP_Error( $error->code->value, $error->message, $error->context );
	}

	// endregion
}

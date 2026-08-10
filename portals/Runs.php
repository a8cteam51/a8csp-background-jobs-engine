<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\EngineUnavailableException;

\defined( 'ABSPATH' ) || exit;

/**
 * Scope-bound capability manager for supported run operations.
 *
 * Scope validation and engine resolution remain lazy until a verb is invoked. Every expected
 * validation, readiness, or engine failure crosses this boundary as a `WP_Error`.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Runs extends AbstractPortal {
	// region METHODS

	/**
	 * Returns the retained status of one run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Scope-local job or chunked job name.
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
			if ( null === $result ) {
				return new \WP_Error( ErrorCode::RunNotRetained->value, 'The requested run is not retained.' );
			}

			return $result;
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
	 * @param   string $name Scope-local job or chunked job name.
	 *
	 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  Run|null|\WP_Error
	 */
	#[\NoDiscard( 'a last-completed-run result must be handled, not dropped' )]
	public function last_completed( string $name ): Run|null|\WP_Error {
		try {
			return $this->operations()->last_completed_run( $name );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( EngineUnavailableException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	/**
	 * Starts a fresh run from one retained failed run's arguments and priority.
	 *
	 * The retained priority is replayed directly without resolving the priority ladder.
	 * A retained entry without a priority field uses engine default 10.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Scope-local job or chunked job name.
	 * @param   RunId  $run_id Retained failed-run identifier.
	 *
	 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed( string $name, RunId $run_id ): Run|\WP_Error {
		try {
			return $this->operations()->retry_failed( $name, (string) $run_id );
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
	 * @param   string $name   Scope-local job or chunked job name.
	 * @param   RunId  $run_id Retained run identifier.
	 *
	 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	public function cancel( string $name, RunId $run_id ): Run|\WP_Error {
		try {
			return $this->operations()->cancel( $name, (string) $run_id );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( EngineUnavailableException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	// endregion
}

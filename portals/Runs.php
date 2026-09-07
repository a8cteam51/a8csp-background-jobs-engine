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

	/**
	 * Stores one consumer value for the duration of one run.
	 *
	 * Run data belongs to the run rather than to the job. The engine drops the whole row wherever it
	 * drops the run row — completed, failed, cancelled, superseded, and the corrupt rows maintenance
	 * removes without firing a hook — so a consumer cannot forget to clean up after a terminal path
	 * it did not think about.
	 *
	 * Values must be a portable, JSON-encodable tree of scalars, null, and arrays within the same
	 * depth bound as start arguments, and the run's complete data row has its own byte ceiling — the
	 * README's consumer-limits table carries both. Writing an existing key replaces it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name   Scope-local job or chunked job name.
	 * @param   RunId                   $run_id Run identifier.
	 * @param   string                  $key    Run data key, 1 to 64 bytes matching `[a-z0-9_-]+`.
	 * @param   array<array-key, mixed> $value  Portable value to store.
	 *
	 * @return  true|\WP_Error
	 */
	#[\NoDiscard( 'a data write failure must be handled, not dropped' )]
	public function set_data( string $name, RunId $run_id, string $key, array $value ): true|\WP_Error {
		try {
			return $this->operations()->set_run_data( $name, (string) $run_id, $key, $value );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( EngineUnavailableException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	/**
	 * Returns one consumer value stored for the duration of one run.
	 *
	 * Null separates a key the run never stored from one holding an empty array, and a run whose
	 * data the engine has already dropped reads as null.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Scope-local job or chunked job name.
	 * @param   RunId  $run_id Run identifier.
	 * @param   string $key    Run data key.
	 *
	 * @return  array<array-key, mixed>|null|\WP_Error
	 */
	#[\NoDiscard( 'a data read result must be handled, not dropped' )]
	public function get_data( string $name, RunId $run_id, string $key ): array|null|\WP_Error {
		try {
			return $this->operations()->get_run_data( $name, (string) $run_id, $key );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( EngineUnavailableException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	// endregion
}

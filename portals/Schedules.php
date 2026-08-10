<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\EngineUnavailableException;

\defined( 'ABSPATH' ) || exit;

/**
 * Scope-bound capability manager for supported schedule operations.
 *
 * Scope validation and engine resolution remain lazy until a verb is invoked. Every expected
 * validation, readiness, or engine failure crosses this boundary as a `WP_Error`.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Schedules extends AbstractPortal {
	// region METHODS

	/**
	 * Synchronizes the bound scope's complete declared schedule set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Schedule ...$schedules Complete declared schedule set.
	 *
	 * @return  true|\WP_Error
	 */
	#[\NoDiscard( 'a schedule-sync failure must be handled, not dropped' )]
	public function sync( Schedule ...$schedules ): true|\WP_Error {
		try {
			return $this->operations()->sync( $schedules );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( EngineUnavailableException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	/**
	 * Immediately dispatches one declared schedule target.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Scope-local schedule name.
	 *
	 * @throws  \ValueError When a non-canonical persisted run identifier is rejected.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a schedule dispatch-now failure must be handled, not dropped' )]
	public function dispatch( string $name ): Run|\WP_Error {
		try {
			return $this->operations()->dispatch_now( $name );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( EngineUnavailableException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	// endregion
}

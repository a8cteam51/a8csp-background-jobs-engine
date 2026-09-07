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

	/**
	 * Returns the bound scope's persisted schedule registrations and their observable live state.
	 *
	 * Read-only, and a projection of facts rather than a verdict: an entry reports its recurrence,
	 * when it is next due, when it last fired, its misfire and overlap counts, and whether a
	 * scheduling backend can currently see its occurrence. What counts as wrong is the caller's
	 * question, because only the caller knows what it declared.
	 *
	 * Entries are ordered by composed identity, not by declaration order.
	 *
	 * `occurrence_visible` comes from one batched census across every ready scheduling backend, not a
	 * query per registration, so the call costs a bounded number of backend reads whatever the scope
	 * declares.
	 *
	 * A `recurrence` of null means the registration is persisted but the current request carries no
	 * declaration for it — `sync()` is per-request, so a request that has not yet synchronised sees
	 * null for every registration. `dormant_backend` reports that a scheduling backend is present but
	 * not usable, which is a site-wide condition rather than one of this scope's.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-return array{observed_at: int, dormant_backend: bool, schedules: list<array{name: string, identity: string, recurrence: int|null, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int, occurrence_visible: bool}>}|\WP_Error
	 *
	 * @return  array|\WP_Error
	 */
	#[\NoDiscard( 'a schedule-registration inspection result must be handled, not dropped' )]
	public function inspect(): array|\WP_Error {
		try {
			return $this->operations()->inspect_schedules();
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( EngineUnavailableException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	// endregion
}

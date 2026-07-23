<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks;

\defined( 'ABSPATH' ) || exit;

/**
 * Carries the actionable result of one persisted-lock maintenance sweep.
 *
 * @internal Engine maintenance only.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class MaintenanceLockSweep {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $run_id              Valid persisted-lock owner, or null when none is actionable.
	 * @param   bool        $malformed_reclaimed Whether an exact malformed row was deleted.
	 */
	public function __construct(
		public ?string $run_id,
		public bool $malformed_reclaimed,
	) {}

	// endregion
}

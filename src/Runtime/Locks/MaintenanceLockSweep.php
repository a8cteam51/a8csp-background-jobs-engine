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
	 * @param   bool        $malformed_preserved Whether a malformed row remains for explicit repair.
	 * @param   int|null    $raw_length          Malformed raw-value length, or null for a valid or absent row.
	 * @param   string|null $raw_sha256          Truncated malformed raw-value digest, or null for a valid or absent row.
	 */
	public function __construct(
		public ?string $run_id,
		public bool $malformed_preserved,
		public ?int $raw_length,
		public ?string $raw_sha256,
	) {}

	// endregion
}

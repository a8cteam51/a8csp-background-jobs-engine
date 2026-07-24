<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks;

\defined( 'ABSPATH' ) || exit;

/**
 * Carries one classified overlap-lock claim and its exact selected snapshot when present.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class LockClaimResult {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   LockClaimOutcome $outcome      Claim classification.
	 * @param   string|null      $owner_run_id Parsed contended owner, or null for another classification.
	 * @param   string|null      $raw          Exact contended or malformed row bytes, or null without a snapshot.
	 * @param   bool|null        $stale        Contended-row staleness, or null for another classification.
	 */
	private function __construct(
		public LockClaimOutcome $outcome,
		public ?string $owner_run_id,
		public ?string $raw,
		public ?bool $stale,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns one confirmed claimed outcome.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  self
	 */
	public static function claimed(): self {
		return new self( LockClaimOutcome::Claimed, null, null, null );
	}

	/**
	 * Returns one parseable contended snapshot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner_run_id Selected owner.
	 * @param   string $raw          Exact selected row bytes.
	 * @param   bool   $stale        Whether the selected row exceeds the resolved staleness window.
	 *
	 * @return  self
	 */
	public static function contended( string $owner_run_id, string $raw, bool $stale ): self {
		return new self( LockClaimOutcome::Contended, $owner_run_id, $raw, $stale );
	}

	/**
	 * Returns one malformed snapshot without changing its bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $raw Exact selected row bytes.
	 *
	 * @return  self
	 */
	public static function malformed( string $raw ): self {
		return new self( LockClaimOutcome::Malformed, null, $raw, null );
	}

	/**
	 * Returns one outcome without a trustworthy authoritative snapshot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  self
	 */
	public static function indeterminate(): self {
		return new self( LockClaimOutcome::Indeterminate, null, null, null );
	}

	// endregion
}

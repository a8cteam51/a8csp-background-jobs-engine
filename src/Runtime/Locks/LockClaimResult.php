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
	 * @param   string|null      $raw          Exact contended row bytes, or null without a snapshot.
	 * @param   bool|null        $stale        Contended-row staleness, or null for another classification.
	 * @param   int|null         $admitted_at  Generation the claim decided under, or null when no decision was reached.
	 */
	private function __construct(
		public LockClaimOutcome $outcome,
		public ?string $owner_run_id,
		public ?string $raw,
		public ?bool $stale,
		public ?int $admitted_at = null,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns one confirmed claimed outcome.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $admitted_at Generation the lock was written under.
	 *
	 * @return  self
	 */
	public static function claimed( int $admitted_at ): self {
		return new self( LockClaimOutcome::Claimed, null, null, null, $admitted_at );
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
	 * @param   int    $admitted_at  Generation the staleness decision was made under.
	 *
	 * @return  self
	 */
	public static function contended( string $owner_run_id, string $raw, bool $stale, int $admitted_at ): self {
		return new self( LockClaimOutcome::Contended, $owner_run_id, $raw, $stale, $admitted_at );
	}

	/**
	 * Returns one outcome carrying no lock this claim can act on.
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

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Occurrences;

\defined( 'ABSPATH' ) || exit;

/**
 * Carries one classified occurrence-lease claim and its claimed handle when present.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class OccurrenceLeaseClaim {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param 'read'|'write'|null $storage_operation
	 *
	 * @param   OccurrenceLeaseOutcome $outcome           Claim classification.
	 * @param   ClaimedLease|null      $lease             Claimed lease handle, or null without ownership.
	 * @param   string|null            $storage_operation Unconfirmed storage operation, or null for a determinate outcome.
	 */
	private function __construct(
		public OccurrenceLeaseOutcome $outcome,
		public ?ClaimedLease $lease,
		public ?string $storage_operation,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns one confirmed claimed outcome.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ClaimedLease $lease Claimed lease handle.
	 *
	 * @return  self
	 */
	public static function claimed( ClaimedLease $lease ): self {
		return new self( OccurrenceLeaseOutcome::Claimed, $lease, null );
	}

	/**
	 * Returns one confirmed concurrent-holder outcome.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  self
	 */
	public static function held(): self {
		return new self( OccurrenceLeaseOutcome::Held, null, null );
	}

	/**
	 * Returns one outcome whose authoritative read could not be confirmed.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  self
	 */
	public static function indeterminate_read(): self {
		return new self( OccurrenceLeaseOutcome::Indeterminate, null, 'read' );
	}

	/**
	 * Returns one outcome whose authoritative write could not be confirmed.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  self
	 */
	public static function indeterminate_write(): self {
		return new self( OccurrenceLeaseOutcome::Indeterminate, null, 'write' );
	}

	/**
	 * Returns the confirmed lease handle.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \LogicException When the claim outcome does not carry ownership.
	 *
	 * @return  ClaimedLease
	 */
	public function claimed_lease(): ClaimedLease {
		if ( null === $this->lease ) {
			throw new \LogicException( 'Only a claimed occurrence-lease outcome carries a lease handle.' );
		}

		return $this->lease;
	}

	// endregion
}

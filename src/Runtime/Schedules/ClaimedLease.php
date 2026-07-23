<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;

\defined( 'ABSPATH' ) || exit;

/**
 * Owns one exact occurrence-lease claim and its idempotent release.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class ClaimedLease {
	// region FIELDS AND CONSTANTS

	/**
	 * Whether this handle has attempted its release.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     bool
	 */
	private bool $released = false;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OptionRows $rows         Authoritative raw lease-row I/O.
	 * @param   string     $option_name  Complete lease option name computed by the claiming lease.
	 * @param   string     $expected_raw Exact claimed row bytes.
	 */
	public function __construct(
		private readonly OptionRows $rows,
		private readonly string $option_name,
		private readonly string $expected_raw,
	) {}

	// endregion

	// region METHODS

	/**
	 * Releases this exact claim at most once.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function release(): void {
		if ( $this->released ) {
			return;
		}

		$this->released = true;
		$this->rows->delete_if_value_matches( $this->option_name, $this->expected_raw );
	}

	// endregion
}

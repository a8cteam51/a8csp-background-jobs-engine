<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;

\defined( 'ABSPATH' ) || exit;

/**
 * Retains the exact preflight snapshots required by one confirmed lock repair.
 *
 * @internal Explicit lock repair only.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class LockRepairPlan {
	// region FIELDS AND CONSTANTS

	/**
	 * Number of Running rows fenced by this plan.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public int $running_run_count;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<array{run_id: string, raw: string, state: RunState}> $running_runs
	 *
	 * @param   string $identity     Complete owner-qualified work identity.
	 * @param   string $args_hash    Selected overlap-lock lane.
	 * @param   int    $raw_length   Selected malformed raw-value length.
	 * @param   string $raw_sha256   Selected truncated malformed raw-value digest.
	 * @param   string $lock_raw     Exact selected malformed lock bytes.
	 * @param   array  $running_runs Exact Running snapshots on the selected lane.
	 */
	public function __construct(
		public string $identity,
		public string $args_hash,
		public int $raw_length,
		public string $raw_sha256,
		private string $lock_raw,
		private array $running_runs,
	) {
		$this->running_run_count = \count( $running_runs );
	}

	// endregion

	// region METHODS

	/**
	 * Returns the exact malformed lock generation selected before confirmation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	public function lock_raw(): string {
		return $this->lock_raw;
	}

	/**
	 * Returns every exact Running snapshot selected before confirmation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{run_id: string, raw: string, state: RunState}>
	 */
	public function running_runs(): array {
		return $this->running_runs;
	}

	// endregion
}

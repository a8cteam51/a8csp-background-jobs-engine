<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockRepair;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockRepairPlan;

\defined( 'ABSPATH' ) || exit;

/**
 * Shapes lock inspection and reports explicit malformed-lane repair.
 *
 * @internal
 *
 * @phpstan-import-type LockLane from LockRepair
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class LocksOutput {
	// region FIELDS AND CONSTANTS

	/**
	 * Fields exposed by persisted execution-overlap lock inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<string>
	 */
	private const array FIELDS = array(
		'identity',
		'args_hash',
		'state',
		'raw_length',
		'raw_sha256',
	);

	// endregion

	// region METHODS

	/**
	 * Returns deterministic rows in their exact public column order.
	 *
	 * @internal Command formatting seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<LockLane> $lanes
	 *
	 * @param   array $lanes Inspected execution-overlap lock lanes.
	 *
	 * @return  list<LockLane>
	 */
	public static function rows_from_lanes( array $lanes ): array {
		\usort(
			$lanes,
			static function ( array $left, array $right ): int {
				$identity_order = $left['identity'] <=> $right['identity'];
				return 0 !== $identity_order ? $identity_order : $left['args_hash'] <=> $right['args_hash'];
			}
		);

		$rows = array();
		foreach ( $lanes as $lane ) {
			$rows[] = array(
				'identity'   => $lane['identity'],
				'args_hash'  => $lane['args_hash'],
				'state'      => $lane['state'],
				'raw_length' => $lane['raw_length'],
				'raw_sha256' => $lane['raw_sha256'],
			);
		}

		return $rows;
	}

	/**
	 * Renders every persisted execution-overlap lock lane.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<LockLane> $lanes
	 *
	 * @param   array  $lanes Inspected execution-overlap lock lanes.
	 * @param   string $format WP-CLI output format.
	 *
	 * @return  void
	 */
	public static function render( array $lanes, string $format ): void {
		$rows = self::rows_from_lanes( $lanes );
		if ( array() === $rows && 'table' === $format ) {
			\WP_CLI::line( 'No execution-overlap locks are persisted.' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, self::FIELDS );
	}

	/**
	 * Renders malformed lanes that require explicit argument-hash selection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<LockLane> $lanes
	 *
	 * @param   array $lanes Ambiguous malformed lanes.
	 *
	 * @return  void
	 */
	public static function render_ambiguity( array $lanes ): void {
		\WP_CLI\Utils\format_items( 'table', self::rows_from_lanes( $lanes ), self::FIELDS );
	}

	/**
	 * Requires acknowledgement of the exact lane and Running-row impact.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   LockRepairPlan       $plan       Exact preflighted repair plan.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 *
	 * @return  void
	 */
	public static function confirm( LockRepairPlan $plan, array $assoc_args ): void {
		\WP_CLI::confirm(
			\sprintf(
				'Repair identity "%1$s" lane "%2$s": supersede %3$d Running run %4$s, then delete the malformed execution-overlap lock. Continue?',
				$plan->identity,
				$plan->args_hash,
				$plan->running_run_count,
				1 === $plan->running_run_count ? 'row' : 'rows',
			),
			$assoc_args
		);
	}

	/**
	 * Reports that discovery found no malformed lane.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete work identity.
	 *
	 * @return  void
	 */
	public static function nothing_to_repair( string $identity ): void {
		\WP_CLI::line( \sprintf( 'Nothing to repair for identity "%s".', $identity ) );
	}

	/**
	 * Reports a successful exact repair without exposing persisted bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   LockRepairPlan $plan             Repaired lane.
	 * @param   int            $runs_superseded Exact run claims completed.
	 *
	 * @return  void
	 */
	public static function report_success( LockRepairPlan $plan, int $runs_superseded ): void {
		self::diagnostic( $plan, $runs_superseded, true );
		\WP_CLI::success( \sprintf( 'Cleared malformed execution-overlap lock for "%1$s" lane "%2$s".', $plan->identity, $plan->args_hash ) );
	}

	/**
	 * Reports a retryable repair failure with partial exact-CAS progress.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   LockRepairPlan $plan  Selected repair lane.
	 * @param   EngineError    $error Corrective runtime failure.
	 *
	 * @return  void
	 */
	public static function report_failure( LockRepairPlan $plan, EngineError $error ): void {
		$runs_superseded = $error->context['runs_superseded'] ?? 0;
		self::diagnostic( $plan, \is_int( $runs_superseded ) ? $runs_superseded : 0, false );
		self::error( $error->message );
	}

	/**
	 * Reports a fatal lock-command error.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $message Corrective error.
	 *
	 * @return  void
	 */
	public static function error( string $message ): void {
		\WP_CLI::error( $message );
	}

	// endregion

	// region HELPERS

	/**
	 * Emits stable repair correlation and effect fields.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   LockRepairPlan $plan             Selected repair lane.
	 * @param   int            $runs_superseded Exact run claims completed.
	 * @param   bool           $lock_cleared     Whether exact lock deletion won.
	 *
	 * @return  void
	 */
	private static function diagnostic( LockRepairPlan $plan, int $runs_superseded, bool $lock_cleared ): void {
		\WP_CLI::line( 'identity=' . $plan->identity );
		\WP_CLI::line( 'args_hash=' . $plan->args_hash );
		\WP_CLI::line( 'raw_length=' . $plan->raw_length );
		\WP_CLI::line( 'raw_sha256=' . $plan->raw_sha256 );
		\WP_CLI::line( 'runs_superseded=' . $runs_superseded );
		\WP_CLI::line( 'lock_cleared=' . ( $lock_cleared ? 'true' : 'false' ) );
	}

	// endregion
}

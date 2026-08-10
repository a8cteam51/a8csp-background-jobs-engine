<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;

\defined( 'ABSPATH' ) || exit;

/**
 * Inspects persisted execution-overlap lock lanes.
 *
 * @internal
 *
 * @phpstan-type LockLane array{
 *     identity: string,
 *     args_hash: string,
 *     state: 'owned'|'stale'|'malformed',
 *     raw_length: int|null,
 *     raw_sha256: string|null
 * }
 * @phpstan-type LockSnapshot array{
 *     identity: Identity,
 *     args_hash: string,
 *     raw: string,
 *     lock: array{run_id: string, heartbeat_at: int}|null
 * }
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class LockInspection {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OptionRows   $rows         Authoritative raw option-row I/O.
	 * @param   OverlapGuard $guard        Persisted lock parser.
	 * @param   LockWindows  $lock_windows Lock-liveness classifier.
	 */
	public function __construct(
		private OptionRows $rows,
		private OverlapGuard $guard,
		private LockWindows $lock_windows,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns every canonical overlap-lock lane with an operational state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<list<LockLane>, EngineError>
	 */
	public function inspect_lanes(): AbstractResult {
		$snapshots = $this->snapshots();
		if ( $snapshots->is_failure() ) {
			return $snapshots;
		}

		$lanes = array();
		foreach ( $snapshots->value as $snapshot ) {
			$lock = $snapshot['lock'];
			if ( null === $lock ) {
				$lanes[] = self::malformed_lane( $snapshot );
				continue;
			}

			$staleness = $this->lock_windows->lock_staleness( $snapshot['identity'], $lock['run_id'] );
			$lanes[]   = array(
				'identity'   => (string) $snapshot['identity'],
				'args_hash'  => $snapshot['args_hash'],
				'state'      => $this->lock_windows->heartbeat_is_stale( $lock['heartbeat_at'], $staleness ) ? 'stale' : 'owned',
				'raw_length' => null,
				'raw_sha256' => null,
			);
		}

		return new Success( $lanes );
	}

	// endregion

	// region HELPERS

	/**
	 * Reads every canonical lock snapshot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<list<LockSnapshot>, EngineError>
	 */
	private function snapshots(): AbstractResult {
		$names = $this->rows->option_names( OverlapGuard::OPTION_PREFIX );
		if ( $names->is_failure() ) {
			return $names;
		}

		$snapshots = array();
		foreach ( $names->value as $option_name ) {
			$lock_identity = OverlapGuard::identity_from_option_name( $option_name );
			if ( null === $lock_identity ) {
				continue;
			}

			$inspected = $this->guard->inspect_persisted_lock( $lock_identity['identity'], $lock_identity['args_hash'] );
			if ( $inspected->is_failure() ) {
				return $inspected;
			}
			if ( null === $inspected->value ) {
				continue;
			}

			$snapshots[] = array(
				'identity'  => $lock_identity['identity'],
				'args_hash' => $lock_identity['args_hash'],
				'raw'       => $inspected->value['raw'],
				'lock'      => $inspected->value['lock'],
			);
		}

		return new Success( $snapshots );
	}

	/**
	 * Projects a malformed snapshot into its redacted operator-facing lane shape.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   LockSnapshot $snapshot Malformed exact lock snapshot.
	 *
	 * @return  LockLane
	 */
	private static function malformed_lane( array $snapshot ): array {
		$correlation = OverlapGuard::raw_correlation( $snapshot['raw'] );

		return array(
			'identity'   => (string) $snapshot['identity'],
			'args_hash'  => $snapshot['args_hash'],
			'state'      => 'malformed',
			'raw_length' => $correlation['raw_length'],
			'raw_sha256' => $correlation['raw_sha256'],
		);
	}

	// endregion
}

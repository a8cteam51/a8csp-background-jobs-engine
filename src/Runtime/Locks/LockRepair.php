<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RowDeleteOutcome;

\defined( 'ABSPATH' ) || exit;

/**
 * Inspects and explicitly repairs malformed execution-overlap lock lanes.
 *
 * Every candidate is decoded before the first mutation. Repair then fences each exact Running
 * snapshot independently before deleting only the exact malformed lock generation selected.
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
final readonly class LockRepair {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OptionRows     $rows         Authoritative raw option-row I/O.
	 * @param   OverlapGuard   $guard        Persisted lock parser.
	 * @param   StoreFactory   $stores       Identity-bound active-run stores.
	 * @param   LockWindows    $lock_windows Lock-liveness classifier.
	 * @param   RunTransitions $transitions  Exact terminal-transition claims.
	 */
	public function __construct(
		private OptionRows $rows,
		private OverlapGuard $guard,
		private StoreFactory $stores,
		private LockWindows $lock_windows,
		private RunTransitions $transitions,
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
		$snapshots = $this->snapshots( null );
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

	/**
	 * Preflights one malformed lane without mutating lock or run storage.
	 *
	 * A null value means no malformed lane exists. A lane list means the identity is ambiguous and
	 * requires an argument hash. A plan retains every exact snapshot needed after confirmation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity    $identity  Complete scope-qualified work identity.
	 * @param   string|null $args_hash Optional selected overlap-lock lane.
	 *
	 * @return  AbstractResult<LockRepairPlan|list<LockLane>|null, EngineError>
	 */
	public function prepare( Identity $identity, ?string $args_hash ): AbstractResult {
		$snapshots = $this->snapshots( $identity );
		if ( $snapshots->is_failure() ) {
			return $snapshots;
		}

		$malformed = \array_values(
			\array_filter(
				$snapshots->value,
				static fn ( array $snapshot ): bool => null === $snapshot['lock']
			)
		);
		if ( null === $args_hash ) {
			if ( array() === $malformed ) {
				return new Success( null );
			}
			if ( 1 < \count( $malformed ) ) {
				return new Success( \array_map( self::malformed_lane( ... ), $malformed ) );
			}

			$selected = $malformed[0];
		} else {
			$selected = null;
			foreach ( $malformed as $candidate ) {
				if ( $args_hash === $candidate['args_hash'] ) {
					$selected = $candidate;
					break;
				}
			}
			if ( null === $selected ) {
				return new Failure(
					new EngineError(
						\sprintf( 'Execution-overlap lock lane "%1$s" / "%2$s" is absent or is not malformed; run locks list and select a malformed lane.', (string) $identity, $args_hash ),
						reason: EngineErrorReason::UnsupportedOperation,
						context: array(
							'identity'  => (string) $identity,
							'args_hash' => $args_hash,
						),
					)
				);
			}
		}

		// Confirmation may pause for human input, so retain a fresh direct snapshot for the later exact delete.
		$inspected = $this->guard->inspect_persisted_lock( $identity, $selected['args_hash'] );
		if ( $inspected->is_failure() ) {
			return $inspected;
		}
		$lock_snapshot = $inspected->value;
		if ( null === $lock_snapshot || null !== $lock_snapshot['lock'] ) {
			return new Failure(
				new EngineError(
					'The selected execution-overlap lock changed during repair preparation; run locks list and retry.',
					reason: EngineErrorReason::UnsupportedOperation,
					context: array(
						'identity'  => (string) $identity,
						'args_hash' => $selected['args_hash'],
					),
				)
			);
		}

		$run_store = $this->stores->run_store( $identity );
		$runs      = $run_store->inspect_all();
		if ( $runs->is_failure() ) {
			return $runs;
		}

		$running = array();
		foreach ( $runs->value as $candidate ) {
			$state = $candidate['state'];
			if ( null === $state ) {
				return new Failure(
					new EngineError(
						\sprintf( 'Active-run row "%s" is unreadable; repair authoritative run storage before repairing the lock.', $candidate['run_id'] ),
						reason: EngineErrorReason::StorageFailure,
						context: array(
							'identity' => (string) $identity,
							'run_id'   => $candidate['run_id'],
						),
					)
				);
			}
			if ( RunStatus::Running !== $state->status || $selected['args_hash'] !== $state->args_hash ) {
				continue;
			}

			$running[] = array(
				'run_id' => $candidate['run_id'],
				'raw'    => $candidate['raw'],
				'state'  => $state,
			);
		}

		$correlation = OverlapGuard::raw_correlation( $lock_snapshot['raw'] );

		return new Success( new LockRepairPlan( $identity, $selected['args_hash'], $correlation['raw_length'], $correlation['raw_sha256'], $lock_snapshot['raw'], $running, ) );
	}

	/**
	 * Fences every preflighted Running row before exact-deleting the malformed lock generation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   LockRepairPlan $plan Confirmed exact repair plan.
	 *
	 * @return  AbstractResult<int, EngineError> Number of runs superseded, or a retryable failure.
	 */
	public function repair( LockRepairPlan $plan ): AbstractResult {
		$run_store  = $this->stores->run_store( $plan->identity );
		$superseded = 0;
		foreach ( $plan->running_runs() as $candidate ) {
			$claimed = $this->transitions->claim_superseded_run( $candidate['run_id'], $candidate['state'], $run_store, $candidate['raw'] );
			if ( $claimed instanceof Failure ) {
				return $this->repair_failure( $claimed->error->message . ' The malformed lock remains; resolve the failure and re-run locks repair.', $plan, $superseded, $claimed->error->reason ?? EngineErrorReason::StorageFailure, $candidate['run_id'], );
			}
			if ( null === $claimed ) {
				return $this->repair_failure( \sprintf( 'Active-run row "%s" changed during lock repair; re-run locks repair against fresh snapshots.', $candidate['run_id'] ), $plan, $superseded, EngineErrorReason::UnsupportedOperation, $candidate['run_id'], );
			}

			++$superseded;
		}

		$deleted = $this->rows->delete_if_value_matches( OverlapGuard::OPTION_PREFIX . (string) $plan->identity . '_' . $plan->args_hash, $plan->lock_raw(), );
		if ( RowDeleteOutcome::Deleted === $deleted ) {
			return new Success( $superseded );
		}
		if ( RowDeleteOutcome::ValueMismatch === $deleted ) {
			return $this->repair_failure( 'The malformed execution-overlap lock changed before exact deletion; run locks list and re-run locks repair.', $plan, $superseded, EngineErrorReason::UnsupportedOperation, );
		}

		return $this->repair_failure( 'The malformed execution-overlap lock could not be deleted; repair authoritative storage and re-run locks repair.', $plan, $superseded, EngineErrorReason::StorageFailure, );
	}

	// endregion

	// region HELPERS

	/**
	 * Reads every canonical lock snapshot, optionally restricted to one exact identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity|null $identity Optional exact work identity.
	 *
	 * @return  AbstractResult<list<LockSnapshot>, EngineError>
	 */
	private function snapshots( ?Identity $identity ): AbstractResult {
		$prefix = null === $identity
			? OverlapGuard::OPTION_PREFIX
			: OverlapGuard::OPTION_PREFIX . (string) $identity . '_';
		$names  = $this->rows->option_names( $prefix );
		if ( $names->is_failure() ) {
			return $names;
		}

		$snapshots = array();
		foreach ( $names->value as $option_name ) {
			$lock_identity = OverlapGuard::identity_from_option_name( $option_name );
			if ( null === $lock_identity || ( null !== $identity && (string) $identity !== (string) $lock_identity['identity'] ) ) {
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

	/**
	 * Builds a retryable repair failure without exposing persisted raw bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string            $message         Corrective operator message.
	 * @param   LockRepairPlan    $plan            Selected repair plan.
	 * @param   int               $runs_superseded Exact claims completed before failure.
	 * @param   EngineErrorReason $reason          Machine-readable failure cause.
	 * @param   string|null       $run_id          Contended run identifier, when applicable.
	 *
	 * @return  Failure<EngineError>
	 */
	private function repair_failure( string $message, LockRepairPlan $plan, int $runs_superseded, EngineErrorReason $reason, ?string $run_id = null ): Failure {
		$context = array(
			'identity'        => (string) $plan->identity,
			'args_hash'       => $plan->args_hash,
			'raw_length'      => $plan->raw_length,
			'raw_sha256'      => $plan->raw_sha256,
			'runs_superseded' => $runs_superseded,
			'lock_cleared'    => false,
		);
		if ( null !== $run_id ) {
			$context['run_id'] = $run_id;
		}

		return new Failure( new EngineError( $message, reason: $reason, context: $context ) );
	}

	// endregion
}

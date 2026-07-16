<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Maintenance;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\AbstractTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\CleanupIntents;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunReconciliation;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Reconciles engine-wide run state, execution locks, and schedule-cleanup intents.
 *
 * @internal Engine wiring only.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class MaintenanceTask extends AbstractTask {
	// region FIELDS AND CONSTANTS

	/**
	 * Engine-reserved maintenance local name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string NAME = 'maintenance';

	/**
	 * Belt-and-braces grace before deleting a terminal run option.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const TERMINAL_GRACE = 3_600;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OptionRows        $rows            Authoritative option-name enumeration.
	 * @param   RunReconciliation $reconciliation  Run-reconciliation boundary.
	 * @param   OverlapGuard      $guard           Lock schema and exact-delete boundary.
	 * @param   CleanupIntents    $cleanup_intents Unknown-chain convergence boundary.
	 * @param   LoggerInterface   $logger          Log event sink.
	 */
	public function __construct(
		private readonly OptionRows $rows,
		private readonly RunReconciliation $reconciliation,
		private readonly OverlapGuard $guard,
		private readonly CleanupIntents $cleanup_intents,
		private readonly LoggerInterface $logger,
	) {}

	// endregion

	// region INHERITED METHODS

	/**
	 * Returns the engine-reserved maintenance local name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	#[\Override]
	public function get_name(): string {
		return self::NAME;
	}

	/**
	 * Performs one option-prefix reconciliation sweep.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $args Unused schedule arguments.
	 *
	 * @return  void
	 */
	#[\Override]
	public function handle( array $args ): void {
		// Run reconciliation consumes transfer evidence before orphan-lock reclamation can erase it.
		$protected_transfers = array();
		$deferred_lock_names = array();
		$run_names           = $this->rows->option_names( RunIdentity::option_prefix() );
		if ( $run_names->is_failure() ) {
			return;
		}

		foreach ( $run_names->value as $option_name ) {
			$identity = RunIdentity::from_option_name( $option_name );
			if ( null === $identity ) {
				continue;
			}

			try {
				$reconciled = $this->reconciliation->reconcile_run( $identity['identity'], $identity['run_id'], self::TERMINAL_GRACE );
			} catch ( \Throwable $throwable ) {
				$deferred_lock_names[ $identity['identity'] ] = true;
				$this->logger->warning(
					'Run reconciliation item could not converge during maintenance; retry on the next sweep.',
					array(
						'name'      => $identity['identity'],
						'run_id'    => $identity['run_id'],
						'exception' => $throwable,
					)
				);

				continue;
			}
			if ( $reconciled->is_failure() ) {
				return;
			}

			$transferred_hash = $reconciled->value;
			if ( null !== $transferred_hash ) {
				$protected_transfers[ $identity['identity'] . '|' . $transferred_hash ] = true;
			}
		}

		$lock_names = $this->rows->option_names( OverlapGuard::OPTION_PREFIX );
		if ( $lock_names->is_failure() ) {
			return;
		}

		foreach ( $lock_names->value as $option_name ) {
			$lock_identity = OverlapGuard::identity_from_option_name( $option_name );
			if ( null === $lock_identity ) {
				continue;
			}

			$identity  = $lock_identity['name'];
			$args_hash = $lock_identity['args_hash'];
			if ( isset( $deferred_lock_names[ $identity ] ) ) {
				// An unclassified run can still depend on every same-name lock as authoritative fence evidence.
				continue;
			}

			if ( isset( $protected_transfers[ $identity . '|' . $args_hash ] ) ) {
				// A fresh displaced run still needs the foreign lock as authoritative takeover evidence.
				continue;
			}

			$run_id = null;
			try {
				$sweep  = $this->guard->sweep_persisted_lock( $identity, $args_hash );
				$run_id = $sweep->run_id;
				if ( $sweep->malformed_reclaimed ) {
					$this->logger->warning(
						'Reclaimed schema-invalid execution-overlap lock during maintenance sweep.',
						array(
							'name'      => $identity,
							'args_hash' => $args_hash,
							'run_id'    => null,
						)
					);
				}

				if ( null === $run_id ) {
					continue;
				}

				$this->reconciliation->reconcile_orphaned_lock( $identity, $args_hash, $run_id );
			} catch ( \Throwable $throwable ) {
				$this->logger->warning(
					'Execution-overlap lock reconciliation item could not converge during maintenance; retry on the next sweep.',
					array(
						'name'      => $identity,
						'args_hash' => $args_hash,
						'run_id'    => $run_id,
						'exception' => $throwable,
					)
				);

				continue;
			}
		}

		$this->cleanup_intents->converge_pending_intents();
	}

	// endregion
}

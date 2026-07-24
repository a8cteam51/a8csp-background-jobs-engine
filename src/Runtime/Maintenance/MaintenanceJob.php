<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Maintenance;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\CleanupIntents;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunReconciliation;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RowDeleteOutcome;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Reconciles engine-wide run state, execution locks, schedule registries, and cleanup intents.
 *
 * @internal Engine wiring only.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class MaintenanceJob implements JobExecutionInterface {
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
	 * Maximum lock option names inspected per maintenance invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int LOCK_SWEEP_BUDGET = 500;

	/**
	 * Maximum run or schedule-registration option names inspected per phase and invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int RUN_SWEEP_BUDGET = 500;

	/**
	 * Durable cursor row shared by all maintenance sweep phases.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const string SWEEP_CURSOR_OPTION = 'a8csp_bgje_maintenance_sweep';

	/**
	 * Maximum option names returned by one maintenance enumeration query.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int SWEEP_PAGE_SIZE = 100;

	/**
	 * Belt-and-braces grace before deleting a terminal run option.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int TERMINAL_GRACE = 3_600;

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

	// region METHODS

	/**
	 * Performs one option-prefix reconciliation sweep.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Unused schedule arguments.
	 * @param   RunContextInterface     $context    Maintenance run context.
	 *
	 * @throws  \LogicException When the site changes or WordPress cannot serialize cursor state.
	 *
	 * @return  void
	 */
	#[\Override]
	public function handle( array $start_args, RunContextInterface $context ): void {
		$selected_cursor = $this->rows->read( self::SWEEP_CURSOR_OPTION );
		if ( $selected_cursor->is_failure() ) {
			$this->log_sweep_abort(
				'Maintenance sweep aborted while reading its cursor; repair WordPress option reads and retry the sweep.',
				'cursor-read',
				$selected_cursor->error
			);

			return;
		}

		$cursor_raw           = $selected_cursor->value;
		$runs_cursor          = null;
		$locks_cursor         = null;
		$registrations_cursor = null;
		if ( null !== $cursor_raw ) {
			$decoded_cursor = RawOptionDecoder::decode( $cursor_raw );
			if (
				\is_array( $decoded_cursor )
				&& 3 === \count( $decoded_cursor )
				&& \array_key_exists( 'runs', $decoded_cursor )
				&& \array_key_exists( 'locks', $decoded_cursor )
				&& \array_key_exists( 'registrations', $decoded_cursor )
				&& ( null === $decoded_cursor['runs'] || \is_string( $decoded_cursor['runs'] ) )
				&& ( null === $decoded_cursor['locks'] || \is_string( $decoded_cursor['locks'] ) )
				&& ( null === $decoded_cursor['registrations'] || \is_string( $decoded_cursor['registrations'] ) )
			) {
				$runs_cursor          = $decoded_cursor['runs'];
				$locks_cursor         = $decoded_cursor['locks'];
				$registrations_cursor = $decoded_cursor['registrations'];
			}
		}

		// Run reconciliation consumes transfer evidence before orphan-lock reclamation can erase it.
		$protected_transfers = array();
		$deferred_lock_names = array();
		$run_count           = 0;
		// Each raw page is atomic, so a phase exceeds its budget by at most SWEEP_PAGE_SIZE - 1 names.
		while ( $run_count < self::RUN_SWEEP_BUDGET ) {
			$run_page = $this->rows->option_names_after( RunIdentity::option_prefix(), $runs_cursor, self::SWEEP_PAGE_SIZE );
			if ( $run_page->is_failure() ) {
				$this->log_sweep_abort(
					'Maintenance run sweep aborted while enumerating run rows; repair WordPress option reads and retry the sweep.',
					'run-enumeration',
					$run_page->error
				);

				return;
			}

			$run_count += $run_page->value['scanned'];
			foreach ( $run_page->value['names'] as $option_name ) {
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
							'identity'  => $identity['identity'],
							'run_id'    => $identity['run_id'],
							'exception' => $throwable,
						)
					);

					continue;
				}
				if ( $reconciled->is_failure() ) {
					$this->log_sweep_abort(
						'Maintenance run sweep aborted while reconciling a run; resolve the reported failure and retry the sweep.',
						'run-reconciliation',
						$reconciled->error,
						array(
							'identity' => $identity['identity'],
							'run_id'   => $identity['run_id'],
						)
					);

					return;
				}

				$transferred_hash = $reconciled->value;
				if ( null !== $transferred_hash ) {
					$protected_transfers[ $identity['identity'] . '|' . $transferred_hash ] = true;
				}
			}

			$runs_cursor = $run_page->value['next_cursor'];
			if ( null === $runs_cursor ) {
				break;
			}
		}
		$run_incomplete = null !== $runs_cursor;

		$lock_count = 0;
		while ( $lock_count < self::LOCK_SWEEP_BUDGET ) {
			$lock_page = $this->rows->option_names_after( OverlapGuard::OPTION_PREFIX, $locks_cursor, self::SWEEP_PAGE_SIZE );
			if ( $lock_page->is_failure() ) {
				$this->log_sweep_abort(
					'Maintenance lock sweep aborted while enumerating overlap-lock rows; repair WordPress option reads and retry the sweep.',
					'lock-enumeration',
					$lock_page->error
				);

				return;
			}

			$lock_count += $lock_page->value['scanned'];
			foreach ( $lock_page->value['names'] as $option_name ) {
				$lock_identity = OverlapGuard::identity_from_option_name( $option_name );
				if ( null === $lock_identity ) {
					continue;
				}

				$identity  = $lock_identity['identity'];
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
					if ( $sweep->malformed_preserved ) {
						$this->logger->warning(
							'Preserved schema-invalid execution-overlap lock during maintenance sweep; inspect and repair it with WP-CLI.',
							array(
								'identity'   => $identity,
								'args_hash'  => $args_hash,
								'malformed'  => true,
								'raw_length' => $sweep->raw_length,
								'raw_sha256' => $sweep->raw_sha256,
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
							'identity'  => $identity,
							'args_hash' => $args_hash,
							'run_id'    => $run_id,
							'exception' => $throwable,
						)
					);

					continue;
				}
			}

			$locks_cursor = $lock_page->value['next_cursor'];
			if ( null === $locks_cursor ) {
				break;
			}
		}
		$lock_incomplete = null !== $locks_cursor;

		// Intent convergence must not starve behind a persistently failing registry phase.
		$this->cleanup_intents->converge_pending_intents();

		$registration_count = 0;
		while ( $registration_count < self::RUN_SWEEP_BUDGET ) {
			$registration_page = $this->rows->option_names_after( ScheduleRegistry::OPTION_PREFIX, $registrations_cursor, self::SWEEP_PAGE_SIZE );
			if ( $registration_page->is_failure() ) {
				$this->log_sweep_abort(
					'Maintenance schedule-registry sweep aborted while enumerating registration rows; repair WordPress option reads and retry the sweep.',
					'registry-enumeration',
					$registration_page->error
				);

				return;
			}

			$registration_count += $registration_page->value['scanned'];
			foreach ( $registration_page->value['names'] as $option_name ) {
				$owner = ScheduleRegistry::owner_from_option_name( $option_name );
				if ( null === $owner ) {
					continue;
				}

				$selected = $this->rows->read( $option_name );
				if ( $selected->is_failure() ) {
					$this->log_sweep_abort(
						'Maintenance schedule-registry sweep aborted while reading a registration row; repair WordPress option reads and retry the sweep.',
						'registry-read',
						$selected->error,
						array( 'option_name' => $option_name )
					);

					return;
				}

				$raw     = $selected->value;
				$decoded = null === $raw ? null : RawOptionDecoder::decode( $raw );
				if (
					null === $raw
					|| (
						\is_array( $decoded )
						&& ! ScheduleRegistry::has_registration_without_undeclared_markers( $owner, $decoded )
					)
				) {
					continue;
				}

				$delete = $this->rows->delete_if_value_matches( $option_name, $raw );
				if ( RowDeleteOutcome::Deleted === $delete ) {
					$this->logger->warning(
						'Deleted corrupt schedule registry option during maintenance sweep.',
						array( 'option_name' => $option_name )
					);
					continue;
				}
				if ( RowDeleteOutcome::DeleteFailed === $delete ) {
					$this->logger->warning(
						'Maintenance schedule-registry sweep aborted while deleting a corrupt registration row; repair WordPress option writes and retry the sweep.',
						array(
							'option_name' => $option_name,
							'phase'       => 'registry-delete',
							'outcome'     => $delete->value,
						)
					);

					return;
				}
			}

			$registrations_cursor = $registration_page->value['next_cursor'];
			if ( null === $registrations_cursor ) {
				break;
			}
		}
		$registration_incomplete = null !== $registrations_cursor;

		if ( $run_incomplete || $lock_incomplete || $registration_incomplete ) {
			$replacement_raw = \maybe_serialize(
				array(
					'runs'          => $run_incomplete ? $runs_cursor : null,
					'locks'         => $lock_incomplete ? $locks_cursor : null,
					'registrations' => $registration_incomplete ? $registrations_cursor : null,
				)
			);
			if ( ! \is_string( $replacement_raw ) ) {
				throw new \LogicException( 'WordPress must serialize the maintenance sweep cursor to a string.' );
			}

			if ( null === $cursor_raw ) {
				$this->rows->insert_if_absent( self::SWEEP_CURSOR_OPTION, $replacement_raw );
			} else {
				$this->rows->compare_and_swap( self::SWEEP_CURSOR_OPTION, $cursor_raw, $replacement_raw );
			}
		} elseif ( null !== $cursor_raw ) {
			$this->rows->delete_if_value_matches( self::SWEEP_CURSOR_OPTION, $cursor_raw );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Emits one phase-specific storage-abort diagnostic.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string               $message Log message.
	 * @param   string               $phase   Aborted sweep phase.
	 * @param   EngineError          $error   Discarded storage failure.
	 * @param   array<string, mixed> $context Phase-specific context.
	 *
	 * @return  void
	 */
	private function log_sweep_abort( string $message, string $phase, EngineError $error, array $context = array() ): void {
		$this->logger->warning(
			$message,
			\array_merge(
				$context,
				array(
					'phase'        => $phase,
					'error_class'  => $error::class,
					'error_reason' => $error->reason?->value,
				)
			)
		);
	}

	// endregion
}

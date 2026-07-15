<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\AbstractTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunReconciliation;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Reconciles abandoned execution locks and active-run options on an hourly engine schedule.
 *
 * @internal Engine wiring only.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class MaintenanceTask extends AbstractTask {
	// region FIELDS AND CONSTANTS

	/**
	 * Engine-reserved maintenance task identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const NAME = 'a8csp-bgte-maintenance';

	/**
	 * Prefix for execution-overlap lock options.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const LOCK_PREFIX = 'a8csp_bgte_lock_';

	/**
	 * Prefix for consolidated run options.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const RUN_PREFIX = 'a8csp_bgte_run_';

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
	 * @param   OptionRows         $rows                Authoritative option-name enumeration.
	 * @param   RunReconciliation  $reconciliation      Run-reconciliation boundary.
	 * @param   OverlapGuard       $guard               Lock schema and exact-delete boundary.
	 * @param   OccurrenceDelivery $occurrence_delivery Unknown-chain convergence boundary.
	 * @param   LoggerInterface    $logger              Log event sink.
	 */
	public function __construct(
		private readonly OptionRows $rows,
		private readonly RunReconciliation $reconciliation,
		private readonly OverlapGuard $guard,
		private readonly OccurrenceDelivery $occurrence_delivery,
		private readonly LoggerInterface $logger,
	) {}

	// endregion

	// region INHERITED METHODS

	/**
	 * Returns the engine-reserved maintenance task identity.
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
		$run_names           = $this->rows->option_names( self::RUN_PREFIX );
		if ( $run_names->is_failure() ) {
			return;
		}

		foreach ( $run_names->value as $option_name ) {
			$identity = self::run_identity( $option_name );
			if ( null === $identity ) {
				continue;
			}

			try {
				$reconciled = $this->reconciliation->reconcile_run(
					$identity[0],
					$identity[1],
					self::TERMINAL_GRACE
				);
			} catch ( \Throwable $throwable ) {
				$deferred_lock_names[ $identity[0] ] = true;
				$this->logger->warning(
					'Run reconciliation item could not converge during maintenance; retry on the next sweep.',
					array(
						'name'      => $identity[0],
						'run_id'    => $identity[1],
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
				$protected_transfers[ $identity[0] . '|' . $transferred_hash ] = true;
			}
		}

		$lock_names = $this->rows->option_names( self::LOCK_PREFIX );
		if ( $lock_names->is_failure() ) {
			return;
		}

		foreach ( $lock_names->value as $option_name ) {
			$identity = self::lock_identity( $option_name );
			if ( null === $identity ) {
				continue;
			}

			[ $name, $args_hash ] = $identity;
			if ( isset( $deferred_lock_names[ $name ] ) ) {
				// An unclassified run can still depend on every same-name lock as authoritative fence evidence.
				continue;
			}

			if ( isset( $protected_transfers[ $name . '|' . $args_hash ] ) ) {
				// A fresh displaced run still needs the foreign lock as authoritative takeover evidence.
				continue;
			}

			$run_id = null;
			try {
				$inspected = $this->guard->inspect_persisted_lock( $name, $args_hash );
				if ( $inspected->is_failure() ) {
					continue;
				}

				$snapshot = $inspected->value;
				if ( null === $snapshot ) {
					continue;
				}

				$lock = $snapshot['lock'];
				if ( null === $lock ) {
					if ( $this->guard->delete_persisted_lock( $name, $args_hash, $snapshot['raw'] ) ) {
						$this->logger->warning(
							'Reclaimed schema-invalid execution-overlap lock during maintenance sweep.',
							array(
								'name'      => $name,
								'args_hash' => $args_hash,
								'run_id'    => null,
							)
						);
					}

					continue;
				}

				$run_id = $lock['run_id'];
				$this->reconciliation->reconcile_orphaned_lock(
					$name,
					$args_hash,
					$run_id
				);
			} catch ( \Throwable $throwable ) {
				$this->logger->warning(
					'Execution-overlap lock reconciliation item could not converge during maintenance; retry on the next sweep.',
					array(
						'name'      => $name,
						'args_hash' => $args_hash,
						'run_id'    => $run_id,
						'exception' => $throwable,
					)
				);

				continue;
			}
		}

		$this->occurrence_delivery->converge_pending_intents();
	}

	// endregion

	// region HELPERS

	/**
	 * Parses a lock option whose fixed hash suffix removes name-boundary ambiguity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $option_name Lock option name.
	 *
	 * @return  array{string, string}|null
	 */
	private static function lock_identity( string $option_name ): ?array {
		$matched = \preg_match(
			'/\Aa8csp_bgte_lock_([a-z0-9_-]+)_([a-f0-9]{64})\z/',
			$option_name,
			$matches
		);
		if ( 1 !== $matched ) {
			return null;
		}

		return array( $matches[1], $matches[2] );
	}

	/**
	 * Parses a run option whose fixed identifier suffix removes name-boundary ambiguity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $option_name Run option name.
	 *
	 * @return  array{string, string}|null
	 */
	private static function run_identity( string $option_name ): ?array {
		$matched = \preg_match(
			'/\Aa8csp_bgte_run_([a-z0-9_-]+)_([0-9]{20}-[0-9]{19})\z/',
			$option_name,
			$matches
		);
		if ( 1 !== $matched ) {
			return null;
		}

		return array( $matches[1], $matches[2] );
	}

	// endregion
}

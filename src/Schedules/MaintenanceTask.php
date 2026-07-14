<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Schedules;

use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\AbstractTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\RunReconciliation;
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
	 * @param   \wpdb             $wpdb           Site-bound WordPress database connection.
	 * @param   RunReconciliation $reconciliation Run-reconciliation boundary.
	 * @param   OverlapGuard      $guard          Lock schema and exact-delete boundary.
	 * @param   LoggerInterface   $logger         Log event sink.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly RunReconciliation $reconciliation,
		private readonly OverlapGuard $guard,
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
		foreach ( $this->option_names( self::RUN_PREFIX ) as $option_name ) {
			$identity = self::run_identity( $option_name );
			if ( null === $identity ) {
				continue;
			}

			$transferred_hash = $this->reconciliation->reconcile_run(
				$identity[0],
				$identity[1],
				self::TERMINAL_GRACE
			);
			if ( null !== $transferred_hash ) {
				$protected_transfers[ $identity[0] . '|' . $transferred_hash ] = true;
			}
		}

		foreach ( $this->option_names( self::LOCK_PREFIX ) as $option_name ) {
			$identity = self::lock_identity( $option_name );
			if ( null === $identity ) {
				continue;
			}

			[ $name, $args_hash ] = $identity;
			if ( isset( $protected_transfers[ $name . '|' . $args_hash ] ) ) {
				// A fresh displaced run still needs the foreign lock as authoritative takeover evidence.
				continue;
			}

			$snapshot = $this->guard->inspect_persisted_lock( $name, $args_hash );
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

			$this->reconciliation->reconcile_orphaned_lock(
				$name,
				$args_hash,
				$lock['run_id']
			);
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Returns exact option names under one escaped literal prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $prefix Literal option-name prefix.
	 *
	 * @return  list<string>
	 */
	private function option_names( string $prefix ): array {
		$wpdb  = $this->wpdb;
		$names = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s ORDER BY `option_name` ASC',
				$wpdb->options,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		$typed = array();
		foreach ( $names as $name ) {
			if ( \is_string( $name ) && \str_starts_with( $name, $prefix ) ) {
				$typed[] = $name;
			}
		}

		return $typed;
	}

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

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\CLI\Commands;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Maintenance\MaintenanceJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\LatestRunPointer;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunDataStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\CleanupIntents;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceLease;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RowDeleteOutcome;

\defined( 'ABSPATH' ) || exit;

/**
 * Destroys engine-owned development state.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ResetCommand {
	// region FIELDS AND CONSTANTS

	/**
	 * Canonical persisted-state prefixes owned by their storage implementations, plus the
	 * maintenance sweeps' fixed cursor keys.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<string>
	 */
	private const array OPTION_PREFIXES = array(
		ScheduleRegistry::OPTION_PREFIX,
		RunStore::OPTION_PREFIX,
		FailedRunStore::OPTION_PREFIX,
		RunHistory::OPTION_PREFIX,
		RunDataStore::OPTION_PREFIX,
		LatestRunPointer::OPTION_PREFIX,
		OverlapGuard::OPTION_PREFIX,
		OccurrenceLease::OPTION_PREFIX,
		CleanupIntents::OPTION_PREFIX,
		CleanupIntents::SWEEP_CURSOR_OPTION,
		MaintenanceJob::SWEEP_CURSOR_OPTION,
	);

	/**
	 * Canonical backend hooks that can carry engine work.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<non-empty-string>
	 */
	private const array ACTION_HOOKS = array(
		ActionDeliveries::DELIVER_HOOK,
		OccurrenceDelivery::SCHEDULE_HOOK,
	);

	// endregion

	// region METHODS

	/**
	 * Permanently deletes every engine runtime option row and pending backend action.
	 *
	 * This is a development reset tool, not an operational cancellation workflow. It destroys
	 * in-flight work irrecoverably, including the engine maintenance registration and occurrence;
	 * the maintenance schedule is recreated by the next boot synchronization.
	 *
	 * The prefixes below are the engine's runtime state. The release updater's cached lookup is
	 * deliberately outside them: it belongs to the update mechanism rather than to background work,
	 * expires on its own, and uninstall clears it from the persisted footprint manifest.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the interactive confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp a8csp-bgje reset
	 *     $ wp a8csp-bgje reset --yes
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<string>         $args       Positional command arguments.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 *
	 * @return  void
	 */
	public function reset( array $args, array $assoc_args ): void {
		$request = self::request_from_args( $args, $assoc_args );
		if ( 'error' === $request['action'] ) {
			\WP_CLI::error( $request['message'] );
			return;
		}

		\WP_CLI::confirm( 'This development reset permanently deletes every engine option row and pending backend action. In-flight work cannot be recovered. Continue?', $assoc_args );

		$option_rows = self::runtime_option_rows();
		$scheduler   = Component::get_scheduler();
		if ( null === $scheduler ) {
			\WP_CLI::error( 'The background jobs scheduler is unavailable; run the command after plugins_loaded.' );
			return;
		}

		$persisted_rows = self::persisted_rows( $option_rows );
		if ( \is_string( $persisted_rows ) ) {
			\WP_CLI::error( $persisted_rows );
			return;
		}

		$clearance = $scheduler->unschedule_hooks( self::ACTION_HOOKS );
		if ( $clearance->is_failure() ) {
			\WP_CLI::error( $clearance->error->message );
			return;
		}

		$deleted = 0;
		foreach ( $persisted_rows as $option_name => $raw ) {
			$outcome = $option_rows->delete_if_value_matches( $option_name, $raw );
			$message = match ( $outcome ) {
				RowDeleteOutcome::Deleted       => null,
				RowDeleteOutcome::ValueMismatch => \sprintf( 'Engine option row "%1$s" changed during reset after %2$d deletions; stop background writes and retry.', $option_name, $deleted ),
				RowDeleteOutcome::DeleteFailed  => \sprintf( 'The database delete for engine option rows failed after %d deletions; repair the database error and retry the reset.', $deleted ),
			};
			if ( null !== $message ) {
				\WP_CLI::error( $message );
				return;
			}

			++$deleted;
		}

		\WP_CLI::line( \sprintf( 'Option rows deleted: %d', $deleted ) );
		\WP_CLI::line( \sprintf( 'Pending backend actions unscheduled: %d', $clearance->value ) );
		\WP_CLI::success( 'Background jobs development state reset.' );
	}

	/**
	 * Validates reset arguments without requiring WordPress or WP-CLI state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<string>         $args       Positional command arguments.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 *
	 * @return  array{action: 'error', message: string}|array{action: 'reset'}
	 */
	public static function request_from_args( array $args, array $assoc_args ): array {
		if (
			array() !== $args
			|| array() !== \array_diff( \array_keys( $assoc_args ), array( 'yes' ) )
			|| ( \array_key_exists( 'yes', $assoc_args ) && ! \is_bool( $assoc_args['yes'] ) )
		) {
			return array(
				'action'  => 'error',
				'message' => 'Reset accepts only --yes; use wp a8csp-bgje reset [--yes].',
			);
		}

		return array( 'action' => 'reset' );
	}

	// endregion

	// region HELPERS

	/**
	 * Reads every engine-owned row before any destructive write begins.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OptionRows $option_rows Authoritative option-row seam.
	 *
	 * @return  array<string, string>|string Rows keyed by option name, or a corrective error.
	 */
	private static function persisted_rows( OptionRows $option_rows ): array|string {
		$rows = array();
		foreach ( self::OPTION_PREFIXES as $prefix ) {
			$names = $option_rows->option_names( $prefix );
			if ( $names->is_failure() ) {
				return 'The database check for engine option rows failed; resolve the database error and retry the reset.';
			}

			foreach ( $names->value as $option_name ) {
				$selected = $option_rows->read( $option_name );
				if ( $selected->is_failure() ) {
					return \sprintf( 'Engine option row "%s" could not be read; resolve the database error and retry the reset.', $option_name );
				}
				if ( null !== $selected->value ) {
					$rows[ $option_name ] = $selected->value;
				}
			}
		}

		return $rows;
	}

	/**
	 * Builds the current site's authoritative option-row seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  OptionRows
	 */
	private static function runtime_option_rows(): OptionRows {
		global $wpdb;

		/**
		 * WordPress database connection for the current site.
		 *
		 * @var \wpdb $wpdb
		 */
		return new OptionRows( $wpdb );
	}

	// endregion
}

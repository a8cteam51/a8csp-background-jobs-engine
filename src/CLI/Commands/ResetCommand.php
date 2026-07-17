<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\CLI\Commands;

use A8C\SpecialProjects\BackgroundTasksEngine\CLI\Output\ResetOutput;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\CleanupIntents;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\LatestRunPointer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RowDeleteOutcome;

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
	 * Canonical persisted-state prefixes owned by their storage implementations.
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
		LatestRunPointer::OPTION_PREFIX,
		OverlapGuard::OPTION_PREFIX,
		OccurrenceLease::OPTION_PREFIX,
		CleanupIntents::OPTION_PREFIX,
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
		ActionDeliveries::START_HOOK,
		ActionDeliveries::CONTINUE_HOOK,
		ActionDeliveries::RUN_TASK_HOOK,
		ActionDeliveries::RUN_CHUNK_HOOK,
		ActionDeliveries::CLEANUP_HOOK,
		OccurrenceDelivery::SCHEDULE_HOOK,
	);

	// endregion

	// region METHODS

	/**
	 * Permanently deletes every engine-owned option row and pending backend action.
	 *
	 * This is a development reset tool, not an operational cancellation workflow. It destroys
	 * in-flight work irrecoverably, including the engine maintenance registration and occurrence;
	 * the maintenance schedule is recreated by the next boot synchronization.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the interactive confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp background-tasks reset
	 *     $ wp background-tasks reset --yes
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
			ResetOutput::error( $request['message'] );
			return;
		}

		ResetOutput::confirm( $assoc_args );

		$option_rows = self::runtime_option_rows();
		$scheduler   = Component::get_scheduler();
		if ( null === $scheduler ) {
			ResetOutput::error( 'The background tasks scheduler is unavailable; run the command after plugins_loaded.' );
			return;
		}

		$persisted_rows = self::persisted_rows( $option_rows );
		if ( \is_string( $persisted_rows ) ) {
			ResetOutput::error( $persisted_rows );
			return;
		}

		$clearance = $scheduler->unschedule_hooks( self::ACTION_HOOKS );
		if ( $clearance->is_failure() ) {
			ResetOutput::error( $clearance->error->message );
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
				ResetOutput::error( $message );
				return;
			}

			++$deleted;
		}

		ResetOutput::report( $deleted, $clearance->value );
	}

	/**
	 * Validates reset arguments without requiring WordPress or WP-CLI state.
	 *
	 * @internal Command decision seam.
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
				'message' => 'Reset accepts only --yes; use wp background-tasks reset [--yes].',
			);
		}

		return array( 'action' => 'reset' );
	}

	/**
	 * Returns the canonical persisted-state prefixes in deletion order.
	 *
	 * @internal Command coverage seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<string>
	 */
	public static function option_prefixes(): array {
		return self::OPTION_PREFIXES;
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

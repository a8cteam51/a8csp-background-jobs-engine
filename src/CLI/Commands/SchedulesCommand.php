<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\CLI\Commands;

use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output\Format;
use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output\ScheduleOutput;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;

\defined( 'ABSPATH' ) || exit;

/**
 * Inspects or removes persisted recurring schedule registrations.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class SchedulesCommand {
	// region METHODS

	/**
	 * Lists persisted registrations or removes every registration for one client owner.
	 *
	 * The `occurrence_visible` column reflects occurrence visibility on backends that are currently ready.
	 * `occurrence_visible: no` means no occurrence is visible there. A present but unavailable backend
	 * candidate is reported separately as dormant and does not establish absence.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Operation to perform: list or remove.
	 *
	 * [<owner>]
	 * : Client owner required by remove.
	 *
	 * [--owner=<owner>]
	 * : Show only registrations belonging to the exact owner.
	 *
	 * [--format=<format>]
	 * : Render list output as table, csv, json, count, or yaml. Defaults to table.
	 *
	 * [--yes]
	 * : Skip the interactive removal confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp background-jobs schedules list
	 *     $ wp background-jobs schedules list --owner=consumer-plugin --format=json
	 *     $ wp background-jobs schedules remove consumer-plugin
	 *     $ wp background-jobs schedules remove consumer-plugin --yes
	 *
	 * An overdue `next_due` with `occurrence_visible: no` means no occurrence is visible on currently-ready
	 * backends. A separately reported dormant backend candidate may retain an occurrence outside that
	 * ready set and is not absence. `occurrence_visible: yes` means a ready backend currently holds a
	 * pending or in-progress occurrence.
	 * A held lock identifies overlapping work, while rising `misfire_skips` or `overlap_skips` identifies
	 * grace-policy or overlap-policy drops.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<string>         $args       Positional command arguments.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 *
	 * @return  void
	 */
	public function schedules( array $args, array $assoc_args ): void {
		$request = self::request_from_args( $args, $assoc_args );
		if ( 'error' === $request['action'] ) {
			\WP_CLI::error( $request['message'] );
			return;
		}

		if ( 'list' === $request['action'] ) {
			$this->list_schedules( $request['owner'], $request['format'] );
			return;
		}

		ScheduleOutput::confirm_removal( $request['owner'], $assoc_args );
		$this->remove_schedules( $request['owner'] );
	}

	/**
	 * Validates schedule arguments without requiring WordPress or WP-CLI state.
	 *
	 * @internal Command decision seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<string>         $args       Positional command arguments.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 *
	 * @return  array{action: 'error', message: string}
	 *          |array{action: 'list', owner: string|null, format: string}
	 *          |array{action: 'remove', owner: string}
	 */
	public static function request_from_args( array $args, array $assoc_args ): array {
		if ( array() === $args ) {
			return array(
				'action'  => 'error',
				'message' => 'A schedule action is required; use list or remove <owner>.',
			);
		}

		if ( 'remove' === $args[0] ) {
			if (
				2 !== \count( $args )
				|| ! self::has_only_keys( $assoc_args, array( 'yes' ) )
				|| ( \array_key_exists( 'yes', $assoc_args ) && ! \is_bool( $assoc_args['yes'] ) )
			) {
				return array(
					'action'  => 'error',
					'message' => 'Schedule removal requires exactly one owner and accepts only --yes; use wp background-jobs schedules remove <owner> [--yes].',
				);
			}

			$owner = $args[1];
			try {
				JobIdentity::validate_owner( $owner );
			} catch ( \InvalidArgumentException ) {
				return array(
					'action'  => 'error',
					'message' => 'Schedule removal owner is invalid; pass a canonical client owner.',
				);
			}

			return array(
				'action' => 'remove',
				'owner'  => $owner,
			);
		}

		if ( 'list' !== $args[0] ) {
			return array(
				'action'  => 'error',
				'message' => \sprintf( 'Schedule action "%s" is invalid; use list or remove.', $args[0] ),
			);
		}

		if ( 1 !== \count( $args ) || ! self::has_only_keys( $assoc_args, array( 'owner', 'format' ) ) ) {
			return array(
				'action'  => 'error',
				'message' => 'Schedule list accepts only --owner and --format; use wp background-jobs schedules list [--owner=<owner>] [--format=<format>].',
			);
		}

		$owner = null;
		if ( \array_key_exists( 'owner', $assoc_args ) ) {
			$owner_argument = $assoc_args['owner'];
			if ( ! \is_string( $owner_argument ) ) {
				return array(
					'action'  => 'error',
					'message' => 'Schedule list owner is invalid; pass a value with --owner=<owner>.',
				);
			}

			$owner = $owner_argument;
			try {
				JobIdentity::validate_owner( $owner, true );
			} catch ( \InvalidArgumentException ) {
				return array(
					'action'  => 'error',
					'message' => 'Schedule list owner is invalid; pass a canonical owner with --owner=<owner>.',
				);
			}
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( ! Format::is_supported( $format ) ) {
			return array(
				'action'  => 'error',
				'message' => 'List format is invalid; use table, csv, json, count, or yaml.',
			);
		}

		return array(
			'action' => 'list',
			'owner'  => $owner,
			'format' => $format,
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Lists persisted schedules through the requested WP-CLI formatter.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $owner  Exact owner filter, or null for every owner.
	 * @param   string      $format WP-CLI output format.
	 *
	 * @return  void
	 */
	private function list_schedules( ?string $owner, string $format ): void {
		$inspection = Component::get_inspection();
		if ( null === $inspection ) {
			\WP_CLI::error( 'The background jobs inspection service is unavailable; run the command after plugins_loaded.' );
			return;
		}

		$snapshot = $inspection->schedules( $owner );
		if ( null === $snapshot ) {
			\WP_CLI::error( 'Schedule registrations are unavailable because the authoritative database read failed; resolve the database error and try again.' );
			return;
		}

		ScheduleOutput::render( $snapshot, $owner, $format );
	}

	/**
	 * Removes every persisted registration for one client owner through schedule convergence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Canonical client owner.
	 *
	 * @return  void
	 */
	private function remove_schedules( string $owner ): void {
		$inspection = Component::get_inspection();
		if ( null === $inspection ) {
			ScheduleOutput::error( 'The background jobs inspection service is unavailable; run the command after plugins_loaded.' );
			return;
		}

		$owner_exists = $inspection->schedule_owner_exists( $owner );
		if ( null === $owner_exists ) {
			ScheduleOutput::error( 'Schedule registrations are unavailable because the authoritative database read failed; resolve the database error and try again.' );
			return;
		}
		if ( ! $owner_exists ) {
			ScheduleOutput::error( \sprintf( 'No schedule registrations are persisted for owner "%s".', $owner ) );
			return;
		}

		$engine = Component::get_engine();
		if ( null === $engine ) {
			ScheduleOutput::error( 'The background jobs engine is unavailable; run the command after plugins_loaded.' );
			return;
		}
		$scheduler = Component::get_scheduler();
		if ( null === $scheduler ) {
			ScheduleOutput::error( 'The background jobs scheduler is unavailable; run the command after plugins_loaded.' );
			return;
		}
		$has_dormant_candidate = $scheduler->has_dormant_candidate();

		$result = $engine->schedules->sync( $owner, array() );
		if ( $result->is_failure() ) {
			ScheduleOutput::removal_error( $owner, $result->error->message );
			return;
		}

		ScheduleOutput::report_removal( $owner, $has_dormant_candidate );
	}

	/**
	 * Returns whether an argument map contains only the allowed keys.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<string, mixed> $args         Named arguments.
	 * @param   list<string>         $allowed_keys Allowed argument keys.
	 *
	 * @return  bool
	 */
	private static function has_only_keys( array $args, array $allowed_keys ): bool {
		return array() === \array_diff( \array_keys( $args ), $allowed_keys );
	}

	// endregion
}

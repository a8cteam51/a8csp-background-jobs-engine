<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\CLI\Commands;

use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output\Format;
use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output\ScheduleOutput;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
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
	 * Lists persisted registrations or removes every registration for one client scope.
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
	 * [<scope>]
	 * : Client scope required by remove.
	 *
	 * [--scope=<scope>]
	 * : Show only registrations belonging to the exact scope.
	 *
	 * [--format=<format>]
	 * : Render list output as table, csv, json, count, or yaml. Defaults to table.
	 *
	 * [--yes]
	 * : Skip the interactive removal confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp a8csp-bgje schedules list
	 *     $ wp a8csp-bgje schedules list --scope=consumer-plugin --format=json
	 *     $ wp a8csp-bgje schedules remove consumer-plugin
	 *     $ wp a8csp-bgje schedules remove consumer-plugin --yes
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
			$this->list_schedules( $request['scope'], $request['format'] );
			return;
		}

		ScheduleOutput::confirm_removal( $request['scope'], $assoc_args );
		$this->remove_schedules( $request['scope'] );
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
	 *          |array{action: 'list', scope: string|null, format: string}
	 *          |array{action: 'remove', scope: string}
	 */
	public static function request_from_args( array $args, array $assoc_args ): array {
		if ( array() === $args ) {
			return array(
				'action'  => 'error',
				'message' => 'A schedule action is required; use list or remove <scope>.',
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
					'message' => 'Schedule removal requires exactly one scope and accepts only --yes; use wp a8csp-bgje schedules remove <scope> [--yes].',
				);
			}

			$scope = $args[1];
			try {
				Identity::validate_scope( $scope );
			} catch ( \InvalidArgumentException ) {
				return array(
					'action'  => 'error',
					'message' => 'Schedule removal scope is invalid; pass a canonical client scope.',
				);
			}

			return array(
				'action' => 'remove',
				'scope'  => $scope,
			);
		}

		if ( 'list' !== $args[0] ) {
			return array(
				'action'  => 'error',
				'message' => \sprintf( 'Schedule action "%s" is invalid; use list or remove.', $args[0] ),
			);
		}

		if ( 1 !== \count( $args ) || ! self::has_only_keys( $assoc_args, array( 'scope', 'format' ) ) ) {
			return array(
				'action'  => 'error',
				'message' => 'Schedule list accepts only --scope and --format; use wp a8csp-bgje schedules list [--scope=<scope>] [--format=<format>].',
			);
		}

		$scope = null;
		if ( \array_key_exists( 'scope', $assoc_args ) ) {
			$scope_argument = $assoc_args['scope'];
			if ( ! \is_string( $scope_argument ) ) {
				return array(
					'action'  => 'error',
					'message' => 'Schedule list scope is invalid; pass a value with --scope=<scope>.',
				);
			}

			$scope = $scope_argument;
			try {
				Identity::validate_scope( $scope, true );
			} catch ( \InvalidArgumentException ) {
				return array(
					'action'  => 'error',
					'message' => 'Schedule list scope is invalid; pass a canonical scope with --scope=<scope>.',
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
			'scope'  => $scope,
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
	 * @param   string|null $scope  Exact scope filter, or null for every scope.
	 * @param   string      $format WP-CLI output format.
	 *
	 * @return  void
	 */
	private function list_schedules( ?string $scope, string $format ): void {
		$inspection = Component::get_inspection();
		if ( null === $inspection ) {
			\WP_CLI::error( 'The background jobs inspection service is unavailable; run the command after plugins_loaded.' );
			return;
		}

		$snapshot = $inspection->schedules( $scope );
		if ( null === $snapshot ) {
			\WP_CLI::error( 'Schedule registrations are unavailable because the authoritative database read failed; resolve the database error and try again.' );
			return;
		}

		ScheduleOutput::render( $snapshot, $scope, $format );
	}

	/**
	 * Removes every persisted registration for one client scope through schedule convergence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scope Canonical client scope.
	 *
	 * @return  void
	 */
	private function remove_schedules( string $scope ): void {
		$inspection = Component::get_inspection();
		if ( null === $inspection ) {
			ScheduleOutput::error( 'The background jobs inspection service is unavailable; run the command after plugins_loaded.' );
			return;
		}

		$scope_exists = $inspection->schedule_scope_exists( $scope );
		if ( null === $scope_exists ) {
			ScheduleOutput::error( 'Schedule registrations are unavailable because the authoritative database read failed; resolve the database error and try again.' );
			return;
		}
		if ( ! $scope_exists ) {
			ScheduleOutput::error( \sprintf( 'No schedule registrations are persisted for scope "%s".', $scope ) );
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

		$result = $engine->schedules->sync( $scope, array() );
		if ( $result->is_failure() ) {
			ScheduleOutput::removal_error( $scope, $result->error->message );
			return;
		}

		ScheduleOutput::report_removal( $scope, $has_dormant_candidate );
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

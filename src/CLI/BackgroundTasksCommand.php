<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\CLI;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Container;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\Inspection;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\WorkIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Inspects and manages the engine's background work from the command line.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @phpstan-type FailedRunEntry array{
 *     run_id: string,
 *     failed_at: int,
 *     start_args: array<array-key, mixed>,
 *     attempts: int,
 *     error: array{class: string|null, message: string}
 * }
 * @phpstan-type FailedRunRow array{
 *     owner: string,
 *     name: string,
 *     run_id: string,
 *     failed_at: string,
 *     attempts: int,
 *     error_class: string|null,
 *     error_message: string
 * }
 * @phpstan-import-type ScheduleEntry from Inspection
 * @phpstan-import-type LiveRunEntry from Inspection
 * @phpstan-import-type HistoryEntry from Inspection
 * @phpstan-type ScheduleRow array{
 *     owner: string,
 *     name: string,
 *     recurrence: int|string,
 *     next_due: string,
 *     last_fired: string,
 *     misfires: int,
 *     skips: int,
 *     scheduled: 'yes'|'no',
 *     lock: string
 * }
 * @phpstan-type LiveRunRow array{
 *     run_id: string,
 *     status: 'running',
 *     phase: 'executing'|'waiting',
 *     attempts: int,
 *     queue: int|'unknown'|'—',
 *     heartbeat: string
 * }
 * @phpstan-type HistoryRow array{
 *     run_id: string,
 *     outcome: 'completed'|'failed'|'cancelled'|'superseded'|'started',
 *     retained: 'failed store'|'—'
 * }
 */
final class BackgroundTasksCommand {
	// region FIELDS AND CONSTANTS

	/**
	 * Prefix for dynamically named failed-run options.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const FAILED_OPTION_PREFIX = 'a8csp_bgte_failed_';

	/**
	 * Fields exposed by the failed-run list.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<string>
	 */
	private const LIST_FIELDS = array(
		'owner',
		'name',
		'run_id',
		'failed_at',
		'attempts',
		'error_class',
		'error_message',
	);

	/**
	 * Supported failed-run list formats.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<string>
	 */
	private const LIST_FORMATS = array( 'table', 'csv', 'json', 'count', 'yaml' );

	/**
	 * Fields exposed by the persisted-schedule list.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<string>
	 */
	private const SCHEDULE_FIELDS = array(
		'owner',
		'name',
		'recurrence',
		'next_due',
		'last_fired',
		'misfires',
		'skips',
		'scheduled',
		'lock',
	);

	/**
	 * Fields exposed by the live-run section.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<string>
	 */
	private const LIVE_RUN_FIELDS = array(
		'run_id',
		'status',
		'phase',
		'attempts',
		'queue',
		'heartbeat',
	);

	/**
	 * Fields exposed by the recent-history section.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<string>
	 */
	private const HISTORY_FIELDS = array(
		'run_id',
		'outcome',
		'retained',
	);

	/**
	 * Explanation printed when union reads exclude a present scheduling backend.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const DORMANT_BACKEND_NOTE = 'note: a scheduling backend is not ready; dormant occurrences are not visible.';

	/**
	 * Stable elapsed-time unit boundaries used by relative inspection output.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const SECONDS_PER_MINUTE = 60;
	private const SECONDS_PER_HOUR   = 3_600;
	private const SECONDS_PER_DAY    = 86_400;

	// endregion

	// region METHODS

	/**
	 * Cancels one retained logical engine run.
	 *
	 * Pending backend delivery is cleared on a best-effort basis after the engine terminalizes the
	 * run. The arguments identify an engine background-work run, not an Action Scheduler action or
	 * hook, and cancellation does not remove an originating recurring schedule.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Composed `{owner}:{name}` task or batch identity.
	 *
	 * <run_id>
	 * : Retained engine-run identifier.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp background-tasks cancel consumer-plugin:email-digest 00000000000000000001-0000000000000000001
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<string>         $args       Positional command arguments.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 *
	 * @return  void
	 */
	public function cancel( array $args, array $assoc_args ): void {
		$request = self::cancel_request_from_args( $args, $assoc_args );
		if ( 'error' === $request['action'] ) {
			\WP_CLI::error( $request['message'] );
			return;
		}

		$this->cancel_run( $request['name'], $request['run_id'] );
	}

	/**
	 * Validates cancel command arguments without requiring WordPress or WP-CLI state.
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
	 *          |array{action: 'cancel', name: string, run_id: string}
	 */
	public static function cancel_request_from_args( array $args, array $assoc_args ): array {
		if ( 2 !== \count( $args ) || array() !== $assoc_args ) {
			return array(
				'action'  => 'error',
				'message' => 'Cancel requires exactly a name and run_id; use wp background-tasks cancel <name> <run_id>.',
			);
		}
		if ( null === WorkIdentity::parts( $args[0] ) ) {
			return array(
				'action'  => 'error',
				'message' => 'Cancel name is invalid; use a composed {owner}:{name} identity.',
			);
		}

		return array(
			'action' => 'cancel',
			'name'   => $args[0],
			'run_id' => $args[1],
		);
	}

	/**
	 * Lists persisted recurring schedule registrations and their observable runtime state.
	 *
	 * The `scheduled` column reflects backends that are currently ready; an occurrence on an
	 * unavailable backend is dormant and not shown.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Operation to perform: list.
	 *
	 * [--owner=<owner>]
	 * : Show only registrations belonging to the exact owner.
	 *
	 * [--format=<format>]
	 * : Render list output as table, csv, json, count, or yaml. Defaults to table.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp background-tasks schedules list
	 *     $ wp background-tasks schedules list --owner=consumer-plugin --format=json
	 *
	 * An overdue `next_due` with `scheduled: no` means the backend chain is absent; the next sync
	 * recreates it unless the consumer no longer declares the schedule. `scheduled: yes` means the
	 * ready backend has not delivered it yet. A held lock identifies overlapping work, while rising
	 * `misfires` or `skips` identifies grace-policy or overlap-policy drops.
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
		$request = self::schedules_request_from_args( $args, $assoc_args );
		if ( 'error' === $request['action'] ) {
			\WP_CLI::error( $request['message'] );
			return;
		}

		$this->list_schedules( $request['owner'], $request['format'] );
	}

	/**
	 * Validates schedule-list arguments without requiring WordPress or WP-CLI state.
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
	 */
	public static function schedules_request_from_args( array $args, array $assoc_args ): array {
		if ( array() === $args ) {
			return array(
				'action'  => 'error',
				'message' => 'A schedule action is required; use list.',
			);
		}

		if ( 'list' !== $args[0] ) {
			return array(
				'action'  => 'error',
				'message' => \sprintf( 'Schedule action "%s" is invalid; use list.', $args[0] ),
			);
		}

		if ( 1 !== \count( $args ) || ! self::has_only_keys( $assoc_args, array( 'owner', 'format' ) ) ) {
			return array(
				'action'  => 'error',
				'message' => 'Schedule list accepts only --owner and --format; use wp background-tasks schedules list [--owner=<owner>] [--format=<format>].',
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
				WorkIdentity::validate_owner( $owner, true );
			} catch ( \InvalidArgumentException ) {
				return array(
					'action'  => 'error',
					'message' => 'Schedule list owner is invalid; pass a canonical owner with --owner=<owner>.',
				);
			}
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( ! \is_string( $format ) || ! \in_array( $format, self::LIST_FORMATS, true ) ) {
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

	/**
	 * Lists live run state and bounded recent history for one background-work name.
	 *
	 * An executing phase that outlives the staleness window is reclaimed by maintenance; the stale
	 * heartbeat suffix identifies that condition.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Operation to perform: list.
	 *
	 * <name>
	 * : Composed `{owner}:{name}` task or batch identity.
	 *
	 * [--format=<format>]
	 * : Render list output as table, csv, json, count, or yaml. Defaults to table.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp background-tasks runs list consumer-plugin:email-digest
	 *     $ wp background-tasks runs list consumer-plugin:email-digest --format=json
	 *
	 * A waiting live run has a backend delivery or retry pending. An executing run is inside its
	 * handler, and a stale heartbeat means maintenance can reclaim the abandoned execution. The
	 * `recent history` section is bounded; `failed store` identifies failures still available to
	 * `wp background-tasks failed retry`.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<string>         $args       Positional command arguments.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 *
	 * @return  void
	 */
	public function runs( array $args, array $assoc_args ): void {
		$request = self::runs_request_from_args( $args, $assoc_args );
		if ( 'error' === $request['action'] ) {
			\WP_CLI::error( $request['message'] );
			return;
		}

		$this->list_runs( $request['name'], $request['format'] );
	}

	/**
	 * Validates runs-list arguments without requiring WordPress or WP-CLI state.
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
	 *          |array{action: 'list', name: string, format: string}
	 */
	public static function runs_request_from_args( array $args, array $assoc_args ): array {
		if ( array() === $args ) {
			return array(
				'action'  => 'error',
				'message' => 'A run action is required; use list <name>.',
			);
		}

		if ( 'list' !== $args[0] ) {
			return array(
				'action'  => 'error',
				'message' => \sprintf( 'Run action "%s" is invalid; use list.', $args[0] ),
			);
		}

		if ( 2 !== \count( $args ) || ! self::has_only_keys( $assoc_args, array( 'format' ) ) ) {
			return array(
				'action'  => 'error',
				'message' => 'Run list requires exactly one name and accepts only --format; use wp background-tasks runs list <name> [--format=<format>].',
			);
		}

		$name = $args[1];
		if ( null === WorkIdentity::parts( $name ) ) {
			return array(
				'action'  => 'error',
				'message' => 'Run name is invalid; use a composed {owner}:{name} identity.',
			);
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( ! \is_string( $format ) || ! \in_array( $format, self::LIST_FORMATS, true ) ) {
			return array(
				'action'  => 'error',
				'message' => 'List format is invalid; use table, csv, json, count, or yaml.',
			);
		}

		return array(
			'action' => 'list',
			'name'   => $name,
			'format' => $format,
		);
	}

	/**
	 * Lists, retries, or purges retained failed runs.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Operation to perform: list, retry, or purge.
	 *
	 * [<name>]
	 * : Composed `{owner}:{name}` task or batch identity. Required by retry and by a name-scoped purge.
	 *
	 * [<run_id>]
	 * : Retained failed-run identifier. Required by retry.
	 *
	 * [--all]
	 * : Purge every failed-run store. Valid only with purge and without a name.
	 *
	 * [--owner=<owner>]
	 * : Show only failed runs belonging to the exact owner. Valid only with list.
	 *
	 * [--format=<format>]
	 * : Render list output in the selected format. Defaults to table.
	 * ---
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - count
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp background-tasks failed list
	 *     $ wp background-tasks failed list --owner=consumer-plugin --format=json
	 *     $ wp background-tasks failed retry consumer-plugin:email-digest 00000000000000000001-0000000000000000001
	 *     $ wp background-tasks failed purge consumer-plugin:email-digest
	 *     $ wp background-tasks failed purge --all
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<string>         $args       Positional command arguments.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 *
	 * @return  void
	 */
	public function failed( array $args, array $assoc_args ): void {
		$request = self::request_from_args( $args, $assoc_args );
		if ( 'error' === $request['action'] ) {
			\WP_CLI::error( $request['message'] );
			return;
		}

		switch ( $request['action'] ) {
			case 'list':
				$this->list_failed_runs( $request['owner'], $request['format'] );
				break;
			case 'retry':
				$this->retry_failed_run( $request['name'], $request['run_id'] );
				break;
			case 'purge':
				$this->purge_failed_runs( $request['name'] );
				break;
		}
	}

	/**
	 * Validates failed-run command arguments without requiring WordPress or WP-CLI state.
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
	 *          |array{action: 'retry', name: string, run_id: string}
	 *          |array{action: 'purge', name: string|null}
	 */
	public static function request_from_args( array $args, array $assoc_args ): array {
		if ( array() === $args ) {
			return array(
				'action'  => 'error',
				'message' => 'A failed-run action is required; use list, retry <name> <run_id>, purge <name>, or purge --all.',
			);
		}

		$action = $args[0];
		switch ( $action ) {
			case 'list':
				if ( 1 !== \count( $args ) || ! self::has_only_keys( $assoc_args, array( 'owner', 'format' ) ) ) {
					return array(
						'action'  => 'error',
						'message' => 'List accepts only --owner and --format; use wp background-tasks failed list [--owner=<owner>] [--format=<format>].',
					);
				}

				$owner = null;
				if ( \array_key_exists( 'owner', $assoc_args ) ) {
					$owner_argument = $assoc_args['owner'];
					if ( ! \is_string( $owner_argument ) ) {
						return array(
							'action'  => 'error',
							'message' => 'List owner is invalid; pass a value with --owner=<owner>.',
						);
					}

					$owner = $owner_argument;
					try {
						WorkIdentity::validate_owner( $owner, true );
					} catch ( \InvalidArgumentException ) {
						return array(
							'action'  => 'error',
							'message' => 'List owner is invalid; pass a canonical owner with --owner=<owner>.',
						);
					}
				}

				// WP-CLI injects documented YAML defaults into every action, so the fallback stays code-only.
				$format = $assoc_args['format'] ?? 'table';
				if ( ! \is_string( $format ) || ! \in_array( $format, self::LIST_FORMATS, true ) ) {
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
			case 'retry':
				if ( 3 !== \count( $args ) || array() !== $assoc_args ) {
					return array(
						'action'  => 'error',
						'message' => 'Retry requires exactly a name and run_id; use wp background-tasks failed retry <name> <run_id>.',
					);
				}
				if ( null === WorkIdentity::parts( $args[1] ) ) {
					return array(
						'action'  => 'error',
						'message' => 'Retry name is invalid; use a composed {owner}:{name} identity.',
					);
				}

				return array(
					'action' => 'retry',
					'name'   => $args[1],
					'run_id' => $args[2],
				);
			case 'purge':
				if ( ! self::has_only_keys( $assoc_args, array( 'all' ) ) ) {
					return array(
						'action'  => 'error',
						'message' => 'Purge accepts only --all; use wp background-tasks failed purge <name> or purge --all.',
					);
				}

				if ( 1 === \count( $args ) && true === ( $assoc_args['all'] ?? null ) ) {
					return array(
						'action' => 'purge',
						'name'   => null,
					);
				}

				if ( 2 === \count( $args ) && ! \array_key_exists( 'all', $assoc_args ) ) {
					$name = $args[1];
					if ( null === WorkIdentity::parts( $name ) ) {
						return array(
							'action'  => 'error',
							'message' => 'Purge name is invalid; use a composed {owner}:{name} identity.',
						);
					}

					return array(
						'action' => 'purge',
						'name'   => $name,
					);
				}

				return array(
					'action'  => 'error',
					'message' => 'Purge requires exactly one name or --all; use wp background-tasks failed purge <name> or purge --all.',
				);
			default:
				return array(
					'action'  => 'error',
					'message' => \sprintf(
						'Failed-run action "%s" is invalid; use list, retry, or purge.',
						$action
					),
				);
		}
	}

	/**
	 * Shapes and orders failed-run entries without requiring WordPress or WP-CLI state.
	 *
	 * @internal Command formatting seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<string, list<FailedRunEntry>> $entries_by_name
	 *
	 * @param   array       $entries_by_name Failed runs keyed by composed task or batch identity.
	 * @param   string|null $owner           Exact owner filter, or null for every owner.
	 *
	 * @phpstan-return list<FailedRunRow>
	 *
	 * @return  array
	 */
	public static function rows_from_entries( array $entries_by_name, ?string $owner = null ): array {
		\ksort( $entries_by_name, \SORT_STRING );

		$rows = array();
		foreach ( $entries_by_name as $name => $entries ) {
			$parts = WorkIdentity::parts( $name );
			if ( null === $parts || ( null !== $owner && $owner !== $parts[0] ) ) {
				continue;
			}

			\usort(
				$entries,
				static function ( array $left, array $right ): int {
					$failed_at_order = $left['failed_at'] <=> $right['failed_at'];
					return 0 !== $failed_at_order ? $failed_at_order : $left['run_id'] <=> $right['run_id'];
				}
			);

			foreach ( $entries as $entry ) {
				$rows[] = array(
					'owner'         => $parts[0],
					'name'          => $name,
					'run_id'        => $entry['run_id'],
					'failed_at'     => \gmdate( \DATE_ATOM, $entry['failed_at'] ),
					'attempts'      => $entry['attempts'],
					'error_class'   => $entry['error']['class'],
					'error_message' => $entry['error']['message'],
				);
			}
		}

		return $rows;
	}

	/**
	 * Extracts valid composed identities from discovered failed-run option names.
	 *
	 * @internal Command discovery seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $option_names Discovered option names.
	 *
	 * @return  list<string>
	 */
	public static function names_from_option_names( array $option_names ): array {
		$names = array();
		foreach ( $option_names as $option_name ) {
			if ( ! \is_string( $option_name ) || ! \str_starts_with( $option_name, self::FAILED_OPTION_PREFIX ) ) {
				continue;
			}

			$name = \substr( $option_name, \strlen( self::FAILED_OPTION_PREFIX ) );
			if ( null !== WorkIdentity::parts( $name ) ) {
				$names[ $name ] = true;
			}
		}

		$stable_names = \array_keys( $names );
		\sort( $stable_names, \SORT_STRING );

		return $stable_names;
	}

	/**
	 * Shapes schedule inspection entries into their exact public columns.
	 *
	 * @internal Command formatting seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<ScheduleEntry> $entries
	 *
	 * @param   array $entries     Validated schedule inspection entries.
	 * @param   int   $observed_at Inspection timestamp.
	 *
	 * @phpstan-return list<ScheduleRow>
	 *
	 * @return  array
	 */
	public static function schedule_rows_from_entries( array $entries, int $observed_at ): array {
		\usort(
			$entries,
			static function ( array $left, array $right ): int {
				$owner_order = $left['owner'] <=> $right['owner'];
				return 0 !== $owner_order ? $owner_order : $left['name'] <=> $right['name'];
			}
		);

		$rows = array();
		foreach ( $entries as $entry ) {
			$rows[] = array(
				'owner'      => $entry['owner'],
				'name'       => $entry['name'],
				'recurrence' => $entry['recurrence'] ?? 'unknown (not declared this request)',
				'next_due'   => self::schedule_due_label( $entry['next_due'], $observed_at ),
				'last_fired' => null === $entry['last_fired']
					? 'never'
					: \gmdate( \DATE_ATOM, $entry['last_fired'] ),
				'misfires'   => $entry['misfires'],
				'skips'      => $entry['skips'],
				'scheduled'  => $entry['scheduled'] ? 'yes' : 'no',
				'lock'       => self::schedule_lock_label( $entry['lock'] ),
			);
		}

		return $rows;
	}

	/**
	 * Shapes live run entries into their exact public columns.
	 *
	 * @internal Command formatting seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<LiveRunEntry> $entries
	 *
	 * @param   array $entries     Validated live-run entries.
	 * @param   int   $observed_at Inspection timestamp.
	 *
	 * @phpstan-return list<LiveRunRow>
	 *
	 * @return  array
	 */
	public static function live_run_rows_from_entries( array $entries, int $observed_at ): array {
		$rows = array();
		foreach ( $entries as $entry ) {
			$rows[] = array(
				'run_id'    => $entry['run_id'],
				'status'    => 'running',
				'phase'     => $entry['executing'] ? 'executing' : 'waiting',
				'attempts'  => $entry['attempts'],
				'queue'     => 'task' === $entry['kind']
					? '—'
					: ( $entry['queue_depth'] ?? 'unknown' ),
				'heartbeat' => self::heartbeat_label( $entry['heartbeat_at'], $observed_at, $entry['stale'] ),
			);
		}

		return $rows;
	}

	/**
	 * Shapes bounded history entries into their exact public columns.
	 *
	 * @internal Command formatting seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<HistoryEntry> $entries
	 *
	 * @param   array $entries Validated recent-history entries.
	 *
	 * @phpstan-return list<HistoryRow>
	 *
	 * @return  array
	 */
	public static function history_rows_from_entries( array $entries ): array {
		$rows = array();
		foreach ( $entries as $entry ) {
			$rows[] = array(
				'run_id'   => $entry['run_id'],
				'outcome'  => $entry['outcome'],
				'retained' => $entry['retained'] ? 'failed store' : '—',
			);
		}

		return $rows;
	}

	/**
	 * Returns the dormant-backend footer only when union reads exclude a present candidate.
	 *
	 * @internal Command honesty seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   bool $has_dormant_candidate Whether a backend is present but not ready.
	 *
	 * @return  string|null
	 */
	public static function dormant_backend_note( bool $has_dormant_candidate ): ?string {
		return $has_dormant_candidate ? self::DORMANT_BACKEND_NOTE : null;
	}

	/**
	 * Returns the corrective CLI error for a compromised live-run listing.
	 *
	 * @internal Command honesty seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'enumeration_failed'|'read_failed'|null $error Inspection failure state.
	 *
	 * @return  string|null
	 */
	public static function live_run_error_message( ?string $error ): ?string {
		return match ( $error ) {
			'enumeration_failed' => 'Live-run state is unknown (run enumeration failed); resolve the database error and try again.',
			'read_failed'        => 'Live-run state is unknown (run read failed); resolve the database error and try again.',
			default              => null,
		};
	}

	/**
	 * Returns the warning carried by every output format when live-run inspection is truncated.
	 *
	 * @internal Command honesty seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $scanned     Number of matching run rows inspected.
	 * @param   int $uninspected Number of matching run rows excluded by the cap.
	 *
	 * @return  string|null
	 */
	public static function live_run_truncation_message( int $scanned, int $uninspected ): ?string {
		if ( 1 > $uninspected ) {
			return null;
		}

		return \sprintf(
			'Showing first %1$d matching run rows; %2$d more %3$s not inspected.',
			$scanned,
			$uninspected,
			1 === $uninspected ? 'was' : 'were'
		);
	}

	/**
	 * Formats one persisted due timestamp as UTC plus its schedule-relative state.
	 *
	 * @internal Command time seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $timestamp   Persisted due timestamp.
	 * @param   int $observed_at Inspection timestamp.
	 *
	 * @return  string
	 */
	public static function schedule_due_label( int $timestamp, int $observed_at ): string {
		if ( $timestamp > $observed_at ) {
			$relative = 'in ' . self::duration_label( self::distance( $timestamp, $observed_at ) );
		} elseif ( $timestamp === $observed_at ) {
			$relative = 'due now';
		} else {
			$relative = 'overdue ' . self::duration_label( self::distance( $observed_at, $timestamp ) );
		}

		return \sprintf( '%1$s (%2$s)', \gmdate( \DATE_ATOM, $timestamp ), $relative );
	}

	/**
	 * Formats one live heartbeat as a non-negative relative age and optional stale signal.
	 *
	 * @internal Command time seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int  $timestamp   Persisted heartbeat timestamp.
	 * @param   int  $observed_at Inspection timestamp.
	 * @param   bool $stale       Whether the effective window is strictly exceeded.
	 *
	 * @return  string
	 */
	public static function heartbeat_label( int $timestamp, int $observed_at, bool $stale ): string {
		$age   = $timestamp > $observed_at ? 0 : self::distance( $observed_at, $timestamp );
		$label = self::duration_label( $age ) . ' ago';

		return $stale ? $label . ' (stale)' : $label;
	}

	/**
	 * Formats one complete schedule lock snapshot.
	 *
	 * @internal Command honesty seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{state: 'free'|'invalid'|'not_declared'|'overlap_allowed'|'read_failed'}
	 *                |array{state: 'held', run_id: string, stale: bool} $lock
	 *
	 * @param   array $lock Complete discriminated lock state.
	 *
	 * @return  string
	 */
	public static function schedule_lock_label( array $lock ): string {
		if ( 'held' !== $lock['state'] ) {
			return match ( $lock['state'] ) {
				'free'            => 'free',
				'invalid'         => 'unknown (invalid lock row)',
				'not_declared'    => 'unknown (not declared this request)',
				'overlap_allowed' => 'not blocking (overlap allowed)',
				'read_failed'     => 'unknown (lock read failed)',
			};
		}

		$label = 'held by ' . $lock['run_id'];

		return $lock['stale'] ? $label . ' (stale)' : $label;
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
		$inspection = Container::get_inspection();
		if ( null === $inspection ) {
			\WP_CLI::error( 'The background tasks inspection service is unavailable; run the command after plugins_loaded.' );
			return;
		}

		$snapshot = $inspection->schedules( $owner );
		if ( null === $snapshot ) {
			\WP_CLI::error( 'Schedule registrations are unavailable because the authoritative database read failed; resolve the database error and try again.' );
			return;
		}

		$rows = self::schedule_rows_from_entries( $snapshot['entries'], $snapshot['observed_at'] );
		if ( array() === $rows && 'table' === $format ) {
			\WP_CLI::line(
				null === $owner
					? 'No schedule registrations are persisted.'
					: \sprintf( 'No schedule registrations are persisted for owner "%s".', $owner )
			);
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, self::SCHEDULE_FIELDS );
		if ( 'table' !== $format ) {
			return;
		}

		$note = self::dormant_backend_note( $snapshot['dormant_candidate'] );
		if ( null !== $note ) {
			\WP_CLI::line( $note );
		}
	}

	/**
	 * Lists live runs and recent history through the format-specific public contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Composed task or batch identity.
	 * @param   string $format WP-CLI output format.
	 *
	 * @return  void
	 */
	private function list_runs( string $name, string $format ): void {
		$inspection = Container::get_inspection();
		if ( null === $inspection ) {
			\WP_CLI::error( 'The background tasks inspection service is unavailable; run the command after plugins_loaded.' );
			return;
		}

		$snapshot      = $inspection->runs( $name );
		$error_message = self::live_run_error_message( $snapshot['live_error'] );
		if ( null !== $error_message ) {
			\WP_CLI::error( $error_message );
			return;
		}

		$live_rows           = self::live_run_rows_from_entries( $snapshot['live'], $snapshot['observed_at'] );
		$history_unavailable = null === $snapshot['history'];
		$history_rows        = $history_unavailable ? array() : self::history_rows_from_entries( $snapshot['history'] );
		$truncation          = self::live_run_truncation_message(
			$snapshot['live_scanned'],
			$snapshot['live_uninspected']
		);
		if (
			array() === $live_rows
			&& array() === $history_rows
			&& 'table' === $format
			&& ! $history_unavailable
			&& null === $truncation
		) {
			\WP_CLI::line( \sprintf( 'No live runs or history are retained for "%s".', $name ) );
			return;
		}

		switch ( $format ) {
			case 'table':
				if ( array() !== $live_rows ) {
					\WP_CLI::line( 'live runs' );
					\WP_CLI\Utils\format_items( 'table', $live_rows, self::LIVE_RUN_FIELDS );
				}
				if ( array() !== $history_rows ) {
					\WP_CLI::line( 'recent history' );
					\WP_CLI\Utils\format_items( 'table', $history_rows, self::HISTORY_FIELDS );
				}
				break;
			case 'csv':
			case 'count':
				\WP_CLI\Utils\format_items( $format, $live_rows, self::LIVE_RUN_FIELDS );
				break;
			case 'json':
			case 'yaml':
				\WP_CLI::print_value(
					\array_merge( $live_rows, $history_rows ),
					array( 'format' => $format )
				);
				break;
		}

		if ( $history_unavailable ) {
			\WP_CLI::warning( 'Recent run history is unavailable because an authoritative database read failed.' );
		}
		if ( null !== $truncation ) {
			\WP_CLI::warning( $truncation );
		}
	}

	/**
	 * Formats a non-negative duration at stable second, minute, hour, and day boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $seconds Non-negative duration in seconds.
	 *
	 * @return  string
	 */
	private static function duration_label( int $seconds ): string {
		if ( self::SECONDS_PER_MINUTE > $seconds ) {
			return $seconds . 's';
		}
		if ( self::SECONDS_PER_HOUR > $seconds ) {
			return \intdiv( $seconds, self::SECONDS_PER_MINUTE ) . 'm';
		}
		if ( self::SECONDS_PER_DAY > $seconds ) {
			return \intdiv( $seconds, self::SECONDS_PER_HOUR ) . 'h';
		}

		return \intdiv( $seconds, self::SECONDS_PER_DAY ) . 'd';
	}

	/**
	 * Returns the saturating distance between ordered integer timestamps.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $larger  Greater timestamp.
	 * @param   int $smaller Lesser timestamp.
	 *
	 * @return  int
	 */
	private static function distance( int $larger, int $smaller ): int {
		if ( 0 <= $smaller || $larger <= \PHP_INT_MAX + $smaller ) {
			return $larger - $smaller;
		}

		return \PHP_INT_MAX;
	}

	/**
	 * Delegates cancellation to the internal engine facade and reports its result.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Composed task or batch identity.
	 * @param   string $run_id Retained run identifier.
	 *
	 * @return  void
	 */
	private function cancel_run( string $name, string $run_id ): void {
		$engine = Container::get_engine();
		if ( null === $engine ) {
			\WP_CLI::error( 'The background tasks engine is unavailable; run the command after plugins_loaded.' );
			return;
		}

		$result = $engine->cancel( $name, $run_id );
		if ( $result->is_failure() ) {
			\WP_CLI::error( $result->error->message );
			return;
		}

		\WP_CLI::success(
			\sprintf(
				'Cancelled run %1$s of "%2$s".',
				$run_id,
				$name
			)
		);
	}

	/**
	 * Lists every retained failed run through the requested WP-CLI formatter.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $owner  Exact owner filter, or null for every owner.
	 * @param   string      $format WP-CLI output format.
	 *
	 * @return  void
	 */
	private function list_failed_runs( ?string $owner, string $format ): void {
		$names = $this->failed_run_names();
		if ( null === $names ) {
			\WP_CLI::error( 'The database check for failed-run stores failed; resolve the database error and try again.' );
			return;
		}
		if ( null !== $owner ) {
			$names = \array_values(
				\array_filter(
					$names,
					static function ( string $name ) use ( $owner ): bool {
						$parts = WorkIdentity::parts( $name );

						return null !== $parts && $owner === $parts[0];
					}
				)
			);
		}

		global $wpdb;

		/**
		 * WordPress database connection for the current site.
		 *
		 * @var \wpdb $wpdb
		 */
		$option_rows     = new OptionRows( $wpdb );
		$entries_by_name = array();
		foreach ( $names as $name ) {
			$entries = ( new FailedRunStore( $name, $option_rows ) )->all();
			if ( $entries->is_failure() ) {
				\WP_CLI::error(
					\sprintf(
						'Failed runs for "%s" are unavailable because the authoritative database read failed; resolve the database error and try again.',
						$name
					)
				);
				return;
			}

			$entries_by_name[ $name ] = $entries->value;
		}

		$rows = self::rows_from_entries( $entries_by_name, $owner );
		if ( array() === $rows && 'table' === $format ) {
			\WP_CLI::line( 'No failed runs are retained.' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, self::LIST_FIELDS );
	}

	/**
	 * Delegates one retry to the internal engine facade and reports its result.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Composed task or batch identity.
	 * @param   string $run_id Retained failed-run identifier.
	 *
	 * @return  void
	 */
	private function retry_failed_run( string $name, string $run_id ): void {
		$engine = Container::get_engine();
		if ( null === $engine ) {
			\WP_CLI::error( 'The background tasks engine is unavailable; run the command after plugins_loaded.' );
			return;
		}

		$result = $engine->retry_failed( $name, $run_id );
		if ( $result->is_failure() ) {
			\WP_CLI::error( $result->error->message );
			return;
		}

		\WP_CLI::success(
			\sprintf(
				'Retried failed run "%1$s" for "%2$s" as new run "%3$s".',
				$run_id,
				$name,
				$result->value
			)
		);
	}

	/**
	 * Purges one failed-run store or every discovered store.
	 *
	 * A null name selects every store discovered under the failed-run option prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $name Composed task or batch identity, or null for every identity.
	 *
	 * @return  void
	 */
	private function purge_failed_runs( ?string $name ): void {
		$names = null === $name ? $this->failed_run_names() : array( $name );
		if ( null === $names ) {
			\WP_CLI::error( 'The database check for failed-run stores failed; resolve the database error and try again.' );
			return;
		}

		global $wpdb;

		/**
		 * WordPress database connection for the current site.
		 *
		 * @var \wpdb $wpdb
		 */
		$rows  = new OptionRows( $wpdb );
		$count = 0;
		foreach ( $names as $store_name ) {
			$purged = ( new FailedRunStore( $store_name, $rows ) )->purge();
			if ( null === $purged ) {
				\WP_CLI::error(
					\sprintf(
						'Failed-run store "%s" could not be purged; resolve its database error or concurrent writes and try again.',
						$store_name
					)
				);
				return;
			}

			$count += $purged;
		}

		$scope = null === $name ? 'across all names' : \sprintf( 'for "%s"', $name );
		\WP_CLI::success(
			\sprintf(
				'Purged %1$d failed run%2$s %3$s.',
				$count,
				1 === $count ? '' : 's',
				$scope
			)
		);
	}

	/**
	 * Discovers composed identities from dynamically named failed-run option rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<string>|null Null when the database query fails.
	 */
	private function failed_run_names(): ?array {
		global $wpdb;

		/**
		 * WordPress database connection for the current site.
		 *
		 * @var \wpdb $wpdb
		 */
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s ORDER BY `option_name` ASC',
				$wpdb->options,
				$wpdb->esc_like( self::FAILED_OPTION_PREFIX ) . '%'
			)
		);
		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		return self::names_from_option_names( $option_names );
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

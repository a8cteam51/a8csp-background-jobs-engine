<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\CLI\Commands;

use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output\FailedRunOutput;
use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output\Format;
use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output\RunOutput;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Logging\HookLogger;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;

\defined( 'ABSPATH' ) || exit;

/**
 * Inspects and manages retained background-work runs.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class RunsCommand {
	// region METHODS

	/**
	 * Lists or cancels retained run state for one background-work identity.
	 *
	 * An executing phase that outlives the staleness window is reclaimed by maintenance; the stale
	 * heartbeat suffix identifies that condition.
	 *
	 * Table, JSON, and YAML include live state plus bounded recent history. CSV includes live state
	 * only, and count is the number of live runs. Every format reports omitted unreadable rows on
	 * STDERR without adding diagnostic prose to rendered data.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Operation to perform: list or cancel.
	 *
	 * <identity>
	 * : Composed `{owner}:{name}` job or chunked job identity.
	 *
	 * [<run_id>]
	 * : Retained engine-run identifier. Required by cancel.
	 *
	 * [--format=<format>]
	 * : Render list output as table, csv, json, count, or yaml. Valid only with list and defaults to table.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp background-jobs runs list consumer-plugin:email-digest
	 *     $ wp background-jobs runs list consumer-plugin:email-digest --format=json
	 *     $ wp background-jobs runs cancel consumer-plugin:email-digest 00000000000000000001-0000000000000000001
	 *
	 * A waiting live run has a backend delivery or retry pending. An executing run has an admitted
	 * lifecycle action in progress, whether engine orchestration or a client callback, and a stale
	 * heartbeat means maintenance can reclaim the abandoned execution. The `recent history` section
	 * is bounded; `failed store` identifies failures still available to
	 * `wp background-jobs failed-runs retry`.
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

		if ( 'list' === $request['action'] ) {
			$this->list_runs( $request['name'], $request['format'] );
			return;
		}

		$this->cancel_run( $request['name'], $request['run_id'] );
	}

	/**
	 * Validates runs-subcommand arguments without requiring WordPress or WP-CLI state.
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
	 *          |array{action: 'cancel', name: string, run_id: RunId}
	 */
	public static function runs_request_from_args( array $args, array $assoc_args ): array {
		if ( array() === $args ) {
			return array(
				'action'  => 'error',
				'message' => 'A run action is required; use list <identity> or cancel <identity> <run_id>.',
			);
		}

		$action = $args[0];
		if ( 'list' !== $action && 'cancel' !== $action ) {
			return array(
				'action'  => 'error',
				'message' => \sprintf( 'Run action "%s" is invalid; use list or cancel.', $action ),
			);
		}

		if ( 'cancel' === $action ) {
			if ( 3 !== \count( $args ) || array() !== $assoc_args ) {
				return array(
					'action'  => 'error',
					'message' => 'Cancel requires exactly an identity and run_id; use wp background-jobs runs cancel <identity> <run_id>.',
				);
			}
			if ( null === JobIdentity::parts( $args[1] ) ) {
				return array(
					'action'  => 'error',
					'message' => 'Cancel identity is invalid; use a composed {owner}:{name} identity.',
				);
			}
			$run_id = RunId::try_from( $args[2] );
			if ( null === $run_id ) {
				return array(
					'action'  => 'error',
					'message' => 'Run identifier is malformed; pass a run ID the engine returned.',
				);
			}

			return array(
				'action' => 'cancel',
				'name'   => $args[1],
				'run_id' => $run_id,
			);
		}

		if ( 2 !== \count( $args ) || ! self::has_only_keys( $assoc_args, array( 'format' ) ) ) {
			return array(
				'action'  => 'error',
				'message' => 'Run list requires exactly one identity and accepts only --format; use wp background-jobs runs list <identity> [--format=<format>].',
			);
		}

		$name = $args[1];
		if ( null === JobIdentity::parts( $name ) ) {
			return array(
				'action'  => 'error',
				'message' => 'Run identity is invalid; use a composed {owner}:{name} identity.',
			);
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
	 * [<identity>]
	 * : Composed `{owner}:{name}` job or chunked job identity. Required by retry and by an identity-scoped purge.
	 *
	 * [<run_id>]
	 * : Retained failed-run identifier. Required by retry.
	 *
	 * [--all]
	 * : Purge every failed-run store. Valid only with purge and without an identity.
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
	 *     $ wp background-jobs failed-runs list
	 *     $ wp background-jobs failed-runs list --owner=consumer-plugin --format=json
	 *     $ wp background-jobs failed-runs retry consumer-plugin:email-digest 00000000000000000001-0000000000000000001
	 *     $ wp background-jobs failed-runs purge consumer-plugin:email-digest
	 *     $ wp background-jobs failed-runs purge --all
	 *
	 * List output excludes unreadable entries or whole option rows and reports one count warning on
	 * STDERR for every format.
	 *
	 * @subcommand failed-runs
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<string>         $args       Positional command arguments.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 *
	 * @return  void
	 */
	public function failed_runs( array $args, array $assoc_args ): void {
		$request = self::failed_runs_request_from_args( $args, $assoc_args );
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
	 *          |array{action: 'retry', name: string, run_id: RunId}
	 *          |array{action: 'purge', name: string|null}
	 */
	public static function failed_runs_request_from_args( array $args, array $assoc_args ): array {
		if ( array() === $args ) {
			return array(
				'action'  => 'error',
				'message' => 'A failed-run action is required; use list, retry <identity> <run_id>, purge <identity>, or purge --all.',
			);
		}

		$action = $args[0];
		switch ( $action ) {
			case 'list':
				if ( 1 !== \count( $args ) || ! self::has_only_keys( $assoc_args, array( 'owner', 'format' ) ) ) {
					return array(
						'action'  => 'error',
						'message' => 'List accepts only --owner and --format; use wp background-jobs failed-runs list [--owner=<owner>] [--format=<format>].',
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
						JobIdentity::validate_owner( $owner, true );
					} catch ( \InvalidArgumentException ) {
						return array(
							'action'  => 'error',
							'message' => 'List owner is invalid; pass a canonical owner with --owner=<owner>.',
						);
					}
				}

				// WP-CLI injects documented YAML defaults into every action, so the fallback stays code-only.
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
			case 'retry':
				if ( 3 !== \count( $args ) || array() !== $assoc_args ) {
					return array(
						'action'  => 'error',
						'message' => 'Retry requires exactly an identity and run_id; use wp background-jobs failed-runs retry <identity> <run_id>.',
					);
				}
				if ( null === JobIdentity::parts( $args[1] ) ) {
					return array(
						'action'  => 'error',
						'message' => 'Retry identity is invalid; use a composed {owner}:{name} identity.',
					);
				}
				$run_id = RunId::try_from( $args[2] );
				if ( null === $run_id ) {
					return array(
						'action'  => 'error',
						'message' => 'Run identifier is malformed; pass a run ID the engine returned.',
					);
				}

				return array(
					'action' => 'retry',
					'name'   => $args[1],
					'run_id' => $run_id,
				);
			case 'purge':
				if ( ! self::has_only_keys( $assoc_args, array( 'all' ) ) ) {
					return array(
						'action'  => 'error',
						'message' => 'Purge accepts only --all; use wp background-jobs failed-runs purge <identity> or purge --all.',
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
					if ( null === JobIdentity::parts( $name ) ) {
						return array(
							'action'  => 'error',
							'message' => 'Purge identity is invalid; use a composed {owner}:{name} identity.',
						);
					}

					return array(
						'action' => 'purge',
						'name'   => $name,
					);
				}

				return array(
					'action'  => 'error',
					'message' => 'Purge requires exactly one identity or --all; use wp background-jobs failed-runs purge <identity> or purge --all.',
				);
			default:
				return array(
					'action'  => 'error',
					'message' => \sprintf( 'Failed-run action "%s" is invalid; use list, retry, or purge.', $action ),
				);
		}
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
			if ( ! \is_string( $option_name ) || ! \str_starts_with( $option_name, FailedRunStore::OPTION_PREFIX ) ) {
				continue;
			}

			$name = \substr( $option_name, \strlen( FailedRunStore::OPTION_PREFIX ) );
			if ( null !== JobIdentity::parts( $name ) ) {
				$names[ $name ] = true;
			}
		}

		$stable_names = \array_keys( $names );
		\sort( $stable_names, \SORT_STRING );

		return $stable_names;
	}

	// endregion

	// region HELPERS

	/**
	 * Delegates cancellation to the internal engine facade and reports its result.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Composed job or chunked job identity.
	 * @param   RunId  $run_id Retained run identifier.
	 *
	 * @return  void
	 */
	private function cancel_run( string $name, RunId $run_id ): void {
		$engine = Component::get_engine();
		if ( null === $engine ) {
			\WP_CLI::error( 'The background jobs engine is unavailable; run the command after plugins_loaded.' );
			return;
		}

		$result = $engine->cancel( $name, (string) $run_id );
		if ( $result->is_failure() ) {
			\WP_CLI::error( $result->error->message );
			return;
		}

		\WP_CLI::success( \sprintf( 'Cancelled run %1$s of "%2$s".', $run_id, $name ) );
	}

	/**
	 * Lists live runs and recent history through the format-specific public contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Composed job or chunked job identity.
	 * @param   string $format WP-CLI output format.
	 *
	 * @return  void
	 */
	private function list_runs( string $name, string $format ): void {
		$inspection = Component::get_inspection();
		if ( null === $inspection ) {
			\WP_CLI::error( 'The background jobs inspection service is unavailable; run the command after plugins_loaded.' );
			return;
		}

		RunOutput::render( $inspection->runs( $name ), $name, $format );
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
						$parts = JobIdentity::parts( $name );

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
		$option_rows        = new OptionRows( $wpdb );
		$logger             = new HookLogger();
		$entries_by_name    = array();
		$unreadable_entries = 0;
		$unreadable_rows    = 0;
		foreach ( $names as $name ) {
			$inspection = new FailedRunStore( $name, $option_rows, $logger )->inspect();
			if ( $inspection->is_failure() ) {
				\WP_CLI::error( \sprintf( 'Failed runs for "%s" are unavailable because the authoritative database read failed; resolve the database error and try again.', $name ) );
				return;
			}

			$entries_by_name[ $name ] = $inspection->value['entries'];
			$unreadable_entries      += $inspection->value['unreadable'];
			if ( $inspection->value['row_unreadable'] ) {
				++$unreadable_rows;
			}
		}

		FailedRunOutput::render( $entries_by_name, $owner, $format, $unreadable_entries, $unreadable_rows );
	}

	/**
	 * Delegates one retry to the internal engine facade and reports its result.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Composed job or chunked job identity.
	 * @param   RunId  $run_id Retained failed-run identifier.
	 *
	 * @return  void
	 */
	private function retry_failed_run( string $name, RunId $run_id ): void {
		$engine = Component::get_engine();
		if ( null === $engine ) {
			\WP_CLI::error( 'The background jobs engine is unavailable; run the command after plugins_loaded.' );
			return;
		}

		$result = $engine->retry_failed( $name, (string) $run_id );
		if ( $result->is_failure() ) {
			\WP_CLI::error( $result->error->message );
			return;
		}

		\WP_CLI::success( \sprintf( 'Retried failed run "%1$s" for "%2$s" as new run "%3$s".', $run_id, $name, $result->value ) );
	}

	/**
	 * Purges one failed-run store or every discovered store.
	 *
	 * A null identity selects every store discovered under the failed-run option prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $name Composed job or chunked job identity, or null for every identity.
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
		$rows   = new OptionRows( $wpdb );
		$logger = new HookLogger();
		$count  = 0;
		foreach ( $names as $store_name ) {
			$purged = new FailedRunStore( $store_name, $rows, $logger )->purge();
			if ( null === $purged ) {
				\WP_CLI::error( \sprintf( 'Failed-run store "%s" could not be purged; resolve its database error or concurrent writes and try again.', $store_name ) );
				return;
			}

			$count += $purged;
		}

		$scope = null === $name ? 'across all names' : \sprintf( 'for "%s"', $name );
		\WP_CLI::success( \sprintf( 'Purged %1$d failed run%2$s %3$s.', $count, 1 === $count ? '' : 's', $scope ) );
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
		$option_names = $wpdb->get_col( $wpdb->prepare( 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s ORDER BY `option_name` ASC', $wpdb->options, $wpdb->esc_like( FailedRunStore::OPTION_PREFIX ) . '%' ) );
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

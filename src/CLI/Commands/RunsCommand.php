<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\CLI\Commands;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output\FailedRunOutput;
use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output\Format;
use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output\RunOutput;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Logging\EngineLogger;
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
	 * STDERR without adding diagnostic prose to rendered data; that count covers every corrupt row
	 * sharing this identity's run option-name prefix, which a longer sibling identity also shares.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Operation to perform: list or cancel.
	 *
	 * <identity>
	 * : Composed `{scope}:{name}` job or chunked job identity.
	 *
	 * [<run_id>]
	 * : Retained engine-run identifier. Required by cancel.
	 *
	 * [--format=<format>]
	 * : Render list output as table, csv, json, count, or yaml. Valid only with list and defaults to table.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp a8csp-bgje runs list consumer-plugin:email-digest
	 *     $ wp a8csp-bgje runs list consumer-plugin:email-digest --format=json
	 *     $ wp a8csp-bgje runs cancel consumer-plugin:email-digest 00000000000000000001-0000000000000000001
	 *
	 * A waiting live run has a backend delivery or retry pending. An executing run has an admitted
	 * lifecycle action in progress, whether engine orchestration or client execution, and a stale
	 * heartbeat means maintenance can reclaim the abandoned execution. The `recent history` section
	 * is bounded; `failed store` identifies failures still available to
	 * `wp a8csp-bgje failed-runs retry`.
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
			$this->list_runs( $request['identity'], $request['format'] );
			return;
		}

		$this->cancel_run( $request['identity'], $request['run_id'] );
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
	 *          |array{action: 'list', identity: Identity, format: string}
	 *          |array{action: 'cancel', identity: Identity, run_id: RunId}
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
					'message' => 'Cancel requires exactly an identity and run_id; use wp a8csp-bgje runs cancel <identity> <run_id>.',
				);
			}
			$identity = Identity::tryFrom( $args[1] );
			if ( null === $identity ) {
				return array(
					'action'  => 'error',
					'message' => 'Cancel identity is invalid; use a composed {scope}:{name} identity.',
				);
			}
			$run_id = RunId::tryFrom( $args[2] );
			if ( null === $run_id ) {
				return array(
					'action'  => 'error',
					'message' => 'Run identifier is malformed; pass a run ID the engine returned.',
				);
			}

			return array(
				'action'   => 'cancel',
				'identity' => $identity,
				'run_id'   => $run_id,
			);
		}

		if ( 2 !== \count( $args ) || ! self::has_only_keys( $assoc_args, array( 'format' ) ) ) {
			return array(
				'action'  => 'error',
				'message' => 'Run list requires exactly one identity and accepts only --format; use wp a8csp-bgje runs list <identity> [--format=<format>].',
			);
		}

		$identity = Identity::tryFrom( $args[1] );
		if ( null === $identity ) {
			return array(
				'action'  => 'error',
				'message' => 'Run identity is invalid; use a composed {scope}:{name} identity.',
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
			'action'   => 'list',
			'identity' => $identity,
			'format'   => $format,
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
	 * : Composed `{scope}:{name}` job or chunked job identity. Required by retry and by an identity-scoped purge.
	 *
	 * [<run_id>]
	 * : Retained failed-run identifier. Required by retry.
	 *
	 * [--all]
	 * : Purge every failed-run store. Valid only with purge and without an identity.
	 *
	 * [--scope=<scope>]
	 * : Show only failed runs belonging to the exact scope. Valid only with list.
	 *
	 * [--format=<format>]
	 * : Render list output as table, csv, json, count, or yaml. Defaults to table.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp a8csp-bgje failed-runs list
	 *     $ wp a8csp-bgje failed-runs list --scope=consumer-plugin --format=json
	 *     $ wp a8csp-bgje failed-runs retry consumer-plugin:email-digest 00000000000000000001-0000000000000000001
	 *     $ wp a8csp-bgje failed-runs purge consumer-plugin:email-digest
	 *     $ wp a8csp-bgje failed-runs purge --all
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
				$this->list_failed_runs( $request['scope'], $request['format'] );
				break;
			case 'retry':
				$this->retry_failed_run( $request['identity'], $request['run_id'] );
				break;
			case 'purge':
				$this->purge_failed_runs( $request['identity'] );
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
	 *          |array{action: 'list', scope: string|null, format: string}
	 *          |array{action: 'retry', identity: Identity, run_id: RunId}
	 *          |array{action: 'purge', identity: Identity|null}
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
				if ( 1 !== \count( $args ) || ! self::has_only_keys( $assoc_args, array( 'scope', 'format' ) ) ) {
					return array(
						'action'  => 'error',
						'message' => 'List accepts only --scope and --format; use wp a8csp-bgje failed-runs list [--scope=<scope>] [--format=<format>].',
					);
				}

				$scope = null;
				if ( \array_key_exists( 'scope', $assoc_args ) ) {
					$scope_argument = $assoc_args['scope'];
					if ( ! \is_string( $scope_argument ) ) {
						return array(
							'action'  => 'error',
							'message' => 'List scope is invalid; pass a value with --scope=<scope>.',
						);
					}

					$scope = $scope_argument;
					try {
						Identity::validate_scope( $scope, true );
					} catch ( \InvalidArgumentException ) {
						return array(
							'action'  => 'error',
							'message' => 'List scope is invalid; pass a canonical scope with --scope=<scope>.',
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
					'scope'  => $scope,
					'format' => $format,
				);
			case 'retry':
				if ( 3 !== \count( $args ) || array() !== $assoc_args ) {
					return array(
						'action'  => 'error',
						'message' => 'Retry requires exactly an identity and run_id; use wp a8csp-bgje failed-runs retry <identity> <run_id>.',
					);
				}
				$identity = Identity::tryFrom( $args[1] );
				if ( null === $identity ) {
					return array(
						'action'  => 'error',
						'message' => 'Retry identity is invalid; use a composed {scope}:{name} identity.',
					);
				}
				$run_id = RunId::tryFrom( $args[2] );
				if ( null === $run_id ) {
					return array(
						'action'  => 'error',
						'message' => 'Run identifier is malformed; pass a run ID the engine returned.',
					);
				}

				return array(
					'action'   => 'retry',
					'identity' => $identity,
					'run_id'   => $run_id,
				);
			case 'purge':
				if ( ! self::has_only_keys( $assoc_args, array( 'all' ) ) ) {
					return array(
						'action'  => 'error',
						'message' => 'Purge accepts only --all; use wp a8csp-bgje failed-runs purge <identity> or purge --all.',
					);
				}

				if ( 1 === \count( $args ) && true === ( $assoc_args['all'] ?? null ) ) {
					return array(
						'action'   => 'purge',
						'identity' => null,
					);
				}

				if ( 2 === \count( $args ) && ! \array_key_exists( 'all', $assoc_args ) ) {
					$identity = Identity::tryFrom( $args[1] );
					if ( null === $identity ) {
						return array(
							'action'  => 'error',
							'message' => 'Purge identity is invalid; use a composed {scope}:{name} identity.',
						);
					}

					return array(
						'action'   => 'purge',
						'identity' => $identity,
					);
				}

				return array(
					'action'  => 'error',
					'message' => 'Purge requires exactly one identity or --all; use wp a8csp-bgje failed-runs purge <identity> or purge --all.',
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
	 * @return  list<Identity>
	 */
	public static function identities_from_option_names( array $option_names ): array {
		$identities = array();
		foreach ( $option_names as $option_name ) {
			if ( ! \is_string( $option_name ) || ! \str_starts_with( $option_name, FailedRunStore::OPTION_PREFIX ) ) {
				continue;
			}

			$name     = \substr( $option_name, \strlen( FailedRunStore::OPTION_PREFIX ) );
			$identity = Identity::tryFrom( $name );
			if ( null !== $identity ) {
				$identities[ $name ] = $identity;
			}
		}

		\ksort( $identities, \SORT_STRING );

		return \array_values( $identities );
	}

	// endregion

	// region HELPERS

	/**
	 * Delegates cancellation to the background-work admission coordinator and reports its result.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Composed job or chunked job identity.
	 * @param   RunId    $run_id   Retained run identifier.
	 *
	 * @return  void
	 */
	private function cancel_run( Identity $identity, RunId $run_id ): void {
		$dispatcher = Component::get_dispatcher();
		if ( null === $dispatcher ) {
			\WP_CLI::error( 'The background jobs engine is unavailable; run the command after plugins_loaded.' );
			return;
		}

		$result = $dispatcher->cancel( $identity, (string) $run_id );
		if ( $result->is_failure() ) {
			\WP_CLI::error( $result->error->message );
			return;
		}

		\WP_CLI::success( \sprintf( 'Cancelled run %1$s of "%2$s".', $run_id, (string) $identity ) );
	}

	/**
	 * Lists live runs and recent history through the format-specific public contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Composed job or chunked job identity.
	 * @param   string   $format   WP-CLI output format.
	 *
	 * @return  void
	 */
	private function list_runs( Identity $identity, string $format ): void {
		$inspection = Component::get_inspection();
		if ( null === $inspection ) {
			\WP_CLI::error( 'The background jobs inspection service is unavailable; run the command after plugins_loaded.' );
			return;
		}

		RunOutput::render( $inspection->runs( $identity ), $identity, $format );
	}

	/**
	 * Lists every retained failed run through the requested WP-CLI formatter.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $scope  Exact scope filter, or null for every scope.
	 * @param   string      $format WP-CLI output format.
	 *
	 * @return  void
	 */
	private function list_failed_runs( ?string $scope, string $format ): void {
		$identities = $this->failed_run_identities();
		if ( null === $identities ) {
			\WP_CLI::error( 'The database check for failed-run stores failed; resolve the database error and try again.' );
			return;
		}
		if ( null !== $scope ) {
			$identities = \array_values(
				\array_filter(
					$identities,
					static fn ( Identity $identity ): bool => $scope === $identity->scope()
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
		$logger             = new EngineLogger();
		$groups             = array();
		$unreadable_entries = 0;
		$unreadable_rows    = 0;
		foreach ( $identities as $identity ) {
			$inspection = new FailedRunStore( $identity, $option_rows, $logger )->inspect();
			if ( $inspection->is_failure() ) {
				\WP_CLI::error( \sprintf( 'Failed runs for "%s" are unavailable because the authoritative database read failed; resolve the database error and try again.', (string) $identity ) );
				return;
			}

			$groups[] = array(
				'identity' => $identity,
				'entries'  => $inspection->value['entries'],
			);

			$unreadable_entries += $inspection->value['unreadable'];
			if ( $inspection->value['row_unreadable'] ) {
				++$unreadable_rows;
			}
		}

		FailedRunOutput::render( $groups, $scope, $format, $unreadable_entries, $unreadable_rows );
	}

	/**
	 * Delegates one retry to the background-work admission coordinator and reports its result.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Composed job or chunked job identity.
	 * @param   RunId    $run_id   Retained failed-run identifier.
	 *
	 * @return  void
	 */
	private function retry_failed_run( Identity $identity, RunId $run_id ): void {
		$dispatcher = Component::get_dispatcher();
		if ( null === $dispatcher ) {
			\WP_CLI::error( 'The background jobs engine is unavailable; run the command after plugins_loaded.' );
			return;
		}

		$result = $dispatcher->retry_failed( $identity, (string) $run_id );
		if ( $result->is_failure() ) {
			\WP_CLI::error( $result->error->message );
			return;
		}

		\WP_CLI::success( \sprintf( 'Retried failed run "%1$s" for "%2$s" as new run "%3$s".', $run_id, (string) $identity, $result->value ) );
	}

	/**
	 * Purges one failed-run store or every discovered store.
	 *
	 * A null identity selects every store discovered under the failed-run option prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity|null $identity Composed job or chunked job identity, or null for every identity.
	 *
	 * @return  void
	 */
	private function purge_failed_runs( ?Identity $identity ): void {
		$identities = null === $identity ? $this->failed_run_identities() : array( $identity );
		if ( null === $identities ) {
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
		$logger = new EngineLogger();
		$count  = 0;
		foreach ( $identities as $store_identity ) {
			$purged = new FailedRunStore( $store_identity, $rows, $logger )->purge();
			if ( null === $purged ) {
				\WP_CLI::error( \sprintf( 'Failed-run store "%s" could not be purged; resolve its database error or concurrent writes and try again.', (string) $store_identity ) );
				return;
			}

			$count += $purged;
		}

		$range = null === $identity ? 'across all names' : \sprintf( 'for "%s"', (string) $identity );
		\WP_CLI::success( \sprintf( 'Purged %1$d failed run%2$s %3$s.', $count, 1 === $count ? '' : 's', $range ) );
	}

	/**
	 * Discovers composed identities from dynamically named failed-run option rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<Identity>|null Null when the database query fails.
	 */
	private function failed_run_identities(): ?array {
		global $wpdb;

		/**
		 * WordPress database connection for the current site.
		 *
		 * @var \wpdb $wpdb
		 */
		$selected = new OptionRows( $wpdb )->option_names( FailedRunStore::OPTION_PREFIX );
		if ( $selected->is_failure() ) {
			return null;
		}

		return self::identities_from_option_names( $selected->value );
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

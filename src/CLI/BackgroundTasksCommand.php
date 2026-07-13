<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\CLI;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\FailedRunStore;

\defined( 'ABSPATH' ) || exit;

/**
 * Formats failed-run data and delegates failed-run operations to engine surfaces.
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
 *     name: string,
 *     run_id: string,
 *     failed_at: string,
 *     attempts: int,
 *     error_class: string|null,
 *     error_message: string
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

	// endregion

	// region METHODS

	/**
	 * Lists, retries, or purges retained failed runs.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Operation to perform: list, retry, or purge.
	 *
	 * [<name>]
	 * : Stable task or batch name. Required by retry and by a name-scoped purge.
	 *
	 * [<run_id>]
	 * : Retained failed-run identifier. Required by retry.
	 *
	 * [--all]
	 * : Purge every failed-run store. Valid only with purge and without a name.
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
	 *     $ wp background-tasks failed list --format=json
	 *     $ wp background-tasks failed retry email-digest 00000000000000000001-0000000000000000001
	 *     $ wp background-tasks failed purge email-digest
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
				$this->list_failed_runs( $request['format'] );
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
	 *          |array{action: 'list', format: string}
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
				if ( 1 !== \count( $args ) || ! self::has_only_keys( $assoc_args, array( 'format' ) ) ) {
					return array(
						'action'  => 'error',
						'message' => 'List accepts only --format; use wp background-tasks failed list [--format=<format>].',
					);
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
					'format' => $format,
				);
			case 'retry':
				if ( 3 !== \count( $args ) || array() !== $assoc_args ) {
					return array(
						'action'  => 'error',
						'message' => 'Retry requires exactly a name and run_id; use wp background-tasks failed retry <name> <run_id>.',
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
					if ( 1 !== \preg_match( '/\A[a-z0-9_-]+\z/', $name ) ) {
						return array(
							'action'  => 'error',
							'message' => 'Purge name is invalid; use lowercase letters, digits, underscores, and hyphens.',
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
	 * @param   array $entries_by_name Failed runs keyed by task or batch name.
	 *
	 * @phpstan-return list<FailedRunRow>
	 *
	 * @return  array
	 */
	public static function rows_from_entries( array $entries_by_name ): array {
		\ksort( $entries_by_name, \SORT_STRING );

		$rows = array();
		foreach ( $entries_by_name as $name => $entries ) {
			\usort(
				$entries,
				static function ( array $left, array $right ): int {
					$failed_at_order = $left['failed_at'] <=> $right['failed_at'];
					return 0 !== $failed_at_order ? $failed_at_order : $left['run_id'] <=> $right['run_id'];
				}
			);

			foreach ( $entries as $entry ) {
				$rows[] = array(
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
	 * Extracts valid stable names from discovered failed-run option names.
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
			if ( 1 === \preg_match( '/\A[a-z0-9_-]+\z/', $name ) ) {
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
	 * Lists every retained failed run through the requested WP-CLI formatter.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $format WP-CLI output format.
	 *
	 * @return  void
	 */
	private function list_failed_runs( string $format ): void {
		$names = $this->failed_run_names();
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
		$option_rows     = new OptionRows( $wpdb );
		$entries_by_name = array();
		foreach ( $names as $name ) {
			$entries_by_name[ $name ] = ( new FailedRunStore( $name, $option_rows ) )->all();
		}

		$rows = self::rows_from_entries( $entries_by_name );
		if ( array() === $rows && 'table' === $format ) {
			\WP_CLI::line( 'No failed runs are retained.' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, self::LIST_FIELDS );
	}

	/**
	 * Delegates one retry to the public engine facade and reports its result.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Stable task or batch name.
	 * @param   string $run_id Retained failed-run identifier.
	 *
	 * @return  void
	 */
	private function retry_failed_run( string $name, string $run_id ): void {
		$engine = \a8csp_bgte_engine();
		if ( null === $engine ) {
			\WP_CLI::error( 'The background tasks engine is unavailable; run the command after plugins_loaded.' );
			return;
		}

		$result = $engine->tasks()->retry_failed( $name, $run_id );
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
	 * @param   string|null $name Stable task or batch name, or null for every name.
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
	 * Discovers stable names from dynamically named failed-run option rows.
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

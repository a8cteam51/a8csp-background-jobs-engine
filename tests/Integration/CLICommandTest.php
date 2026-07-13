<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;

/**
 * Pins command registration, WP-CLI argument normalization, and failed-run output at the process boundary.
 */
final class CLICommandTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** WordPress root shared by the wp-env CLI and web containers. */
	private const WP_PATH = '/var/www/html';

	/** Failed-run identity isolated to list coverage. */
	private const LIST_STORE_NAME = 'integration-cli-command-list-store';

	/** Failed-run identity isolated to name-scoped purge coverage. */
	private const PURGE_STORE_NAME = 'integration-cli-command-purge-store';

	/** Failed-run identity isolated to prefix-discovered purge coverage. */
	private const ALL_STORE_NAME = 'integration-cli-command-all-store';

	/** Background-work identity deliberately absent from the child request's registries. */
	private const UNREGISTERED_NAME = 'integration-cli-command-unregistered';

	/** Run identity shared by deterministic retained-failure fixtures. */
	private const RUN_ID = 'integration-cli-command-run-1';

	/** Deterministic failure time exposed by JSON output. */
	private const FAILED_AT = 1_700_000_001;

	/** Prefix shared by dynamically named failed-run options. */
	private const FAILED_OPTION_PREFIX = 'a8csp_bgte_failed_';

	// endregion.

	// region LIFECYCLE.

	/**
	 * Declares the registry row every spawned WP-CLI child recreates through its own init sync.
	 *
	 * @return  void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->expect_option( 'a8csp_bgte_schedules' );
	}

	// endregion.

	// region TESTS.

	/**
	 * An empty store census exits successfully with an informative line.
	 *
	 * @return  void
	 */
	public function test_failed_list_reports_an_informative_empty_result(): void {
		$result = self::run_failed_command( 'list' );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame( "No failed runs are retained.\n", $result['stdout'] );
		self::assertSame( '', $result['stderr'] );
	}

	/**
	 * JSON output contains the exact public row projected from a retained failure.
	 *
	 * @return  void
	 */
	public function test_failed_list_json_exposes_the_exact_retained_row(): void {
		$this->expect_option( self::option_name( self::LIST_STORE_NAME ) );
		$this->seed_failed_run( self::LIST_STORE_NAME );

		$result = self::run_failed_command( 'list', '--format=json' );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame(
			'[{"name":"integration-cli-command-list-store","run_id":"integration-cli-command-run-1",' .
			'"failed_at":"2023-11-14T22:13:21+00:00","attempts":3,"error_class":"RuntimeException",' .
			'"error_message":"CLI boundary failure."}]',
			$result['stdout']
		);
		self::assertSame( '', $result['stderr'] );
	}

	/**
	 * Retry preserves the engine's corrective failure for an unregistered background-work name.
	 *
	 * @return  void
	 */
	public function test_failed_retry_surfaces_the_unregistered_engine_error(): void {
		$result = self::run_failed_command( 'retry', self::UNREGISTERED_NAME, self::RUN_ID );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame(
			'Error: Background-work "integration-cli-command-unregistered" is not registered; ' .
			"register the matching task or batch before retrying its failed run.\n",
			$result['stderr']
		);
	}

	/**
	 * A name-scoped purge reports the exact deleted count and removes the authoritative store row.
	 *
	 * @return  void
	 */
	public function test_failed_purge_name_round_trips_the_store(): void {
		$rows  = self::option_rows();
		$store = $this->seed_failed_run( self::PURGE_STORE_NAME, $rows );

		$result = self::run_failed_command( 'purge', self::PURGE_STORE_NAME );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame(
			"Success: Purged 1 failed run for \"integration-cli-command-purge-store\".\n",
			$result['stdout']
		);
		self::assertSame( '', $result['stderr'] );
		self::assert_store_absent( self::PURGE_STORE_NAME, $store, $rows );
	}

	/**
	 * An all-names purge discovers the failed-store prefix and removes its retained entry.
	 *
	 * @return  void
	 */
	public function test_failed_purge_all_discovers_and_removes_the_store(): void {
		$rows  = self::option_rows();
		$store = $this->seed_failed_run( self::ALL_STORE_NAME, $rows );

		$result = self::run_failed_command( 'purge', '--all' );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame( "Success: Purged 1 failed run across all names.\n", $result['stdout'] );
		self::assertSame( '', $result['stderr'] );
		self::assert_store_absent( self::ALL_STORE_NAME, $store, $rows );
	}

	/**
	 * A negated all flag is not accepted as an all-names purge request.
	 *
	 * @return  void
	 */
	public function test_failed_purge_no_all_names_the_correct_usage(): void {
		$result = self::run_failed_command( 'purge', '--no-all' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( self::purge_usage_error(), $result['stderr'] );
	}

	/**
	 * A purge without a name or affirmative all flag names the corrective usage.
	 *
	 * @return  void
	 */
	public function test_failed_purge_without_a_scope_names_the_correct_usage(): void {
		$result = self::run_failed_command( 'purge' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( self::purge_usage_error(), $result['stderr'] );
	}

	/**
	 * An unsupported action exits unsuccessfully and names every accepted action.
	 *
	 * @return  void
	 */
	public function test_invalid_failed_action_names_the_supported_actions(): void {
		$result = self::run_failed_command( 'remove' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame(
			"Error: Failed-run action \"remove\" is invalid; use list, retry, or purge.\n",
			$result['stderr']
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Runs the registered command through wp-env's actual WP-CLI executable.
	 *
	 * @param   string ...$arguments Arguments following the failed command.
	 *
	 * @return  array{stdout: string, stderr: string, exit_code: int}
	 */
	private static function run_failed_command( string ...$arguments ): array {
		$command = \array_values(
			\array_merge(
				array(
					'wp',
					'--path=' . self::WP_PATH,
					'--no-color',
					'background-tasks',
					'failed',
				),
				$arguments
			)
		);
		$pipes   = array();

		// The boundary requires an isolated request through wp-env's WP-CLI executable.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open
		$process = \proc_open(
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			self::WP_PATH
		);
		self::assertIsResource( $process, 'wp-env must expose the WP-CLI executable to integration tests' );
		self::assertCount( 3, $pipes );
		$stdin  = $pipes[0] ?? null;
		$stdout = $pipes[1] ?? null;
		$stderr = $pipes[2] ?? null;
		self::assertIsResource( $stdin );
		self::assertIsResource( $stdout );
		self::assertIsResource( $stderr );

		// Process pipes are not filesystem paths and have no WP_Filesystem equivalent.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		\fclose( $stdin );
		$stdout_content = \stream_get_contents( $stdout );
		$stderr_content = \stream_get_contents( $stderr );
		\fclose( $stdout );
		\fclose( $stderr );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		self::assertIsString( $stdout_content );
		self::assertIsString( $stderr_content );

		return array(
			'stdout'    => $stdout_content,
			'stderr'    => $stderr_content,
			'exit_code' => \proc_close( $process ),
		);
	}

	/**
	 * Creates the site-bound row seam used by a failed-run store.
	 *
	 * @return  OptionRows
	 */
	private static function option_rows(): OptionRows {
		global $wpdb;

		/** @var \wpdb $wpdb */
		return new OptionRows( $wpdb );
	}

	/**
	 * Persists one deterministic retained failure.
	 *
	 * @param   string          $name Stable background-work name.
	 * @param   OptionRows|null $rows Site-bound row seam, or null to construct one.
	 *
	 * @return  FailedRunStore
	 */
	private function seed_failed_run( string $name, ?OptionRows $rows = null ): FailedRunStore {
		$store = new FailedRunStore( $name, $rows ?? self::option_rows() );
		$store->record(
			self::RUN_ID,
			self::FAILED_AT,
			array( 'account_id' => 42 ),
			3,
			new EngineError( 'CLI boundary failure.', \RuntimeException::class )
		);

		return $store;
	}

	/**
	 * Asserts that a child-process purge removed both authoritative and public store state.
	 *
	 * @param   string         $name  Stable background-work name.
	 * @param   FailedRunStore $store Failed-run store constructed by the PHPUnit request.
	 * @param   OptionRows     $rows  Authoritative option-row seam.
	 *
	 * @return  void
	 */
	private static function assert_store_absent( string $name, FailedRunStore $store, OptionRows $rows ): void {
		$option_name = self::option_name( $name );

		self::assertNull( $rows->select( $option_name ) );
		self::assertFalse( $rows->last_select_failed() );

		// The child process cannot clear this PHPUnit request's in-memory option cache.
		\wp_cache_delete( $option_name, 'options' );
		self::assertSame( array(), $store->all() );
	}

	/**
	 * Returns the failed-store option name for a stable background-work identity.
	 *
	 * @param   string $name Stable background-work name.
	 *
	 * @return  string
	 */
	private static function option_name( string $name ): string {
		return self::FAILED_OPTION_PREFIX . $name;
	}

	/**
	 * Returns WP-CLI's exact corrective purge usage error.
	 *
	 * @return  string
	 */
	private static function purge_usage_error(): string {
		return 'Error: Purge requires exactly one name or --all; ' .
			"use wp background-tasks failed purge <name> or purge --all.\n";
	}

	// endregion.
}

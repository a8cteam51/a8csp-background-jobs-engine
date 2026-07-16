<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins command registration, WP-CLI argument normalization, and output at the process boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class CLICommandTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** WordPress root shared by the wp-env CLI and web containers. */
	private const WP_PATH = '/var/www/html';

	/** Failed-run identity isolated to list coverage. */
	private const LIST_STORE_NAME = 'integration-cli-command:integration-cli-command-list-store';

	/** Failed-run identity isolated to name-scoped purge coverage. */
	private const PURGE_STORE_NAME = 'integration-cli-command:integration-cli-command-purge-store';

	/** Failed-run identity isolated to prefix-discovered purge coverage. */
	private const ALL_STORE_NAME = 'integration-cli-command:integration-cli-command-all-store';

	/** Background-work identity deliberately absent from the child request's registries. */
	private const UNREGISTERED_NAME = 'integration-cli-command:integration-cli-command-unregistered';

	/** Background-work identity registered by the engine in every WP-CLI child request. */
	private const CANCEL_NAME = 'a8csp-bgte:maintenance';

	/** Batch identity registered by the cancel-completeness WP-CLI bootstrap. */
	private const CANCEL_BATCH_NAME = 'integration-cli-command:integration-cli-command-cancel-batch';

	/** Test-only WP-CLI bootstrap that registers the cancel-completeness batch. */
	private const CANCEL_BATCH_BOOTSTRAP = self::WP_PATH . '/wp-content/plugins/a8csp-background-tasks-engine/tests/Support/Fixtures/cli-cancel-batch.php';

	/** Test-only WP-CLI bootstrap that declares the inspection task and schedule. */
	private const INSPECTION_BOOTSTRAP = self::WP_PATH . '/wp-content/plugins/a8csp-background-tasks-engine/tests/Support/Fixtures/cli-inspection.php';

	/** Test-only WP-CLI bootstrap that fails the retained-run row read after name discovery. */
	private const FAILED_READ_BOOTSTRAP = self::WP_PATH . '/wp-content/plugins/a8csp-background-tasks-engine/tests/Support/Fixtures/cli-failed-read.php';

	/** Owner declared in every isolated inspection request. */
	private const INSPECTION_OWNER = 'integration-cli-inspection-owner';

	/** Schedule declared in every isolated inspection request. */
	private const INSPECTION_SCHEDULE = 'inspection-schedule';

	/** Task declared in every isolated inspection request. */
	private const INSPECTION_TASK = 'integration-cli-inspection-task';

	/** Owner-qualified task identity declared in every isolated inspection request. */
	private const INSPECTION_TASK_IDENTITY = self::INSPECTION_OWNER . ':' . self::INSPECTION_TASK;

	/** Run identity shared by deterministic retained-failure fixtures. */
	private const RUN_ID = 'integration-cli-command-run-1';

	/** Canonical-format run identifier for rows the live-run enumeration must parse. */
	private const CANONICAL_RUN_ID = '00000000001784030000-0000000000000000001';

	/** Deterministic failure time exposed by JSON output. */
	private const FAILED_AT = 1_700_000_001;

	/** Prefix shared by dynamically named failed-run options. */
	private const FAILED_OPTION_PREFIX = 'a8csp_bgte_failed_runs_';

	// endregion.

	// region LIFECYCLE.

	/**
	 * Declares the registry rows every spawned WP-CLI child recreates through its own init sync.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->expect_option( 'a8csp_bgte_schedule_registrations_a8csp-bgte' );
		$this->expect_option( 'a8csp_bgte_schedule_registrations_' . self::INSPECTION_OWNER );
	}

	// endregion.

	// region TESTS.

	/**
	 * A retained run is cancelled through the real command with declarative success output.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_terminalizes_a_retained_run(): void {
		$this->seed_cancel_run();

		$result = self::run_cancel_command( self::CANCEL_NAME, self::RUN_ID );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame( "Success: Cancelled run integration-cli-command-run-1 of \"a8csp-bgte:maintenance\".\n", $result['stdout'] );
		self::assertSame( '', $result['stderr'] );
		$inspection = self::run_runs_command( 'list', self::CANCEL_NAME, '--format=json' );
		self::assertSame( 0, $inspection['exit_code'] );
		self::assertSame( '', $inspection['stderr'] );
		self::assertStringContainsString( '"run_id":"' . self::RUN_ID . '"', $inspection['stdout'] );
		self::assertStringContainsString( '"outcome":"cancelled"', $inspection['stdout'] );
	}

	/**
	 * The real command preserves the engine's executing-run refusal.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_surfaces_the_executing_refusal(): void {
		$option_name = $this->seed_cancel_run( true );

		$result = self::run_cancel_command( self::CANCEL_NAME, self::RUN_ID );
		self::assertTrue( \delete_option( $option_name ), 'The executing boundary fixture must be removable after refusal' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( "Error: Run \"integration-cli-command-run-1\" is executing; a run in flight completes or fails on its own.\n", $result['stderr'] );
	}

	/**
	 * The real command preserves the zero-chunk batch completeness refusal.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_surfaces_the_zero_chunk_completeness_refusal(): void {
		$this->expect_option( self::cancel_batch_run_option_name() );
		$option_name = $this->seed_cancel_batch_pending_cleanup();

		$result = self::run_command_with_globals( 'cancel', array( '--require=' . self::CANCEL_BATCH_BOOTSTRAP ), self::CANCEL_BATCH_NAME, self::RUN_ID );
		self::assertTrue( \delete_option( $option_name ), 'The completeness fixture must remain retained after refusal' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( "Error: Run \"integration-cli-command-run-1\" has no chunks left to process; the pending cleanup completes it.\n", $result['stderr'] );
	}

	/**
	 * The real command preserves the engine's unregistered-name correction.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_surfaces_the_unregistered_engine_error(): void {
		$result = self::run_cancel_command( self::UNREGISTERED_NAME, self::RUN_ID );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( 'Error: Background-work "integration-cli-command:integration-cli-command-unregistered" is not registered; ' . "register the matching task or batch before cancelling its run.\n", $result['stderr'] );
	}

	/**
	 * The real command preserves the engine's registered-but-unretained correction.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_surfaces_the_not_retained_engine_error(): void {
		$result = self::run_cancel_command( self::CANCEL_NAME, self::RUN_ID );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( 'Error: Run "integration-cli-command-run-1" for background-work ' . "\"a8csp-bgte:maintenance\" is not retained; nothing remains to cancel.\n", $result['stderr'] );
	}

	/**
	 * Missing required identities use WP-CLI's native required-synopsis failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_without_arguments_uses_the_native_synopsis(): void {
		$result = self::run_cancel_command();

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( "usage: wp background-tasks cancel <identity> <run_id>\n", $result['stdout'] );
		self::assertSame( '', $result['stderr'] );
	}

	/**
	 * An extra identity is rejected by WP-CLI before the command seam runs.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_rejects_an_extra_positional_argument(): void {
		$result = self::run_cancel_command( self::CANCEL_NAME, self::RUN_ID, 'extra' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( "Error: Too many positional arguments: extra\n", $result['stderr'] );
	}

	/**
	 * An undocumented flag is rejected by WP-CLI before the command seam runs.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_rejects_an_undocumented_flag(): void {
		$result = self::run_cancel_command( self::CANCEL_NAME, self::RUN_ID, '--force' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( "Error: Parameter errors:\n unknown --force parameter\n", $result['stderr'] );
	}

	/**
	 * A negated undocumented flag still carries a key and is rejected by WP-CLI.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_rejects_a_negated_undocumented_flag(): void {
		$result = self::run_cancel_command( self::CANCEL_NAME, self::RUN_ID, '--no-force' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( "Error: Parameter errors:\n unknown --force parameter\n", $result['stderr'] );
	}

	/**
	 * An empty store census exits successfully with an informative line.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_list_reports_an_informative_empty_result(): void {
		$result = self::run_failed_runs_command( 'list' );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame( "No failed runs are retained.\n", $result['stdout'] );
		self::assertSame( '', $result['stderr'] );
	}

	/**
	 * JSON output contains the exact public row projected from a retained failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_list_json_exposes_the_exact_retained_row(): void {
		$this->expect_option( self::option_name( self::LIST_STORE_NAME ) );
		$this->seed_failed_run( self::LIST_STORE_NAME );

		$result = self::run_failed_runs_command( 'list', '--format=json' );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame(
			'[{"owner":"integration-cli-command","identity":"integration-cli-command:integration-cli-command-list-store",' .
			'"run_id":"integration-cli-command-run-1",' .
			'"failed_at":"2023-11-14T22:13:21+00:00","attempts":3,"error_class":"RuntimeException",' .
			'"error_message":"CLI boundary failure."}]',
			$result['stdout']
		);
		self::assertSame( '', $result['stderr'] );
	}

	/**
	 * A failed retained-run read renders as unavailable instead of an empty failed-run list.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing security
	 * @pin-rationale The injected database detail must not cross the documented CLI error boundary; an unavailable public result cannot otherwise prove the confidential query text was redacted.
	 * @fixture StoreFixtureBuilder
	 *
	 * @return  void
	 */
	public function test_failed_list_reports_an_authoritative_store_read_failure(): void {
		$this->expect_option( self::option_name( self::LIST_STORE_NAME ) );
		$this->seed_failed_run( self::LIST_STORE_NAME );

		$result = self::run_command_with_globals( 'failed-runs', array( '--require=' . self::FAILED_READ_BOOTSTRAP ), 'list' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( 'Error: Failed runs for "integration-cli-command:integration-cli-command-list-store" are unavailable because the authoritative ' . "database read failed; resolve the database error and try again.\n", $result['stderr'] );
		self::assertStringNotContainsString( 'a8csp_bgte_missing_option_rows', $result['stderr'], 'CLI failure output must redact the failed database query' );
	}

	/**
	 * Retry preserves the engine's corrective failure for an unregistered background-work identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_retry_surfaces_the_unregistered_engine_error(): void {
		$result = self::run_failed_runs_command( 'retry', self::UNREGISTERED_NAME, self::RUN_ID );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( 'Error: Background-work "integration-cli-command:integration-cli-command-unregistered" is not registered; ' . "register the matching task or batch before retrying its failed run.\n", $result['stderr'] );
	}

	/**
	 * A name-scoped purge reports the exact deleted count and removes the authoritative store row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_purge_name_round_trips_the_store(): void {
		$this->seed_failed_run( self::PURGE_STORE_NAME );

		$result = self::run_failed_runs_command( 'purge', self::PURGE_STORE_NAME );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame( "Success: Purged 1 failed run for \"integration-cli-command:integration-cli-command-purge-store\".\n", $result['stdout'] );
		self::assertSame( '', $result['stderr'] );
		$remaining = self::run_failed_runs_command( 'list' );
		self::assertSame( 0, $remaining['exit_code'] );
		self::assertSame( "No failed runs are retained.\n", $remaining['stdout'] );
	}

	/**
	 * An all-names purge discovers the failed-store prefix and removes its retained entry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_purge_all_discovers_and_removes_the_store(): void {
		$this->seed_failed_run( self::ALL_STORE_NAME );

		$result = self::run_failed_runs_command( 'purge', '--all' );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame( "Success: Purged 1 failed run across all names.\n", $result['stdout'] );
		self::assertSame( '', $result['stderr'] );
		$remaining = self::run_failed_runs_command( 'list' );
		self::assertSame( 0, $remaining['exit_code'] );
		self::assertSame( "No failed runs are retained.\n", $remaining['stdout'] );
	}

	/**
	 * A negated all flag is not accepted as an all-names purge request.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_purge_no_all_names_the_correct_usage(): void {
		$result = self::run_failed_runs_command( 'purge', '--no-all' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( self::purge_usage_error(), $result['stderr'] );
	}

	/**
	 * A purge without a name or affirmative all flag names the corrective usage.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_purge_without_a_scope_names_the_correct_usage(): void {
		$result = self::run_failed_runs_command( 'purge' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( self::purge_usage_error(), $result['stderr'] );
	}

	/**
	 * An unsupported action exits unsuccessfully and names every accepted action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_invalid_failed_action_names_the_supported_actions(): void {
		$result = self::run_failed_runs_command( 'remove' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( "Error: Failed-run action \"remove\" is invalid; use list, retry, or purge.\n", $result['stderr'] );
	}

	/**
	 * The real schedules command renders every public table column without a false dormant note.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[Group( 'degraded' )]
	public function test_schedules_list_renders_the_table(): void {
		$result = self::run_command_with_globals( 'schedules', array( '--require=' . self::INSPECTION_BOOTSTRAP ), 'list' );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame( '', $result['stderr'] );
		foreach ( array( 'owner', 'identity', 'recurrence', 'next_due', 'last_fired', 'misfires', 'skips', 'scheduled', 'lock' ) as $field ) {
			self::assertStringContainsString( $field, $result['stdout'] );
		}
		self::assertStringContainsString( self::INSPECTION_OWNER, $result['stdout'] );
		self::assertStringContainsString( self::INSPECTION_SCHEDULE, $result['stdout'] );
		self::assertStringContainsString( '300', $result['stdout'] );
		self::assertStringContainsString( 'yes', $result['stdout'] );
		self::assertStringContainsString( 'free', $result['stdout'] );
		self::assertStringNotContainsString( 'dormant occurrences are not visible', $result['stdout'] );
	}

	/**
	 * The real schedules command exposes the exact machine-readable owner-filtered row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedules_list_json_exposes_the_registered_row(): void {
		$result  = self::run_command_with_globals( 'schedules', array( '--require=' . self::INSPECTION_BOOTSTRAP ), 'list', '--owner=' . self::INSPECTION_OWNER, '--format=json' );
		$decoded = \json_decode( $result['stdout'], true, 512, \JSON_THROW_ON_ERROR );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame( '', $result['stderr'] );
		self::assertIsArray( $decoded );
		self::assertCount( 1, $decoded );
		$row = $decoded[0] ?? null;
		self::assertIsArray( $row );
		self::assertSame( array( 'owner', 'identity', 'recurrence', 'next_due', 'last_fired', 'misfires', 'skips', 'scheduled', 'lock' ), \array_keys( $row ) );
		self::assertSame( self::INSPECTION_OWNER, $row['owner'] ?? null );
		self::assertSame( self::INSPECTION_OWNER . ':' . self::INSPECTION_SCHEDULE, $row['identity'] ?? null );
		self::assertSame( 300, $row['recurrence'] ?? null );
		$next_due = $row['next_due'] ?? null;
		self::assertIsString( $next_due );
		self::assertMatchesRegularExpression( '/\A\d{4}-\d{2}-\d{2}T.*\+00:00 \(in \d+[smhd]\)\z/', $next_due );
		self::assertSame( 'never', $row['last_fired'] ?? null );
		self::assertSame( 0, $row['misfires'] ?? null );
		self::assertSame( 0, $row['skips'] ?? null );
		self::assertSame( 'yes', $row['scheduled'] ?? null );
		self::assertSame( 'free', $row['lock'] ?? null );
	}

	/**
	 * An unknown schedule owner exits successfully with the exact filtered empty state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedules_list_reports_the_filtered_empty_state(): void {
		$result = self::run_command_with_globals( 'schedules', array( '--require=' . self::INSPECTION_BOOTSTRAP ), 'list', '--owner=missing-owner' );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame( "No schedule registrations are persisted for owner \"missing-owner\".\n", $result['stdout'] );
		self::assertSame( '', $result['stderr'] );
	}

	/**
	 * A failed schedule-registry read renders as unavailable instead of an empty registry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing security
	 * @pin-rationale The injected database detail must not cross the documented CLI error boundary; an unavailable public result cannot otherwise prove the confidential query text was redacted.
	 *
	 * @return  void
	 */
	public function test_schedules_list_reports_an_authoritative_registry_read_failure(): void {
		$result = self::run_command_with_globals(
			'schedules',
			array(
				'--require=' . self::INSPECTION_BOOTSTRAP,
				'--require=' . self::FAILED_READ_BOOTSTRAP,
			),
			'list'
		);

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( "Error: Schedule registrations are unavailable because the authoritative database read failed; resolve the database error and try again.\n", $result['stderr'] );
		self::assertStringNotContainsString( 'a8csp_bgte_missing_option_rows', $result['stderr'], 'CLI failure output must redact the failed database query' );
	}

	/**
	 * A missing schedule action uses WP-CLI's native required-synopsis failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedules_without_an_action_uses_the_native_synopsis(): void {
		$result = self::run_command( 'schedules' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( "usage: wp background-tasks schedules <action> [--owner=<owner>] [--format=<format>]\n", $result['stdout'] );
		self::assertSame( '', $result['stderr'] );
	}

	/**
	 * The schedules decision seam owns unsupported format correction at the binary boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedules_list_rejects_an_invalid_format(): void {
		$result = self::run_command( 'schedules', 'list', '--format=ids' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( "Error: List format is invalid; use table, csv, json, count, or yaml.\n", $result['stderr'] );
	}

	/**
	 * Negated schedule value parameters reach the command seam as false.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedules_list_rejects_negated_value_parameters(): void {
		$owner = self::run_command( 'schedules', 'list', '--no-owner' );

		self::assertSame( 1, $owner['exit_code'] );
		self::assertSame( '', $owner['stdout'] );
		self::assertSame( "Error: Schedule list owner is invalid; pass a value with --owner=<owner>.\n", $owner['stderr'] );

		$format = self::run_command( 'schedules', 'list', '--no-format' );

		self::assertSame( 1, $format['exit_code'] );
		self::assertSame( '', $format['stdout'] );
		self::assertSame( "Error: List format is invalid; use table, csv, json, count, or yaml.\n", $format['stderr'] );
	}

	/**
	 * The real parser rejects an extra schedules-list positional before execution.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedules_list_rejects_an_extra_positional_argument(): void {
		$result = self::run_command( 'schedules', 'list', 'extra' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( "Error: Too many positional arguments: extra\n", $result['stderr'] );
	}

	/**
	 * The real runs command renders both live and recent-history table sections.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_runs_list_renders_live_and_recent_history_sections(): void {
		$builder   = StoreFixtureBuilder::for_identity( self::CANCEL_NAME );
		$args_hash = $builder->args_hash( array() );
		$now       = \time();
		$state     = new RunState( RunStatus::Running, true, array(), $args_hash, array( array() ), 0, 1, $now, $now );
		$fixtures  = array(
			$builder->run( self::CANONICAL_RUN_ID, $state ),
			$builder->history(
				array(
					array(
						'run_id'    => self::CANONICAL_RUN_ID,
						'args_hash' => $args_hash,
					),
				),
				array(
					array(
						'run_id'    => 'integration-cli-history-failed',
						'args_hash' => 'history-hash',
						'status'    => RunStatus::Failed,
					),
				)
			),
			$builder->failed( self::FAILED_AT, array(), new RunFailure( identity: self::CANCEL_NAME, run_id: 'integration-cli-history-failed', attempts: 2, stage: RunFailureStage::Execution, code: ApiErrorCode::ExecutionFailed, summary: 'CLI history failure.', failed_chunk: null, ), new EngineError( 'CLI history failure.' ) ),
		);
		foreach ( $fixtures as $fixture ) {
			self::persist_store_fixture( $fixture );
		}

		try {
			$result = self::run_runs_command( 'list', self::CANCEL_NAME );

			self::assertSame( 0, $result['exit_code'] );
			self::assertSame( '', $result['stderr'] );
			self::assertStringContainsString( 'live runs', $result['stdout'] );
			self::assertStringContainsString( 'recent history', $result['stdout'] );
			self::assertStringContainsString( self::CANONICAL_RUN_ID, $result['stdout'] );
			self::assertStringContainsString( 'executing', $result['stdout'] );
			self::assertStringContainsString( 'integration-cli-history-failed', $result['stdout'] );
			self::assertStringContainsString( 'failed store', $result['stdout'] );
			self::assertStringContainsString( '—', $result['stdout'] );
		} finally {
			foreach ( $fixtures as $fixture ) {
				\delete_option( $fixture[0] );
			}
		}
	}

	/**
	 * An unknown stable run name exits successfully with the exact empty state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_runs_list_reports_the_empty_state(): void {
		$result = self::run_runs_command( 'list', 'integration-cli-command:unknown-stable-name' );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame( "No live runs or history are retained for \"integration-cli-command:unknown-stable-name\".\n", $result['stdout'] );
		self::assertSame( '', $result['stderr'] );
	}

	/**
	 * A failed run-history read renders as unavailable instead of an empty history.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing security
	 * @pin-rationale The injected database detail must not cross the documented CLI warning boundary; an unavailable public result cannot otherwise prove the confidential query text was redacted.
	 *
	 * @return  void
	 */
	public function test_runs_list_reports_an_authoritative_history_read_failure(): void {
		$result = self::run_command_with_globals(
			'runs',
			array(
				'--require=' . self::INSPECTION_BOOTSTRAP,
				'--require=' . self::FAILED_READ_BOOTSTRAP,
			),
			'list',
			self::INSPECTION_TASK_IDENTITY
		);

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( "Warning: Recent run history is unavailable because an authoritative database read failed.\n", $result['stderr'] );
		self::assertStringNotContainsString( 'a8csp_bgte_missing_option_rows', $result['stderr'], 'CLI warning output must redact the failed database query' );
	}

	/**
	 * Missing run positionals use WP-CLI's native required-synopsis failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_runs_without_required_positionals_use_the_native_synopsis(): void {
		$expected = "usage: wp background-tasks runs <action> <identity> [--format=<format>]\n";

		foreach ( array( array(), array( 'list' ) ) as $arguments ) {
			$result = self::run_runs_command( ...$arguments );

			self::assertSame( 1, $result['exit_code'] );
			self::assertSame( $expected, $result['stdout'] );
			self::assertSame( '', $result['stderr'] );
		}
	}

	/**
	 * The real parser rejects an extra runs-list positional before execution.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_runs_list_rejects_an_extra_positional_argument(): void {
		$result = self::run_runs_command( 'list', self::CANCEL_NAME, 'extra' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( "Error: Too many positional arguments: extra\n", $result['stderr'] );
	}

	/**
	 * A negated run format reaches the command seam as false.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_runs_list_rejects_a_negated_format(): void {
		$result = self::run_runs_command( 'list', self::CANCEL_NAME, '--no-format' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( "Error: List format is invalid; use table, csv, json, count, or yaml.\n", $result['stderr'] );
	}

	/**
	 * The runs decision seam rejects invalid names and formats at the binary boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_runs_list_rejects_invalid_name_and_format(): void {
		$invalid_name = self::run_runs_command( 'list', 'Invalid Name' );

		self::assertSame( 1, $invalid_name['exit_code'] );
		self::assertSame( '', $invalid_name['stdout'] );
		self::assertSame( "Error: Run identity is invalid; use a composed {owner}:{name} identity.\n", $invalid_name['stderr'] );

		$invalid_format = self::run_runs_command( 'list', self::CANCEL_NAME, '--format=ids' );

		self::assertSame( 1, $invalid_format['exit_code'] );
		self::assertSame( '', $invalid_format['stdout'] );
		self::assertSame( "Error: List format is invalid; use table, csv, json, count, or yaml.\n", $invalid_format['stderr'] );
	}

	/**
	 * Schedule and run inspection survive one retryable failure on every supported backend set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[Group( 'degraded' )]
	public function test_seeded_waiting_run_renders_through_normal_and_degraded_backends(): void {
		$consumer        = \a8csp_bgte( self::INSPECTION_OWNER );
		$task            = new RecordingTask( self::INSPECTION_TASK );
		$task->throwable = new \RuntimeException( 'Retry the inspection fixture.' );
		$consumer->tasks()->register( $task );
		$schedule = new Schedule( self::INSPECTION_SCHEDULE, Recurrence::every( 300 ), self::INSPECTION_TASK, array( 'source' => 'schedule' ) );
		$synced   = $consumer->schedules()->sync( array( $schedule ) );
		self::assertInstanceOf( Success::class, $synced );
		$retry_policy = new RetryPolicy( max_attempts: 2, base_delay: 60, multiplier: 1, max_delay: 60 );
		\add_filter( 'a8csp_background_tasks/retry_policy/' . self::INSPECTION_TASK_IDENTITY, static fn (): RetryPolicy => $retry_policy );

		$enqueued = $consumer->tasks()->enqueue( self::INSPECTION_TASK, array( 'source' => 'manual' ) );
		self::assertInstanceOf( Success::class, $enqueued );
		self::assertIsString( $enqueued->value );
		$run_id = $enqueued->value;
		$this->expect_option( 'a8csp_bgte_latest_run_' . self::INSPECTION_TASK_IDENTITY );

		try {
			self::assertSame( 1, $this->run_next_engine_action() );

			$schedules = self::run_command_with_globals( 'schedules', array( '--require=' . self::INSPECTION_BOOTSTRAP ), 'list', '--owner=' . self::INSPECTION_OWNER, '--format=json' );
			$runs      = self::run_command_with_globals( 'runs', array( '--require=' . self::INSPECTION_BOOTSTRAP ), 'list', self::INSPECTION_TASK_IDENTITY, '--format=json' );

			self::assertSame( 0, $schedules['exit_code'] );
			self::assertSame( '', $schedules['stderr'] );
			self::assertStringNotContainsString( 'dormant occurrences are not visible', $schedules['stdout'] );
			$schedule_rows = \json_decode( $schedules['stdout'], true, 512, \JSON_THROW_ON_ERROR );
			self::assertIsArray( $schedule_rows );
			$schedule_row = $schedule_rows[0] ?? null;
			self::assertIsArray( $schedule_row );
			self::assertSame( self::INSPECTION_OWNER, $schedule_row['owner'] ?? null );
			self::assertSame( self::INSPECTION_OWNER . ':' . self::INSPECTION_SCHEDULE, $schedule_row['identity'] ?? null );
			self::assertSame( 'yes', $schedule_row['scheduled'] ?? null );
			self::assertSame( 'free', $schedule_row['lock'] ?? null );

			self::assertSame( 0, $runs['exit_code'] );
			self::assertSame( '', $runs['stderr'] );
			$run_rows = \json_decode( $runs['stdout'], true, 512, \JSON_THROW_ON_ERROR );
			self::assertIsArray( $run_rows );
			$live_rows    = array();
			$history_rows = array();
			foreach ( $run_rows as $run_row ) {
				self::assertIsArray( $run_row );
				if ( isset( $run_row['status'] ) ) {
					$live_rows[] = $run_row;
				}
				if ( isset( $run_row['outcome'] ) ) {
					$history_rows[] = $run_row;
				}
			}
			self::assertCount( 1, $live_rows );
			self::assertSame( $run_id, $live_rows[0]['run_id'] ?? null );
			self::assertSame( 'waiting', $live_rows[0]['phase'] ?? null );
			self::assertSame( 1, $live_rows[0]['attempts'] ?? null );
			self::assertSame( '—', $live_rows[0]['queue'] ?? null );
			$heartbeat = $live_rows[0]['heartbeat'] ?? null;
			self::assertIsString( $heartbeat );
			self::assertStringNotContainsString( '(stale)', $heartbeat );
			self::assertNotSame( array(), $history_rows );
			self::assertSame( $run_id, $history_rows[0]['run_id'] ?? null );
			self::assertSame( 'started', $history_rows[0]['outcome'] ?? null );
		} finally {
			$cancelled = $consumer->runs()->cancel( self::INSPECTION_TASK, $run_id );
			self::assertInstanceOf( Success::class, $cancelled );
		}
	}

	// endregion.

	// region HELPERS.

	/**
	 * Runs the registered command through wp-env's actual WP-CLI executable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string ...$arguments Arguments following the failed-runs command.
	 *
	 * @return  array{stdout: string, stderr: string, exit_code: int}
	 */
	private static function run_failed_runs_command( string ...$arguments ): array {
		return self::run_command( 'failed-runs', ...$arguments );
	}

	/**
	 * Runs the cancel command through wp-env's actual WP-CLI executable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string ...$arguments Arguments following the cancel command.
	 *
	 * @return  array{stdout: string, stderr: string, exit_code: int}
	 */
	private static function run_cancel_command( string ...$arguments ): array {
		return self::run_command( 'cancel', ...$arguments );
	}

	/**
	 * Runs the runs command through wp-env's actual WP-CLI executable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string ...$arguments Arguments following the runs command.
	 *
	 * @return  array{stdout: string, stderr: string, exit_code: int}
	 */
	private static function run_runs_command( string ...$arguments ): array {
		return self::run_command( 'runs', ...$arguments );
	}

	/**
	 * Runs one registered subcommand through wp-env's actual WP-CLI executable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $subcommand  Background-tasks subcommand.
	 * @param   string ...$arguments Arguments following the subcommand.
	 *
	 * @return  array{stdout: string, stderr: string, exit_code: int}
	 */
	private static function run_command( string $subcommand, string ...$arguments ): array {
		return self::run_command_with_globals( $subcommand, array(), ...$arguments );
	}

	/**
	 * Runs one registered subcommand with WP-CLI global arguments through the actual executable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<string> $global_arguments
	 *
	 * @param   string $subcommand       Background-tasks subcommand.
	 * @param   array  $global_arguments Arguments preceding the registered command.
	 * @param   string ...$arguments     Arguments following the subcommand.
	 *
	 * @return  array{stdout: string, stderr: string, exit_code: int}
	 */
	private static function run_command_with_globals(
		string $subcommand,
		array $global_arguments,
		string ...$arguments
	): array {
		$command = \array_values(
			\array_merge(
				array(
					'wp',
					'--path=' . self::WP_PATH,
					'--no-color',
				),
				$global_arguments,
				array(
					'background-tasks',
					$subcommand,
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
	 * Persists one deterministic run under the task registered in every child process.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   bool $executing Whether the fixture carries an admitted-delivery marker.
	 *
	 * @return  string Active-run option name.
	 */
	private function seed_cancel_run( bool $executing = false ): string {
		$args    = array( 'source' => 'cli-boundary' );
		$builder = StoreFixtureBuilder::for_identity( self::CANCEL_NAME );
		$now     = \time();
		$state   = new RunState( RunStatus::Running, $executing, $args, $builder->args_hash( $args ), array(), 0, 1, $now, $now );
		$fixture = $builder->run( self::RUN_ID, $state );
		self::persist_store_fixture( $fixture );

		return $fixture[0];
	}

	/**
	 * Persists one materialized zero-chunk batch waiting for cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string Active-run option name.
	 */
	private function seed_cancel_batch_pending_cleanup(): string {
		$args    = array( 'source' => 'cli-completeness-boundary' );
		$builder = StoreFixtureBuilder::for_identity( self::CANCEL_BATCH_NAME );
		$now     = \time();
		$state   = new RunState( RunStatus::Running, false, $args, $builder->args_hash( $args ), array(), 0, 2, $now, $now );
		$fixture = $builder->run( self::RUN_ID, $state );
		self::persist_store_fixture( $fixture );

		return $fixture[0];
	}

	/**
	 * Returns the deterministic cancel-completeness batch option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private static function cancel_batch_run_option_name(): string {
		return 'a8csp_bgte_run_' . self::CANCEL_BATCH_NAME . '_' . self::RUN_ID;
	}

	/**
	 * Persists one deterministic retained failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Complete owner-qualified background-work identity.
	 *
	 * @return  string Failed-run option name.
	 */
	private function seed_failed_run( string $name ): string {
		$builder = StoreFixtureBuilder::for_identity( $name );
		$fixture = $builder->failed( self::FAILED_AT, array( 'account_id' => 42 ), new RunFailure( identity: $name, run_id: self::RUN_ID, attempts: 3, stage: RunFailureStage::Execution, code: ApiErrorCode::ExecutionFailed, summary: 'CLI boundary failure.', failed_chunk: null, ), new EngineError( 'CLI boundary failure.', \RuntimeException::class ) );
		self::persist_store_fixture( $fixture );

		return $fixture[0];
	}

	/**
	 * Persists exact production-serialized fixture bytes through WordPress's option API.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{string, string} $fixture Option name and exact raw production value.
	 *
	 * @return  void
	 */
	private static function persist_store_fixture( array $fixture ): void {
		self::assertTrue( \update_option( $fixture[0], \maybe_unserialize( $fixture[1] ), false ), 'The production-generated store fixture must persist through the options API' );
	}

	/**
	 * Returns the failed-store option name for a stable background-work identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Complete owner-qualified background-work identity.
	 *
	 * @return  string
	 */
	private static function option_name( string $name ): string {
		return self::FAILED_OPTION_PREFIX . $name;
	}

	/**
	 * Returns WP-CLI's exact corrective purge usage error.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private static function purge_usage_error(): string {
		return 'Error: Purge requires exactly one identity or --all; ' . "use wp background-tasks failed-runs purge <identity> or purge --all.\n";
	}

	// endregion.
}

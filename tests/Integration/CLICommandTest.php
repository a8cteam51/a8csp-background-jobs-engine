<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\JobType;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Logging\ErrorLogSink;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Occurrences\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Success;
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
	private const string WP_PATH = '/var/www/html';

	/** Failed-run identity isolated to list coverage. */
	private const string LIST_STORE_NAME = 'integration-cli-command:integration-cli-command-list-store';

	/** Failed-run identity isolated to name-scoped purge coverage. */
	private const string PURGE_STORE_NAME = 'integration-cli-command:integration-cli-command-purge-store';

	/** Failed-run identity isolated to prefix-discovered purge coverage. */
	private const string ALL_STORE_NAME = 'integration-cli-command:integration-cli-command-all-store';

	/** Background-work identity deliberately absent from the child request's registries. */
	private const string UNREGISTERED_NAME = 'integration-cli-command:integration-cli-command-unregistered';

	/** Background-work identity registered by the engine in every WP-CLI child request. */
	private const string CANCEL_NAME = 'a8csp-jobs-engine:maintenance';

	/** Failed run retained beside the live canonical run in list coverage. */
	private const string HISTORY_FAILED_RUN_ID = '00000000001784029999-0000000000000000000';

	/** Background-work identity isolated to real reset state. */
	private const string RESET_NAME = 'integration-cli-command:integration-cli-command-reset-store';

	/** Chunked Job identity registered by the cancel-completeness WP-CLI bootstrap. */
	private const string CANCEL_CHUNKED_JOB_NAME = 'integration-cli-command:integration-cli-command-cancel-chunked-job';

	/** Test-only WP-CLI bootstrap that registers the cancel-completeness chunked job. */
	private const string CANCEL_CHUNKED_JOB_BOOTSTRAP = self::WP_PATH . '/wp-content/plugins/a8csp-background-jobs-engine/tests/Support/Fixtures/cli-cancel-chunked-job.php';

	/** Test-only WP-CLI bootstrap that declares the inspection job and schedule. */
	private const string INSPECTION_BOOTSTRAP = self::WP_PATH . '/wp-content/plugins/a8csp-background-jobs-engine/tests/Support/Fixtures/cli-inspection.php';

	/** Test-only WP-CLI bootstrap that fails the retained-run row read after name discovery. */
	private const string FAILED_READ_BOOTSTRAP = self::WP_PATH . '/wp-content/plugins/a8csp-background-jobs-engine/tests/Support/Fixtures/cli-failed-read.php';

	/** Owner declared in every isolated inspection request. */
	private const string INSPECTION_OWNER = 'integration-cli-inspection-owner';

	/** Schedule declared in every isolated inspection request. */
	private const string INSPECTION_SCHEDULE = 'inspection-schedule';

	/** Job declared in every isolated inspection request. */
	private const string INSPECTION_JOB = 'integration-cli-inspection-job';

	/** Owner-qualified job identity declared in every isolated inspection request. */
	private const string INSPECTION_JOB_IDENTITY = self::INSPECTION_OWNER . ':' . self::INSPECTION_JOB;

	/** Run identity shared by deterministic retained-failure fixtures. */
	private const string RUN_ID = '00000000001784030000-0000000000000000002';

	/** Canonical-format run identifier for rows the live-run enumeration must parse. */
	private const string CANONICAL_RUN_ID = '00000000001784030000-0000000000000000001';

	/** Deterministic failure time exposed by JSON output. */
	private const int FAILED_AT = 1_700_000_001;

	/** Prefix shared by dynamically named failed-run options. */
	private const string FAILED_OPTION_PREFIX = 'a8csp_bgje_failed_runs_';

	/** Owner isolated to real owner-scoped schedule removal. */
	private const string REMOVE_OWNER = 'integration-cli-remove-owner';

	/** Schedule isolated to real owner-scoped schedule removal. */
	private const string REMOVE_SCHEDULE = 'removable-schedule';

	/** Job isolated to real owner-scoped schedule removal. */
	private const string REMOVE_JOB = 'removable-job';

	/** Registration identity isolated to real owner-scoped schedule removal. */
	private const string REMOVE_KEY = self::REMOVE_OWNER . ':' . self::REMOVE_SCHEDULE;

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

		$this->expect_option( 'a8csp_bgje_schedule_registrations_a8csp-jobs-engine' );
		$this->expect_option( 'a8csp_bgje_schedule_registrations_' . self::INSPECTION_OWNER );
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
		self::assertSame( 'Success: Cancelled run ' . self::RUN_ID . ' of "a8csp-jobs-engine:maintenance".' . "\n", $result['stdout'] );
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
		self::assertSame( 'Error: Run "' . self::RUN_ID . '" is executing; a run in flight completes or fails on its own.' . "\n", $result['stderr'] );
	}

	/**
	 * The real command preserves the zero-chunk chunked job completeness refusal.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_surfaces_the_zero_chunk_completeness_refusal(): void {
		$this->expect_option( self::cancel_chunked_job_run_option_name() );
		$option_name = $this->seed_cancel_chunked_job_pending_cleanup();

		$result = self::run_command_with_globals( 'runs', array( '--require=' . self::CANCEL_CHUNKED_JOB_BOOTSTRAP ), 'cancel', self::CANCEL_CHUNKED_JOB_NAME, self::RUN_ID );
		self::assertTrue( \delete_option( $option_name ), 'The completeness fixture must remain retained after refusal' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( 'Error: Run "' . self::RUN_ID . '" has no chunks left to process; the pending cleanup completes it.' . "\n", $result['stderr'] );
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
		self::assertSame( 'Error: Background-work "integration-cli-command:integration-cli-command-unregistered" is not registered; ' . "register the matching job or chunked job before cancelling its run.\n", $result['stderr'] );
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
		self::assertSame( 'Error: Run "' . self::RUN_ID . '" for background-work ' . "\"a8csp-jobs-engine:maintenance\" is not retained; nothing remains to cancel.\n", $result['stderr'] );
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
		self::assertSame( "usage: wp background-jobs runs <action> <identity> [<run_id>] [--format=<format>]\n", $result['stdout'] );
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
			'"run_id":"' . self::RUN_ID . '",' .
			'"failed_at":"2023-11-14T22:13:21+00:00","attempts":3,"stage":"execution","code":"execution_failed",' .
			'"error_class":"RuntimeException","error_message":"CLI boundary failure.","failed_chunk":null}]',
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
		self::assertStringNotContainsString( 'a8csp_bgje_missing_option_rows', $result['stderr'], 'CLI failure output must redact the failed database query' );
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
		self::assertSame( 'Error: Background-work "integration-cli-command:integration-cli-command-unregistered" is not registered; ' . "register the matching job or chunked job before retrying its failed run.\n", $result['stderr'] );
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
	 * The acknowledged real command clears persisted rows and its maintenance occurrence; a later
	 * process boot recreates only the reserved maintenance registration and occurrence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_reset_yes_converges_engine_state_and_the_next_boot_recreates_maintenance(): void {
		$this->seed_failed_run( self::RESET_NAME );
		$builder = StoreFixtureBuilder::for_identity( self::RESET_NAME );
		self::persist_store_fixture(
			$builder->latest(
				array(
					array(
						'run_id'    => self::RUN_ID,
						'args_hash' => $builder->args_hash( array( 'source' => 'reset-boundary' ) ),
					),
				)
			)
		);
		$action_id = \as_schedule_recurring_action( \time() + 300, 300, OccurrenceDelivery::SCHEDULE_HOOK, array( self::CANCEL_NAME ), self::CANCEL_NAME, true, 10 );
		self::assertGreaterThan( 0, $action_id );
		self::assertTrue( \as_has_scheduled_action( OccurrenceDelivery::SCHEDULE_HOOK, array( self::CANCEL_NAME ), self::CANCEL_NAME ) );

		$result = self::run_command( 'reset', '--yes' );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame( "Option rows deleted: 3\nPending backend actions unscheduled: 1\nSuccess: Background jobs development state reset.\n", $result['stdout'] );
		self::assertSame( '', $result['stderr'] );
		self::assertSame( array(), $this->engine_option_rows() );
		self::assertFalse( \as_has_scheduled_action( OccurrenceDelivery::SCHEDULE_HOOK, array( self::CANCEL_NAME ), self::CANCEL_NAME ) );

		$next_boot = self::run_failed_runs_command( 'list' );

		self::assertSame( 0, $next_boot['exit_code'] );
		self::assertSame( "No failed runs are retained.\n", $next_boot['stdout'] );
		self::assertSame( '', $next_boot['stderr'] );
		self::assertSame( array( 'a8csp_bgje_schedule_registrations_a8csp-jobs-engine' ), \array_column( $this->engine_option_rows(), 'option_name' ) );
		self::assertTrue( \as_has_scheduled_action( OccurrenceDelivery::SCHEDULE_HOOK, array( self::CANCEL_NAME ), self::CANCEL_NAME ) );

		$cleanup = self::run_command( 'reset', '--yes' );

		self::assertSame( 0, $cleanup['exit_code'] );
		self::assertSame( "Option rows deleted: 1\nPending backend actions unscheduled: 1\nSuccess: Background jobs development state reset.\n", $cleanup['stdout'] );
		self::assertSame( '', $cleanup['stderr'] );
		self::assertSame( array(), $this->engine_option_rows() );
		self::assertFalse( \as_has_scheduled_action( OccurrenceDelivery::SCHEDULE_HOOK, array( self::CANCEL_NAME ), self::CANCEL_NAME ) );
	}

	/**
	 * An extra positional is rejected by the real WP-CLI synopsis before reset can execute.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_reset_rejects_an_extra_positional_argument(): void {
		$result = self::run_command( 'reset', 'extra', '--yes' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( "Error: Too many positional arguments: extra\n", $result['stderr'] );
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
		foreach ( array( 'owner', 'identity', 'recurrence', 'next_due', 'last_fired', 'misfire_skips', 'overlap_skips', 'occurrence_visible', 'lock' ) as $field ) {
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
		self::assertSame( array( 'owner', 'identity', 'recurrence', 'next_due', 'last_fired', 'misfire_skips', 'overlap_skips', 'occurrence_visible', 'lock' ), \array_keys( $row ) );
		self::assertSame( self::INSPECTION_OWNER, $row['owner'] ?? null );
		self::assertSame( self::INSPECTION_OWNER . ':' . self::INSPECTION_SCHEDULE, $row['identity'] ?? null );
		self::assertSame( 300, $row['recurrence'] ?? null );
		$next_due = $row['next_due'] ?? null;
		self::assertIsString( $next_due );
		self::assertMatchesRegularExpression( '/\A\d{4}-\d{2}-\d{2}T.*\+00:00 \(in \d+[smhd]\)\z/', $next_due );
		self::assertSame( 'never', $row['last_fired'] ?? null );
		self::assertSame( 0, $row['misfire_skips'] ?? null );
		self::assertSame( 0, $row['overlap_skips'] ?? null );
		self::assertSame( 'yes', $row['occurrence_visible'] ?? null );
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
	 * The real remove command clears an escalated zombie registry and recurring chain, then reports not found.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale An isolated request cannot withdraw a declaration after sync, so a production-built durable row plus direct delivery-hook drives reproduce later undeclared requests before the WP-CLI process removes that exact escalated state.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedules_remove_converges_one_owner_and_is_not_silently_idempotent(): void {
		$option_name = ScheduleRegistry::option_name( self::REMOVE_OWNER );
		$this->expect_option( $option_name );
		$schedule = new Schedule( self::REMOVE_SCHEDULE, Recurrence::every( 300 ), self::REMOVE_JOB );
		$fixture  = StoreFixtureBuilder::for_identity( self::REMOVE_KEY )->schedule_registration(
			array(
				'owner'         => self::REMOVE_OWNER,
				'declarations'  => array(
					self::REMOVE_KEY => array(
						'schedule' => $schedule,
						'job'      => self::REMOVE_OWNER . ':' . self::REMOVE_JOB,
					),
				),
				'registrations' => array(
					self::REMOVE_KEY => StoreFixtureBuilder::schedule_registration_state( $schedule->fingerprint(), \time() - 1 ),
				),
			)
		);
		self::persist_store_fixture( $fixture );
		$action_id = \as_schedule_recurring_action( \time() - 1, 300, OccurrenceDelivery::SCHEDULE_HOOK, array( self::REMOVE_KEY ), self::REMOVE_KEY, true, 10 );
		self::assertGreaterThan( 0, $action_id );
		self::assertTrue( \as_has_scheduled_action( OccurrenceDelivery::SCHEDULE_HOOK, array( self::REMOVE_KEY ), self::REMOVE_KEY ) );

		/** @var list<array{string, string, array<array-key, mixed>}> $log_records */
		$log_records = array();
		\remove_action( 'a8csp_jobs_engine/log', array( ErrorLogSink::class, 'log' ), 10 );
		\add_action(
			'a8csp_jobs_engine/log',
			static function ( string $level, string $message, array $context ) use ( &$log_records ): void {
				$log_records[] = array( $level, $message, $context );
			},
			10,
			3
		);
		for ( $occurrence = 0; $occurrence < 4; ++$occurrence ) {
			\do_action( OccurrenceDelivery::SCHEDULE_HOOK, self::REMOVE_KEY );
		}
		$warnings = \array_values(
			\array_filter(
				$log_records,
				static fn ( array $record ): bool => 'warning' === $record[0] && \str_contains( $record[1], 'fired undeclared' )
			)
		);
		self::assertCount( 1, $warnings );

		$removed = self::run_command( 'schedules', 'remove', self::REMOVE_OWNER, '--yes' );

		self::assertSame( 0, $removed['exit_code'] );
		self::assertSame( 'Success: Removed every persisted schedule registration for owner "' . self::REMOVE_OWNER . '".' . "\n", $removed['stdout'] );
		self::assertSame( '', $removed['stderr'] );
		\wp_cache_delete( $option_name, 'options' );
		$missing = new \stdClass();
		self::assertSame( $missing, \get_option( $option_name, $missing ) );
		self::assertFalse( \as_has_scheduled_action( OccurrenceDelivery::SCHEDULE_HOOK, array( self::REMOVE_KEY ), self::REMOVE_KEY ) );

		$list = self::run_command( 'schedules', 'list', '--format=json' );
		self::assertSame( 0, $list['exit_code'] );
		self::assertSame( '', $list['stderr'] );
		self::assertStringNotContainsString( self::REMOVE_OWNER, $list['stdout'] );

		$repeat = self::run_command( 'schedules', 'remove', self::REMOVE_OWNER, '--yes' );
		self::assertSame( 1, $repeat['exit_code'] );
		self::assertSame( '', $repeat['stdout'] );
		self::assertSame( 'Error: No schedule registrations are persisted for owner "' . self::REMOVE_OWNER . '".' . "\n", $repeat['stderr'] );
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
		self::assertStringNotContainsString( 'a8csp_bgje_missing_option_rows', $result['stderr'], 'CLI failure output must redact the failed database query' );
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
		self::assertSame( "usage: wp background-jobs schedules <action> [<owner>] [--owner=<owner>] [--format=<format>] [--yes]\n", $result['stdout'] );
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
	 * The command guard rejects a remove-only owner positional on schedule listing.
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
		self::assertSame( "Error: Schedule list accepts only --owner and --format; use wp background-jobs schedules list [--owner=<owner>] [--format=<format>].\n", $result['stderr'] );
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
		$state     = new RunState( RunStatus::Running, JobType::Job, true, array(), $args_hash, array( array() ), 0, 1, $now, $now );
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
						'run_id'    => self::HISTORY_FAILED_RUN_ID,
						'args_hash' => 'history-hash',
						'status'    => RunStatus::Failed,
					),
				)
			),
			$builder->failed( self::FAILED_AT, array(), new RunFailure( identity: self::CANCEL_NAME, run_id: RunId::from( self::HISTORY_FAILED_RUN_ID ), attempts: 2, stage: RunFailureStage::Execution, code: ErrorCode::ExecutionFailed, summary: 'CLI history failure.', failed_chunk: null, ), new EngineError( 'CLI history failure.' ) ),
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
			self::assertStringContainsString( self::HISTORY_FAILED_RUN_ID, $result['stdout'] );
			self::assertStringContainsString( 'failed_store', $result['stdout'] );
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
			self::INSPECTION_JOB_IDENTITY
		);

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame( "Warning: Recent run history is unavailable because an authoritative database read failed.\n", $result['stderr'] );
		self::assertStringNotContainsString( 'a8csp_bgje_missing_option_rows', $result['stderr'], 'CLI warning output must redact the failed database query' );
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
		$expected = "usage: wp background-jobs runs <action> <identity> [<run_id>] [--format=<format>]\n";

		foreach ( array( array(), array( 'list' ) ) as $arguments ) {
			$result = self::run_runs_command( ...$arguments );

			self::assertSame( 1, $result['exit_code'] );
			self::assertSame( $expected, $result['stdout'] );
			self::assertSame( '', $result['stderr'] );
		}
	}

	/**
	 * The command guard rejects an extra runs-list positional before execution.
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
		self::assertSame( "Error: Run list requires exactly one identity and accepts only --format; use wp background-jobs runs list <identity> [--format=<format>].\n", $result['stderr'] );
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
		$this->expectOutputRegex( '/Run attempt failed and was scheduled for retry/' );
		$client         = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::client( self::INSPECTION_OWNER );
		$job            = new RecordingJob( self::INSPECTION_JOB );
		$job->throwable = new \RuntimeException( 'Retry the inspection fixture.' );
		$client->jobs()->register( $job );
		$schedule = new Schedule( self::INSPECTION_SCHEDULE, Recurrence::every( 300 ), self::INSPECTION_JOB, array( 'source' => 'schedule' ) );
		$synced   = $client->schedules()->sync( array( $schedule ) );
		self::assertInstanceOf( Success::class, $synced );
		$retry_policy = new RetryPolicy( max_attempts: 2, base_delay: 60, multiplier: 1, max_delay: 60 );
		\add_filter( 'a8csp_jobs_engine/retry_policy/' . self::INSPECTION_JOB_IDENTITY, static fn (): RetryPolicy => $retry_policy );

		$enqueued = $client->jobs()->enqueue( self::INSPECTION_JOB, array( 'source' => 'manual' ) );
		self::assertInstanceOf( Success::class, $enqueued );
		self::assertIsString( $enqueued->value );
		$run_id = $enqueued->value;
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::INSPECTION_JOB_IDENTITY );

		try {
			self::assertSame( 1, $this->run_next_engine_action() );

			$schedules = self::run_command_with_globals( 'schedules', array( '--require=' . self::INSPECTION_BOOTSTRAP ), 'list', '--owner=' . self::INSPECTION_OWNER, '--format=json' );
			$runs      = self::run_command_with_globals( 'runs', array( '--require=' . self::INSPECTION_BOOTSTRAP ), 'list', self::INSPECTION_JOB_IDENTITY, '--format=json' );

			self::assertSame( 0, $schedules['exit_code'] );
			self::assertSame( '', $schedules['stderr'] );
			self::assertStringNotContainsString( 'dormant occurrences are not visible', $schedules['stdout'] );
			$schedule_rows = \json_decode( $schedules['stdout'], true, 512, \JSON_THROW_ON_ERROR );
			self::assertIsArray( $schedule_rows );
			$schedule_row = $schedule_rows[0] ?? null;
			self::assertIsArray( $schedule_row );
			self::assertSame( self::INSPECTION_OWNER, $schedule_row['owner'] ?? null );
			self::assertSame( self::INSPECTION_OWNER . ':' . self::INSPECTION_SCHEDULE, $schedule_row['identity'] ?? null );
			self::assertSame( 'yes', $schedule_row['occurrence_visible'] ?? null );
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
			$cancelled = $client->runs()->cancel( self::INSPECTION_JOB, $run_id );
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
	 * @param   string ...$arguments Arguments following the runs cancel action.
	 *
	 * @return  array{stdout: string, stderr: string, exit_code: int}
	 */
	private static function run_cancel_command( string ...$arguments ): array {
		return self::run_command( 'runs', 'cancel', ...$arguments );
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
	 * @param   string $subcommand  Background-jobs subcommand.
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
	 * @param   string $subcommand       Background-jobs subcommand.
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
					'background-jobs',
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
	 * Persists one deterministic run under the job registered in every child process.
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
		$state   = new RunState( RunStatus::Running, JobType::Job, $executing, $args, $builder->args_hash( $args ), array(), 0, 1, $now, $now );
		$fixture = $builder->run( self::RUN_ID, $state );
		self::persist_store_fixture( $fixture );

		return $fixture[0];
	}

	/**
	 * Persists one materialized zero-chunk chunked job waiting for cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string Active-run option name.
	 */
	private function seed_cancel_chunked_job_pending_cleanup(): string {
		$args    = array( 'source' => 'cli-completeness-boundary' );
		$builder = StoreFixtureBuilder::for_identity( self::CANCEL_CHUNKED_JOB_NAME );
		$now     = \time();
		$state   = new RunState( RunStatus::Running, JobType::ChunkedJob, false, $args, $builder->args_hash( $args ), array(), 0, 2, $now, $now );
		$fixture = $builder->run( self::RUN_ID, $state );
		self::persist_store_fixture( $fixture );

		return $fixture[0];
	}

	/**
	 * Returns the deterministic cancel-completeness chunked job option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private static function cancel_chunked_job_run_option_name(): string {
		return 'a8csp_bgje_run_' . self::CANCEL_CHUNKED_JOB_NAME . '_' . self::RUN_ID;
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
		$fixture = $builder->failed( self::FAILED_AT, array( 'account_id' => 42 ), new RunFailure( identity: $name, run_id: RunId::from( self::RUN_ID ), attempts: 3, stage: RunFailureStage::Execution, code: ErrorCode::ExecutionFailed, summary: 'CLI boundary failure.', failed_chunk: null, ), new EngineError( 'CLI boundary failure.', \RuntimeException::class ) );
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
		return 'Error: Purge requires exactly one identity or --all; ' . "use wp background-jobs failed-runs purge <identity> or purge --all.\n";
	}

	// endregion.
}

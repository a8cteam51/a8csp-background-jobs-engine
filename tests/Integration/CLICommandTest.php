<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\Clock\SystemClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins command registration, WP-CLI argument normalization, and output at the process boundary.
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
	private const CANCEL_BATCH_BOOTSTRAP = self::WP_PATH
		. '/wp-content/plugins/a8csp-background-tasks-engine/tests/Support/Fixtures/cli-cancel-batch.php';

	/** Test-only WP-CLI bootstrap that declares the inspection task and schedule. */
	private const INSPECTION_BOOTSTRAP = self::WP_PATH
		. '/wp-content/plugins/a8csp-background-tasks-engine/tests/Support/Fixtures/cli-inspection.php';

	/** Test-only WP-CLI bootstrap that fails the retained-run row read after name discovery. */
	private const FAILED_READ_BOOTSTRAP = self::WP_PATH
		. '/wp-content/plugins/a8csp-background-tasks-engine/tests/Support/Fixtures/cli-failed-read.php';

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
	 * A retained run is cancelled through the real command with declarative success output.
	 *
	 * @return  void
	 */
	public function test_cancel_terminalizes_a_retained_run(): void {
		$this->seed_cancel_run();

		$result = self::run_cancel_command( self::CANCEL_NAME, self::RUN_ID );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame(
			"Success: Cancelled run integration-cli-command-run-1 of \"a8csp-bgte:maintenance\".\n",
			$result['stdout']
		);
		self::assertSame( '', $result['stderr'] );
		$run_read = self::option_rows()->read( self::cancel_run_option_name() );
		if ( $run_read->is_failure() ) {
			self::fail( 'The cancelled run option could not be read.' );
		}
		self::assertNull( $run_read->value );
		\wp_cache_delete( self::cancel_run_option_name(), 'options' );

		$history_read = self::option_rows()->read( 'a8csp_bgte_history_' . self::CANCEL_NAME );
		if ( $history_read->is_failure() ) {
			self::fail( 'The cancelled run history could not be read.' );
		}
		$history_raw = $history_read->value;
		self::assertIsString( $history_raw );
		$history = \maybe_unserialize( $history_raw );
		self::assertIsArray( $history );
		self::assertSame(
			array(
				array(
					'run_id' => self::RUN_ID,
					'status' => 'cancelled',
				),
			),
			$history['terminal'] ?? null
		);
	}

	/**
	 * The real command preserves the engine's executing-run refusal.
	 *
	 * @return  void
	 */
	public function test_cancel_surfaces_the_executing_refusal(): void {
		$run_store = $this->seed_cancel_run( true );

		$result = self::run_cancel_command( self::CANCEL_NAME, self::RUN_ID );
		self::assertTrue( $run_store->delete( self::RUN_ID ), 'The executing boundary fixture must be removable after refusal' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame(
			"Error: Run \"integration-cli-command-run-1\" is executing; a run in flight completes or fails on its own.\n",
			$result['stderr']
		);
	}

	/**
	 * The real command preserves the zero-chunk batch completeness refusal.
	 *
	 * @return  void
	 */
	public function test_cancel_surfaces_the_zero_chunk_completeness_refusal(): void {
		$this->expect_option( self::cancel_batch_run_option_name() );
		$run_store = $this->seed_cancel_batch_pending_cleanup();

		$result = self::run_command_with_globals(
			'cancel',
			array( '--require=' . self::CANCEL_BATCH_BOOTSTRAP ),
			self::CANCEL_BATCH_NAME,
			self::RUN_ID
		);
		self::assertTrue( $run_store->delete( self::RUN_ID ), 'The completeness fixture must remain retained after refusal' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame(
			"Error: Run \"integration-cli-command-run-1\" has no chunks left to process; the pending cleanup completes it.\n",
			$result['stderr']
		);
	}

	/**
	 * The real command preserves the engine's unregistered-name correction.
	 *
	 * @return  void
	 */
	public function test_cancel_surfaces_the_unregistered_engine_error(): void {
		$result = self::run_cancel_command( self::UNREGISTERED_NAME, self::RUN_ID );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame(
			'Error: Background-work "integration-cli-command:integration-cli-command-unregistered" is not registered; ' .
			"register the matching task or batch before cancelling its run.\n",
			$result['stderr']
		);
	}

	/**
	 * The real command preserves the engine's registered-but-unretained correction.
	 *
	 * @return  void
	 */
	public function test_cancel_surfaces_the_not_retained_engine_error(): void {
		$result = self::run_cancel_command( self::CANCEL_NAME, self::RUN_ID );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame(
			'Error: Run "integration-cli-command-run-1" for background-work ' .
			"\"a8csp-bgte:maintenance\" is not retained; nothing remains to cancel.\n",
			$result['stderr']
		);
	}

	/**
	 * Missing required identities use WP-CLI's native required-synopsis failure.
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
	 * @return  void
	 */
	public function test_failed_list_json_exposes_the_exact_retained_row(): void {
		$this->expect_option( self::option_name( self::LIST_STORE_NAME ) );
		$this->seed_failed_run( self::LIST_STORE_NAME );

		$result = self::run_failed_runs_command( 'list', '--format=json' );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame(
			'[{"owner":"integration-cli-command","name":"integration-cli-command:integration-cli-command-list-store",' .
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
	 * @return  void
	 */
	public function test_failed_list_reports_an_authoritative_store_read_failure(): void {
		$this->expect_option( self::option_name( self::LIST_STORE_NAME ) );
		$this->seed_failed_run( self::LIST_STORE_NAME );

		$result = self::run_command_with_globals(
			'failed-runs',
			array( '--require=' . self::FAILED_READ_BOOTSTRAP ),
			'list'
		);

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame(
			'Error: Failed runs for "integration-cli-command:integration-cli-command-list-store" are unavailable because the authoritative ' .
			"database read failed; resolve the database error and try again.\n",
			$result['stderr']
		);
	}

	/**
	 * Retry preserves the engine's corrective failure for an unregistered background-work identity.
	 *
	 * @return  void
	 */
	public function test_failed_retry_surfaces_the_unregistered_engine_error(): void {
		$result = self::run_failed_runs_command( 'retry', self::UNREGISTERED_NAME, self::RUN_ID );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame(
			'Error: Background-work "integration-cli-command:integration-cli-command-unregistered" is not registered; ' .
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

		$result = self::run_failed_runs_command( 'purge', self::PURGE_STORE_NAME );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame(
			"Success: Purged 1 failed run for \"integration-cli-command:integration-cli-command-purge-store\".\n",
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

		$result = self::run_failed_runs_command( 'purge', '--all' );

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
		$result = self::run_failed_runs_command( 'purge', '--no-all' );

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
		$result = self::run_failed_runs_command( 'purge' );

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
		$result = self::run_failed_runs_command( 'remove' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame(
			"Error: Failed-run action \"remove\" is invalid; use list, retry, or purge.\n",
			$result['stderr']
		);
	}

	/**
	 * The real schedules command renders every public table column without a false dormant note.
	 *
	 * @return  void
	 */
	#[Group( 'degraded' )]
	public function test_schedules_list_renders_the_table(): void {
		$result = self::run_command_with_globals(
			'schedules',
			array( '--require=' . self::INSPECTION_BOOTSTRAP ),
			'list'
		);

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame( '', $result['stderr'] );
		foreach ( array( 'owner', 'name', 'recurrence', 'next_due', 'last_fired', 'misfires', 'skips', 'scheduled', 'lock' ) as $field ) {
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
	 * @return  void
	 */
	public function test_schedules_list_json_exposes_the_registered_row(): void {
		$result  = self::run_command_with_globals(
			'schedules',
			array( '--require=' . self::INSPECTION_BOOTSTRAP ),
			'list',
			'--owner=' . self::INSPECTION_OWNER,
			'--format=json'
		);
		$decoded = \json_decode( $result['stdout'], true, 512, \JSON_THROW_ON_ERROR );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame( '', $result['stderr'] );
		self::assertIsArray( $decoded );
		self::assertCount( 1, $decoded );
		$row = $decoded[0] ?? null;
		self::assertIsArray( $row );
		self::assertSame(
			array( 'owner', 'name', 'recurrence', 'next_due', 'last_fired', 'misfires', 'skips', 'scheduled', 'lock' ),
			\array_keys( $row )
		);
		self::assertSame( self::INSPECTION_OWNER, $row['owner'] ?? null );
		self::assertSame( self::INSPECTION_OWNER . ':' . self::INSPECTION_SCHEDULE, $row['name'] ?? null );
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
	 * @return  void
	 */
	public function test_schedules_list_reports_the_filtered_empty_state(): void {
		$result = self::run_command_with_globals(
			'schedules',
			array( '--require=' . self::INSPECTION_BOOTSTRAP ),
			'list',
			'--owner=missing-owner'
		);

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame( "No schedule registrations are persisted for owner \"missing-owner\".\n", $result['stdout'] );
		self::assertSame( '', $result['stderr'] );
	}

	/**
	 * A failed schedule-registry read renders as unavailable instead of an empty registry.
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
		self::assertSame(
			"Error: Schedule registrations are unavailable because the authoritative database read failed; resolve the database error and try again.\n",
			$result['stderr']
		);
	}

	/**
	 * A missing schedule action uses WP-CLI's native required-synopsis failure.
	 *
	 * @return  void
	 */
	public function test_schedules_without_an_action_uses_the_native_synopsis(): void {
		$result = self::run_command( 'schedules' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame(
			"usage: wp background-tasks schedules <action> [--owner=<owner>] [--format=<format>]\n",
			$result['stdout']
		);
		self::assertSame( '', $result['stderr'] );
	}

	/**
	 * The schedules decision seam owns unsupported format correction at the binary boundary.
	 *
	 * @return  void
	 */
	public function test_schedules_list_rejects_an_invalid_format(): void {
		$result = self::run_command( 'schedules', 'list', '--format=ids' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame(
			"Error: List format is invalid; use table, csv, json, count, or yaml.\n",
			$result['stderr']
		);
	}

	/**
	 * Negated schedule value parameters reach the command seam as false.
	 *
	 * @return  void
	 */
	public function test_schedules_list_rejects_negated_value_parameters(): void {
		$owner = self::run_command( 'schedules', 'list', '--no-owner' );

		self::assertSame( 1, $owner['exit_code'] );
		self::assertSame( '', $owner['stdout'] );
		self::assertSame(
			"Error: Schedule list owner is invalid; pass a value with --owner=<owner>.\n",
			$owner['stderr']
		);

		$format = self::run_command( 'schedules', 'list', '--no-format' );

		self::assertSame( 1, $format['exit_code'] );
		self::assertSame( '', $format['stdout'] );
		self::assertSame(
			"Error: List format is invalid; use table, csv, json, count, or yaml.\n",
			$format['stderr']
		);
	}

	/**
	 * The real parser rejects an extra schedules-list positional before execution.
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
	 * @return  void
	 */
	public function test_runs_list_renders_live_and_recent_history_sections(): void {
		$rows       = self::option_rows();
		$run_store  = new RunStore( self::CANCEL_NAME, new SystemClock(), $rows );
		$live_state = $run_store->create( self::CANONICAL_RUN_ID, array(), self::args_hash( array() ), array( array() ) );
		self::assertNotNull( $live_state );
		self::assertIsString(
			$run_store->transition_state( self::CANONICAL_RUN_ID, $live_state, $live_state->with_executing( true ) )
		);
		$history = new RunHistory( self::CANCEL_NAME, $rows );
		self::assertTrue( $history->record_started( self::CANONICAL_RUN_ID, self::args_hash( array() ) ) );
		self::assertTrue( $history->record_terminal( 'integration-cli-history-failed', 'history-hash', RunStatus::Failed ) );
		$failed_store = new FailedRunStore( self::CANCEL_NAME, $rows );
		self::assertTrue(
			$failed_store->record(
				'integration-cli-history-failed',
				self::FAILED_AT,
				array(),
				2,
				new EngineError( 'CLI history failure.' ),
				new RunFailure(
					name: self::CANCEL_NAME,
					run_id: 'integration-cli-history-failed',
					attempts: 2,
					stage: 'execution',
					code: ApiErrorCode::ExecutionFailed,
					summary: 'CLI history failure.',
					failed_chunk: null,
				)
			)
		);

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
			$run_store->delete( self::CANONICAL_RUN_ID );
			$failed_store->purge();
		}
	}

	/**
	 * An unknown stable run name exits successfully with the exact empty state.
	 *
	 * @return  void
	 */
	public function test_runs_list_reports_the_empty_state(): void {
		$result = self::run_runs_command( 'list', 'integration-cli-command:unknown-stable-name' );

		self::assertSame( 0, $result['exit_code'] );
		self::assertSame(
			"No live runs or history are retained for \"integration-cli-command:unknown-stable-name\".\n",
			$result['stdout']
		);
		self::assertSame( '', $result['stderr'] );
	}

	/**
	 * A failed run-history read renders as unavailable instead of an empty history.
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
		self::assertSame(
			"Warning: Recent run history is unavailable because an authoritative database read failed.\n",
			$result['stderr']
		);
	}

	/**
	 * Missing run positionals use WP-CLI's native required-synopsis failure.
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
	 * @return  void
	 */
	public function test_runs_list_rejects_a_negated_format(): void {
		$result = self::run_runs_command( 'list', self::CANCEL_NAME, '--no-format' );

		self::assertSame( 1, $result['exit_code'] );
		self::assertSame( '', $result['stdout'] );
		self::assertSame(
			"Error: List format is invalid; use table, csv, json, count, or yaml.\n",
			$result['stderr']
		);
	}

	/**
	 * The runs decision seam rejects invalid names and formats at the binary boundary.
	 *
	 * @return  void
	 */
	public function test_runs_list_rejects_invalid_name_and_format(): void {
		$invalid_name = self::run_runs_command( 'list', 'Invalid Name' );

		self::assertSame( 1, $invalid_name['exit_code'] );
		self::assertSame( '', $invalid_name['stdout'] );
		self::assertSame(
			"Error: Run identity is invalid; use a composed {owner}:{name} identity.\n",
			$invalid_name['stderr']
		);

		$invalid_format = self::run_runs_command( 'list', self::CANCEL_NAME, '--format=ids' );

		self::assertSame( 1, $invalid_format['exit_code'] );
		self::assertSame( '', $invalid_format['stdout'] );
		self::assertSame(
			"Error: List format is invalid; use table, csv, json, count, or yaml.\n",
			$invalid_format['stderr']
		);
	}

	/**
	 * Schedule and run inspection survive one retryable failure on every supported backend set.
	 *
	 * @return  void
	 */
	#[Group( 'degraded' )]
	public function test_seeded_waiting_run_renders_through_normal_and_degraded_backends(): void {
		$consumer        = \a8csp_bgte( self::INSPECTION_OWNER );
		$task            = new RecordingTask( self::INSPECTION_TASK );
		$task->throwable = new \RuntimeException( 'Retry the inspection fixture.' );
		$consumer->tasks()->register( $task );
		$schedule = new Schedule(
			self::INSPECTION_SCHEDULE,
			Recurrence::every( 300 ),
			self::INSPECTION_TASK,
			array( 'source' => 'schedule' )
		);
		$synced   = $consumer->schedules()->sync( array( $schedule ) );
		self::assertInstanceOf( Success::class, $synced );
		$retry_policy = new RetryPolicy( max_attempts: 2, base_delay: 60, multiplier: 1, max_delay: 60 );
		\add_filter(
			'a8csp_background_tasks/retry_policy/' . self::INSPECTION_TASK_IDENTITY,
			static fn (): RetryPolicy => $retry_policy
		);

		$enqueued = $consumer->tasks()->enqueue( self::INSPECTION_TASK, array( 'source' => 'manual' ) );
		self::assertInstanceOf( Success::class, $enqueued );
		self::assertIsString( $enqueued->value );
		$run_id = $enqueued->value;
		$this->expect_option( 'a8csp_bgte_latest_' . self::INSPECTION_TASK_IDENTITY );

		try {
			self::assertSame( 1, $this->run_next_engine_action() );

			$schedules = self::run_command_with_globals(
				'schedules',
				array( '--require=' . self::INSPECTION_BOOTSTRAP ),
				'list',
				'--owner=' . self::INSPECTION_OWNER,
				'--format=json'
			);
			$runs      = self::run_command_with_globals(
				'runs',
				array( '--require=' . self::INSPECTION_BOOTSTRAP ),
				'list',
				self::INSPECTION_TASK_IDENTITY,
				'--format=json'
			);

			self::assertSame( 0, $schedules['exit_code'] );
			self::assertSame( '', $schedules['stderr'] );
			self::assertStringNotContainsString( 'dormant occurrences are not visible', $schedules['stdout'] );
			$schedule_rows = \json_decode( $schedules['stdout'], true, 512, \JSON_THROW_ON_ERROR );
			self::assertIsArray( $schedule_rows );
			$schedule_row = $schedule_rows[0] ?? null;
			self::assertIsArray( $schedule_row );
			self::assertSame( self::INSPECTION_OWNER, $schedule_row['owner'] ?? null );
			self::assertSame(
				self::INSPECTION_OWNER . ':' . self::INSPECTION_SCHEDULE,
				$schedule_row['name'] ?? null
			);
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
	 * @param   bool $executing Whether the fixture carries an admitted-delivery marker.
	 *
	 * @return  RunStore
	 */
	private function seed_cancel_run( bool $executing = false ): RunStore {
		$args      = array( 'source' => 'cli-boundary' );
		$run_store = new RunStore( self::CANCEL_NAME, new SystemClock(), self::option_rows() );
		$state     = $run_store->create( self::RUN_ID, $args, self::args_hash( $args ), array() );
		self::assertNotNull( $state, 'The CLI cancel boundary requires one deterministic retained run' );

		if ( $executing ) {
			self::assertIsString(
				$run_store->transition_state( self::RUN_ID, $state, $state->with_executing( true ) ),
				'The executing-refusal fixture must persist its admitted-delivery marker'
			);
		}

		return $run_store;
	}

	/**
	 * Persists one materialized zero-chunk batch waiting for cleanup.
	 *
	 * @return  RunStore
	 */
	private function seed_cancel_batch_pending_cleanup(): RunStore {
		$args      = array( 'source' => 'cli-completeness-boundary' );
		$run_store = new RunStore( self::CANCEL_BATCH_NAME, new SystemClock(), self::option_rows() );
		$state     = $run_store->create( self::RUN_ID, $args, self::args_hash( $args ), array() );
		self::assertNotNull( $state, 'The CLI completeness boundary requires one retained batch run' );
		self::assertIsString(
			$run_store->transition_state( self::RUN_ID, $state, $state->with_action_seq( 2 ) ),
			'The zero-chunk fixture must advance beyond its unmaterialized state'
		);

		return $run_store;
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
	 * Returns the deterministic cancel fixture's active-run option name.
	 *
	 * @return  string
	 */
	private static function cancel_run_option_name(): string {
		return 'a8csp_bgte_run_' . self::CANCEL_NAME . '_' . self::RUN_ID;
	}

	/**
	 * Returns the deterministic cancel-completeness batch option name.
	 *
	 * @return  string
	 */
	private static function cancel_batch_run_option_name(): string {
		return 'a8csp_bgte_run_' . self::CANCEL_BATCH_NAME . '_' . self::RUN_ID;
	}

	/**
	 * Persists one deterministic retained failure.
	 *
	 * @param   string          $name Complete owner-qualified background-work identity.
	 * @param   OptionRows|null $rows Site-bound row seam, or null to construct one.
	 *
	 * @return  FailedRunStore
	 */
	private function seed_failed_run( string $name, ?OptionRows $rows = null ): FailedRunStore {
		$store = new FailedRunStore( $name, $rows ?? self::option_rows() );
		self::assertTrue(
			$store->record(
				self::RUN_ID,
				self::FAILED_AT,
				array( 'account_id' => 42 ),
				3,
				new EngineError( 'CLI boundary failure.', \RuntimeException::class ),
				new RunFailure(
					name: $name,
					run_id: self::RUN_ID,
					attempts: 3,
					stage: 'execution',
					code: ApiErrorCode::ExecutionFailed,
					summary: 'CLI boundary failure.',
					failed_chunk: null,
				)
			)
		);

		return $store;
	}

	/**
	 * Asserts that a child-process purge removed both authoritative and public store state.
	 *
	 * @param   string         $name  Complete owner-qualified background-work identity.
	 * @param   FailedRunStore $store Failed-run store constructed by the PHPUnit request.
	 * @param   OptionRows     $rows  Authoritative option-row seam.
	 *
	 * @return  void
	 */
	private static function assert_store_absent( string $name, FailedRunStore $store, OptionRows $rows ): void {
		$option_name = self::option_name( $name );

		$read = $rows->read( $option_name );
		if ( $read->is_failure() ) {
			self::fail( 'The failed-run option could not be read.' );
		}
		self::assertNull( $read->value );

		// The child process cannot clear this PHPUnit request's in-memory option cache.
		\wp_cache_delete( $option_name, 'options' );
		$all = $store->all();
		if ( $all->is_failure() ) {
			self::fail( 'The failed-run store could not be read.' );
		}
		self::assertSame( array(), $all->value );
	}

	/**
	 * Returns the failed-store option name for a stable background-work identity.
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
	 * @return  string
	 */
	private static function purge_usage_error(): string {
		return 'Error: Purge requires exactly one identity or --all; ' .
			"use wp background-tasks failed-runs purge <identity> or purge --all.\n";
	}

	// endregion.
}

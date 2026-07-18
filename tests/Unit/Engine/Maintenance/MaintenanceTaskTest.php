<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Maintenance;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Maintenance\MaintenanceTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\CleanupIntents;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunReconciliation;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\WorkRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies bounded, resumable maintenance enumeration through authoritative storage seams.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( MaintenanceTask::class )]
#[UsesClass( CleanupIntents::class )]
#[UsesClass( LifecycleEffects::class )]
#[UsesClass( LockWindows::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( RawOptionDecoder::class )]
#[UsesClass( RunReconciliation::class )]
#[UsesClass( RunTransitions::class )]
#[UsesClass( ScheduleRegistry::class )]
#[UsesClass( StoreFactory::class )]
#[UsesClass( WorkRegistry::class )]
final class MaintenanceTaskTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string ARGS_HASH     = 'd3e2a7f3f4041a96ec4e9d3de1622dea7c050a65d9ee0b77a49a76848fdd9737';
	private const string CURSOR_OPTION = 'a8csp_bgte_maintenance_sweep';
	private const int NOW              = 1_700_000_000;
	private const string RUN_ID        = '00000000001700000000-0000000000000000042';

	private RecordingLogger $logger;
	private MaintenanceTask $maintenance;
	private WpdbLockSpy $wpdb;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress seams before maintenance classes are instantiated.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 2 ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/wp-hook-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/wp-lock-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/wp-time-constant-stubs.php';
		require_once \dirname( __DIR__ ) . '/Backends/wp-json-encode-stub.php';
	}

	/**
	 * Constructs one maintenance task over real reconciliation stores and the wpdb double.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_options']               = array();
		$GLOBALS['a8csp_bgte_test_option_calls']          = array();
		$GLOBALS['a8csp_bgte_test_option_autoload']       = array();
		$GLOBALS['a8csp_bgte_test_update_option_results'] = array();
		$GLOBALS['a8csp_bgte_test_update_option_values']  = array();
		$GLOBALS['a8csp_bgte_test_delete_option_results'] = array();
		$GLOBALS['a8csp_bgte_test_filter_values']         = array();
		$GLOBALS['a8csp_bgte_test_filter_registrations']  = array();
		$GLOBALS['a8csp_bgte_test_fired_actions']         = array();
		$GLOBALS['a8csp_bgte_test_action_throwables']     = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events']      = array();
		$GLOBALS['a8csp_bgte_test_blog_id']               = 1;
		$GLOBALS['a8csp_bgte_test_cache']                 = array();
		$GLOBALS['a8csp_bgte_test_cache_calls']           = array();
		unset( $GLOBALS['a8csp_bgte_test_before_add_option'] );

		$clock                = new FixedClock( self::NOW );
		$this->logger         = new RecordingLogger();
		$this->wpdb           = new WpdbLockSpy();
		$backend              = new RecordingBackend();
		$work                 = new WorkRegistry();
		$rows                 = new OptionRows( $this->wpdb );
		$guard                = new OverlapGuard( $clock, $this->logger, new OptionRows( $this->wpdb ) );
		$stores               = new StoreFactory( $clock, $rows, $this->logger );
		$lock_windows         = new LockWindows( $clock, $this->logger );
		$terminal_effects     = new LifecycleEffects( $guard, $stores, $this->logger );
		$terminal_transitions = new RunTransitions( $guard, $stores, $clock, $lock_windows, $this->logger, $terminal_effects );
		$reconciliation       = new RunReconciliation( $guard, $stores, $clock, $this->logger, $lock_windows, $terminal_transitions, $terminal_effects, $work, $backend );
		$cleanup_intents      = new CleanupIntents( new ScheduleRegistry( $rows, $this->logger ), new SchedulerFacade( array( $backend ) ), $rows, $clock, $this->logger );
		$this->maintenance    = new MaintenanceTask( $rows, $reconciliation, $guard, $cleanup_intents, $this->logger );
	}

	// endregion.

	// region TESTS.

	/**
	 * Each phase consumes its own budget when both prefixes contain more work.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_budget_exhaustion_does_not_starve_the_lock_budget(): void {
		$this->put_hostile_run_names( 501 );
		$lock_raw = $this->stale_lock_raw();
		for ( $index = 0; $index < 501; ++$index ) {
			$this->wpdb->put( self::lock_name( $index ), $lock_raw );
		}
		$this->put_corrupt_registration_names( 501 );

		$this->maintenance->handle( array() );

		self::assertCount( 1, $this->names_under( 'a8csp_bgte_overlap_lock_' ) );
		self::assertCount( 1, $this->names_under( ScheduleRegistry::OPTION_PREFIX ) );
		self::assertSame( self::hostile_run_name( 499 ), $this->cursor_state()['runs'] );
		self::assertSame( self::lock_name( 499 ), $this->cursor_state()['locks'] );
		self::assertSame( self::registration_name( 499 ), $this->cursor_state()['registrations'] );
	}

	/**
	 * A later invocation resumes strictly after the durable run cursor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_incomplete_pass_persists_and_resumes_after_the_cursor(): void {
		$this->put_hostile_run_names( 500 );
		$remaining = 'a8csp_bgte_run_sweep-tests:remaining_' . self::RUN_ID;
		$this->wpdb->put( $remaining, 'schema-invalid-run' );

		$this->maintenance->handle( array() );

		self::assertArrayHasKey( $remaining, $this->wpdb->rows );
		self::assertSame( self::hostile_run_name( 499 ), $this->cursor_state()['runs'] );

		$this->maintenance->handle( array() );

		self::assertArrayNotHasKey( $remaining, $this->wpdb->rows );
		self::assertArrayNotHasKey( self::CURSOR_OPTION, $this->wpdb->rows );
		self::assertStringContainsString( 'BINARY `option_name` > BINARY ', $this->wpdb->recorded_queries[ $this->first_query_after_cursor() ] );
	}

	/**
	 * An under-budget pass preserves lock reconciliation and leaves no cursor residue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_completed_under_budget_pass_reconciles_and_leaves_no_cursor_row(): void {
		$lock_name = self::lock_name( 0 );
		$this->wpdb->put( $lock_name, $this->stale_lock_raw() );

		$this->maintenance->handle( array() );

		self::assertArrayNotHasKey( $lock_name, $this->wpdb->rows );
		self::assertArrayNotHasKey( self::CURSOR_OPTION, $this->wpdb->rows );
	}

	/**
	 * An unreadable schedule-registry row is reclaimed with its exact option name.
	 *
	 * @return  void
	 */
	public function test_corrupt_schedule_registry_row_is_reclaimed(): void {
		$option_name = ScheduleRegistry::option_name( 'poison-owner' );
		$this->wpdb->put( $option_name, 'poison-registry-row' );

		$this->maintenance->handle( array() );

		self::assertArrayNotHasKey( $option_name, $this->wpdb->rows );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Deleted corrupt schedule registry option during maintenance sweep.',
					'context' => array( 'option_name' => $option_name ),
				),
			),
			$this->logger->records
		);
	}

	/**
	 * Exact deletion cannot remove a registry row replaced after maintenance selected it.
	 *
	 * @return  void
	 */
	public function test_corrupt_schedule_registry_reclaim_preserves_a_concurrent_replacement(): void {
		$option_name = ScheduleRegistry::option_name( 'poison-owner' );
		$this->wpdb->put( $option_name, 'poison-registry-row' );
		[ $replacement_name, $replacement ] = StoreFixtureBuilder::for_identity( 'poison-owner:replacement-task' )->schedule_registration(
			array(
				'owner'         => 'poison-owner',
				'declarations'  => array(),
				'registrations' => array(
					'poison-owner:replacement' => array(
						'fingerprint'   => 'replacement-fingerprint',
						'next_due'      => self::NOW + 300,
						'last_fired'    => null,
						'misfire_skips' => 0,
						'overlap_skips' => 0,
					),
				),
			)
		);
		self::assertSame( $option_name, $replacement_name );
		$this->wpdb->before_next(
			'delete',
			static function ( WpdbLockSpy $wpdb ) use ( $option_name, $replacement ): void {
				$wpdb->put( $option_name, $replacement );
			}
		);

		$this->maintenance->handle( array() );

		self::assertSame( $replacement, $this->wpdb->rows[ $option_name ] ?? null );
		self::assertSame( array(), $this->logger->records );
	}

	/**
	 * A database delete failure aborts without advancing past the corrupt registry row.
	 *
	 * @return  void
	 */
	public function test_corrupt_schedule_registry_delete_failure_retries_the_same_row(): void {
		$option_name = ScheduleRegistry::option_name( 'poison-owner' );
		$this->wpdb->put( $option_name, 'poison-registry-row' );
		$this->wpdb->script_result( 'delete', false );

		$this->maintenance->handle( array() );

		self::assertSame( 'poison-registry-row', $this->wpdb->rows[ $option_name ] ?? null );
		self::assertArrayNotHasKey( self::CURSOR_OPTION, $this->wpdb->rows );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Maintenance schedule-registry sweep aborted while deleting a corrupt registration row; repair WordPress option writes and retry the sweep.',
					'context' => array(
						'option_name' => $option_name,
						'phase'       => 'registry-delete',
						'outcome'     => 'delete_failed',
					),
				),
			),
			$this->logger->records
		);

		$this->logger->records = array();
		$this->maintenance->handle( array() );

		self::assertArrayNotHasKey( $option_name, $this->wpdb->rows );
		self::assertSame( 'Deleted corrupt schedule registry option during maintenance sweep.', $this->logger->records[0]['message'] ?? null );
	}

	/**
	 * Unparseable names consume the run scan budget before a valid later name is reached.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_budget_counts_unparseable_option_names(): void {
		$this->put_hostile_run_names( 500 );
		$later = 'a8csp_bgte_run_sweep-tests:later_' . self::RUN_ID;
		$this->wpdb->put( $later, 'schema-invalid-run' );

		$this->maintenance->handle( array() );

		self::assertArrayHasKey( $later, $this->wpdb->rows );
		self::assertSame( self::hostile_run_name( 499 ), $this->cursor_state()['runs'] );
	}

	/**
	 * A full raw page advances past a filtered case collision before exhaustion is recorded.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_case_colliding_raw_page_does_not_starve_later_run_rows(): void {
		$this->put_case_colliding_run_names( 1 );
		$this->put_hostile_run_names( 99 );
		$remaining = 'a8csp_bgte_run_sweep-tests:remaining_' . self::RUN_ID;
		$this->wpdb->put( $remaining, 'schema-invalid-run' );

		$this->maintenance->handle( array() );

		self::assertArrayNotHasKey( $remaining, $this->wpdb->rows );
		self::assertArrayNotHasKey( self::CURSOR_OPTION, $this->wpdb->rows );
		self::assertStringContainsString( self::hostile_run_name( 98 ), $this->wpdb->recorded_queries[ $this->first_query_after_cursor() ] );
	}

	/**
	 * Raw work stops at the page budget and retains the cursor before filtered counting could overshoot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_raw_page_budget_retains_cursor_before_filtered_count_would_overshoot(): void {
		$this->put_case_colliding_run_names( 50 );
		$this->put_hostile_run_names( 551 );

		$this->maintenance->handle( array() );

		self::assertSame( self::hostile_run_name( 449 ), $this->cursor_state()['runs'] );
		self::assertArrayHasKey( self::hostile_run_name( 550 ), $this->wpdb->rows );
	}

	/**
	 * A malformed cursor row restarts both prefixes without raising an exception.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_malformed_cursor_row_starts_a_fresh_full_pass(): void {
		$lock_name = self::lock_name( 0 );
		$this->wpdb->put( self::CURSOR_OPTION, 'schema-invalid-cursor' );
		$this->wpdb->put( $lock_name, $this->stale_lock_raw() );

		$this->maintenance->handle( array() );

		self::assertArrayNotHasKey( $lock_name, $this->wpdb->rows );
		self::assertArrayNotHasKey( self::CURSOR_OPTION, $this->wpdb->rows );
	}

	/**
	 * A failed cursor read aborts before either sweep phase and reports the storage cause.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cursor_read_failure_logs_the_aborted_phase(): void {
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'scripted cursor read failure';
			}
		);

		$this->maintenance->handle( array() );

		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'cursor-read', $this->logger->records[0]['context']['phase'] ?? null );
		self::assertSame( EngineError::class, $this->logger->records[0]['context']['error_class'] ?? null );
		self::assertSame( EngineErrorReason::StorageFailure->value, $this->logger->records[0]['context']['error_reason'] ?? null );
		self::assertCount( 1, $this->wpdb->recorded_queries );
	}

	/**
	 * Enumeration failure leaves the previously persisted cursor bytes unchanged.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enumeration_failure_does_not_advance_persisted_cursors(): void {
		$cursor_raw = self::cursor_raw( self::hostile_run_name( 99 ), self::lock_name( 99 ) );
		$this->wpdb->put( self::CURSOR_OPTION, $cursor_raw );
		$this->wpdb->before_next(
			'scan',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'scripted enumeration failure';
			}
		);

		$this->maintenance->handle( array() );

		self::assertSame( $cursor_raw, $this->wpdb->rows[ self::CURSOR_OPTION ] ?? null );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'run-enumeration', $this->logger->records[0]['context']['phase'] ?? null );
		self::assertSame( EngineError::class, $this->logger->records[0]['context']['error_class'] ?? null );
		self::assertSame( EngineErrorReason::StorageFailure->value, $this->logger->records[0]['context']['error_reason'] ?? null );
	}

	/**
	 * Reconciliation failure leaves the previously persisted cursor bytes unchanged.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_reconciliation_failure_does_not_advance_persisted_cursors(): void {
		$cursor_raw = self::cursor_raw( null, null );
		$this->wpdb->put( self::CURSOR_OPTION, $cursor_raw );
		[ $run_name, $run_raw ] = StoreFixtureBuilder::for_identity( 'sweep-tests:read-failure' )->run(
			self::RUN_ID,
			new RunState( status: RunStatus::Running, kind: 'Task', executing: false, start_args: array(), args_hash: self::ARGS_HASH, queue: array(), failed_attempts: 0, action_seq: 0, created_at: self::NOW, heartbeat_at: self::NOW )
		);
		$this->wpdb->put( $run_name, $run_raw );
		$this->wpdb->before_next( 'select', static function ( WpdbLockSpy $database ): void {} );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'scripted reconciliation read failure';
			}
		);

		$this->maintenance->handle( array() );

		self::assertSame( $cursor_raw, $this->wpdb->rows[ self::CURSOR_OPTION ] ?? null );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'run-reconciliation', $this->logger->records[0]['context']['phase'] ?? null );
		self::assertSame( 'sweep-tests:read-failure', $this->logger->records[0]['context']['name'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
		self::assertSame( EngineError::class, $this->logger->records[0]['context']['error_class'] ?? null );
		self::assertSame( EngineErrorReason::StorageFailure->value, $this->logger->records[0]['context']['error_reason'] ?? null );
	}

	/**
	 * A failed lock-page read aborts the lock phase and reports the storage cause.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_lock_enumeration_failure_logs_the_aborted_phase(): void {
		$this->wpdb->before_next( 'scan', static function (): void {} );
		$this->wpdb->before_next(
			'scan',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'scripted lock enumeration failure';
			}
		);

		$this->maintenance->handle( array() );

		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'lock-enumeration', $this->logger->records[0]['context']['phase'] ?? null );
		self::assertSame( EngineError::class, $this->logger->records[0]['context']['error_class'] ?? null );
		self::assertSame( EngineErrorReason::StorageFailure->value, $this->logger->records[0]['context']['error_reason'] ?? null );
	}

	/**
	 * A failed registry-page read aborts its phase and reports the storage cause.
	 *
	 * @return  void
	 */
	public function test_registry_enumeration_failure_logs_the_aborted_phase(): void {
		$this->wpdb->before_next( 'scan', static function (): void {} );
		$this->wpdb->before_next( 'scan', static function (): void {} );
		$this->wpdb->before_next( 'scan', static function (): void {} );
		$this->wpdb->before_next(
			'scan',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'scripted registry enumeration failure';
			}
		);

		$this->maintenance->handle( array() );

		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'Maintenance schedule-registry sweep aborted while enumerating registration rows; repair WordPress option reads and retry the sweep.', $this->logger->records[0]['message'] ?? null );
		self::assertSame( 'registry-enumeration', $this->logger->records[0]['context']['phase'] ?? null );
		self::assertSame( EngineError::class, $this->logger->records[0]['context']['error_class'] ?? null );
		self::assertSame( EngineErrorReason::StorageFailure->value, $this->logger->records[0]['context']['error_reason'] ?? null );
	}

	/**
	 * A failed authoritative registry-row read aborts with the exact row in context.
	 *
	 * @return  void
	 */
	public function test_registry_row_read_failure_logs_the_aborted_phase(): void {
		$option_name = ScheduleRegistry::option_name( 'poison-owner' );
		$this->wpdb->put( $option_name, 'poison-registry-row' );
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'scripted registry row read failure';
			}
		);

		$this->maintenance->handle( array() );

		self::assertSame( 'poison-registry-row', $this->wpdb->rows[ $option_name ] ?? null );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'Maintenance schedule-registry sweep aborted while reading a registration row; repair WordPress option reads and retry the sweep.', $this->logger->records[0]['message'] ?? null );
		self::assertSame( 'registry-read', $this->logger->records[0]['context']['phase'] ?? null );
		self::assertSame( $option_name, $this->logger->records[0]['context']['option_name'] ?? null );
		self::assertSame( EngineError::class, $this->logger->records[0]['context']['error_class'] ?? null );
		self::assertSame( EngineErrorReason::StorageFailure->value, $this->logger->records[0]['context']['error_reason'] ?? null );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Stores a requested count of unparseable names under the complete run prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $count Number of hostile names.
	 *
	 * @return  void
	 */
	private function put_hostile_run_names( int $count ): void {
		for ( $index = 0; $index < $count; ++$index ) {
			$this->wpdb->put( self::hostile_run_name( $index ), 'hostile-prefix-row' );
		}
	}

	/**
	 * Stores a requested count of canonical unreadable schedule-registration rows.
	 *
	 * @param   int $count Number of corrupt rows.
	 *
	 * @return  void
	 */
	private function put_corrupt_registration_names( int $count ): void {
		for ( $index = 0; $index < $count; ++$index ) {
			$this->wpdb->put( self::registration_name( $index ), 'poison-registry-row' );
		}
	}

	/**
	 * Stores case-colliding names selected by the database prefix collation but rejected bytewise.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $count Number of foreign colliding names.
	 *
	 * @return  void
	 */
	private function put_case_colliding_run_names( int $count ): void {
		for ( $index = 0; $index < $count; ++$index ) {
			$this->wpdb->put( 'A8CSP_BGTE_RUN_!foreign-' . \sprintf( '%03d', $index ), 'foreign-prefix-row' );
		}
	}

	/**
	 * Returns one complete hostile run option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $index Stable lexical index.
	 *
	 * @return  string
	 */
	private static function hostile_run_name( int $index ): string {
		return 'a8csp_bgte_run_!hostile-' . \sprintf( '%03d', $index );
	}

	/**
	 * Returns one complete valid lock option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $index Stable lexical index.
	 *
	 * @return  string
	 */
	private static function lock_name( int $index ): string {
		return 'a8csp_bgte_overlap_lock_sweep-tests:lock-' . \sprintf( '%03d', $index ) . '_' . self::ARGS_HASH;
	}

	/**
	 * Returns one complete canonical schedule-registration option name.
	 *
	 * @param   int $index Stable lexical index.
	 *
	 * @return  string
	 */
	private static function registration_name( int $index ): string {
		return ScheduleRegistry::option_name( 'sweep-owner-' . \sprintf( '%03d', $index ) );
	}

	/**
	 * Produces exact production-serialized bytes for one stale lock.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function stale_lock_raw(): string {
		[ , $raw ] = StoreFixtureBuilder::for_identity( 'sweep-tests:lock-fixture' )->lock( self::ARGS_HASH, self::RUN_ID, self::NOW - 901, self::NOW - 901 );

		return $raw;
	}

	/**
	 * Returns exact serialized cursor bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $runs         Run cursor.
	 * @param   string|null $locks        Lock cursor.
	 * @param   string|null $registrations Schedule-registration cursor.
	 *
	 * @return  string
	 */
	private static function cursor_raw( ?string $runs, ?string $locks, ?string $registrations = null ): string {
		$raw = \maybe_serialize(
			array(
				'runs'          => $runs,
				'locks'         => $locks,
				'registrations' => $registrations,
			)
		);
		self::assertIsString( $raw );

		return $raw;
	}

	/**
	 * Returns the decoded persisted cursor state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array{runs: string|null, locks: string|null, registrations: string|null}
	 */
	private function cursor_state(): array {
		$raw = $this->wpdb->rows[ self::CURSOR_OPTION ] ?? null;
		self::assertIsString( $raw );
		$state = RawOptionDecoder::decode( $raw );
		self::assertIsArray( $state );
		self::assertArrayHasKey( 'runs', $state );
		self::assertArrayHasKey( 'locks', $state );
		self::assertArrayHasKey( 'registrations', $state );

		return array(
			'runs'          => \is_string( $state['runs'] ) ? $state['runs'] : null,
			'locks'         => \is_string( $state['locks'] ) ? $state['locks'] : null,
			'registrations' => \is_string( $state['registrations'] ) ? $state['registrations'] : null,
		);
	}

	/**
	 * Returns row names under one complete literal prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $prefix Complete literal prefix.
	 *
	 * @return  list<string>
	 */
	private function names_under( string $prefix ): array {
		return \array_values( \array_filter( \array_keys( $this->wpdb->rows ), static fn ( string $name ): bool => \str_starts_with( $name, $prefix ) ) );
	}

	/**
	 * Returns the first keyset scan recorded after the initial cursor-based invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int
	 */
	private function first_query_after_cursor(): int {
		foreach ( $this->wpdb->recorded_queries as $index => $query ) {
			if ( \str_contains( $query, 'BINARY `option_name` > BINARY' ) ) {
				return $index;
			}
		}

		self::fail( 'Expected a keyset scan after a persisted cursor.' );
	}

	// endregion.
}

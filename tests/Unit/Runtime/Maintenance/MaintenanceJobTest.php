<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Maintenance;

use A8C\SpecialProjects\BackgroundJobsEngine\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Maintenance\MaintenanceJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\DeliveryScheduler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\ChunkedJobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\JobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunReconciliation;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\CleanupIntents;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingRandomizer;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies bounded, resumable maintenance enumeration through authoritative storage seams.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( MaintenanceJob::class )]
#[UsesClass( CleanupIntents::class )]
#[UsesClass( LifecycleEffects::class )]
#[UsesClass( LockWindows::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( RawOptionDecoder::class )]
#[UsesClass( RunContext::class )]
#[UsesClass( RunReconciliation::class )]
#[UsesClass( RunHistory::class )]
#[UsesClass( RunTransitions::class )]
#[UsesClass( ScheduleRegistry::class )]
#[UsesClass( StoreFactory::class )]
#[UsesClass( JobRegistry::class )]
final class MaintenanceJobTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string ARGS_HASH = 'd3e2a7f3f4041a96ec4e9d3de1622dea7c050a65d9ee0b77a49a76848fdd9737';
	private const int NOW          = 1_700_000_000;
	private const string RUN_ID    = '00000000001700000000-0000000000000000042';

	private string $cursor_option;
	private string $cursor_raw;
	private RecordingLogger $logger;
	private MaintenanceJob $maintenance;
	private RunContext $run_context;
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
	 * Constructs one maintenance job over real reconciliation stores and the wpdb double.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgje_test_options']               = array();
		$GLOBALS['a8csp_bgje_test_option_calls']          = array();
		$GLOBALS['a8csp_bgje_test_option_autoload']       = array();
		$GLOBALS['a8csp_bgje_test_update_option_results'] = array();
		$GLOBALS['a8csp_bgje_test_update_option_values']  = array();
		$GLOBALS['a8csp_bgje_test_delete_option_results'] = array();
		$GLOBALS['a8csp_bgje_test_filter_values']         = array();
		$GLOBALS['a8csp_bgje_test_filter_registrations']  = array();
		$GLOBALS['a8csp_bgje_test_fired_actions']         = array();
		$GLOBALS['a8csp_bgje_test_action_throwables']     = array();
		$GLOBALS['a8csp_bgje_test_lifecycle_events']      = array();
		$GLOBALS['a8csp_bgje_test_blog_id']               = 1;
		$GLOBALS['a8csp_bgje_test_cache']                 = array();
		$GLOBALS['a8csp_bgje_test_cache_calls']           = array();
		unset( $GLOBALS['a8csp_bgje_test_before_add_option'] );

		$clock        = new FixedClock( self::NOW );
		$this->logger = new RecordingLogger();
		$this->wpdb   = new WpdbLockSpy();

		[ $this->cursor_option, $this->cursor_raw ] = self::cursor_fixture();

		$backend              = new RecordingBackend();
		$registry             = new JobRegistry();
		$rows                 = new OptionRows( $this->wpdb );
		$guard                = new OverlapGuard( $clock, $this->logger, new OptionRows( $this->wpdb ), new LockWindows( $clock, $this->logger ) );
		$stores               = new StoreFactory( $clock, $rows, $this->logger );
		$randomizer           = new RecordingRandomizer( 42 );
		$lock_windows         = new LockWindows( $clock, $this->logger );
		$terminal_effects     = new LifecycleEffects( $guard, $stores, $this->logger );
		$terminal_transitions = new RunTransitions( $guard, $stores, $clock, $lock_windows, $this->logger, $terminal_effects );
		$delivery_scheduler   = new DeliveryScheduler( $backend, $clock );
		$failure_lifecycle    = new FailureLifecycle( $delivery_scheduler, $clock, $randomizer, $this->logger, $terminal_transitions, $terminal_effects );
		$job_handler          = new JobKindHandler( $registry, $this->logger, $clock, $lock_windows, $terminal_transitions, $terminal_effects, $failure_lifecycle );
		$chunked_job_handler  = new ChunkedJobKindHandler( $registry, $delivery_scheduler, $this->logger, $clock, $lock_windows, $terminal_transitions, $terminal_effects, $failure_lifecycle );
		$handlers             = array(
			$job_handler->key()         => $job_handler,
			$chunked_job_handler->key() => $chunked_job_handler,
		);
		$reconciliation       = new RunReconciliation( $guard, $stores, $clock, $this->logger, $lock_windows, $terminal_transitions, $terminal_effects, $handlers, $delivery_scheduler );
		$cleanup_intents      = new CleanupIntents( new ScheduleRegistry( $rows, $this->logger ), new SchedulerFacade( array( $backend ) ), $rows, $clock, $this->logger );
		$this->maintenance    = new MaintenanceJob( $rows, $reconciliation, $guard, $cleanup_intents, $this->logger );
		$this->run_context    = new RunContext( RunId::from( self::RUN_ID ), array() );
	}

	// endregion.

	// region TESTS.

	/**
	 * Each phase consumes its own budget when both prefixes contain more work.
	 *
	 * @load-bearing bounded-retry-liveness
	 * @pin-rationale Independent phase budgets ensure a saturated run scan cannot starve stale-lock and registry convergence across maintenance invocations.
	 * @fixture StoreFixtureBuilder
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

		$this->maintenance->handle( array(), $this->run_context );

		self::assertSame(
			self::cursor_bytes( self::hostile_run_name( 499 ), self::lock_name( 499 ), self::registration_name( 499 ) ),
			$this->wpdb->rows[ $this->cursor_option ] ?? null
		);
		self::assertCount( 1, $this->names_under( OverlapGuard::OPTION_PREFIX ) );
		self::assertCount( 1, $this->names_under( ScheduleRegistry::OPTION_PREFIX ) );
		self::assertSame( self::hostile_run_name( 499 ), $this->cursor_state()['runs'] );
		self::assertSame( self::lock_name( 499 ), $this->cursor_state()['locks'] );
		self::assertSame( self::registration_name( 499 ), $this->cursor_state()['registrations'] );
	}

	/**
	 * A later invocation resumes strictly after the durable run cursor.
	 *
	 * @load-bearing bounded-retry-liveness
	 * @pin-rationale A durable bytewise keyset cursor must resume beyond the exhausted page so bounded passes eventually reach every later run row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_incomplete_pass_persists_and_resumes_after_the_cursor(): void {
		$this->put_hostile_run_names( 500 );
		$remaining = RunStore::OPTION_PREFIX . 'sweep-tests:remaining_' . self::RUN_ID;
		$this->wpdb->put( $remaining, 'schema-invalid-run' );

		$this->maintenance->handle( array(), $this->run_context );

		self::assertArrayHasKey( $remaining, $this->wpdb->rows );
		self::assertSame( self::hostile_run_name( 499 ), $this->cursor_state()['runs'] );

		$this->maintenance->handle( array(), $this->run_context );

		self::assertArrayNotHasKey( $remaining, $this->wpdb->rows );
		self::assertArrayNotHasKey( $this->cursor_option, $this->wpdb->rows );
		self::assertStringContainsString( 'BINARY `option_name` > BINARY ', $this->wpdb->recorded_queries[ $this->first_query_after_cursor() ] );
	}

	/**
	 * An under-budget pass preserves lock reconciliation and leaves no cursor residue.
	 *
	 * @load-bearing bounded-retry-liveness
	 * @pin-rationale An under-budget pass must converge its stale lock and remove cursor residue so later invocations restart a complete sweep.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_completed_under_budget_pass_reconciles_and_leaves_no_cursor_row(): void {
		$lock_name = self::lock_name( 0 );
		$this->wpdb->put( $lock_name, $this->stale_lock_raw() );

		$this->maintenance->handle( array(), $this->run_context );

		self::assertArrayNotHasKey( $lock_name, $this->wpdb->rows );
		self::assertArrayNotHasKey( $this->cursor_option, $this->wpdb->rows );
	}

	/**
	 * An unreadable schedule-registry row is reclaimed with its exact option name.
	 *
	 * @return  void
	 */
	public function test_corrupt_schedule_registry_row_is_reclaimed(): void {
		$option_name = ScheduleRegistry::option_name( 'poison-scope' );
		$this->wpdb->put( $option_name, 'poison-registry-row' );

		$this->maintenance->handle( array(), $this->run_context );

		self::assertArrayNotHasKey( $option_name, $this->wpdb->rows );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( $option_name, $this->logger->records[0]['context']['option_name'] ?? null );
	}

	/**
	 * A registration row missing mandatory inactive-episode markers is reclaimed.
	 *
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schema_invalid_schedule_registry_row_is_reclaimed(): void {
		$complete   = StoreFixtureBuilder::for_identity( 'legacy-scope:job' )->schedule_registration(
			array(
				'scope'         => 'legacy-scope',
				'declarations'  => array(),
				'registrations' => array(
					'legacy-scope:schedule' => StoreFixtureBuilder::schedule_registration_state( 'legacy-fingerprint', self::NOW + 300 ),
				),
			)
		);
		$incomplete = StoreFixtureBuilder::schedule_registration_without_undeclared_markers( $complete );
		$this->wpdb->put( $incomplete[0], $incomplete[1] );

		$this->maintenance->handle( array(), $this->run_context );

		self::assertArrayNotHasKey( $incomplete[0], $this->wpdb->rows );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( $incomplete[0], $this->logger->records[0]['context']['option_name'] ?? null );
	}

	/**
	 * Exact deletion cannot remove a registry row replaced after maintenance selected it.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Exact-value deletion must lose to a concurrent registry generation so maintenance cannot erase a replacement selected after its read.
	 * @fixture StoreFixtureBuilder
	 *
	 * @return  void
	 */
	public function test_corrupt_schedule_registry_reclaim_preserves_a_concurrent_replacement(): void {
		$option_name = ScheduleRegistry::option_name( 'poison-scope' );
		$this->wpdb->put( $option_name, 'poison-registry-row' );
		[ $replacement_name, $replacement ] = StoreFixtureBuilder::for_identity( 'poison-scope:replacement-job' )->schedule_registration(
			array(
				'scope'         => 'poison-scope',
				'declarations'  => array(),
				'registrations' => array(
					'poison-scope:replacement' => StoreFixtureBuilder::schedule_registration_state( 'replacement-fingerprint', self::NOW + 300 ),
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

		$this->maintenance->handle( array(), $this->run_context );

		self::assertSame( $replacement, $this->wpdb->rows[ $option_name ] ?? null );
		self::assertSame( array(), $this->logger->records );
	}

	/**
	 * A database delete failure aborts without advancing past the corrupt registry row.
	 *
	 * @load-bearing bounded-retry-liveness
	 * @pin-rationale A failed corrupt-row delete must leave the cursor unadvanced so the same registry row remains eligible on the next bounded retry.
	 *
	 * @return  void
	 */
	public function test_corrupt_schedule_registry_delete_failure_retries_the_same_row(): void {
		$option_name = ScheduleRegistry::option_name( 'poison-scope' );
		$this->wpdb->put( $option_name, 'poison-registry-row' );
		$this->wpdb->script_result( 'delete', false );

		$this->maintenance->handle( array(), $this->run_context );

		self::assertSame( 'poison-registry-row', $this->wpdb->rows[ $option_name ] ?? null );
		self::assertArrayNotHasKey( $this->cursor_option, $this->wpdb->rows );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( $option_name, $this->logger->records[0]['context']['option_name'] ?? null );
		self::assertSame( 'registry-delete', $this->logger->records[0]['context']['phase'] ?? null );
		self::assertSame( 'delete_failed', $this->logger->records[0]['context']['outcome'] ?? null );

		$this->logger->records = array();
		$this->maintenance->handle( array(), $this->run_context );

		self::assertArrayNotHasKey( $option_name, $this->wpdb->rows );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( $option_name, $this->logger->records[0]['context']['option_name'] ?? null );
	}

	/**
	 * Unparseable names consume the run scan budget before a valid later name is reached.
	 *
	 * @load-bearing bounded-retry-liveness
	 * @pin-rationale Rejected names must consume the raw scan budget or hostile rows can turn one bounded maintenance invocation into unbounded work.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_budget_counts_unparseable_option_names(): void {
		$this->put_hostile_run_names( 500 );
		$later = RunStore::OPTION_PREFIX . 'sweep-tests:later_' . self::RUN_ID;
		$this->wpdb->put( $later, 'schema-invalid-run' );

		$this->maintenance->handle( array(), $this->run_context );

		self::assertArrayHasKey( $later, $this->wpdb->rows );
		self::assertSame( self::hostile_run_name( 499 ), $this->cursor_state()['runs'] );
	}

	/**
	 * A run-history row does not consume the active-run scan budget.
	 *
	 * @load-bearing bounded-retry-liveness
	 * @pin-rationale A saturated active-run prefix must still reach later active runs without unrelated history rows consuming its raw-name budget.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_history_row_does_not_consume_the_active_run_budget(): void {
		$this->put_hostile_run_names( 499 );
		[ $history_name, $history_raw ] = StoreFixtureBuilder::for_identity( 'sweep-tests:history' )->history(
			array(
				array(
					'run_id'    => self::RUN_ID,
					'args_hash' => self::ARGS_HASH,
				),
			),
		);

		$later = RunStore::OPTION_PREFIX . 'sweep-tests:later_' . self::RUN_ID;
		$this->wpdb->put( $history_name, $history_raw );
		$this->wpdb->put( $later, 'schema-invalid-run' );

		$this->maintenance->handle( array(), $this->run_context );

		self::assertArrayNotHasKey( $later, $this->wpdb->rows );
		self::assertSame( $history_raw, $this->wpdb->rows[ $history_name ] ?? null );
	}

	/**
	 * A full raw page advances past a filtered case collision before exhaustion is recorded.
	 *
	 * @load-bearing bounded-retry-liveness
	 * @pin-rationale Case-colliding candidates must advance the raw cursor so a full filtered page cannot permanently starve later canonical run rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_case_colliding_raw_page_does_not_starve_later_run_rows(): void {
		$this->put_case_colliding_run_names( 1 );
		$this->put_hostile_run_names( 99 );
		$remaining = RunStore::OPTION_PREFIX . 'sweep-tests:remaining_' . self::RUN_ID;
		$this->wpdb->put( $remaining, 'schema-invalid-run' );

		$this->maintenance->handle( array(), $this->run_context );

		self::assertArrayNotHasKey( $remaining, $this->wpdb->rows );
		self::assertArrayNotHasKey( $this->cursor_option, $this->wpdb->rows );
		self::assertStringContainsString( self::hostile_run_name( 98 ), $this->wpdb->recorded_queries[ $this->first_query_after_cursor() ] );
	}

	/**
	 * Raw work stops at the page budget and retains the cursor before filtered counting could overshoot.
	 *
	 * @load-bearing bounded-retry-liveness
	 * @pin-rationale The raw candidate count, not the accepted-name count, bounds each pass and persists a cursor before filtered rows can bypass the work ceiling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_raw_page_budget_retains_cursor_before_filtered_count_would_overshoot(): void {
		$this->put_case_colliding_run_names( 50 );
		$this->put_hostile_run_names( 551 );

		$this->maintenance->handle( array(), $this->run_context );

		self::assertSame( self::hostile_run_name( 449 ), $this->cursor_state()['runs'] );
		self::assertArrayHasKey( self::hostile_run_name( 550 ), $this->wpdb->rows );
	}

	/**
	 * A malformed cursor row restarts both prefixes without raising an exception.
	 *
	 * @load-bearing bounded-retry-liveness
	 * @pin-rationale A malformed durable cursor must fail open to a fresh bounded pass so corrupt progress metadata cannot halt maintenance permanently.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_malformed_cursor_row_starts_a_fresh_full_pass(): void {
		$lock_name = self::lock_name( 0 );
		$this->wpdb->put( $this->cursor_option, 'schema-invalid-cursor' );
		$this->wpdb->put( $lock_name, $this->stale_lock_raw() );

		$this->maintenance->handle( array(), $this->run_context );

		self::assertArrayNotHasKey( $lock_name, $this->wpdb->rows );
		self::assertArrayNotHasKey( $this->cursor_option, $this->wpdb->rows );
	}

	/**
	 * A failed cursor read aborts before either sweep phase and reports the storage cause.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale An unauthoritative cursor read must abort before any phase mutates rows because advancing from guessed progress can skip concurrently retained work.
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

		$this->maintenance->handle( array(), $this->run_context );

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
	 * @load-bearing concurrency
	 * @pin-rationale A failed authoritative page read cannot advance the exact durable cursor generation without risking skipped work.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enumeration_failure_does_not_advance_persisted_cursors(): void {
		$cursor_raw = $this->cursor_raw;
		$this->wpdb->put( $this->cursor_option, $cursor_raw );
		$this->wpdb->before_next(
			'scan',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'scripted enumeration failure';
			}
		);

		$this->maintenance->handle( array(), $this->run_context );

		self::assertSame( $cursor_raw, $this->wpdb->rows[ $this->cursor_option ] ?? null );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'run-enumeration', $this->logger->records[0]['context']['phase'] ?? null );
		self::assertSame( EngineError::class, $this->logger->records[0]['context']['error_class'] ?? null );
		self::assertSame( EngineErrorReason::StorageFailure->value, $this->logger->records[0]['context']['error_reason'] ?? null );
	}

	/**
	 * Reconciliation failure leaves the previously persisted cursor bytes unchanged.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A failed run reconciliation cannot publish later progress over the exact selected cursor generation.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_reconciliation_failure_does_not_advance_persisted_cursors(): void {
		$cursor_raw = $this->cursor_raw;
		$this->wpdb->put( $this->cursor_option, $cursor_raw );
		[ $run_name, $run_raw ] = StoreFixtureBuilder::for_identity( 'sweep-tests:read-failure' )->run(
			self::RUN_ID,
			new RunState( status: RunStatus::Running, kind: 'job', executing: false, start_args: array(), args_hash: self::ARGS_HASH, kind_state: array(), failed_attempts: 0, action_sequence: 0, created_at: self::NOW, heartbeat_at: self::NOW )
		);
		$this->wpdb->put( $run_name, $run_raw );
		$this->wpdb->before_next( 'select', static function ( WpdbLockSpy $database ): void {} );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'scripted reconciliation read failure';
			}
		);

		$this->maintenance->handle( array(), $this->run_context );

		self::assertSame( $cursor_raw, $this->wpdb->rows[ $this->cursor_option ] ?? null );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'run-reconciliation', $this->logger->records[0]['context']['phase'] ?? null );
		self::assertSame( 'sweep-tests:read-failure', $this->logger->records[0]['context']['identity'] ?? null );
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

		$this->maintenance->handle( array(), $this->run_context );

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

		$this->maintenance->handle( array(), $this->run_context );

		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
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
		$option_name = ScheduleRegistry::option_name( 'poison-scope' );
		$this->wpdb->put( $option_name, 'poison-registry-row' );
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'scripted registry row read failure';
			}
		);

		$this->maintenance->handle( array(), $this->run_context );

		self::assertSame( 'poison-registry-row', $this->wpdb->rows[ $option_name ] ?? null );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
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
			$this->wpdb->put( 'A8CSP_BGJE_ACTIVE_RUN_!foreign-' . \sprintf( '%03d', $index ), 'foreign-prefix-row' );
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
		return RunStore::OPTION_PREFIX . '!hostile-' . \sprintf( '%03d', $index );
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
		return OverlapGuard::OPTION_PREFIX . 'sweep-tests:lock-' . \sprintf( '%03d', $index ) . '_' . self::ARGS_HASH;
	}

	/**
	 * Returns one independently serialized sweep cursor fixture.
	 *
	 * The test owns this schema so cursor preconditions do not execute MaintenanceJob::handle().
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function cursor_fixture(): array {
		return array(
			'a8csp_bgje_maintenance_sweep',
			self::cursor_bytes( RunStore::OPTION_PREFIX . '!fixture-499', null, null ),
		);
	}

	/**
	 * Returns exact maintenance cursor bytes for the supplied phase positions.
	 *
	 * @param   string|null $runs          Active-run cursor.
	 * @param   string|null $locks         Overlap-lock cursor.
	 * @param   string|null $registrations Schedule-registration cursor.
	 *
	 * @return  string
	 */
	private static function cursor_bytes( ?string $runs, ?string $locks, ?string $registrations ): string {
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
	 * Returns one complete canonical schedule-registration option name.
	 *
	 * @param   int $index Stable lexical index.
	 *
	 * @return  string
	 */
	private static function registration_name( int $index ): string {
		return ScheduleRegistry::option_name( 'sweep-scope-' . \sprintf( '%03d', $index ) );
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
	 * Returns the decoded persisted cursor state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array{runs: string|null, locks: string|null, registrations: string|null}
	 */
	private function cursor_state(): array {
		$raw = $this->wpdb->rows[ $this->cursor_option ] ?? null;
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

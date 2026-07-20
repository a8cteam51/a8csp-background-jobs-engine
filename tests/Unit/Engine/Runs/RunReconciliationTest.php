<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\JobType;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\MaintenanceLockSweep;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunReconciliation;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Maintenance\MaintenanceJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\CleanupIntents;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingRandomizer;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins periodic reconciliation of abandoned lock and run state.
 *
 * @load-bearing durability
 * @pin-rationale Maintenance must classify and converge exact retained run/lock combinations, including crashed and corrupt states that supported consumer operations cannot manufacture.
 * @fixture StoreFixtureBuilder
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 */
#[CoversClass( RunReconciliation::class )]
#[UsesClass( Dispatcher::class )]
#[UsesClass( MaintenanceJob::class )]
#[UsesClass( MaintenanceLockSweep::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
#[UsesClass( RunFailure::class )]
#[UsesClass( StoreFactory::class )]
#[UsesClass( LifecycleEffects::class )]
#[UsesClass( JobRegistry::class )]
final class RunReconciliationTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array ARGS       = array( 'site_id' => 7 );
	private const string ARGS_HASH = 'd3e2a7f3f4041a96ec4e9d3de1622dea7c050a65d9ee0b77a49a76848fdd9737';
	private const string IDENTITY  = self::OWNER . ':' . self::NAME;
	private const string NAME      = 'crashed-job';
	private const int NOW          = 1_700_000_000;
	private const string OWNER     = 'runs-tests';
	private const string RUN_ID    = '00000000001700000000-0000000000000000042';

	private FixedClock $clock;
	private JobRegistry $work;
	private RecordingJob $job;
	private RecordingBackend $backend;
	private Dispatcher $dispatcher;
	private ActionDeliveries $lifecycle_deliveries;
	private RecordingLogger $logger;
	private MaintenanceJob $maintenance;
	private StoreFactory $stores;
	private LifecycleEffects $terminal_effects;
	private RunTransitions $terminal_transitions;
	private WpdbLockSpy $wpdb;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress seams before maintenance classes are instantiated.
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
	 * Constructs one maintenance job over the real orchestration stores and lock guard.
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

		$this->clock  = new FixedClock( self::NOW );
		$this->work   = new JobRegistry();
		$this->logger = new RecordingLogger();
		$this->wpdb   = new WpdbLockSpy();
		$this->job    = new RecordingJob( self::NAME );
		$this->work->register_job( self::IDENTITY, $this->job );
		$this->backend              = new RecordingBackend();
		$option_rows                = new OptionRows( $this->wpdb );
		$guard                      = new OverlapGuard( $this->clock, $this->logger, new OptionRows( $this->wpdb ) );
		$this->stores               = new StoreFactory( $this->clock, $option_rows, $this->logger );
		$randomizer                 = new RecordingRandomizer( 42 );
		$lock_windows               = new LockWindows( $this->clock, $this->logger );
		$this->terminal_effects     = new LifecycleEffects( $guard, $this->stores, $this->logger );
		$this->terminal_transitions = new RunTransitions( $guard, $this->stores, $this->clock, $lock_windows, $this->logger, $this->terminal_effects );
		$failure_lifecycle          = new FailureLifecycle( $this->backend, $this->clock, $randomizer, $this->logger, $this->terminal_transitions );
		$this->lifecycle_deliveries = new ActionDeliveries( $this->work, $this->backend, $this->stores, $this->logger, $this->clock, $lock_windows, $this->terminal_transitions, $this->terminal_effects, $failure_lifecycle );
		$this->dispatcher           = new Dispatcher( $this->work, $this->backend, $guard, $this->stores, $this->clock, $randomizer, $this->logger, $lock_windows, $this->terminal_transitions, $this->terminal_effects );
		$reconciliation             = new RunReconciliation( $guard, $this->stores, $this->clock, $this->logger, $lock_windows, $this->terminal_transitions, $this->terminal_effects, $this->work, $this->backend );
		$cleanup_intents            = new CleanupIntents( new ScheduleRegistry( $option_rows, $this->logger ), new SchedulerFacade( array( $this->backend ) ), $option_rows, $this->clock, $this->logger );
		$this->maintenance          = new MaintenanceJob( $option_rows, $reconciliation, $guard, $cleanup_intents, $this->logger );
	}

	// endregion.

	// region TESTS.

	/**
	 * The maintenance job exposes one stable owner-local job name.
	 *
	 * @return  void
	 */
	public function test_job_name_is_owner_local(): void {
		self::assertSame( 'maintenance', $this->maintenance->get_name() );
	}

	/**
	 * The final maintenance phase converges durable unknown-chain intent.
	 *
	 * @return  void
	 */
	public function test_sweep_converges_pending_unknown_chain_intent(): void {
		$registration_key = 'orphan-owner:orphan-schedule';

		[ $option_name, $raw ] = StoreFixtureBuilder::for_identity( $registration_key )->cleanup_intent( self::NOW );
		$this->wpdb->put( $option_name, $raw );

		$this->run_maintenance();

		self::assertArrayNotHasKey( $option_name, $this->wpdb->rows );
	}

	/**
	 * A stale lock whose owning run option is gone is deleted and logged.
	 *
	 * @return  void
	 */
	public function test_sweep_deletes_a_stale_orphaned_lock(): void {
		$orphan_name = self::identity( 'orphan-job' );
		$lock_name   = OverlapGuard::OPTION_PREFIX . $orphan_name . '_' . \str_repeat( 'a', 64 );
		$this->put_lock( $lock_name, self::RUN_ID, self::NOW - 901 );

		$this->run_maintenance();

		self::assertArrayNotHasKey( $lock_name, $this->wpdb->rows );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( $orphan_name, $this->logger->records[0]['context']['name'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
	}

	/**
	 * A fresh orphan lock survives the claim-to-run-option creation window.
	 *
	 * @return  void
	 */
	public function test_sweep_leaves_a_fresh_orphaned_lock_untouched(): void {
		$lock_name = OverlapGuard::OPTION_PREFIX . self::identity( 'orphan-job' ) . '_' . \str_repeat( 'a', 64 );
		$this->put_lock( $lock_name, self::RUN_ID, self::NOW );

		$this->run_maintenance();

		self::assertArrayHasKey( $lock_name, $this->wpdb->rows );
		self::assertSame( array(), $this->logger->records );
	}

	/**
	 * A marker-stuck running run with a stale owned lock follows the crash-failure terminal path.
	 *
	 * @return  void
	 */
	public function test_sweep_terminalizes_a_running_run_with_a_stale_lock(): void {
		$this->create_running_run();
		$options = $this->options();
		$state   = $options[ $this->run_option_name() ] ?? null;
		self::assertIsArray( $state );
		$state['executing']                  = true;
		$options[ $this->run_option_name() ] = $state;
		$GLOBALS['a8csp_bgje_test_options']  = $options;
		$this->clock->timestamp              = self::NOW + 901;

		$this->run_maintenance();

		self::assertSame( array(), $this->backend->calls );
		$this->assert_crashed_run_terminalized();
	}

	/**
	 * A running run with no owned lock follows the same crash-failure terminal path.
	 *
	 * @return  void
	 */
	public function test_sweep_terminalizes_a_running_run_with_a_missing_lock(): void {
		$this->create_running_run();
		$this->set_run_fields( self::IDENTITY, array( 'executing' => true ) );
		unset( $this->wpdb->rows[ $this->lock_option_name() ] );

		$this->run_maintenance();

		$this->assert_crashed_run_terminalized();
	}

	/**
	 * A stale committed chunked job continuation is redelivered and advances through one chunk without failure.
	 *
	 * @return  void
	 */
	public function test_sweep_redelivers_a_stale_pending_chunked_job_continue_and_processes_its_chunk(): void {
		$name               = self::identity( 'redelivered-chunked-job' );
		$chunk              = array( 'page' => 1 );
		$chunked_job        = new RecordingChunkedJob( 'redelivered-chunked-job' );
		$chunked_job->queue = array( $chunk );
		$this->work->register_chunked_job( $name, $chunked_job );
		$result = $this->dispatcher->start_chunked_job( $name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		$this->lifecycle_deliveries->handle_start_action( $name, self::RUN_ID, 1 );
		$this->backend->calls   = array();
		$this->clock->timestamp = self::NOW + 901;

		$this->run_maintenance();

		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'a8csp_jobs_engine/continue_chunked_job',
						'args'     => array( $name, self::RUN_ID, 2 ),
						'group'    => $name . '|' . self::RUN_ID,
						'priority' => 10,
					),
				),
			),
			$this->backend->calls
		);
		$state = $this->run_state( $name );
		self::assertSame( 'running', $state['status'] ?? null );
		self::assertFalse( $state['executing'] ?? true );
		self::assertSame( 2, $state['action_sequence'] ?? null );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . $name, $this->options() );
		self::assertSame( array(), $chunked_job->failed_calls );

		$this->lifecycle_deliveries->handle_continue_action( $name, self::RUN_ID, 2 );

		self::assertCount( 1, $chunked_job->process_calls );
		self::assertSame( $chunk, $chunked_job->process_calls[0]['chunk_args'] ?? null );
		self::assertSame( array(), $chunked_job->failed_calls );
	}

	/**
	 * A pending chunked-job-start descriptor preserves the scheduler request under every overlap policy.
	 *
	 * @param   string $overlap_value Declared overlap policy value used for admission.
	 *
	 * @return  void
	 */
	#[DataProvider( 'chunked_job_start_redelivery_policies' )]
	public function test_sweep_redelivers_a_stale_pending_chunked_job_start_for_every_overlap_policy( string $overlap_value ): void {
		$name                        = self::identity( 'redelivered-start-chunked-job' );
		$chunked_job                 = new RecordingChunkedJob( 'redelivered-start-chunked-job' );
		$chunked_job->overlap_policy = OverlapPolicy::from( $overlap_value );
		$this->work->register_chunked_job( $name, $chunked_job );
		$result = $this->dispatcher->start_chunked_job( $name, self::ARGS, priority: 23 );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		self::assertSame(
			array(
				'stage'    => 'start',
				'mode'     => 'async',
				'fire_at'  => null,
				'priority' => 23,
			),
			$this->run_state( $name )['pending'] ?? null
		);
		$this->backend->calls   = array();
		$this->clock->timestamp = self::NOW + 901;

		$this->run_maintenance();

		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'a8csp_jobs_engine/start_chunked_job',
						'args'     => array( $name, self::RUN_ID, 1 ),
						'group'    => $name . '|' . self::RUN_ID,
						'priority' => 23,
					),
				),
			),
			$this->backend->calls
		);
		self::assertSame( 'running', $this->run_state( $name )['status'] ?? null );
		self::assertSame( array(), $chunked_job->failed_calls );
	}

	/**
	 * Supplies every overlap policy that can schedule a chunked job start.
	 *
	 * @return  array<string, array{overlap_value: string}>
	 */
	public static function chunked_job_start_redelivery_policies(): array {
		return array(
			'allow'   => array(
				'overlap_value' => 'allow',
			),
			'reject'  => array(
				'overlap_value' => 'reject',
			),
			'replace' => array(
				'overlap_value' => 'replace',
			),
		);
	}

	/**
	 * A stale schedule-driven job descriptor preserves its priority.
	 *
	 * @return  void
	 */
	public function test_sweep_redelivers_a_stale_pending_job_action(): void {
		$result = $this->dispatcher->dispatch_scheduled_job( self::IDENTITY, self::ARGS, priority: 23 );
		self::assertInstanceOf( Success::class, $result );
		$this->backend->calls   = array();
		$this->clock->timestamp = self::NOW + 901;

		$this->run_maintenance();

		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'a8csp_jobs_engine/run_job',
						'args'     => array( self::IDENTITY, self::RUN_ID, 1 ),
						'group'    => self::IDENTITY . '|' . self::RUN_ID,
						'priority' => 23,
					),
				),
			),
			$this->backend->calls
		);
		self::assertSame( 'running', $this->run_state( self::IDENTITY )['status'] ?? null );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $this->options() );
	}

	/**
	 * A missing lock is reconstructed for the retained generation before its job action is redelivered.
	 *
	 * @return  void
	 */
	public function test_sweep_restores_a_missing_lock_before_redelivering_a_stale_pending_job(): void {
		$this->create_running_run();
		unset( $this->wpdb->rows[ $this->lock_option_name() ] );
		$this->backend->calls   = array();
		$this->clock->timestamp = self::NOW + 901;

		$this->run_maintenance();

		self::assertCount( 1, $this->backend->calls );
		self::assertSame( 'enqueue_async', $this->backend->calls[0]['verb'] ?? null );
		$lock_raw = $this->wpdb->rows[ $this->lock_option_name() ] ?? null;
		self::assertIsString( $lock_raw );
		self::assertSame(
			array(
				'run_id'       => self::RUN_ID,
				'claimed_at'   => self::NOW,
				'heartbeat_at' => self::NOW,
			),
			\maybe_unserialize( $lock_raw )
		);

		$this->lifecycle_deliveries->handle_run_job_action( self::IDENTITY, self::RUN_ID, 1 );

		self::assertArrayNotHasKey( $this->run_option_name(), $this->options() );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $this->options() );
	}

	/**
	 * A backend may deliver an accepted redelivery before returning without a later state overwrite.
	 *
	 * @return  void
	 */
	public function test_sweep_allows_a_redelivered_job_to_complete_during_scheduler_acceptance(): void {
		$this->create_running_run();
		$this->clock->timestamp = self::NOW + 901;
		$this->backend->calls   = array();
		$this->backend->before_next(
			'enqueue_async',
			function (): void {
				$this->lifecycle_deliveries->handle_run_job_action( self::IDENTITY, self::RUN_ID, 1 );
			}
		);

		$this->run_maintenance();

		$options = $this->options();
		self::assertCount( 1, $this->backend->calls );
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $options );
		$this->assert_history_status( $options, 'completed' );
		self::assertSame(
			array(
				'a8csp_jobs_engine/completed/' . self::IDENTITY,
				'a8csp_jobs_engine/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
	}

	/**
	 * A newer same-owner lock credit defers redelivery until it can be restored to the retained generation.
	 *
	 * @return  void
	 */
	public function test_sweep_repairs_a_stale_same_owner_heartbeat_mismatch_before_redelivery(): void {
		$result = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS, delay: 1_200 );
		self::assertInstanceOf( Success::class, $result );
		$this->set_run_fields( self::IDENTITY, array( 'heartbeat_at' => self::NOW ) );
		$this->backend->calls   = array();
		$this->clock->timestamp = self::NOW + 901;

		$this->run_maintenance();

		self::assertSame( array(), $this->backend->calls );
		$credited_lock = $this->wpdb->rows[ $this->lock_option_name() ] ?? null;
		self::assertIsString( $credited_lock );
		$credited_lock = \maybe_unserialize( $credited_lock );
		self::assertIsArray( $credited_lock );
		self::assertSame( self::NOW + 1_200, $credited_lock['heartbeat_at'] ?? null );

		$this->clock->timestamp = self::NOW + 2_101;
		$this->run_maintenance();

		self::assertSame(
			array(
				array(
					'verb' => 'schedule_single',
					'args' => array(
						'hook'      => 'a8csp_jobs_engine/run_job',
						'timestamp' => self::NOW + 2_101,
						'args'      => array( self::IDENTITY, self::RUN_ID, 1 ),
						'group'     => self::IDENTITY . '|' . self::RUN_ID,
						'priority'  => 10,
					),
				),
			),
			$this->backend->calls
		);
		$restored_lock = $this->wpdb->rows[ $this->lock_option_name() ] ?? null;
		self::assertIsString( $restored_lock );
		$restored_lock = \maybe_unserialize( $restored_lock );
		self::assertIsArray( $restored_lock );
		self::assertSame( self::NOW, $restored_lock['heartbeat_at'] ?? null );

		$this->lifecycle_deliveries->handle_run_job_action( self::IDENTITY, self::RUN_ID, 1 );

		self::assertArrayNotHasKey( $this->run_option_name(), $this->options() );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $this->options() );
	}

	/**
	 * Single-mode retry redelivery clamps a past fire time to now and retains a future fire time.
	 *
	 * @param   int $fire_at           Persisted retry fire time.
	 * @param   int $expected_fire_at  Expected scheduler timestamp.
	 *
	 * @return  void
	 */
	#[DataProvider( 'retry_redelivery_fire_times' )]
	public function test_sweep_redelivers_a_stale_pending_retry_at_the_remaining_delay( int $fire_at, int $expected_fire_at ): void {
		$this->create_running_run();
		$this->set_run_fields(
			self::IDENTITY,
			array(
				'heartbeat_at'    => self::NOW - 901,
				'failed_attempts' => 1,
				'pending'         => array(
					'stage'    => 'run',
					'mode'     => 'single',
					'fire_at'  => $fire_at,
					'priority' => 17,
				),
			)
		);
		$this->put_lock( $this->lock_option_name(), self::RUN_ID, self::NOW - 901 );
		$this->backend->calls = array();

		$this->run_maintenance();

		self::assertSame(
			array(
				array(
					'verb' => 'schedule_single',
					'args' => array(
						'hook'      => 'a8csp_jobs_engine/run_job',
						'timestamp' => $expected_fire_at,
						'args'      => array( self::IDENTITY, self::RUN_ID, 1 ),
						'group'     => self::IDENTITY . '|' . self::RUN_ID,
						'priority'  => 17,
					),
				),
			),
			$this->backend->calls
		);
		self::assertSame( 'running', $this->run_state( self::IDENTITY )['status'] ?? null );
	}

	/**
	 * Supplies future and elapsed retry fire times.
	 *
	 * @return  array<string, array{fire_at: int, expected_fire_at: int}>
	 */
	public static function retry_redelivery_fire_times(): array {
		return array(
			'future fire retains remaining delay' => array(
				'fire_at'          => self::NOW + 75,
				'expected_fire_at' => self::NOW + 75,
			),
			'elapsed fire runs immediately'       => array(
				'fire_at'          => self::NOW - 75,
				'expected_fire_at' => self::NOW,
			),
		);
	}

	/**
	 * A scheduler rejection preserves the pending run for a later redelivery.
	 *
	 * @return  void
	 */
	public function test_sweep_preserves_a_stale_pending_run_when_redelivery_is_rejected(): void {
		$name        = self::identity( 'redelivery-rejection-chunked-job' );
		$chunked_job = new RecordingChunkedJob( 'redelivery-rejection-chunked-job' );
		$this->work->register_chunked_job( $name, $chunked_job );
		$result = $this->dispatcher->start_chunked_job( $name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );

		$state_before = $this->run_state( $name );
		$raw_before   = \maybe_serialize( $state_before );
		self::assertIsString( $raw_before );
		$pending_before = $state_before['pending'] ?? null;
		self::assertIsArray( $pending_before );

		$this->clock->timestamp                   = self::NOW + 901;
		$this->backend->results['enqueue_async']  = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Restore the scheduler before redelivering.', array( 'client_payload' => self::ARGS ) ) );
		$this->backend->calls                     = array();
		$this->logger->records                    = array();
		$GLOBALS['a8csp_bgje_test_fired_actions'] = array();

		$this->run_maintenance();

		self::assertCount( 1, $this->backend->calls );
		$rejected_call = $this->backend->calls[0];
		$state_after   = $this->run_state( $name );
		$raw_after     = \maybe_serialize( $state_after );
		self::assertIsString( $raw_after );
		self::assertSame( $raw_before, $raw_after );
		self::assertSame( 'running', $state_after['status'] ?? null );
		self::assertFalse( $state_after['executing'] ?? true );
		self::assertSame( $pending_before, $state_after['pending'] ?? null );
		$options = $this->options();
		self::assertArrayHasKey( $this->run_option_name( $name ), $options );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . $name, $options );
		self::assertSame( array(), $chunked_job->failed_calls );
		self::assertSame( array(), $this->fired_actions() );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( $name, $this->logger->records[0]['context']['name'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
		self::assertSame( SchedulingError::class, $this->logger->records[0]['context']['error_class'] ?? null );
		self::assertSame( SchedulingErrorReason::ScheduleFailed->value, $this->logger->records[0]['context']['error_reason'] ?? null );

		unset( $this->backend->results['enqueue_async'] );

		$this->run_maintenance();

		self::assertCount( 2, $this->backend->calls );
		self::assertSame( $rejected_call, $this->backend->calls[1] );
		self::assertCount( 1, $this->logger->records );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . $name, $this->options() );
		self::assertSame( array(), $chunked_job->failed_calls );
		self::assertSame( array(), $this->fired_actions() );
	}

	/**
	 * A transfer completed during a rejected redelivery is reconciled by the next sweep.
	 *
	 * @return  void
	 */
	public function test_next_sweep_supersedes_when_ownership_transfers_during_a_rejected_redelivery(): void {
		$this->create_running_run();
		$this->clock->timestamp                  = self::NOW + 901;
		$replacement_run_id                      = '00000000001700000001-0000000000000000043';
		$this->backend->results['enqueue_async'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Rejection resolves after ownership transfers.' ) );
		$this->backend->before_next(
			'enqueue_async',
			function () use ( $replacement_run_id ): void {
				$this->put_lock( $this->lock_option_name(), $replacement_run_id, $this->clock->timestamp );
			}
		);
		$this->backend->calls = array();

		$this->run_maintenance();

		$options = $this->options();
		self::assertCount( 1, $this->backend->calls );
		self::assertArrayHasKey( $this->run_option_name(), $options );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $options );
		self::assertSame( array(), $this->fired_actions() );
		$lock_raw = $this->wpdb->rows[ $this->lock_option_name() ] ?? null;
		self::assertIsString( $lock_raw );
		$lock = \maybe_unserialize( $lock_raw );
		self::assertIsArray( $lock );
		self::assertSame( $replacement_run_id, $lock['run_id'] ?? null );

		$this->run_maintenance();

		$options = $this->options();
		self::assertCount( 1, $this->backend->calls );
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $options );
		self::assertSame(
			array(
				'a8csp_jobs_engine/superseded/' . self::IDENTITY,
				'a8csp_jobs_engine/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_history_status( $options, 'superseded' );
	}

	/**
	 * A rejected redelivery never enters the terminal lock-claim path.
	 *
	 * @return  void
	 */
	public function test_sweep_does_not_claim_a_terminal_fence_after_redelivery_rejection(): void {
		$this->create_running_run();
		$this->clock->timestamp                  = self::NOW + 901;
		$replacement_run_id                      = '00000000001700000001-0000000000000000043';
		$this->backend->results['enqueue_async'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Rejection resolves before the terminal fence transfers.' ) );
		$this->backend->before_next(
			'enqueue_async',
			function () use ( $replacement_run_id ): void {
				$this->wpdb->before_next(
					'delete',
					function () use ( $replacement_run_id ): void {
						$this->put_lock( $this->lock_option_name(), $replacement_run_id, $this->clock->timestamp );
					}
				);
			}
		);
		$this->backend->calls = array();

		$this->run_maintenance();

		self::assertArrayHasKey( $this->run_option_name(), $this->options() );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $this->options() );
		self::assertSame( array(), $this->fired_actions() );
		$lock_raw = $this->wpdb->rows[ $this->lock_option_name() ] ?? null;
		self::assertIsString( $lock_raw );
		$lock = \maybe_unserialize( $lock_raw );
		self::assertIsArray( $lock );
		self::assertSame( self::RUN_ID, $lock['run_id'] ?? null );

		unset( $this->backend->results['enqueue_async'] );

		$this->run_maintenance();

		$options = $this->options();
		self::assertCount( 2, $this->backend->calls );
		self::assertSame( $this->backend->calls[0], $this->backend->calls[1] );
		self::assertArrayHasKey( $this->run_option_name(), $options );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $options );
		self::assertSame( array(), $this->fired_actions() );
		$lock_raw = $this->wpdb->rows[ $this->lock_option_name() ] ?? null;
		self::assertIsString( $lock_raw );
		$lock = \maybe_unserialize( $lock_raw );
		self::assertIsArray( $lock );
		self::assertSame( self::RUN_ID, $lock['run_id'] ?? null );
	}

	/**
	 * A stale legacy running row without a descriptor remains on the crash-failure path with a diagnostic.
	 *
	 * @return  void
	 */
	public function test_sweep_terminalizes_a_stale_run_missing_its_pending_descriptor(): void {
		$this->create_running_run();
		$state = $this->run_state( self::IDENTITY );
		unset( $state['pending'] );
		$state['heartbeat_at'] = self::NOW - 901;
		$this->replace_run_state( self::IDENTITY, $state );
		$this->put_lock( $this->lock_option_name(), self::RUN_ID, self::NOW - 901 );
		$this->backend->calls = array();

		$this->run_maintenance();

		self::assertSame( array(), $this->backend->calls );
		$this->assert_crashed_run_terminalized();
		self::assertNotNull(
			$this->log_record(
				'warning',
				array(
					'name'   => self::IDENTITY,
					'run_id' => self::RUN_ID,
				)
			)
		);
	}

	/**
	 * A pending row exactly at the strict heartbeat boundary is untouched.
	 *
	 * @return  void
	 */
	public function test_sweep_leaves_a_fresh_pending_run_untouched(): void {
		$this->create_running_run();
		$state_before           = $this->run_state( self::IDENTITY );
		$lock_before            = $this->wpdb->rows[ $this->lock_option_name() ] ?? null;
		$this->backend->calls   = array();
		$this->clock->timestamp = self::NOW + 900;

		$this->run_maintenance();

		self::assertSame( array(), $this->backend->calls );
		self::assertSame( $state_before, $this->run_state( self::IDENTITY ) );
		self::assertSame( $lock_before, $this->wpdb->rows[ $this->lock_option_name() ] ?? null );
	}

	/**
	 * Repeated accepted redeliveries remain harmless because only one exact action generation advances.
	 *
	 * @return  void
	 */
	public function test_two_sweeps_redeliver_twice_but_duplicate_delivery_processes_once(): void {
		$name               = self::identity( 'idempotent-chunked-job' );
		$chunk              = array( 'page' => 1 );
		$chunked_job        = new RecordingChunkedJob( 'idempotent-chunked-job' );
		$chunked_job->queue = array( $chunk );
		$this->work->register_chunked_job( $name, $chunked_job );
		$result = $this->dispatcher->start_chunked_job( $name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		$this->lifecycle_deliveries->handle_start_action( $name, self::RUN_ID, 1 );
		$this->backend->calls   = array();
		$this->clock->timestamp = self::NOW + 901;

		$this->run_maintenance();
		$this->run_maintenance();

		self::assertCount( 2, $this->backend->calls );
		self::assertSame( $this->backend->calls[0], $this->backend->calls[1] );
		self::assertSame( 'enqueue_async', $this->backend->calls[0]['verb'] ?? null );
		self::assertSame( 'a8csp_jobs_engine/continue_chunked_job', $this->backend->calls[0]['args']['hook'] ?? null );
		self::assertSame( array( $name, self::RUN_ID, 2 ), $this->backend->calls[0]['args']['args'] ?? null );

		$this->lifecycle_deliveries->handle_continue_action( $name, self::RUN_ID, 2 );
		$this->lifecycle_deliveries->handle_continue_action( $name, self::RUN_ID, 2 );

		self::assertCount( 1, $chunked_job->process_calls );
		self::assertSame( array(), $chunked_job->failed_calls );
	}

	/**
	 * A retained Chunked Job run cannot be delivered through a Job that reused its identity.
	 *
	 * @return  void
	 */
	public function test_pending_chunked_job_redelivery_ignores_a_current_job_with_the_same_identity(): void {
		$chunk = array( 'page' => 1 );
		$state = new RunState( status: RunStatus::Running, kind: JobType::ChunkedJob, executing: false, start_args: self::ARGS, args_hash: self::ARGS_HASH, queue: array( $chunk ), failed_attempts: 0, action_sequence: 1, created_at: self::NOW - 901, heartbeat_at: self::NOW - 901, pending: PendingAction::async( 'continue', 10 ) );
		$this->store_running_state( self::IDENTITY, $state );
		$this->put_lock( $this->lock_option_name(), self::RUN_ID, self::NOW - 901 );
		$current_job = $this->work->job( self::IDENTITY );
		self::assertInstanceOf( RecordingJob::class, $current_job );

		$this->run_maintenance();

		self::assertSame( 'a8csp_jobs_engine/continue_chunked_job', $this->backend->calls[0]['args']['hook'] ?? null );
		self::assertSame( array( self::IDENTITY, self::RUN_ID, 1 ), $this->backend->calls[0]['args']['args'] ?? null );

		$this->lifecycle_deliveries->handle_continue_action( self::IDENTITY, self::RUN_ID, 1 );

		self::assertSame( array(), $current_job->calls );
		self::assertArrayNotHasKey( $this->run_option_name(), $this->options() );
		$failed = $this->options()[ 'a8csp_bgje_failed_runs_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $failed );
		$failed_entry = $failed[0] ?? null;
		self::assertIsArray( $failed_entry );
		$error = $failed_entry['error'] ?? null;
		self::assertIsArray( $error );
		self::assertSame( $chunk, $error['failed_chunk'] ?? null );
		$failure = $this->fired_actions()[0]['args'][2] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( $chunk, $failure->failed_chunk );
		$record = $this->log_record(
			'warning',
			array(
				'chunked_job_name' => self::IDENTITY,
				'run_id'           => self::RUN_ID,
			)
		);
		self::assertNotNull( $record );
		self::assertSame( self::IDENTITY, $record['context']['chunked_job_name'] ?? null );
	}

	/**
	 * A retained Job run cannot be delivered through a Chunked Job that reused its identity.
	 *
	 * @return  void
	 */
	public function test_pending_job_redelivery_ignores_a_current_chunked_job_with_the_same_identity(): void {
		$name                = self::identity( 'reused-as-chunked-job' );
		$current_chunked_job = new RecordingChunkedJob( 'reused-as-chunked-job' );
		$this->work->register_chunked_job( $name, $current_chunked_job );
		$state = new RunState( status: RunStatus::Running, kind: JobType::Job, executing: false, start_args: self::ARGS, args_hash: self::ARGS_HASH, queue: array( self::ARGS ), failed_attempts: 0, action_sequence: 1, created_at: self::NOW - 901, heartbeat_at: self::NOW - 901, pending: PendingAction::async( 'run', 10 ) );
		$this->store_running_state( $name, $state );
		$this->put_lock( 'a8csp_bgje_overlap_lock_' . $name . '_' . self::ARGS_HASH, self::RUN_ID, self::NOW - 901 );

		$this->run_maintenance();

		self::assertSame( 'a8csp_jobs_engine/run_job', $this->backend->calls[0]['args']['hook'] ?? null );
		self::assertSame( array( $name, self::RUN_ID, 1 ), $this->backend->calls[0]['args']['args'] ?? null );

		$this->lifecycle_deliveries->handle_run_job_action( $name, self::RUN_ID, 1 );

		self::assertSame( array(), $current_chunked_job->process_calls );
		self::assertArrayNotHasKey( $this->run_option_name( $name ), $this->options() );
		self::assertArrayHasKey( 'a8csp_bgje_failed_runs_' . $name, $this->options() );
		$record = $this->log_record(
			'warning',
			array(
				'job_name' => $name,
				'run_id'   => self::RUN_ID,
			)
		);
		self::assertNotNull( $record );
		self::assertSame( $name, $record['context']['job_name'] ?? null );
	}

	/**
	 * A throwing run timing filter leaves its row unchanged without starving a later client.
	 *
	 * @return  void
	 */
	public function test_sweep_continues_after_one_run_staleness_filter_throws(): void {
		$this->create_running_run();
		$run_name = $this->run_option_name();
		$run_raw  = \maybe_serialize( $this->options()[ $run_name ] ?? null );
		self::assertIsString( $run_raw );
		$replacement_run_id = '00000000001700000001-0000000000000000043';
		$this->put_lock( $this->lock_option_name(), $replacement_run_id, self::NOW - 901 );
		$lock_raw = $this->wpdb->rows[ $this->lock_option_name() ] ?? null;
		self::assertIsString( $lock_raw );

		$healthy_name        = self::identity( 'healthy-chunked-job' );
		$healthy_chunked_job = new RecordingChunkedJob( 'healthy-chunked-job' );
		$this->work->register_chunked_job( $healthy_name, $healthy_chunked_job );
		$result = $this->dispatcher->start_chunked_job( $healthy_name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		$this->set_run_fields( $healthy_name, array( 'executing' => true ) );
		unset( $this->wpdb->rows[ 'a8csp_bgje_overlap_lock_' . $healthy_name . '_' . self::ARGS_HASH ] );

		$throwable = new \RuntimeException( 'Run staleness filter exploded.' );

		$GLOBALS['a8csp_bgje_test_filter_values'] = array(
			'a8csp_jobs_engine/lock_staleness/' . self::IDENTITY => static function () use ( $throwable ): int {
				throw $throwable;
			},
		);

		$this->logger->records = array();

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayHasKey( $run_name, $options );
		self::assertSame( $run_raw, \maybe_serialize( $options[ $run_name ] ) );
		self::assertSame( $lock_raw, $this->wpdb->rows[ $this->lock_option_name() ] ?? null );
		self::assertArrayNotHasKey( RunStore::OPTION_PREFIX . $healthy_name . '_' . self::RUN_ID, $options );
		self::assertArrayHasKey( 'a8csp_bgje_failed_runs_' . $healthy_name, $options );
		self::assertCount( 1, $healthy_chunked_job->failed_calls );
		$diagnostic = $this->exception_diagnostic( $throwable );
		self::assertSame( 'warning', $diagnostic['level'] ?? null );
		self::assertSame(
			array(
				'name'      => self::IDENTITY,
				'run_id'    => self::RUN_ID,
				'exception' => $throwable,
			),
			$diagnostic['context']
		);
	}

	/**
	 * An unclassified run keeps same-name transfer evidence for a later sweep.
	 *
	 * @return  void
	 */
	public function test_sweep_defers_same_name_locks_after_run_timing_filter_throws(): void {
		$this->create_running_run();
		$run_name = $this->run_option_name();
		$run_raw  = \maybe_serialize( $this->options()[ $run_name ] ?? null );
		self::assertIsString( $run_raw );

		$replacement_run_id = '00000000001700000001-0000000000000000043';
		$this->put_lock( $this->lock_option_name(), $replacement_run_id, self::NOW - 901 );
		$lock_raw = $this->wpdb->rows[ $this->lock_option_name() ] ?? null;
		self::assertIsString( $lock_raw );

		$throwable = new \RuntimeException( 'Run continue-delay filter exploded.' );

		$GLOBALS['a8csp_bgje_test_filter_values'] = array(
			'a8csp_jobs_engine/continue_delay' => static function ( int $delay, string $name, string $run_id ) use ( $throwable ): int {
				if ( self::IDENTITY === $name && self::RUN_ID === $run_id ) {
					throw $throwable;
				}

				return $delay;
			},
		);

		$this->logger->records = array();

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayHasKey( $run_name, $options );
		self::assertSame( $run_raw, \maybe_serialize( $options[ $run_name ] ) );
		self::assertSame( $lock_raw, $this->wpdb->rows[ $this->lock_option_name() ] ?? null );
		$diagnostic = $this->exception_diagnostic( $throwable );
		self::assertSame( 'warning', $diagnostic['level'] ?? null );
		self::assertSame(
			array(
				'name'      => self::IDENTITY,
				'run_id'    => self::RUN_ID,
				'exception' => $throwable,
			),
			$diagnostic['context']
		);

		$GLOBALS['a8csp_bgje_test_filter_values'] = array();
		$this->clock->timestamp                   = self::NOW + 901;

		$this->run_maintenance();

		self::assertArrayNotHasKey( $this->lock_option_name(), $this->wpdb->rows );
	}

	/**
	 * A lock read failure cannot be mistaken for authoritative absence during crash reconciliation.
	 *
	 * @return  void
	 */
	public function test_sweep_leaves_a_running_run_untouched_when_lock_read_fails(): void {
		$this->create_running_run();
		unset( $this->wpdb->rows[ $this->lock_option_name() ] );
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient read failure';
			}
		);

		$this->run_maintenance();

		self::assertArrayHasKey( $this->run_option_name(), $this->options() );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $this->options() );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'run-reconciliation', $this->logger->records[0]['context']['phase'] ?? null );
		self::assertSame( self::IDENTITY, $this->logger->records[0]['context']['name'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
		self::assertSame( EngineError::class, $this->logger->records[0]['context']['error_class'] ?? null );
		self::assertSame( 'storage_failure', $this->logger->records[0]['context']['error_reason'] ?? null );
	}

	/**
	 * A run read failure aborts the sweep before lock reconciliation can erase fencing evidence.
	 *
	 * @return  void
	 */
	public function test_sweep_aborts_lock_reconciliation_when_a_run_read_fails(): void {
		$this->create_running_run();
		$orphan_lock = 'a8csp_bgje_overlap_lock_' . self::identity( 'orphan-job' ) . '_' . \str_repeat( 'a', 64 );
		$this->put_lock( $orphan_lock, 'orphan-run', self::NOW - 901 );
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient run read failure';
			}
		);

		$this->run_maintenance();

		self::assertArrayHasKey( $this->run_option_name(), $this->options() );
		self::assertArrayHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertArrayHasKey( $orphan_lock, $this->wpdb->rows );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $this->options() );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'run-reconciliation', $this->logger->records[0]['context']['phase'] ?? null );
		self::assertSame( self::IDENTITY, $this->logger->records[0]['context']['name'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
		self::assertSame( EngineError::class, $this->logger->records[0]['context']['error_class'] ?? null );
		self::assertSame( 'storage_failure', $this->logger->records[0]['context']['error_reason'] ?? null );
	}

	/**
	 * A failed run-name enumeration aborts before the lock phase.
	 *
	 * @return  void
	 */
	public function test_sweep_aborts_lock_reconciliation_when_run_enumeration_fails(): void {
		$lock_name = 'a8csp_bgje_overlap_lock_' . self::identity( 'orphan-job' ) . '_' . \str_repeat( 'a', 64 );
		$this->put_lock( $lock_name, 'orphan-run', self::NOW - 901 );
		$lock_raw = $this->wpdb->rows[ $lock_name ] ?? null;
		self::assertIsString( $lock_raw );
		$this->wpdb->before_next(
			'scan',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient run-name enumeration failure';
			}
		);

		$this->run_maintenance();

		self::assertSame( $lock_raw, $this->wpdb->rows[ $lock_name ] ?? null );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'run-enumeration', $this->logger->records[0]['context']['phase'] ?? null );
		self::assertSame( EngineError::class, $this->logger->records[0]['context']['error_class'] ?? null );
		self::assertSame( 'storage_failure', $this->logger->records[0]['context']['error_reason'] ?? null );
	}

	/**
	 * A retained Chunked Job run keeps Chunked Job supersession routing after its identity is reused by a Job.
	 *
	 * @return  void
	 */
	public function test_sweep_supersedes_a_stale_persisted_chunked_job_whose_lock_has_transferred(): void {
		$state = new RunState( status: RunStatus::Running, kind: JobType::ChunkedJob, executing: false, start_args: self::ARGS, args_hash: self::ARGS_HASH, queue: array( self::ARGS ), failed_attempts: 0, action_sequence: 1, created_at: self::NOW, heartbeat_at: self::NOW, pending: PendingAction::async( 'continue', 10 ) );
		$this->store_running_state( self::IDENTITY, $state );
		$this->clock->timestamp = self::NOW + 901;
		$replacement_run_id     = '00000000001700000001-0000000000000000043';
		$this->put_lock( $this->lock_option_name(), $replacement_run_id, $this->clock->timestamp );

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $options );
		self::assertSame(
			array(
				'a8csp_jobs_engine/superseded/' . self::IDENTITY,
				'a8csp_jobs_engine/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$history = $options[ 'a8csp_bgje_history_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $history );
		self::assertSame(
			array(
				array(
					'run_id' => self::RUN_ID,
					'status' => 'superseded',
				),
			),
			$history['terminal'] ?? null
		);
		$lock = $this->wpdb->rows[ $this->lock_option_name() ] ?? null;
		self::assertIsString( $lock );
		$lock_row = \maybe_unserialize( $lock );
		self::assertIsArray( $lock_row );
		self::assertSame( $replacement_run_id, $lock_row['run_id'] ?? null );
		$record = $this->log_record(
			'info',
			array(
				'chunked_job_name' => self::IDENTITY,
				'run_id'           => self::RUN_ID,
			)
		);
		self::assertNotNull( $record );
		self::assertSame( self::IDENTITY, $record['context']['chunked_job_name'] ?? null );
		self::assertArrayNotHasKey( 'job_name', $record['context'] );
	}

	/**
	 * Transfer evidence classifies the old run before its stale orphaned replacement lock is reclaimed.
	 *
	 * @return  void
	 */
	public function test_sweep_preserves_supersession_when_the_transferred_lock_is_also_orphaned(): void {
		$this->create_running_run();
		$this->clock->timestamp = self::NOW + 901;
		$replacement_run_id     = '00000000001700000001-0000000000000000043';
		$this->put_lock( $this->lock_option_name(), $replacement_run_id, self::NOW );

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $options );
		self::assertArrayNotHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertSame(
			array(
				'a8csp_jobs_engine/superseded/' . self::IDENTITY,
				'a8csp_jobs_engine/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$history = $options[ 'a8csp_bgje_history_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $history );
		self::assertSame(
			array(
				array(
					'run_id' => self::RUN_ID,
					'status' => 'superseded',
				),
			),
			$history['terminal'] ?? null
		);
	}

	/**
	 * A fresh running run survives a transferred lock until its own worker reaches a fence.
	 *
	 * @return  void
	 */
	public function test_sweep_leaves_a_fresh_running_run_untouched_when_lock_has_transferred(): void {
		$this->create_running_run();
		$replacement_run_id = '00000000001700000001-0000000000000000043';
		$this->put_lock( $this->lock_option_name(), $replacement_run_id, self::NOW );

		$this->run_maintenance();

		self::assertArrayHasKey( $this->run_option_name(), $this->options() );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $this->options() );
		self::assertSame( array(), $this->fired_actions() );
		self::assertSame( array(), $this->logger->records );
	}

	/**
	 * A fresh displaced run protects takeover evidence across unequal run-specific stale windows.
	 *
	 * @return  void
	 */
	public function test_sweep_preserves_transferred_lock_until_the_displaced_run_is_stale(): void {
		$this->create_running_run();
		$replacement_run_id = '00000000001700000001-0000000000000000043';
		$this->put_lock( $this->lock_option_name(), $replacement_run_id, self::NOW );
		$GLOBALS['a8csp_bgje_test_filter_values'] = array(
			'a8csp_jobs_engine/continue_delay' => static function (
				int $delay,
				string $name,
				string $run_id
			): int {
				return self::RUN_ID === $run_id ? 1_000 : $delay;
			},
		);

		$this->clock->timestamp = self::NOW + 901;

		$this->run_maintenance();

		self::assertArrayHasKey( $this->run_option_name(), $this->options() );
		self::assertArrayHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertSame( array(), $this->fired_actions() );

		$this->clock->timestamp = self::NOW + 2_001;
		$this->run_maintenance();

		self::assertArrayNotHasKey( $this->run_option_name(), $this->options() );
		self::assertArrayNotHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $this->options() );
		self::assertSame(
			array(
				'a8csp_jobs_engine/superseded/' . self::IDENTITY,
				'a8csp_jobs_engine/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
	}

	/**
	 * A live heartbeat written after the stale transfer gate makes the sweep lose its exact run fence.
	 *
	 * @return  void
	 */
	public function test_sweep_loses_when_a_transferred_run_refreshes_after_the_stale_gate(): void {
		$this->create_running_run();
		$this->clock->timestamp = self::NOW + 901;
		$replacement_run_id     = '00000000001700000901-0000000000000000043';
		$this->put_lock( $this->lock_option_name(), $replacement_run_id, $this->clock->timestamp );
		$this->wpdb->before_next(
			'update',
			function (): void {
				$options = $this->options();
				$state   = $options[ $this->run_option_name() ] ?? null;
				self::assertIsArray( $state );
				$state['heartbeat_at']               = $this->clock->timestamp;
				$options[ $this->run_option_name() ] = $state;
				$GLOBALS['a8csp_bgje_test_options']  = $options;
			}
		);

		$this->run_maintenance();

		$options = $this->options();
		$state   = $options[ $this->run_option_name() ] ?? null;
		self::assertIsArray( $state );
		self::assertSame( $this->clock->timestamp, $state['heartbeat_at'] ?? null );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $options );
		$history = $options[ 'a8csp_bgje_history_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $history );
		self::assertSame( array(), $history['terminal'] ?? null );
		self::assertSame( array(), $this->fired_actions() );
	}

	/**
	 * A crashed chunked job follows its registered `on_failed()` callback before common terminal cleanup.
	 *
	 * @return  void
	 */
	public function test_sweep_terminalizes_a_running_chunked_job_through_chunked_job_failure_machinery(): void {
		$name        = self::identity( 'crashed-chunked-job' );
		$chunked_job = new RecordingChunkedJob( 'crashed-chunked-job' );
		$this->work->register_chunked_job( $name, $chunked_job );
		$result = $this->dispatcher->start_chunked_job( $name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );

		$lock_name = 'a8csp_bgje_overlap_lock_' . $name . '_' . self::ARGS_HASH;
		$this->set_run_fields( $name, array( 'executing' => true ) );
		unset( $this->wpdb->rows[ $lock_name ] );
		$this->logger->records                    = array();
		$GLOBALS['a8csp_bgje_test_fired_actions'] = array();

		$this->run_maintenance();

		self::assertCount( 1, $chunked_job->failed_calls );
		self::assertSame( self::RUN_ID, $chunked_job->failed_calls[0]['run_id'] ?? null );
		self::assertSame( self::ARGS, $chunked_job->failed_calls[0]['start_args'] ?? null );
		$failure = $chunked_job->failed_calls[0]['error'];
		self::assertSame( $name, $failure->identity );
		self::assertSame( self::RUN_ID, $failure->run_id );
		self::assertSame( 1, $failure->attempts );
		self::assertSame( RunFailureStage::CrashReclaim, $failure->stage );
		self::assertSame( ApiErrorCode::ExecutionFailed, $failure->code );
		self::assertStringContainsString( 'maintenance crash reclaim path', $failure->summary );
		self::assertNull( $failure->failed_chunk );
		$actions = $this->fired_actions();
		$options = $this->options();
		self::assertArrayNotHasKey( RunStore::OPTION_PREFIX . $name . '_' . self::RUN_ID, $options );
		self::assertArrayHasKey( 'a8csp_bgje_failed_runs_' . $name, $options );
		self::assertSame(
			array(
				'a8csp_jobs_engine/failed/' . $name,
				'a8csp_jobs_engine/failed',
			),
			\array_column( $actions, 'hook_name' )
		);
		self::assertSame( $failure, $actions[0]['args'][2] ?? null );
		self::assertSame( $failure, $actions[1]['args'][3] ?? null );
	}

	/**
	 * A retained Chunked Job row keeps Chunked Job crash routing after its identity is reused by a Job.
	 *
	 * @return  void
	 */
	public function test_sweep_routes_a_running_row_by_its_persisted_chunked_job_kind(): void {
		$state = new RunState( status: RunStatus::Running, kind: JobType::ChunkedJob, executing: true, start_args: self::ARGS, args_hash: self::ARGS_HASH, queue: array( self::ARGS ), failed_attempts: 0, action_sequence: 1, created_at: self::NOW - 7_201, heartbeat_at: self::NOW - 3_601, pending: PendingAction::async( 'continue', 10 ) );

		[ $option_name, $raw ] = StoreFixtureBuilder::for_identity( self::IDENTITY )->run( self::RUN_ID, $state );

		$decoded = RawOptionDecoder::decode( $raw );
		self::assertIsArray( $decoded );
		$options                 = $this->options();
		$options[ $option_name ] = $decoded;

		$GLOBALS['a8csp_bgje_test_options'] = $options;

		$this->run_maintenance();

		$failed = $this->options()[ 'a8csp_bgje_failed_runs_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $failed );
		$failed_entry = $failed[0] ?? null;
		self::assertIsArray( $failed_entry );
		$error = $failed_entry['error'] ?? null;
		self::assertIsArray( $error );
		self::assertSame( self::ARGS, $error['failed_chunk'] ?? null );
		$failure = $this->fired_actions()[0]['args'][2] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( self::ARGS, $failure->failed_chunk );
		self::assertNotNull(
			$this->log_record(
				'warning',
				array(
					'chunked_job_name' => self::IDENTITY,
					'run_id'           => self::RUN_ID,
					'status'           => 'failed',
				)
			)
		);
	}

	/**
	 * A throwing chunked job `on_failed()` callback cannot starve a later client's crash reconciliation.
	 *
	 * @return  void
	 */
	public function test_sweep_continues_after_a_chunked_job_on_failed_callback_throws(): void {
		$throwing_name        = self::identity( 'broken-chunked-job' );
		$throwing_chunked_job = new RecordingChunkedJob( 'broken-chunked-job' );
		$this->work->register_chunked_job( $throwing_name, $throwing_chunked_job );
		$result = $this->dispatcher->start_chunked_job( $throwing_name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		$this->set_run_fields( $throwing_name, array( 'executing' => true ) );
		unset( $this->wpdb->rows[ 'a8csp_bgje_overlap_lock_' . $throwing_name . '_' . self::ARGS_HASH ] );

		$this->create_running_run();
		$this->set_run_fields( self::IDENTITY, array( 'executing' => true ) );
		unset( $this->wpdb->rows[ $this->lock_option_name() ] );
		$throwable                              = new \RuntimeException( 'Chunked Job on_failed callback exploded.' );
		$throwing_chunked_job->failed_throwable = $throwable;

		$this->run_maintenance();

		$options = $this->options();
		self::assertCount( 1, $throwing_chunked_job->failed_calls );
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		self::assertArrayHasKey( 'a8csp_bgje_failed_runs_' . self::IDENTITY, $options );
		$history = $options[ 'a8csp_bgje_history_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $history );
		self::assertSame(
			array(
				array(
					'run_id' => self::RUN_ID,
					'status' => 'failed',
				),
			),
			$history['terminal'] ?? null
		);
		$diagnostic = $this->exception_diagnostic( $throwable );
		self::assertSame( 'warning', $diagnostic['level'] ?? null );
		self::assertSame(
			array(
				'name'      => $throwing_name,
				'run_id'    => self::RUN_ID,
				'exception' => $throwable,
			),
			$diagnostic['context']
		);
	}

	/**
	 * A schema-invalid lock row is deleted without trusting its serialized fields.
	 *
	 * @return  void
	 */
	public function test_sweep_deletes_a_schema_invalid_lock_row(): void {
		$corrupt_name = self::identity( 'corrupt-job' );
		$lock_name    = OverlapGuard::OPTION_PREFIX . $corrupt_name . '_' . \str_repeat( 'b', 64 );
		$this->wpdb->put( $lock_name, 'not-serialized' );

		$this->run_maintenance();

		self::assertArrayNotHasKey( $lock_name, $this->wpdb->rows );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( $corrupt_name, $this->logger->records[0]['context']['name'] ?? null );
		self::assertNull( $this->logger->records[0]['context']['run_id'] ?? null );
	}

	/**
	 * A throwing lock cleanup leaves its exact row intact without starving later lock rows.
	 *
	 * @return  void
	 */
	public function test_sweep_continues_after_one_lock_cleanup_throws(): void {
		$throwing_name = self::identity( 'broken-lock' );
		$throwing_hash = \str_repeat( 'b', 64 );
		$throwing_key  = OverlapGuard::OPTION_PREFIX . $throwing_name . '_' . $throwing_hash;
		$throwing_raw  = 'broken-lock-row';
		$this->wpdb->put( $throwing_key, $throwing_raw );

		$healthy_name = self::identity( 'healthy-lock' );
		$healthy_hash = \str_repeat( 'c', 64 );
		$healthy_key  = OverlapGuard::OPTION_PREFIX . $healthy_name . '_' . $healthy_hash;
		$this->wpdb->put( $healthy_key, 'healthy-lock-row' );

		$throwable = new \RuntimeException( 'Lock cleanup exploded.' );
		$this->wpdb->before_next(
			'delete',
			static function () use ( $throwable ): void {
				throw $throwable;
			}
		);

		$this->run_maintenance();

		self::assertSame( $throwing_raw, $this->wpdb->rows[ $throwing_key ] ?? null );
		self::assertArrayNotHasKey( $healthy_key, $this->wpdb->rows );
		$diagnostic = $this->exception_diagnostic( $throwable );
		self::assertSame( 'warning', $diagnostic['level'] ?? null );
		self::assertSame(
			array(
				'name'      => $throwing_name,
				'args_hash' => $throwing_hash,
				'run_id'    => null,
				'exception' => $throwable,
			),
			$diagnostic['context']
		);
	}

	/**
	 * A corrupt run row cannot preserve a stale lock that names it as owner.
	 *
	 * @return  void
	 */
	public function test_sweep_reclaims_a_stale_lock_whose_run_row_is_corrupt(): void {
		$name                               = self::identity( 'corrupt-job' );
		$args_hash                          = \str_repeat( 'b', 64 );
		$run_name                           = RunStore::OPTION_PREFIX . $name . '_' . self::RUN_ID;
		$options                            = $this->options();
		$options[ $run_name ]               = 'corrupt-run';
		$GLOBALS['a8csp_bgje_test_options'] = $options;
		$lock_name                          = OverlapGuard::OPTION_PREFIX . $name . '_' . $args_hash;
		$this->put_lock( $lock_name, self::RUN_ID, self::NOW - 901 );

		$this->run_maintenance();

		self::assertArrayNotHasKey( $run_name, $this->options() );
		self::assertArrayNotHasKey( $lock_name, $this->wpdb->rows );
		self::assertSame( array( 'warning', 'warning' ), \array_column( $this->logger->records, 'level' ) );
	}

	/**
	 * A valid run owns only the lock whose hash matches its persisted fencing identity.
	 *
	 * @return  void
	 */
	public function test_sweep_reclaims_a_stale_lock_when_the_valid_run_has_a_different_fencing_hash(): void {
		$this->create_running_run();
		$mismatched_hash = \str_repeat( 'f', 64 );
		$mismatched_lock = OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $mismatched_hash;
		$this->put_lock( $mismatched_lock, self::RUN_ID, self::NOW - 901 );

		$this->run_maintenance();

		self::assertArrayNotHasKey( $mismatched_lock, $this->wpdb->rows );
		self::assertArrayHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertArrayHasKey( $this->run_option_name(), $this->options() );
	}

	/**
	 * A corrupt run row without any lock is exact-deleted by the run-prefix pass.
	 *
	 * @return  void
	 */
	public function test_sweep_exact_deletes_an_unpaired_corrupt_run_row(): void {
		$run_name                           = RunStore::OPTION_PREFIX . self::identity( 'corrupt-job' ) . '_' . self::RUN_ID;
		$options                            = $this->options();
		$options[ $run_name ]               = 'corrupt-run';
		$GLOBALS['a8csp_bgje_test_options'] = $options;

		$this->run_maintenance();

		self::assertArrayNotHasKey( $run_name, $this->options() );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
	}

	/**
	 * Job crash reconciliation saturates the failed-attempt count at PHP_INT_MAX.
	 *
	 * @return  void
	 */
	public function test_sweep_saturates_job_failure_attempts_at_php_int_max(): void {
		$this->create_running_run();
		$this->set_run_fields( self::IDENTITY, array( 'executing' => true ) );
		$this->set_run_failed_attempts( $this->run_option_name(), \PHP_INT_MAX );
		unset( $this->wpdb->rows[ $this->lock_option_name() ] );

		$this->run_maintenance();

		$failed = $this->options()[ 'a8csp_bgje_failed_runs_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $failed );
		$failure = $failed[0] ?? null;
		self::assertIsArray( $failure );
		self::assertSame( \PHP_INT_MAX, $failure['attempts'] ?? null );
	}

	/**
	 * Chunked Job crash reconciliation saturates the failed-attempt count at PHP_INT_MAX.
	 *
	 * @return  void
	 */
	public function test_sweep_saturates_chunked_job_failure_attempts_at_php_int_max(): void {
		$name = self::identity( 'crashed-chunked-job' );
		$this->work->register_chunked_job( $name, new RecordingChunkedJob( 'crashed-chunked-job' ) );
		$result = $this->dispatcher->start_chunked_job( $name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		$run_name = 'a8csp_bgje_run_' . $name . '_' . self::RUN_ID;
		$this->set_run_fields( $name, array( 'executing' => true ) );
		$this->set_run_failed_attempts( $run_name, \PHP_INT_MAX );
		unset( $this->wpdb->rows[ 'a8csp_bgje_overlap_lock_' . $name . '_' . self::ARGS_HASH ] );

		$this->run_maintenance();

		$failed = $this->options()[ 'a8csp_bgje_failed_runs_' . $name ] ?? null;
		self::assertIsArray( $failed );
		$failure = $failed[0] ?? null;
		self::assertIsArray( $failure );
		self::assertSame( \PHP_INT_MAX, $failure['attempts'] ?? null );
	}

	/**
	 * A sweep and live fence loss emit terminal hooks only from the raw-CAS winner.
	 *
	 * @return  void
	 */
	public function test_sweep_and_live_terminalization_emit_only_the_cas_winner_hooks(): void {
		$this->create_running_run();
		$this->clock->timestamp = self::NOW + 901;
		$replacement_run_id     = '00000000001700000901-0000000000000000043';
		$this->put_lock( $this->lock_option_name(), $replacement_run_id, $this->clock->timestamp );
		$this->wpdb->before_next(
			'update',
			function (): void {
				$this->run_maintenance();
			}
		);

		$this->lifecycle_deliveries->handle_run_job_action( self::IDENTITY, self::RUN_ID, 1 );

		self::assertSame(
			array(
				'a8csp_jobs_engine/superseded/' . self::IDENTITY,
				'a8csp_jobs_engine/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $this->options() );
		$history = $this->options()[ 'a8csp_bgje_history_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $history );
		self::assertSame(
			array(
				array(
					'run_id' => self::RUN_ID,
					'status' => 'superseded',
				),
			),
			$history['terminal'] ?? null
		);
		$lock = \maybe_unserialize( $this->wpdb->rows[ $this->lock_option_name() ] ?? '' );
		self::assertIsArray( $lock );
		self::assertSame( $replacement_run_id, $lock['run_id'] ?? null );
	}

	/**
	 * A failed chunked job left after its terminal claim replays every missing durable effect.
	 *
	 * @return  void
	 */
	public function test_sweep_replays_all_effects_for_an_old_failed_chunked_job(): void {
		$name        = self::identity( 'failed-chunked-job' );
		$chunked_job = new RecordingChunkedJob( 'failed-chunked-job' );
		$this->work->register_chunked_job( $name, $chunked_job );
		$this->store_terminal_run(
			$name,
			'failed',
			array(),
			array(
				'class'   => \RuntimeException::class,
				'message' => 'Persisted chunked job failure.',
				'stage'   => RunFailureStage::Execution->value,
				'code'    => ApiErrorCode::ExecutionFailed->value,
			),
			3,
			JobType::ChunkedJob
		);

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name( $name ), $options );
		$failed = $options[ 'a8csp_bgje_failed_runs_' . $name ] ?? null;
		self::assertIsArray( $failed );
		self::assertSame(
			array(
				array(
					'run_id'     => self::RUN_ID,
					'failed_at'  => self::NOW - 3_601,
					'start_args' => self::ARGS,
					'attempts'   => 3,
					'error'      => array(
						'class'   => \RuntimeException::class,
						'message' => 'Persisted chunked job failure.',
						'stage'   => RunFailureStage::Execution->value,
						'code'    => ApiErrorCode::ExecutionFailed->value,
					),
				),
			),
			$failed
		);
		self::assertCount( 1, $chunked_job->failed_calls );
		self::assertSame( self::RUN_ID, $chunked_job->failed_calls[0]['run_id'] ?? null );
		self::assertSame( self::ARGS, $chunked_job->failed_calls[0]['start_args'] ?? null );
		$failure = $chunked_job->failed_calls[0]['error'];
		self::assertSame( $name, $failure->identity );
		self::assertSame( self::RUN_ID, $failure->run_id );
		self::assertSame( 3, $failure->attempts );
		self::assertSame( RunFailureStage::Execution, $failure->stage );
		self::assertSame( ApiErrorCode::ExecutionFailed, $failure->code );
		self::assertSame( 'Persisted chunked job failure.', $failure->summary );
		self::assertNull( $failure->failed_chunk );
		$actions = $this->fired_actions();
		self::assertSame(
			array(
				'a8csp_jobs_engine/failed/' . $name,
				'a8csp_jobs_engine/failed',
			),
			\array_column( $actions, 'hook_name' )
		);
		self::assertSame( $failure, $actions[0]['args'][2] ?? null );
		self::assertSame( $failure, $actions[1]['args'][3] ?? null );
		$this->assert_history_status( $options, 'failed', $name );
	}

	/**
	 * Missing persisted failure detail is replayed as a storage failure.
	 *
	 * @return  void
	 */
	public function test_sweep_classifies_missing_persisted_failure_detail_as_storage_failure(): void {
		$name        = self::identity( 'failed-without-detail' );
		$chunk       = array( 'page' => 1 );
		$chunked_job = new RecordingChunkedJob( 'failed-without-detail' );
		$this->work->register_chunked_job( $name, $chunked_job );
		$this->store_terminal_run( $name, 'failed', array(), null, 2, JobType::ChunkedJob, array( $chunk ), PendingAction::async( 'continue', 10 ) );

		$this->run_maintenance();

		$options = $this->options();
		$failed  = $options[ 'a8csp_bgje_failed_runs_' . $name ] ?? null;
		self::assertIsArray( $failed );
		$expected_summary = \sprintf( 'Run "%1$s" for background-work "%2$s" failed before recoverable terminal detail was persisted.', self::RUN_ID, $name );
		self::assertSame(
			array(
				array(
					'run_id'     => self::RUN_ID,
					'failed_at'  => self::NOW - 3_601,
					'start_args' => self::ARGS,
					'attempts'   => 3,
					'error'      => array(
						'class'        => null,
						'message'      => $expected_summary,
						'stage'        => RunFailureStage::CrashReclaim->value,
						'code'         => ApiErrorCode::StorageFailure->value,
						'failed_chunk' => $chunk,
					),
				),
			),
			$failed
		);
		self::assertCount( 1, $chunked_job->failed_calls );
		$failure = $chunked_job->failed_calls[0]['error'];
		self::assertSame( $name, $failure->identity );
		self::assertSame( self::RUN_ID, $failure->run_id );
		self::assertSame( 3, $failure->attempts );
		self::assertSame( RunFailureStage::CrashReclaim, $failure->stage );
		self::assertSame( ApiErrorCode::StorageFailure, $failure->code );
		self::assertSame( $expected_summary, $failure->summary );
		self::assertSame( $chunk, $failure->failed_chunk );
		$actions = $this->fired_actions();
		self::assertSame( $failure, $actions[0]['args'][2] ?? null );
		self::assertSame( $failure, $actions[1]['args'][3] ?? null );
	}

	/**
	 * Durable effect markers prevent a replay from repeating already completed chunked job effects.
	 *
	 * @return  void
	 */
	public function test_sweep_replays_only_missing_failed_chunked_job_effects(): void {
		$name        = self::identity( 'partially-effected-chunked-job' );
		$chunked_job = new RecordingChunkedJob( 'partially-effected-chunked-job' );
		$this->work->register_chunked_job( $name, $chunked_job );
		self::assertTrue( $this->stores->failed_run_store( $name )->record( self::RUN_ID, self::NOW - 3_601, self::ARGS, 2, new EngineError( 'Persisted chunked job failure.', \RuntimeException::class ), new RunFailure( identity: $name, run_id: self::RUN_ID, attempts: 2, stage: RunFailureStage::Execution, code: ApiErrorCode::ExecutionFailed, summary: 'Persisted chunked job failure.', failed_chunk: null, ) ) );
		$failed_option = 'a8csp_bgje_failed_runs_' . $name;
		$failed_raw    = $this->wpdb->rows[ $failed_option ] ?? null;
		self::assertIsString( $failed_raw );
		$this->store_terminal_run(
			$name,
			'failed',
			array( 'retention', 'callbacks', 'hooks' ),
			array(
				'class'   => \RuntimeException::class,
				'message' => 'Persisted chunked job failure.',
				'stage'   => RunFailureStage::Execution->value,
				'code'    => ApiErrorCode::ExecutionFailed->value,
			),
			2,
			JobType::ChunkedJob
		);

		$this->run_maintenance();

		self::assertSame( $failed_raw, $this->wpdb->rows[ $failed_option ] ?? null );
		self::assertSame( array(), $chunked_job->failed_calls );
		self::assertSame( array(), $this->fired_actions() );
		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name( $name ), $options );
		$this->assert_history_status( $options, 'failed', $name );
	}

	/**
	 * One-off failed callbacks replay only when their durable marker is absent.
	 *
	 * @return  void
	 */
	public function test_sweep_replays_only_unmarked_failed_job_callbacks(): void {
		$error = array(
			'class'   => \RuntimeException::class,
			'message' => 'Persisted job failure.',
			'stage'   => RunFailureStage::Execution->value,
			'code'    => ApiErrorCode::ExecutionFailed->value,
		);
		$this->store_terminal_run( self::IDENTITY, 'failed', error: $error, failed_attempts: 2 );

		$marked_name = self::identity( 'marked-failed-job' );
		$marked_job  = new RecordingJob( 'marked-failed-job' );
		$this->work->register_job( $marked_name, $marked_job );
		$failure = new RunFailure( identity: $marked_name, run_id: self::RUN_ID, attempts: 2, stage: RunFailureStage::Execution, code: ApiErrorCode::ExecutionFailed, summary: 'Persisted job failure.', failed_chunk: null );
		self::assertTrue( $this->stores->failed_run_store( $marked_name )->record( self::RUN_ID, self::NOW - 3_601, self::ARGS, 2, new EngineError( 'Persisted job failure.', \RuntimeException::class ), $failure ) );
		$this->store_terminal_run( $marked_name, 'failed', array( 'retention', 'callbacks', 'hooks' ), $error, 2 );

		$this->run_maintenance();

		self::assertCount( 1, $this->job->failed_calls );
		self::assertSame( self::RUN_ID, $this->job->failed_calls[0]['run_id'] );
		self::assertSame( array(), $marked_job->failed_calls );
		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		self::assertArrayNotHasKey( $this->run_option_name( $marked_name ), $options );
		$this->assert_history_status( $options, 'failed' );
		$this->assert_history_status( $options, 'failed', $marked_name );
	}

	/**
	 * A throwing one-off failed callback stays unmarked and replays without repeating later effects.
	 *
	 * @return  void
	 */
	public function test_sweep_retries_a_throwing_failed_job_callback(): void {
		$error   = array(
			'class'   => \RuntimeException::class,
			'message' => 'Persisted job failure.',
			'stage'   => RunFailureStage::Execution->value,
			'code'    => ApiErrorCode::ExecutionFailed->value,
		);
		$failure = new RunFailure( identity: self::IDENTITY, run_id: self::RUN_ID, attempts: 2, stage: RunFailureStage::Execution, code: ApiErrorCode::ExecutionFailed, summary: 'Persisted job failure.', failed_chunk: null );
		self::assertTrue( $this->stores->failed_run_store( self::IDENTITY )->record( self::RUN_ID, self::NOW - 3_601, self::ARGS, 2, new EngineError( 'Persisted job failure.', \RuntimeException::class ), $failure ) );
		$this->store_terminal_run( self::IDENTITY, 'failed', array( 'retention' ), $error, 2 );
		$this->job->failed_throwable = new \RuntimeException( 'One-off on_failed callback exploded.' );

		$this->run_maintenance();

		$state = $this->options()[ $this->run_option_name() ] ?? null;
		self::assertIsArray( $state );
		self::assertSame( array( 'retention', 'hooks', 'history' ), $state['effects'] ?? null );
		self::assertCount( 1, $this->job->failed_calls );
		self::assertCount( 2, $this->fired_actions() );

		$this->job->failed_throwable              = null;
		$GLOBALS['a8csp_bgje_test_fired_actions'] = array();
		$this->run_maintenance();

		self::assertCount( 2, $this->job->failed_calls );
		self::assertSame( array(), $this->fired_actions() );
		self::assertArrayNotHasKey( $this->run_option_name(), $this->options() );
	}

	/**
	 * A throwing one-off completed callback is marked best-effort and never replayed.
	 *
	 * @return  void
	 */
	public function test_sweep_marks_a_throwing_completed_job_callback(): void {
		$this->store_terminal_run( self::IDENTITY, 'completed' );
		$this->job->completed_throwable = new \RuntimeException( 'One-off on_completed callback exploded.' );
		$this->wpdb->script_result( 'delete', false );

		$this->run_maintenance();

		$state = $this->options()[ $this->run_option_name() ] ?? null;
		self::assertIsArray( $state );
		self::assertSame( array( 'callbacks', 'hooks', 'history' ), $state['effects'] ?? null );
		self::assertCount( 1, $this->job->completed_calls );

		$this->job->completed_throwable           = null;
		$GLOBALS['a8csp_bgje_test_fired_actions'] = array();
		$this->run_maintenance();

		self::assertCount( 1, $this->job->completed_calls );
		self::assertSame( array(), $this->fired_actions() );
		self::assertArrayNotHasKey( $this->run_option_name(), $this->options() );
	}

	/**
	 * A losing failed callback worker does not emit downstream effects after a rival finishes the row.
	 *
	 * @return  void
	 */
	public function test_failed_callback_race_stops_after_a_rival_finishes_the_terminal_row(): void {
		$name        = self::identity( 'racing-failed-chunked-job' );
		$chunked_job = new RecordingChunkedJob( 'racing-failed-chunked-job' );
		$this->store_terminal_run(
			$name,
			'failed',
			array(),
			array(
				'class'   => \RuntimeException::class,
				'message' => 'Persisted chunked job failure.',
				'stage'   => RunFailureStage::Execution->value,
				'code'    => ApiErrorCode::ExecutionFailed->value,
			),
			2,
			JobType::ChunkedJob
		);
		$run_store              = $this->stores->run_store( $name );
		$rival_started          = false;
		$chunked_job->on_failed = function () use ( $chunked_job, $name, $run_store, &$rival_started ): void {
			if ( $rival_started ) {
				return;
			}

			$rival_started = true;
			$inspected     = $run_store->inspect( self::RUN_ID );
			self::assertFalse( $inspected->is_failure() );
			$snapshot = $inspected->value;
			self::assertIsArray( $snapshot );
			$state = $snapshot['state'];
			self::assertNotNull( $state );
			self::assertTrue( $this->terminal_effects->replay_terminal_run( $name, self::RUN_ID, $state, $snapshot['raw'], $run_store, JobType::ChunkedJob, $chunked_job ) );

			throw new \RuntimeException( 'Original callback worker resumed after rival cleanup.' );
		};

		$inspected = $run_store->inspect( self::RUN_ID );
		self::assertFalse( $inspected->is_failure() );
		$snapshot = $inspected->value;
		self::assertIsArray( $snapshot );
		$state = $snapshot['state'];
		self::assertNotNull( $state );
		$caught = null;

		try {
			$this->terminal_effects->replay_terminal_run( $name, self::RUN_ID, $state, $snapshot['raw'], $run_store, JobType::ChunkedJob, $chunked_job );
		} catch ( \RuntimeException $throwable ) {
			$caught = $throwable;
		}

		self::assertInstanceOf( \RuntimeException::class, $caught );
		self::assertSame( 'Original callback worker resumed after rival cleanup.', $caught->getMessage() );
		self::assertCount( 2, $chunked_job->failed_calls );
		self::assertSame(
			array(
				'a8csp_jobs_engine/failed/' . $name,
				'a8csp_jobs_engine/failed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name( $name ), $options );
		$failed = $options[ 'a8csp_bgje_failed_runs_' . $name ] ?? null;
		self::assertIsArray( $failed );
		self::assertCount( 1, $failed );
		$this->assert_history_status( $options, 'failed', $name );
	}

	/**
	 * A completed chunked job left after its terminal claim replays its callback, hooks, and history.
	 *
	 * @return  void
	 */
	public function test_sweep_replays_all_effects_for_an_old_completed_chunked_job(): void {
		$name        = self::identity( 'completed-chunked-job' );
		$chunked_job = new RecordingChunkedJob( 'completed-chunked-job' );
		$this->work->register_chunked_job( $name, $chunked_job );
		$this->store_terminal_run( $name, 'completed', kind: JobType::ChunkedJob );

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name( $name ), $options );
		self::assertSame(
			array(
				array(
					'run_id'                    => self::RUN_ID,
					'start_args'                => self::ARGS,
					'previous_completed_run_id' => null,
				),
			),
			$chunked_job->completed_calls
		);
		self::assertSame(
			array(
				'a8csp_jobs_engine/completed/' . $name,
				'a8csp_jobs_engine/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_history_status( $options, 'completed', $name );
	}

	/**
	 * A completed job left after its terminal claim replays its callback, hooks, and history.
	 *
	 * @return  void
	 */
	public function test_sweep_replays_all_effects_for_an_old_completed_job(): void {
		$this->store_terminal_run( self::IDENTITY, 'completed', previous_completed_run_id: 'previous-completed-run' );

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		self::assertSame(
			array(
				array(
					'run_id'                    => self::RUN_ID,
					'start_args'                => self::ARGS,
					'previous_completed_run_id' => 'previous-completed-run',
				),
			),
			$this->job->completed_calls
		);
		$this->assert_history_status( $options, 'completed' );
		self::assertSame(
			array(
				'a8csp_jobs_engine/completed/' . self::IDENTITY,
				'a8csp_jobs_engine/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
	}

	/**
	 * A retained Chunked Job row keeps Chunked Job terminal routing after its identity is reused by a Job.
	 *
	 * @return  void
	 */
	public function test_sweep_routes_a_terminal_row_by_its_persisted_chunked_job_kind(): void {
		$this->store_terminal_run( self::IDENTITY, 'completed', kind: JobType::ChunkedJob );

		$this->run_maintenance();

		self::assertArrayNotHasKey( $this->run_option_name(), $this->options() );
		self::assertNotNull(
			$this->log_record(
				'warning',
				array(
					'chunked_job_name' => self::IDENTITY,
					'run_id'           => self::RUN_ID,
					'status'           => 'completed',
				)
			)
		);
	}

	/**
	 * A deactivated client cannot leave its terminal row permanently unfinished.
	 *
	 * @return  void
	 */
	public function test_sweep_skips_an_unregistered_chunked_job_callback_and_finishes_the_row(): void {
		$name = self::identity( 'deactivated-client' );
		$this->store_terminal_run( $name, 'completed', kind: JobType::ChunkedJob );

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name( $name ), $options );
		$this->assert_history_status( $options, 'completed', $name );
		self::assertSame(
			array(
				'a8csp_jobs_engine/completed/' . $name,
				'a8csp_jobs_engine/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertNotNull(
			$this->log_record(
				'warning',
				array(
					'chunked_job_name' => $name,
					'run_id'           => self::RUN_ID,
					'status'           => 'completed',
				)
			)
		);
	}

	/**
	 * A deactivated one-off client cannot leave its callback effect permanently unfinished.
	 *
	 * @return  void
	 */
	public function test_sweep_skips_an_unregistered_job_callback_and_finishes_the_row(): void {
		$name = self::identity( 'deactivated-job-client' );
		$this->store_terminal_run( $name, 'completed' );

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name( $name ), $options );
		$this->assert_history_status( $options, 'completed', $name );
		self::assertSame(
			array(
				'a8csp_jobs_engine/completed/' . $name,
				'a8csp_jobs_engine/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertNotNull(
			$this->log_record(
				'warning',
				array(
					'job_name' => $name,
					'run_id'   => self::RUN_ID,
					'status'   => 'completed',
				)
			)
		);
	}

	/**
	 * Terminal cleanup leaves rows whose required effects have not been marked.
	 *
	 * @return  void
	 */
	public function test_terminal_finish_is_gated_until_the_sweep_completes_missing_effects(): void {
		$this->store_terminal_run( self::IDENTITY, 'completed' );
		$run_store = $this->stores->run_store( self::IDENTITY );
		$inspected = $run_store->inspect( self::RUN_ID );
		self::assertFalse( $inspected->is_failure() );
		$snapshot = $inspected->value;
		self::assertIsArray( $snapshot );
		$state = $snapshot['state'];
		self::assertNotNull( $state );

		self::assertFalse( $this->terminal_effects->finish_claimed_transition( self::IDENTITY, self::RUN_ID, $state, $snapshot['raw'], $run_store, JobType::Job ) );
		self::assertArrayHasKey( $this->run_option_name(), $this->options() );

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		$this->assert_history_status( $options, 'completed' );
	}

	/**
	 * A failed terminal delete retries cleanup without repeating marked effects or history.
	 *
	 * @return  void
	 */
	public function test_sweep_retries_a_failed_terminal_delete_without_repeating_effects(): void {
		$this->store_terminal_run( self::IDENTITY, 'completed' );
		$options = $this->options();
		// A retained pre-bucket row exercises normalization; current history writes also populate by_hash.
		$options[ 'a8csp_bgje_history_' . self::IDENTITY ] = array(
			'started'  => array( 'existing-run' ),
			'terminal' => array(
				array(
					'run_id' => 'existing-run',
					'status' => 'completed',
				),
			),
			'by_hash'  => array(),
		);
		$GLOBALS['a8csp_bgje_test_options']                = $options;
		$this->wpdb->script_result( 'delete', false );

		$this->run_maintenance();

		$options = $this->options();
		$state   = $options[ $this->run_option_name() ] ?? null;
		self::assertIsArray( $state );
		self::assertSame( array( 'callbacks', 'hooks', 'history' ), $state['effects'] ?? null );
		self::assertCount( 1, $this->job->completed_calls );
		$history = $options[ 'a8csp_bgje_history_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $history );
		self::assertSame(
			array(
				array(
					'run_id' => 'existing-run',
					'status' => 'completed',
				),
				array(
					'run_id' => self::RUN_ID,
					'status' => 'completed',
				),
			),
			$history['terminal'] ?? null
		);
		self::assertCount( 2, $this->fired_actions() );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'error', $this->logger->records[0]['level'] ?? null );

		$this->logger->records                    = array();
		$GLOBALS['a8csp_bgje_test_fired_actions'] = array();
		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		$history = $options[ 'a8csp_bgje_history_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $history );
		$terminal = $history['terminal'] ?? null;
		self::assertIsArray( $terminal );
		self::assertCount( 2, $terminal );
		self::assertCount( 1, $this->job->completed_calls );
		self::assertSame( array(), $this->fired_actions() );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Runs one maintenance sweep with a real internal invocation context.
	 *
	 * @return  void
	 */
	private function run_maintenance(): void {
		$this->maintenance->handle( array(), new RunContext( 'maintenance-test-run', array() ) );
	}

	/**
	 * Returns one owner-qualified test work identity.
	 *
	 * @param   string $name Owner-local work name.
	 *
	 * @return  string
	 */
	private static function identity( string $name ): string {
		return self::OWNER . ':' . $name;
	}

	/**
	 * Creates one ordinary running job through the dispatcher.
	 *
	 * @return  void
	 */
	private function create_running_run(): void {
		$result = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );

		$this->backend->calls                     = array();
		$this->logger->records                    = array();
		$GLOBALS['a8csp_bgje_test_fired_actions'] = array();
	}

	/**
	 * Asserts the latest retained history status through the untyped option boundary.
	 *
	 * @param   array<array-key, mixed> $options Persisted options.
	 * @param   string                  $status  Expected terminal status.
	 * @param   string                  $name    Stable job or chunked job name.
	 *
	 * @return  void
	 */
	private function assert_history_status( array $options, string $status, string $name = self::IDENTITY ): void {
		$history = $options[ 'a8csp_bgje_history_' . $name ] ?? null;
		self::assertIsArray( $history );
		$terminal = $history['terminal'] ?? null;
		self::assertIsArray( $terminal );
		$entry = $terminal[0] ?? null;
		self::assertIsArray( $entry );
		self::assertSame( $status, $entry['status'] ?? null );
	}

	/**
	 * Stores one production-serialized running state.
	 *
	 * @param   string   $name  Complete work identity.
	 * @param   RunState $state Running state fixture.
	 *
	 * @return  void
	 */
	private function store_running_state( string $name, RunState $state ): void {
		[ $option_name, $raw ] = StoreFixtureBuilder::for_identity( $name )->run( self::RUN_ID, $state );
		$decoded               = RawOptionDecoder::decode( $raw );
		self::assertIsArray( $decoded );

		$options                 = $this->options();
		$options[ $option_name ] = $decoded;

		$GLOBALS['a8csp_bgje_test_options'] = $options;
	}

	/**
	 * Stores one old terminal state whose omitted optional fields exercise decoder defaults.
	 *
	 * @phpstan-param list<string> $effects
	 * @phpstan-param array{class: string|null, message: string, stage: string, code: string, failed_chunk?: array<array-key, mixed>}|null $error
	 * @phpstan-param JobType $kind
	 * @phpstan-param list<array<array-key, mixed>> $queue
	 *
	 * @param   string             $name                      Stable job or chunked job name.
	 * @param   string             $status                    Terminal status value.
	 * @param   array              $effects                   Completed terminal effect keys.
	 * @param   array|null         $error                     Persisted terminal failure detail.
	 * @param   int                $failed_attempts           Attempts consumed by a failed run.
	 * @param   JobType            $kind                      Persisted work contract type.
	 * @param   array              $queue                     Persisted chunk queue.
	 * @param   PendingAction|null $pending                   Durable successor descriptor.
	 * @param   string|null        $previous_completed_run_id Frozen previous completed run identifier.
	 *
	 * @return  void
	 */
	private function store_terminal_run( string $name, string $status, array $effects = array(), ?array $error = null, int $failed_attempts = 0, JobType $kind = JobType::Job, array $queue = array(), ?PendingAction $pending = null, ?string $previous_completed_run_id = null ): void {
		$state = new RunState( status: RunStatus::from( $status ), kind: $kind, executing: true, start_args: self::ARGS, args_hash: self::ARGS_HASH, queue: $queue, failed_attempts: $failed_attempts, action_sequence: 1, created_at: self::NOW - 7_201, heartbeat_at: self::NOW - 3_601, pending: $pending, error: $error, previous_completed_run_id: $previous_completed_run_id, effects: $effects );

		[ $option_name, $raw ] = StoreFixtureBuilder::for_identity( $name )->run( self::RUN_ID, $state );

		$decoded = RawOptionDecoder::decode( $raw );
		self::assertIsArray( $decoded );

		$options = $this->options();

		$options[ $option_name ] = $decoded;

		$GLOBALS['a8csp_bgje_test_options'] = $options;
	}

	/**
	 * Asserts the complete crash reclaim terminal effect.
	 *
	 * @param   bool $lock_survives Whether a replacement lock remains after terminalization.
	 *
	 * @return  void
	 */
	private function assert_crashed_run_terminalized( bool $lock_survives = false ): void {
		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		if ( $lock_survives ) {
			self::assertArrayHasKey( $this->lock_option_name(), $this->wpdb->rows );
		} else {
			self::assertArrayNotHasKey( $this->lock_option_name(), $this->wpdb->rows );
		}
		$failed = $options[ 'a8csp_bgje_failed_runs_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $failed );
		self::assertCount( 1, $failed );
		$failed_entry = $failed[0] ?? null;
		self::assertIsArray( $failed_entry );
		$error = $failed_entry['error'] ?? null;
		self::assertIsArray( $error );
		$message = $error['message'] ?? null;
		self::assertIsString( $message );
		self::assertStringContainsString( 'maintenance crash reclaim path', $message );
		self::assertNull( $error['class'] ?? null );
		self::assertSame( RunFailureStage::CrashReclaim->value, $error['stage'] ?? null );
		self::assertSame( ApiErrorCode::ExecutionFailed->value, $error['code'] ?? null );
		self::assertArrayNotHasKey( 'failed_chunk', $error );
		$actions = $this->fired_actions();
		self::assertSame(
			array(
				'a8csp_jobs_engine/failed/' . self::IDENTITY,
				'a8csp_jobs_engine/failed',
			),
			\array_column( $actions, 'hook_name' )
		);
		self::assertSame( self::RUN_ID, $actions[0]['args'][0] ?? null );
		self::assertSame( self::ARGS, $actions[0]['args'][1] ?? null );
		$failure = $actions[0]['args'][2] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( self::IDENTITY, $failure->identity );
		self::assertSame( self::RUN_ID, $failure->run_id );
		self::assertSame( 1, $failure->attempts );
		self::assertSame( RunFailureStage::CrashReclaim, $failure->stage );
		self::assertSame( ApiErrorCode::ExecutionFailed, $failure->code );
		self::assertSame( $message, $failure->summary );
		self::assertNull( $failure->failed_chunk );
		self::assertSame( array( self::IDENTITY, ...( $actions[0]['args'] ?? array() ) ), $actions[1]['args'] ?? null );
		$history = $options[ 'a8csp_bgje_history_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $history );
		self::assertSame(
			array(
				array(
					'run_id' => self::RUN_ID,
					'status' => 'failed',
				),
			),
			$history['terminal'] ?? null
		);
	}

	/**
	 * Stores one raw valid lock row.
	 *
	 * @param   string $option_name Lock option name.
	 * @param   string $run_id     Owning run identifier.
	 * @param   int    $heartbeat  Latest heartbeat timestamp.
	 *
	 * @return  void
	 */
	private function put_lock( string $option_name, string $run_id, int $heartbeat ): void {
		$raw = \maybe_serialize(
			array(
				'run_id'       => $run_id,
				'claimed_at'   => $heartbeat,
				'heartbeat_at' => $heartbeat,
			)
		);
		self::assertIsString( $raw );
		$this->wpdb->put( $option_name, $raw );
	}

	/**
	 * Sets one persisted run's failed-attempt count without changing any other field.
	 *
	 * @param   string $option_name     Run option name.
	 * @param   int    $failed_attempts Failed-attempt count to persist.
	 *
	 * @return  void
	 */
	private function set_run_failed_attempts( string $option_name, int $failed_attempts ): void {
		$options = $this->options();
		$state   = $options[ $option_name ] ?? null;
		self::assertIsArray( $state );
		$state['failed_attempts']           = $failed_attempts;
		$options[ $option_name ]            = $state;
		$GLOBALS['a8csp_bgje_test_options'] = $options;
	}

	/**
	 * Replaces selected fields in one retained running state.
	 *
	 * @param   string                  $name   Stable job or chunked job name.
	 * @param   array<array-key, mixed> $fields Replacement fields.
	 *
	 * @return  void
	 */
	private function set_run_fields( string $name, array $fields ): void {
		$this->replace_run_state( $name, \array_replace( $this->run_state( $name ), $fields ) );
	}

	/**
	 * Stores one decoded run-state fixture.
	 *
	 * @param   string                  $name  Stable job or chunked job name.
	 * @param   array<array-key, mixed> $state Persisted run state.
	 *
	 * @return  void
	 */
	private function replace_run_state( string $name, array $state ): void {
		$options                                    = $this->options();
		$options[ $this->run_option_name( $name ) ] = $state;
		$GLOBALS['a8csp_bgje_test_options']         = $options;
	}

	/**
	 * Returns one retained decoded run state.
	 *
	 * @param   string $name Stable job or chunked job name.
	 *
	 * @return  array<array-key, mixed>
	 */
	private function run_state( string $name ): array {
		$state = $this->options()[ $this->run_option_name( $name ) ] ?? null;
		self::assertIsArray( $state );

		return $state;
	}

	/**
	 * Returns the deterministic run option name.
	 *
	 * @param   string $name Stable job or chunked job name.
	 *
	 * @return  string
	 */
	private function run_option_name( string $name = self::IDENTITY ): string {
		return RunStore::OPTION_PREFIX . $name . '_' . self::RUN_ID;
	}

	/**
	 * Returns the deterministic lock option name.
	 *
	 * @return  string
	 */
	private function lock_option_name(): string {
		return OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . self::ARGS_HASH;
	}

	/**
	 * Returns one caught-exception diagnostic by object identity.
	 *
	 * @param   \Throwable $throwable Expected exception or error.
	 *
	 * @return  array{level: mixed, message: string, context: array<array-key, mixed>}
	 */
	private function exception_diagnostic( \Throwable $throwable ): array {
		foreach ( $this->logger->records as $record ) {
			if ( ( $record['context']['exception'] ?? null ) === $throwable ) {
				return $record;
			}
		}

		throw new \LogicException( 'Expected a maintenance caught-exception diagnostic.' );
	}

	/**
	 * Returns the first log record matching a level and context identity.
	 *
	 * @param   string                  $level            Expected log level.
	 * @param   array<array-key, mixed> $context_identity Context entries that identify the record.
	 *
	 * @return  array{level: string, message: string, context: array<array-key, mixed>}|null
	 */
	private function log_record( string $level, array $context_identity ): ?array {
		foreach ( $this->logger->records as $record ) {
			if ( $level !== $record['level'] ) {
				continue;
			}
			foreach ( $context_identity as $key => $value ) {
				if ( ! \array_key_exists( $key, $record['context'] ) || $value !== $record['context'][ $key ] ) {
					continue 2;
				}
			}

			return $record;
		}

		return null;
	}

	/**
	 * Returns fired lifecycle actions.
	 *
	 * @return  list<array{hook_name: string, args: list<mixed>}>
	 */
	private function fired_actions(): array {
		$actions = $GLOBALS['a8csp_bgje_test_fired_actions'] ?? null;
		self::assertIsArray( $actions );
		$typed = array();
		foreach ( $actions as $action ) {
			self::assertIsArray( $action );
			$hook_name = $action['hook_name'] ?? null;
			$args      = $action['args'] ?? null;
			self::assertIsString( $hook_name );
			self::assertIsArray( $args );
			$typed[] = array(
				'hook_name' => $hook_name,
				'args'      => \array_values( $args ),
			);
		}

		return $typed;
	}

	/**
	 * Returns the in-memory option store through its typed boundary.
	 *
	 * @return  array<array-key, mixed>
	 */
	private function options(): array {
		$options = $GLOBALS['a8csp_bgje_test_options'] ?? null;
		self::assertIsArray( $options );
		foreach ( $this->wpdb->rows as $name => $raw ) {
			self::assertIsString( $name );
			self::assertIsString( $raw );
			if (
				\str_starts_with( $name, 'a8csp_bgje_failed_runs_' )
				|| \str_starts_with( $name, 'a8csp_bgje_history_' )
			) {
				$options[ $name ] = RawOptionDecoder::decode( $raw );
			}
		}

		return $options;
	}

	// endregion.
}

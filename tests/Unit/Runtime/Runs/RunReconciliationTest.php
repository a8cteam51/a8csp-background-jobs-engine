<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\DeliveryScheduler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\MaintenanceLockSweep;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunReconciliation;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\ChunkedJobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\JobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\KindHandlerInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Maintenance\MaintenanceJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\CleanupIntents;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
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

	private const array ARGS             = array( 'site_id' => 7 );
	private const string ARGS_HASH       = 'd3e2a7f3f4041a96ec4e9d3de1622dea7c050a65d9ee0b77a49a76848fdd9737';
	private const string IDENTITY        = self::OWNER . ':' . self::NAME;
	private const string NAME            = 'crashed-job';
	private const int NOW                = 1_700_000_000;
	private const string OWNER           = 'runs-tests';
	private const string PREVIOUS_RUN_ID = '00000000001699999999-0000000000000000041';
	private const string RUN_ID          = '00000000001700000000-0000000000000000042';

	private FixedClock $clock;
	private JobRegistry $registry;
	private RecordingJob $job;
	private RecordingBackend $backend;
	private Dispatcher $dispatcher;

	/**
	 * @var array<string, KindHandlerInterface>
	 */
	private array $handlers;

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
		$GLOBALS['a8csp_bgje_test_action_callbacks']      = array();
		$GLOBALS['a8csp_bgje_test_action_throwables']     = array();
		$GLOBALS['a8csp_bgje_test_lifecycle_events']      = array();
		$GLOBALS['a8csp_bgje_test_blog_id']               = 1;
		$GLOBALS['a8csp_bgje_test_cache']                 = array();
		$GLOBALS['a8csp_bgje_test_cache_calls']           = array();
		unset( $GLOBALS['a8csp_bgje_test_before_add_option'] );

		$this->clock    = new FixedClock( self::NOW );
		$this->registry = new JobRegistry();
		$this->logger   = new RecordingLogger();
		$this->wpdb     = new WpdbLockSpy();
		$this->job      = new RecordingJob( self::NAME );
		$this->registry->register( self::IDENTITY, $this->job->definition() );
		$this->backend              = new RecordingBackend();
		$option_rows                = new OptionRows( $this->wpdb );
		$guard                      = new OverlapGuard( $this->clock, $this->logger, new OptionRows( $this->wpdb ) );
		$overlap_identity           = new OverlapIdentity();
		$this->stores               = new StoreFactory( $this->clock, $option_rows, $this->logger );
		$randomizer                 = new RecordingRandomizer( 42 );
		$lock_windows               = new LockWindows( $this->clock, $this->logger );
		$this->terminal_effects     = new LifecycleEffects( $guard, $this->stores, $this->logger );
		$this->terminal_transitions = new RunTransitions( $guard, $this->stores, $this->clock, $lock_windows, $this->logger, $this->terminal_effects );
		$delivery_scheduler         = new DeliveryScheduler( $this->backend, $this->clock );
		$failure_lifecycle          = new FailureLifecycle( $delivery_scheduler, $this->clock, $randomizer, $this->logger, $this->terminal_transitions, $this->terminal_effects );
		$job_handler                = new JobKindHandler( $this->registry, $this->logger, $this->clock, $lock_windows, $this->terminal_transitions, $this->terminal_effects, $failure_lifecycle );
		$chunked_job_handler        = new ChunkedJobKindHandler( $this->registry, $delivery_scheduler, $this->logger, $this->clock, $lock_windows, $this->terminal_transitions, $this->terminal_effects, $failure_lifecycle );
		$this->handlers             = array(
			$job_handler->key()         => $job_handler,
			$chunked_job_handler->key() => $chunked_job_handler,
		);
		$this->lifecycle_deliveries = new ActionDeliveries( $this->handlers, $this->stores, $this->terminal_transitions );
		$this->dispatcher           = new Dispatcher( $this->registry, $this->handlers, $this->backend, $delivery_scheduler, $guard, $overlap_identity, $this->stores, $this->clock, $randomizer, $this->logger, $lock_windows, $this->terminal_transitions );
		$reconciliation             = new RunReconciliation( $guard, $this->stores, $this->clock, $this->logger, $lock_windows, $this->terminal_transitions, $this->terminal_effects, $this->handlers, $delivery_scheduler );
		$cleanup_intents            = new CleanupIntents( new ScheduleRegistry( $option_rows, $this->logger ), new SchedulerFacade( array( $this->backend ) ), $option_rows, $this->clock, $this->logger );
		$this->maintenance          = new MaintenanceJob( $option_rows, $reconciliation, $guard, $cleanup_intents, $this->logger );
	}

	// endregion.

	// region TESTS.

	/**
	 * The maintenance execution co-locates its stable owner-local job name.
	 *
	 * @return  void
	 */
	public function test_job_name_is_owner_local(): void {
		self::assertSame( 'maintenance', MaintenanceJob::NAME );
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
	 * A grammar-valid unknown run kind survives maintenance byte-untouched.
	 *
	 * @return  void
	 */
	public function test_sweep_leaves_a_grammar_valid_unknown_run_kind_untouched(): void {
		$state = new RunState( status: RunStatus::Running, kind: 'acme.export', executing: false, start_args: self::ARGS, args_hash: self::ARGS_HASH, kind_state: array( self::ARGS ), failed_attempts: 0, action_sequence: 1, created_at: self::NOW, heartbeat_at: self::NOW, pending: PendingAction::async( 'run', 10 ) );

		[ $option_name, $raw ] = StoreFixtureBuilder::for_identity( self::IDENTITY )->run( self::RUN_ID, $state );
		$this->wpdb->put( $option_name, $raw );
		$before = $this->wpdb->rows[ $option_name ] ?? null;
		self::assertIsString( $before );

		$this->run_maintenance();

		self::assertSame( $before, $this->wpdb->rows[ $option_name ] ?? null );
		self::assertNotNull(
			$this->log_record(
				'warning',
				array(
					'identity' => self::IDENTITY,
					'run_id'   => self::RUN_ID,
					'kind'     => 'acme.export',
				)
			)
		);
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
		self::assertSame( $orphan_name, $this->logger->records[0]['context']['identity'] ?? null );
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
		$this->registry->register( $name, $chunked_job->definition() );
		$result = $this->dispatcher->dispatch( $name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		$this->lifecycle_deliveries->handle_deliver_action( $name, self::RUN_ID, 1 );
		$this->backend->calls   = array();
		$this->clock->timestamp = self::NOW + 901;

		$this->run_maintenance();

		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'a8csp_bgje/internal/deliver',
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

		$this->lifecycle_deliveries->handle_deliver_action( $name, self::RUN_ID, 2 );

		self::assertCount( 1, $chunked_job->process_calls );
		self::assertSame( $chunk, $chunked_job->process_calls[0]['chunk_args'] ?? null );
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
		$name        = self::identity( 'redelivered-start-chunked-job' );
		$chunked_job = new RecordingChunkedJob( 'redelivered-start-chunked-job' );
		$this->registry->register( $name, $chunked_job->definition( new JobOptions( overlap: OverlapPolicy::from( $overlap_value ) ) ) );
		$result = $this->dispatcher->dispatch( $name, self::ARGS, priority: 23 );
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
						'hook'     => 'a8csp_bgje/internal/deliver',
						'args'     => array( $name, self::RUN_ID, 1 ),
						'group'    => $name . '|' . self::RUN_ID,
						'priority' => 23,
					),
				),
			),
			$this->backend->calls
		);
		self::assertSame( 'running', $this->run_state( $name )['status'] ?? null );
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
		$result = $this->dispatcher->dispatch_scheduled_target( self::IDENTITY, self::ARGS, priority: 23 );
		self::assertInstanceOf( Success::class, $result );
		$this->backend->calls   = array();
		$this->clock->timestamp = self::NOW + 901;

		$this->run_maintenance();

		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'a8csp_bgje/internal/deliver',
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
	 * An unowned persisted stage cannot manufacture a redelivery fence or enter a rescheduling loop.
	 *
	 * @return  void
	 */
	public function test_sweep_drops_an_unowned_pending_stage_without_a_redelivery_loop(): void {
		$this->create_running_run();
		$this->set_run_fields(
			self::IDENTITY,
			array(
				'heartbeat_at' => self::NOW - 901,
				'pending'      => array(
					'stage'    => 'continue',
					'mode'     => 'async',
					'fire_at'  => null,
					'priority' => 10,
				),
			)
		);
		unset( $this->wpdb->rows[ $this->lock_option_name() ] );
		$state_before = $this->run_state( self::IDENTITY );

		$this->run_maintenance();
		$this->run_maintenance();

		self::assertSame( array(), $this->backend->calls );
		self::assertSame( $state_before, $this->run_state( self::IDENTITY ) );
		self::assertArrayNotHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Persisted lifecycle stage is not owned by the resolved kind handler; maintenance left the run untouched.',
					'context' => array(
						'identity' => self::IDENTITY,
						'run_id'   => self::RUN_ID,
						'kind'     => 'job',
						'stage'    => 'continue',
					),
				),
				array(
					'level'   => 'warning',
					'message' => 'Persisted lifecycle stage is not owned by the resolved kind handler; maintenance left the run untouched.',
					'context' => array(
						'identity' => self::IDENTITY,
						'run_id'   => self::RUN_ID,
						'kind'     => 'job',
						'stage'    => 'continue',
					),
				),
			),
			$this->logger->records
		);
	}

	/**
	 * An owned persisted stage remains eligible for ordinary pending-action redelivery.
	 *
	 * @return  void
	 */
	public function test_sweep_redelivers_an_owned_pending_stage(): void {
		$this->create_running_run();
		unset( $this->wpdb->rows[ $this->lock_option_name() ] );
		$this->clock->timestamp = self::NOW + 901;

		$this->run_maintenance();

		self::assertCount( 1, $this->backend->calls );
		self::assertSame( 'enqueue_async', $this->backend->calls[0]['verb'] ?? null );
		self::assertSame( array( self::IDENTITY, self::RUN_ID, 1 ), $this->backend->calls[0]['args']['args'] ?? null );
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

		$this->lifecycle_deliveries->handle_deliver_action( self::IDENTITY, self::RUN_ID, 1 );

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
				$this->lifecycle_deliveries->handle_deliver_action( self::IDENTITY, self::RUN_ID, 1 );
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
				'a8csp_bgje/completed/' . self::IDENTITY,
				'a8csp_bgje/completed',
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
		$result = $this->dispatcher->dispatch( self::IDENTITY, self::ARGS, delay: 1_200 );
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
						'hook'      => 'a8csp_bgje/internal/deliver',
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

		$this->lifecycle_deliveries->handle_deliver_action( self::IDENTITY, self::RUN_ID, 1 );

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
						'hook'      => 'a8csp_bgje/internal/deliver',
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
		$this->registry->register( $name, $chunked_job->definition() );
		$result = $this->dispatcher->dispatch( $name, self::ARGS );
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
		self::assertSame( array(), $this->fired_actions() );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( $name, $this->logger->records[0]['context']['identity'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
		self::assertSame( SchedulingError::class, $this->logger->records[0]['context']['error_class'] ?? null );
		self::assertSame( SchedulingErrorReason::ScheduleFailed->value, $this->logger->records[0]['context']['error_reason'] ?? null );

		unset( $this->backend->results['enqueue_async'] );

		$this->run_maintenance();

		self::assertCount( 2, $this->backend->calls );
		self::assertSame( $rejected_call, $this->backend->calls[1] );
		self::assertCount( 1, $this->logger->records );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . $name, $this->options() );
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
				'a8csp_bgje/superseded/' . self::IDENTITY,
				'a8csp_bgje/superseded',
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
					'identity' => self::IDENTITY,
					'run_id'   => self::RUN_ID,
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
		$this->registry->register( $name, $chunked_job->definition() );
		$result = $this->dispatcher->dispatch( $name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		$this->lifecycle_deliveries->handle_deliver_action( $name, self::RUN_ID, 1 );
		$this->backend->calls   = array();
		$this->clock->timestamp = self::NOW + 901;

		$this->run_maintenance();
		$this->run_maintenance();

		self::assertCount( 2, $this->backend->calls );
		self::assertSame( $this->backend->calls[0], $this->backend->calls[1] );
		self::assertSame( 'enqueue_async', $this->backend->calls[0]['verb'] ?? null );
		self::assertSame( 'a8csp_bgje/internal/deliver', $this->backend->calls[0]['args']['hook'] ?? null );
		self::assertSame( array( $name, self::RUN_ID, 2 ), $this->backend->calls[0]['args']['args'] ?? null );

		$this->lifecycle_deliveries->handle_deliver_action( $name, self::RUN_ID, 2 );
		$this->lifecycle_deliveries->handle_deliver_action( $name, self::RUN_ID, 2 );

		self::assertCount( 1, $chunked_job->process_calls );
	}

	/**
	 * A retained Chunked Job run cannot be delivered through a Job that reused its identity.
	 *
	 * @return  void
	 */
	public function test_pending_chunked_job_redelivery_ignores_a_current_job_with_the_same_identity(): void {
		$chunk = array( 'page' => 1 );
		$state = new RunState( status: RunStatus::Running, kind: 'chunked_job', executing: false, start_args: self::ARGS, args_hash: self::ARGS_HASH, kind_state: array( $chunk ), failed_attempts: 0, action_sequence: 1, created_at: self::NOW - 901, heartbeat_at: self::NOW - 901, pending: PendingAction::async( 'continue', 10 ) );
		$this->store_running_state( self::IDENTITY, $state );
		$this->put_lock( $this->lock_option_name(), self::RUN_ID, self::NOW - 901 );
		$current_job = $this->registry->execution( self::IDENTITY );
		self::assertInstanceOf( RecordingJob::class, $current_job );

		$this->run_maintenance();

		self::assertSame( 'a8csp_bgje/internal/deliver', $this->backend->calls[0]['args']['hook'] ?? null );
		self::assertSame( array( self::IDENTITY, self::RUN_ID, 1 ), $this->backend->calls[0]['args']['args'] ?? null );

		$this->lifecycle_deliveries->handle_deliver_action( self::IDENTITY, self::RUN_ID, 1 );

		self::assertSame( array(), $current_job->calls );
		self::assertArrayNotHasKey( $this->run_option_name(), $this->options() );
		$failed = $this->options()[ 'a8csp_bgje_failed_runs_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $failed );
		$failed_entry = $failed[0] ?? null;
		self::assertIsArray( $failed_entry );
		$error = $failed_entry['error'] ?? null;
		self::assertIsArray( $error );
		self::assertSame( array( 'failed_chunk' => $chunk ), $error['details'] ?? null );
		$failure = $this->fired_actions()[0]['args'][0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( array( 'failed_chunk' => $chunk ), $failure->details );
		$record = $this->log_record(
			'warning',
			array(
				'identity' => self::IDENTITY,
				'run_id'   => self::RUN_ID,
			)
		);
		self::assertNotNull( $record );
		self::assertSame( self::IDENTITY, $record['context']['identity'] ?? null );
	}

	/**
	 * A retained Job run cannot be delivered through a Chunked Job that reused its identity.
	 *
	 * @return  void
	 */
	public function test_pending_job_redelivery_ignores_a_current_chunked_job_with_the_same_identity(): void {
		$name                = self::identity( 'reused-as-chunked-job' );
		$current_chunked_job = new RecordingChunkedJob( 'reused-as-chunked-job' );
		$this->registry->register( $name, $current_chunked_job->definition() );
		$state = new RunState( status: RunStatus::Running, kind: 'job', executing: false, start_args: self::ARGS, args_hash: self::ARGS_HASH, kind_state: array(), failed_attempts: 0, action_sequence: 1, created_at: self::NOW - 901, heartbeat_at: self::NOW - 901, pending: PendingAction::async( 'run', 10 ) );
		$this->store_running_state( $name, $state );
		$this->put_lock( 'a8csp_bgje_overlap_lock_' . $name . '_' . self::ARGS_HASH, self::RUN_ID, self::NOW - 901 );

		$this->run_maintenance();

		self::assertSame( 'a8csp_bgje/internal/deliver', $this->backend->calls[0]['args']['hook'] ?? null );
		self::assertSame( array( $name, self::RUN_ID, 1 ), $this->backend->calls[0]['args']['args'] ?? null );

		$this->lifecycle_deliveries->handle_deliver_action( $name, self::RUN_ID, 1 );

		self::assertSame( array(), $current_chunked_job->process_calls );
		self::assertArrayNotHasKey( $this->run_option_name( $name ), $this->options() );
		self::assertArrayHasKey( 'a8csp_bgje_failed_runs_' . $name, $this->options() );
		$record = $this->log_record(
			'warning',
			array(
				'identity' => $name,
				'run_id'   => self::RUN_ID,
			)
		);
		self::assertNotNull( $record );
		self::assertSame( $name, $record['context']['identity'] ?? null );
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
		$this->registry->register( $healthy_name, $healthy_chunked_job->definition() );
		$result = $this->dispatcher->dispatch( $healthy_name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		$this->set_run_fields( $healthy_name, array( 'executing' => true ) );
		unset( $this->wpdb->rows[ 'a8csp_bgje_overlap_lock_' . $healthy_name . '_' . self::ARGS_HASH ] );

		$throwable = new \RuntimeException( 'Run staleness filter exploded.' );

		$GLOBALS['a8csp_bgje_test_filter_values'] = array(
			'a8csp_bgje/lock_staleness/' . self::IDENTITY => static function () use ( $throwable ): int {
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
		$diagnostic = $this->exception_diagnostic( $throwable );
		self::assertSame( 'warning', $diagnostic['level'] ?? null );
		self::assertSame(
			array(
				'identity'  => self::IDENTITY,
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
			'a8csp_bgje/continue_delay' => static function ( int $delay, string $name, string $run_id ) use ( $throwable ): int {
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
				'identity'  => self::IDENTITY,
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
		self::assertSame( self::IDENTITY, $this->logger->records[0]['context']['identity'] ?? null );
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
		self::assertSame( self::IDENTITY, $this->logger->records[0]['context']['identity'] ?? null );
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
		$state = new RunState( status: RunStatus::Running, kind: 'chunked_job', executing: false, start_args: self::ARGS, args_hash: self::ARGS_HASH, kind_state: array( self::ARGS ), failed_attempts: 0, action_sequence: 1, created_at: self::NOW, heartbeat_at: self::NOW, pending: PendingAction::async( 'continue', 10 ) );
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
				'a8csp_bgje/superseded/' . self::IDENTITY,
				'a8csp_bgje/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$history = $options[ 'a8csp_bgje_run_history_' . self::IDENTITY ] ?? null;
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
				'identity' => self::IDENTITY,
				'run_id'   => self::RUN_ID,
			)
		);
		self::assertNotNull( $record );
		self::assertSame( self::IDENTITY, $record['context']['identity'] ?? null );
		self::assertArrayNotHasKey( 'chunked_job_name', $record['context'] );
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
				'a8csp_bgje/superseded/' . self::IDENTITY,
				'a8csp_bgje/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$history = $options[ 'a8csp_bgje_run_history_' . self::IDENTITY ] ?? null;
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
			'a8csp_bgje/continue_delay' => static function (
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
				'a8csp_bgje/superseded/' . self::IDENTITY,
				'a8csp_bgje/superseded',
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
		$history = $options[ 'a8csp_bgje_run_history_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $history );
		self::assertSame( array(), $history['terminal'] ?? null );
		self::assertSame( array(), $this->fired_actions() );
	}

	/**
	 * A crashed chunked job emits its failure hook before common terminal cleanup.
	 *
	 * @return  void
	 */
	public function test_sweep_terminalizes_a_running_chunked_job_through_chunked_job_failure_machinery(): void {
		$name        = self::identity( 'crashed-chunked-job' );
		$chunked_job = new RecordingChunkedJob( 'crashed-chunked-job' );
		$this->registry->register( $name, $chunked_job->definition() );
		$result = $this->dispatcher->dispatch( $name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );

		$lock_name = 'a8csp_bgje_overlap_lock_' . $name . '_' . self::ARGS_HASH;
		$this->set_run_fields( $name, array( 'executing' => true ) );
		unset( $this->wpdb->rows[ $lock_name ] );
		$this->logger->records                    = array();
		$GLOBALS['a8csp_bgje_test_fired_actions'] = array();

		$this->run_maintenance();

		$actions = $this->fired_actions();
		$failure = $actions[0]['args'][0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( $name, $failure->identity );
		self::assertSame( self::RUN_ID, (string) $failure->run_id );
		self::assertSame( 1, $failure->attempts );
		self::assertSame( RunFailureStage::crash_reclamation(), $failure->stage );
		self::assertSame( ErrorCode::ExecutionFailed, $failure->code );
		self::assertStringContainsString( 'maintenance crash reclaim path', $failure->summary );
		self::assertNull( $failure->details );
		$options = $this->options();
		self::assertArrayNotHasKey( RunStore::OPTION_PREFIX . $name . '_' . self::RUN_ID, $options );
		self::assertArrayHasKey( 'a8csp_bgje_failed_runs_' . $name, $options );
		self::assertSame(
			array( 'a8csp_bgje/failed/' . $name, 'a8csp_bgje/failed' ),
			\array_column( $actions, 'hook_name' )
		);
		self::assertSame( array( $failure ), $actions[0]['args'] ?? null );
	}

	/**
	 * A retained chunked-job row keeps its crash routing after its identity is reused by a job.
	 *
	 * @return  void
	 */
	public function test_sweep_routes_a_running_row_by_its_persisted_chunked_job_kind(): void {
		$state = new RunState( status: RunStatus::Running, kind: 'chunked_job', executing: true, start_args: self::ARGS, args_hash: self::ARGS_HASH, kind_state: array( self::ARGS ), failed_attempts: 0, action_sequence: 1, created_at: self::NOW - 7_201, heartbeat_at: self::NOW - 3_601, pending: PendingAction::async( 'continue', 10 ) );

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
		self::assertSame( array( 'failed_chunk' => self::ARGS ), $error['details'] ?? null );
		$failure = $this->fired_actions()[0]['args'][0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( array( 'failed_chunk' => self::ARGS ), $failure->details );
	}

	/**
	 * A throwing failed hook cannot starve a later client's crash reconciliation.
	 *
	 * @return  void
	 */
	public function test_sweep_continues_after_a_failed_hook_throws_for_one_chunked_job(): void {
		$throwing_name        = self::identity( 'broken-chunked-job' );
		$throwing_chunked_job = new RecordingChunkedJob( 'broken-chunked-job' );
		$this->registry->register( $throwing_name, $throwing_chunked_job->definition() );
		$result = $this->dispatcher->dispatch( $throwing_name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		$this->set_run_fields( $throwing_name, array( 'executing' => true ) );
		unset( $this->wpdb->rows[ 'a8csp_bgje_overlap_lock_' . $throwing_name . '_' . self::ARGS_HASH ] );

		$this->create_running_run();
		$this->set_run_fields( self::IDENTITY, array( 'executing' => true ) );
		unset( $this->wpdb->rows[ $this->lock_option_name() ] );
		$throwable = new \RuntimeException( 'Chunked job failed hook exploded.' );

		$GLOBALS['a8csp_bgje_test_action_callbacks'] = array(
			'a8csp_bgje/failed' => static function ( RunFailure $failure ) use ( $throwable, $throwing_name ): void {
				if ( $throwing_name === $failure->identity ) {
					throw $throwable;
				}
			},
		);

		$this->run_maintenance();

		$options        = $this->options();
		$throwing_state = $options[ $this->run_option_name( $throwing_name ) ] ?? null;
		self::assertIsArray( $throwing_state );
		self::assertSame( array( 'retention', 'history' ), $throwing_state['effects'] ?? null );
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		self::assertArrayHasKey( 'a8csp_bgje_failed_runs_' . self::IDENTITY, $options );
		$history = $options[ 'a8csp_bgje_run_history_' . self::IDENTITY ] ?? null;
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
				'identity'  => $throwing_name,
				'run_id'    => self::RUN_ID,
				'exception' => $throwable,
			),
			$diagnostic['context']
		);
	}

	/**
	 * A schema-invalid lock row is preserved with a redacted operator diagnostic.
	 *
	 * @return  void
	 */
	public function test_sweep_preserves_and_diagnoses_a_schema_invalid_lock_row(): void {
		$corrupt_name = self::identity( 'corrupt-job' );
		$lock_name    = OverlapGuard::OPTION_PREFIX . $corrupt_name . '_' . \str_repeat( 'b', 64 );
		$raw          = 'not-serialized';
		$this->wpdb->put( $lock_name, $raw );

		$this->run_maintenance();

		self::assertSame( $raw, $this->wpdb->rows[ $lock_name ] ?? null );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'Preserved schema-invalid execution-overlap lock during maintenance sweep; inspect and repair it with WP-CLI.', $this->logger->records[0]['message'] ?? null );
		self::assertSame( $corrupt_name, $this->logger->records[0]['context']['identity'] ?? null );
		self::assertSame( \str_repeat( 'b', 64 ), $this->logger->records[0]['context']['args_hash'] ?? null );
		self::assertTrue( $this->logger->records[0]['context']['malformed'] ?? null );
		self::assertSame( \strlen( $raw ), $this->logger->records[0]['context']['raw_length'] ?? null );
		self::assertSame( \substr( \hash( 'sha256', $raw ), 0, 16 ), $this->logger->records[0]['context']['raw_sha256'] ?? null );
		self::assertArrayNotHasKey( 'run_id', $this->logger->records[0]['context'] );
	}

	/**
	 * Multiple schema-invalid lock rows are preserved and diagnosed independently.
	 *
	 * @return  void
	 */
	public function test_sweep_preserves_and_diagnoses_multiple_schema_invalid_lock_rows(): void {
		$first_name = self::identity( 'broken-lock' );
		$first_hash = \str_repeat( 'b', 64 );
		$first_key  = OverlapGuard::OPTION_PREFIX . $first_name . '_' . $first_hash;
		$first_raw  = 'broken-lock-row';
		$this->wpdb->put( $first_key, $first_raw );

		$second_name = self::identity( 'other-broken-lock' );
		$second_hash = \str_repeat( 'c', 64 );
		$second_key  = OverlapGuard::OPTION_PREFIX . $second_name . '_' . $second_hash;
		$second_raw  = 'other-broken-lock-row';
		$this->wpdb->put( $second_key, $second_raw );

		$this->run_maintenance();

		self::assertSame( $first_raw, $this->wpdb->rows[ $first_key ] ?? null );
		self::assertSame( $second_raw, $this->wpdb->rows[ $second_key ] ?? null );
		self::assertCount( 2, $this->logger->records );
		self::assertSame( array( $first_name, $second_name ), \array_column( \array_column( $this->logger->records, 'context' ), 'identity' ) );
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
	 * A grammar-valid unknown kind remains available for a future handler while maintenance skips running recovery.
	 *
	 * @return  void
	 */
	public function test_sweep_leaves_a_running_unknown_kind_untouched(): void {
		$name  = self::identity( 'unknown-running-kind' );
		$state = new RunState( status: RunStatus::Running, kind: 'acme.export', executing: true, start_args: self::ARGS, args_hash: self::ARGS_HASH, kind_state: array( self::ARGS ), failed_attempts: 0, action_sequence: 1, created_at: self::NOW - 7_201, heartbeat_at: self::NOW - 3_601, pending: PendingAction::async( 'export', 19 ) );
		$this->store_running_state( $name, $state );
		$before = $this->run_state( $name );

		$this->run_maintenance();

		self::assertSame( $before, $this->run_state( $name ) );
		self::assertSame( array(), $this->backend->calls );
		$record = $this->log_record(
			'warning',
			array(
				'identity' => $name,
				'run_id'   => self::RUN_ID,
				'kind'     => 'acme.export',
			)
		);
		self::assertNotNull( $record );
		self::assertSame( 'Background-work run kind has no registered handler; maintenance left the run untouched.', $record['message'] );
	}

	/**
	 * Generic superseded hooks and history let maintenance finish an unknown terminal kind.
	 *
	 * @return  void
	 */
	public function test_sweep_replays_an_old_superseded_run_of_unknown_kind(): void {
		$name = self::identity( 'unknown-terminal-kind' );
		$this->store_terminal_run( $name, 'superseded', kind: 'acme.export' );

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name( $name ), $options );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . $name, $options );
		self::assertSame(
			array(
				'a8csp_bgje/superseded/' . $name,
				'a8csp_bgje/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_history_status( $options, 'superseded', $name );
		$record = $this->log_record(
			'warning',
			array(
				'identity' => $name,
				'run_id'   => self::RUN_ID,
				'status'   => 'superseded',
			)
		);
		self::assertNotNull( $record );
		self::assertSame( 'Reclaimed old terminal run option left behind after transition cleanup.', $record['message'] );
		self::assertNull(
			$this->log_record(
				'warning',
				array(
					'identity' => $name,
					'run_id'   => self::RUN_ID,
					'kind'     => 'acme.export',
				)
			)
		);
	}

	/**
	 * An unknown Failed kind retains a generic failure without invented kind-specific detail.
	 *
	 * @return  void
	 */
	public function test_sweep_replays_an_old_failed_run_of_unknown_kind_with_generic_detail(): void {
		$name = self::identity( 'unknown-failed-kind' );
		$this->store_terminal_run( $name, 'failed', error: null, failed_attempts: 2, kind: 'acme.export', kind_state: array( 'opaque' => 'payload' ) );

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name( $name ), $options );
		$failed = $options[ FailedRunStore::OPTION_PREFIX . $name ] ?? null;
		self::assertIsArray( $failed );
		$summary = \sprintf( 'Run "%1$s" for background-work "%2$s" failed before recoverable terminal detail was persisted.', self::RUN_ID, $name );
		self::assertSame(
			array(
				array(
					'run_id'     => self::RUN_ID,
					'kind'       => 'acme.export',
					'failed_at'  => self::NOW - 3_601,
					'start_args' => self::ARGS,
					'attempts'   => 3,
					'error'      => array(
						'class'   => null,
						'message' => $summary,
						'stage'   => RunFailureStage::crash_reclamation()->value,
						'code'    => ErrorCode::StorageFailed->value,
					),
				),
			),
			$failed
		);
		$actions = $this->fired_actions();
		self::assertSame( array( 'a8csp_bgje/failed/' . $name, 'a8csp_bgje/failed' ), \array_column( $actions, 'hook_name' ) );
		$failure = $actions[0]['args'][0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( 3, $failure->attempts );
		self::assertSame( RunFailureStage::crash_reclamation(), $failure->stage );
		self::assertSame( ErrorCode::StorageFailed, $failure->code );
		self::assertSame( $summary, $failure->summary );
		self::assertNull( $failure->details );
		$this->assert_history_status( $options, 'failed', $name );
		$generic = $this->log_record(
			'warning',
			array(
				'identity' => $name,
				'run_id'   => self::RUN_ID,
			)
		);
		self::assertNotNull( $generic );
		self::assertSame( 'Failed terminal run has no persisted failure detail; replay uses a generic failure.', $generic['message'] );
		$cleanup = $this->log_record(
			'warning',
			array(
				'identity' => $name,
				'run_id'   => self::RUN_ID,
				'status'   => 'failed',
			)
		);
		self::assertNotNull( $cleanup );
		self::assertSame( 'Reclaimed old terminal run option left behind after transition cleanup.', $cleanup['message'] );
		self::assertNull(
			$this->log_record(
				'warning',
				array(
					'identity' => $name,
					'run_id'   => self::RUN_ID,
					'kind'     => 'acme.export',
				)
			)
		);
	}

	/**
	 * Registered kinds retain cleanup behavior for every terminal status.
	 *
	 * @param   string $status Terminal status value.
	 *
	 * @return  void
	 */
	#[DataProvider( 'registered_terminal_statuses' )]
	public function test_sweep_replays_every_old_registered_terminal_status( string $status ): void {
		$error = 'failed' === $status
			? array(
				'class'   => \RuntimeException::class,
				'message' => 'Persisted registered failure.',
				'stage'   => RunFailureStage::execution()->value,
				'code'    => ErrorCode::ExecutionFailed->value,
			)
			: null;
		$this->store_terminal_run( self::IDENTITY, $status, error: $error, failed_attempts: 'failed' === $status ? 2 : 0 );

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		self::assertSame(
			array(
				'a8csp_bgje/' . $status . '/' . self::IDENTITY,
				'a8csp_bgje/' . $status,
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_history_status( $options, $status );
		if ( 'failed' === $status ) {
			self::assertArrayHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $options );
		} else {
			self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $options );
		}
		self::assertNotNull(
			$this->log_record(
				'warning',
				array(
					'identity' => self::IDENTITY,
					'run_id'   => self::RUN_ID,
					'status'   => $status,
				)
			)
		);
	}

	/**
	 * Supplies every terminal status whose cleanup is handler-independent after failure resolution.
	 *
	 * @return  array<string, array{status: string}>
	 */
	public static function registered_terminal_statuses(): array {
		return array(
			'completed'  => array( 'status' => 'completed' ),
			'failed'     => array( 'status' => 'failed' ),
			'cancelled'  => array( 'status' => 'cancelled' ),
			'superseded' => array( 'status' => 'superseded' ),
		);
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
		$name        = self::identity( 'crashed-chunked-job' );
		$chunked_job = new RecordingChunkedJob( 'crashed-chunked-job' );
		$this->registry->register( $name, $chunked_job->definition() );
		$result = $this->dispatcher->dispatch( $name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		$run_name = 'a8csp_bgje_active_run_' . $name . '_' . self::RUN_ID;
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

		$this->lifecycle_deliveries->handle_deliver_action( self::IDENTITY, self::RUN_ID, 1 );

		self::assertSame(
			array(
				'a8csp_bgje/superseded/' . self::IDENTITY,
				'a8csp_bgje/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $this->options() );
		$history = $this->options()[ 'a8csp_bgje_run_history_' . self::IDENTITY ] ?? null;
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
		$this->registry->register( $name, $chunked_job->definition() );
		$this->store_terminal_run(
			$name,
			'failed',
			array(),
			array(
				'class'   => \RuntimeException::class,
				'message' => 'Persisted chunked job failure.',
				'stage'   => RunFailureStage::execution()->value,
				'code'    => ErrorCode::ExecutionFailed->value,
			),
			3,
			'chunked_job'
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
					'kind'       => 'chunked_job',
					'failed_at'  => self::NOW - 3_601,
					'start_args' => self::ARGS,
					'attempts'   => 3,
					'error'      => array(
						'class'   => \RuntimeException::class,
						'message' => 'Persisted chunked job failure.',
						'stage'   => RunFailureStage::execution()->value,
						'code'    => ErrorCode::ExecutionFailed->value,
					),
				),
			),
			$failed
		);
		$actions = $this->fired_actions();
		$failure = $actions[0]['args'][0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( $name, $failure->identity );
		self::assertSame( self::RUN_ID, (string) $failure->run_id );
		self::assertSame( 3, $failure->attempts );
		self::assertSame( RunFailureStage::execution(), $failure->stage );
		self::assertSame( ErrorCode::ExecutionFailed, $failure->code );
		self::assertSame( 'Persisted chunked job failure.', $failure->summary );
		self::assertNull( $failure->details );
		self::assertSame(
			array( 'a8csp_bgje/failed/' . $name, 'a8csp_bgje/failed' ),
			\array_column( $actions, 'hook_name' )
		);
		self::assertSame( array( $failure ), $actions[0]['args'] ?? null );
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
		$this->registry->register( $name, $chunked_job->definition() );
		$this->store_terminal_run( $name, 'failed', array(), null, 2, 'chunked_job', array( $chunk ), PendingAction::async( 'continue', 10 ) );

		$this->run_maintenance();

		$options = $this->options();
		$failed  = $options[ 'a8csp_bgje_failed_runs_' . $name ] ?? null;
		self::assertIsArray( $failed );
		$expected_summary = \sprintf( 'Run "%1$s" for background-work "%2$s" failed before recoverable terminal detail was persisted.', self::RUN_ID, $name );
		self::assertSame(
			array(
				array(
					'run_id'     => self::RUN_ID,
					'kind'       => 'chunked_job',
					'failed_at'  => self::NOW - 3_601,
					'start_args' => self::ARGS,
					'attempts'   => 3,
					'error'      => array(
						'class'   => null,
						'message' => $expected_summary,
						'stage'   => RunFailureStage::crash_reclamation()->value,
						'code'    => ErrorCode::StorageFailed->value,
						'details' => array( 'failed_chunk' => $chunk ),
					),
				),
			),
			$failed
		);
		$actions = $this->fired_actions();
		$failure = $actions[0]['args'][0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( $name, $failure->identity );
		self::assertSame( self::RUN_ID, (string) $failure->run_id );
		self::assertSame( 3, $failure->attempts );
		self::assertSame( RunFailureStage::crash_reclamation(), $failure->stage );
		self::assertSame( ErrorCode::StorageFailed, $failure->code );
		self::assertSame( $expected_summary, $failure->summary );
		self::assertSame( array( 'failed_chunk' => $chunk ), $failure->details );
		self::assertSame( array( $failure ), $actions[0]['args'] ?? null );
	}

	/**
	 * Durable effect markers prevent a replay from repeating already completed chunked job effects.
	 *
	 * @return  void
	 */
	public function test_sweep_replays_only_missing_failed_chunked_job_effects(): void {
		$name        = self::identity( 'partially-effected-chunked-job' );
		$chunked_job = new RecordingChunkedJob( 'partially-effected-chunked-job' );
		$this->registry->register( $name, $chunked_job->definition() );
		self::assertTrue( $this->stores->failed_run_store( $name )->record( self::RUN_ID, 'chunked_job', self::NOW - 3_601, self::ARGS, 2, new EngineError( 'Persisted chunked job failure.', \RuntimeException::class ), new RunFailure( identity: $name, run_id: RunId::from( self::RUN_ID ), attempts: 2, stage: RunFailureStage::execution(), code: ErrorCode::ExecutionFailed, summary: 'Persisted chunked job failure.', details: null, ) ) );
		$failed_option = 'a8csp_bgje_failed_runs_' . $name;
		$failed_raw    = $this->wpdb->rows[ $failed_option ] ?? null;
		self::assertIsString( $failed_raw );
		$this->store_terminal_run(
			$name,
			'failed',
			array( 'retention', 'hooks' ),
			array(
				'class'   => \RuntimeException::class,
				'message' => 'Persisted chunked job failure.',
				'stage'   => RunFailureStage::execution()->value,
				'code'    => ErrorCode::ExecutionFailed->value,
			),
			2,
			'chunked_job'
		);

		$this->run_maintenance();

		self::assertSame( $failed_raw, $this->wpdb->rows[ $failed_option ] ?? null );
		self::assertSame( array(), $this->fired_actions() );
		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name( $name ), $options );
		$this->assert_history_status( $options, 'failed', $name );
	}

	/**
	 * Failed hooks replay only when their durable marker is absent.
	 *
	 * @return  void
	 */
	public function test_sweep_replays_only_unmarked_failed_job_hooks(): void {
		$error = array(
			'class'   => \RuntimeException::class,
			'message' => 'Persisted job failure.',
			'stage'   => RunFailureStage::execution()->value,
			'code'    => ErrorCode::ExecutionFailed->value,
		);
		$this->store_terminal_run( self::IDENTITY, 'failed', error: $error, failed_attempts: 2 );

		$marked_name = self::identity( 'marked-failed-job' );
		$marked_job  = new RecordingJob( 'marked-failed-job' );
		$this->registry->register( $marked_name, $marked_job->definition() );
		$failure = new RunFailure( identity: $marked_name, run_id: RunId::from( self::RUN_ID ), attempts: 2, stage: RunFailureStage::execution(), code: ErrorCode::ExecutionFailed, summary: 'Persisted job failure.', details: null );
		self::assertTrue( $this->stores->failed_run_store( $marked_name )->record( self::RUN_ID, 'job', self::NOW - 3_601, self::ARGS, 2, new EngineError( 'Persisted job failure.', \RuntimeException::class ), $failure ) );
		$this->store_terminal_run( $marked_name, 'failed', array( 'retention', 'hooks' ), $error, 2 );

		$this->run_maintenance();

		$actions = $this->fired_actions();
		self::assertSame( array( 'a8csp_bgje/failed/' . self::IDENTITY, 'a8csp_bgje/failed' ), \array_column( $actions, 'hook_name' ) );
		$emitted_failure = $actions[0]['args'][0] ?? null;
		self::assertInstanceOf( RunFailure::class, $emitted_failure );
		self::assertSame( self::IDENTITY, $emitted_failure->identity );
		self::assertSame( self::RUN_ID, (string) $emitted_failure->run_id );
		$options = $this->options();
		$failed  = $options[ 'a8csp_bgje_failed_runs_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $failed );
		$failed_entry = $failed[0] ?? null;
		self::assertIsArray( $failed_entry );
		self::assertSame( self::ARGS, $failed_entry['start_args'] ?? null );
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		self::assertArrayNotHasKey( $this->run_option_name( $marked_name ), $options );
		$this->assert_history_status( $options, 'failed' );
		$this->assert_history_status( $options, 'failed', $marked_name );
	}

	/**
	 * A throwing failed hook stays unmarked and replays without repeating durable history.
	 *
	 * @return  void
	 */
	public function test_sweep_retries_a_throwing_failed_job_hook(): void {
		$error   = array(
			'class'   => \RuntimeException::class,
			'message' => 'Persisted job failure.',
			'stage'   => RunFailureStage::execution()->value,
			'code'    => ErrorCode::ExecutionFailed->value,
		);
		$failure = new RunFailure( identity: self::IDENTITY, run_id: RunId::from( self::RUN_ID ), attempts: 2, stage: RunFailureStage::execution(), code: ErrorCode::ExecutionFailed, summary: 'Persisted job failure.', details: null );
		self::assertTrue( $this->stores->failed_run_store( self::IDENTITY )->record( self::RUN_ID, 'job', self::NOW - 3_601, self::ARGS, 2, new EngineError( 'Persisted job failure.', \RuntimeException::class ), $failure ) );
		$this->store_terminal_run( self::IDENTITY, 'failed', array( 'retention' ), $error, 2 );
		$throwable = new \RuntimeException( 'Failed hook exploded.' );

		$GLOBALS['a8csp_bgje_test_action_throwables'] = array( 'a8csp_bgje/failed' => $throwable );

		$this->run_maintenance();

		$state = $this->options()[ $this->run_option_name() ] ?? null;
		self::assertIsArray( $state );
		self::assertSame( array( 'retention', 'history' ), $state['effects'] ?? null );
		$actions = $this->fired_actions();
		self::assertSame( array( 'a8csp_bgje/failed/' . self::IDENTITY, 'a8csp_bgje/failed' ), \array_column( $actions, 'hook_name' ) );
		self::assertEquals( $failure, $actions[0]['args'][0] ?? null );

		$GLOBALS['a8csp_bgje_test_action_throwables'] = array();
		$GLOBALS['a8csp_bgje_test_fired_actions']     = array();
		$this->run_maintenance();

		self::assertSame( array( 'a8csp_bgje/failed/' . self::IDENTITY, 'a8csp_bgje/failed' ), \array_column( $this->fired_actions(), 'hook_name' ) );
		self::assertArrayNotHasKey( $this->run_option_name(), $this->options() );
		$this->assert_history_status( $this->options(), 'failed' );
	}

	/**
	 * A throwing completed hook stays unmarked while history remains idempotent.
	 *
	 * @return  void
	 */
	public function test_sweep_retries_a_throwing_completed_job_hook(): void {
		$this->store_terminal_run( self::IDENTITY, 'completed' );
		$throwable = new \RuntimeException( 'Completed hook exploded.' );

		$GLOBALS['a8csp_bgje_test_action_throwables'] = array( 'a8csp_bgje/completed/' . self::IDENTITY => $throwable );

		$this->run_maintenance();

		$state = $this->options()[ $this->run_option_name() ] ?? null;
		self::assertIsArray( $state );
		self::assertSame( array( 'history' ), $state['effects'] ?? null );
		self::assertSame(
			array(
				'a8csp_bgje/completed/' . self::IDENTITY,
				'a8csp_bgje/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);

		$GLOBALS['a8csp_bgje_test_action_throwables'] = array();
		$GLOBALS['a8csp_bgje_test_fired_actions']     = array();
		$this->run_maintenance();

		self::assertSame(
			array(
				'a8csp_bgje/completed/' . self::IDENTITY,
				'a8csp_bgje/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertArrayNotHasKey( $this->run_option_name(), $this->options() );
		$this->assert_history_status( $this->options(), 'completed' );
	}

	/**
	 * A completed chunked job left after its terminal claim replays its hooks and history.
	 *
	 * @return  void
	 */
	public function test_sweep_replays_all_effects_for_an_old_completed_chunked_job(): void {
		$name        = self::identity( 'completed-chunked-job' );
		$chunked_job = new RecordingChunkedJob( 'completed-chunked-job' );
		$this->registry->register( $name, $chunked_job->definition() );
		$this->store_terminal_run( $name, 'completed', kind: 'chunked_job' );

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name( $name ), $options );
		$actions = $this->fired_actions();
		self::assertSame(
			array(
				'a8csp_bgje/completed/' . $name,
				'a8csp_bgje/completed',
			),
			\array_column( $actions, 'hook_name' )
		);
		self::assertEquals(
			array(
				RunId::from( self::RUN_ID ),
				self::ARGS,
				null,
			),
			$actions[0]['args'] ?? null
		);
		self::assertEquals(
			array(
				$name,
				RunId::from( self::RUN_ID ),
				self::ARGS,
				null,
			),
			$actions[1]['args'] ?? null
		);
		$this->assert_history_status( $options, 'completed', $name );
	}

	/**
	 * A completed job left after its terminal claim replays its hooks and history.
	 *
	 * @return  void
	 */
	public function test_sweep_replays_all_effects_for_an_old_completed_job(): void {
		$this->store_terminal_run( self::IDENTITY, 'completed', previous_completed_run_id: self::PREVIOUS_RUN_ID );

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		$this->assert_history_status( $options, 'completed' );
		$actions = $this->fired_actions();
		self::assertSame(
			array(
				'a8csp_bgje/completed/' . self::IDENTITY,
				'a8csp_bgje/completed',
			),
			\array_column( $actions, 'hook_name' )
		);
		self::assertEquals(
			array(
				RunId::from( self::RUN_ID ),
				self::ARGS,
				RunId::from( self::PREVIOUS_RUN_ID ),
			),
			$actions[0]['args'] ?? null
		);
		self::assertEquals(
			array(
				self::IDENTITY,
				RunId::from( self::RUN_ID ),
				self::ARGS,
				RunId::from( self::PREVIOUS_RUN_ID ),
			),
			$actions[1]['args'] ?? null
		);
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
	}

	/**
	 * A retained chunked-job row keeps its terminal routing after its identity is reused by a job.
	 *
	 * @return  void
	 */
	public function test_sweep_routes_a_terminal_row_by_its_persisted_chunked_job_kind(): void {
		$this->store_terminal_run( self::IDENTITY, 'completed', kind: 'chunked_job' );

		$this->run_maintenance();

		self::assertArrayNotHasKey( $this->run_option_name(), $this->options() );
		self::assertSame(
			array(
				'a8csp_bgje/completed/' . self::IDENTITY,
				'a8csp_bgje/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertNotNull(
			$this->log_record(
				'warning',
				array(
					'identity' => self::IDENTITY,
					'run_id'   => self::RUN_ID,
					'status'   => 'completed',
				)
			)
		);
	}

	/**
	 * Hooks and history do not require request-local chunked-job registration.
	 *
	 * @return  void
	 */
	public function test_sweep_finishes_an_unregistered_chunked_job_terminal_row(): void {
		$name = self::identity( 'deactivated-client' );
		$this->store_terminal_run( $name, 'completed', kind: 'chunked_job' );

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name( $name ), $options );
		$this->assert_history_status( $options, 'completed', $name );
		self::assertSame(
			array(
				'a8csp_bgje/completed/' . $name,
				'a8csp_bgje/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertNotNull(
			$this->log_record(
				'warning',
				array(
					'identity' => $name,
					'run_id'   => self::RUN_ID,
					'status'   => 'completed',
				)
			)
		);
	}

	/**
	 * Hooks and history do not require request-local job registration.
	 *
	 * @return  void
	 */
	public function test_sweep_finishes_an_unregistered_job_terminal_row(): void {
		$name = self::identity( 'deactivated-job-client' );
		$this->store_terminal_run( $name, 'completed' );

		$this->run_maintenance();

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name( $name ), $options );
		$this->assert_history_status( $options, 'completed', $name );
		self::assertSame(
			array(
				'a8csp_bgje/completed/' . $name,
				'a8csp_bgje/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertNotNull(
			$this->log_record(
				'warning',
				array(
					'identity' => $name,
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

		self::assertFalse( $this->terminal_effects->finish_claimed_transition( self::IDENTITY, self::RUN_ID, $state, $snapshot['raw'], $run_store ) );
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
		$options[ 'a8csp_bgje_run_history_' . self::IDENTITY ] = array(
			'started'  => array( self::PREVIOUS_RUN_ID ),
			'terminal' => array(
				array(
					'run_id' => self::PREVIOUS_RUN_ID,
					'status' => 'completed',
				),
			),
			'by_hash'  => array(),
		);
		$GLOBALS['a8csp_bgje_test_options']                    = $options;
		$this->wpdb->script_result( 'delete', false );

		$this->run_maintenance();

		$options = $this->options();
		$state   = $options[ $this->run_option_name() ] ?? null;
		self::assertIsArray( $state );
		self::assertSame( array( 'hooks', 'history' ), $state['effects'] ?? null );
		$history = $options[ 'a8csp_bgje_run_history_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $history );
		self::assertSame(
			array(
				array(
					'run_id' => self::PREVIOUS_RUN_ID,
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
		$history = $options[ 'a8csp_bgje_run_history_' . self::IDENTITY ] ?? null;
		self::assertIsArray( $history );
		$terminal = $history['terminal'] ?? null;
		self::assertIsArray( $terminal );
		self::assertCount( 2, $terminal );
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
		$this->maintenance->handle( array(), new RunContext( RunId::from( '00000000000000000000-0000000000000000001' ), array() ) );
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
		$result = $this->dispatcher->dispatch( self::IDENTITY, self::ARGS );
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
		$history = $options[ 'a8csp_bgje_run_history_' . $name ] ?? null;
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
	 * @phpstan-param array{class: string|null, message: string, stage: string, code: string, details?: array<array-key, mixed>}|null $error
	 * @phpstan-param array<array-key, mixed> $kind_state
	 *
	 * @param   string                  $name                      Stable job or chunked job name.
	 * @param   string                  $status                    Terminal status value.
	 * @param   array                   $effects                   Completed terminal effect keys.
	 * @param   array|null              $error                     Persisted terminal failure detail.
	 * @param   int                     $failed_attempts           Attempts consumed by a failed run.
	 * @param   string                  $kind                      Persisted kind.
	 * @param   array<array-key, mixed> $kind_state                Opaque kind-owned payload.
	 * @param   PendingAction|null      $pending                   Durable successor descriptor.
	 * @param   string|null             $previous_completed_run_id Frozen previous completed run identifier.
	 *
	 * @return  void
	 */
	private function store_terminal_run( string $name, string $status, array $effects = array(), ?array $error = null, int $failed_attempts = 0, string $kind = 'job', array $kind_state = array(), ?PendingAction $pending = null, ?string $previous_completed_run_id = null ): void {
		$state = new RunState( status: RunStatus::from( $status ), kind: $kind, executing: true, start_args: self::ARGS, args_hash: self::ARGS_HASH, kind_state: $kind_state, failed_attempts: $failed_attempts, action_sequence: 1, created_at: self::NOW - 7_201, heartbeat_at: self::NOW - 3_601, pending: $pending, error: $error, previous_completed_run_id: $previous_completed_run_id, effects: $effects );

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
		self::assertSame( RunFailureStage::crash_reclamation()->value, $error['stage'] ?? null );
		self::assertSame( ErrorCode::ExecutionFailed->value, $error['code'] ?? null );
		self::assertArrayNotHasKey( 'details', $error );
		$actions = $this->fired_actions();
		self::assertSame(
			array( 'a8csp_bgje/failed/' . self::IDENTITY, 'a8csp_bgje/failed' ),
			\array_column( $actions, 'hook_name' )
		);
		$failure = $actions[0]['args'][0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( self::IDENTITY, $failure->identity );
		self::assertSame( self::RUN_ID, (string) $failure->run_id );
		self::assertSame( 1, $failure->attempts );
		self::assertSame( RunFailureStage::crash_reclamation(), $failure->stage );
		self::assertSame( ErrorCode::ExecutionFailed, $failure->code );
		self::assertSame( $message, $failure->summary );
		self::assertNull( $failure->details );
		self::assertSame( array( $failure ), $actions[0]['args'] ?? null );
		$history = $options[ 'a8csp_bgje_run_history_' . self::IDENTITY ] ?? null;
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
				|| \str_starts_with( $name, 'a8csp_bgje_run_history_' )
			) {
				$options[ $name ] = RawOptionDecoder::decode( $raw );
			}
		}

		return $options;
	}

	// endregion.
}

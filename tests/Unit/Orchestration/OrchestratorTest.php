<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Orchestration;

use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\NonRetryableTaskException;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LockRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Orchestrator;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Randomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\LatestRunPointer;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\TerminalTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingRandomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the observable single-task lifecycle across scheduling, storage, hooks, locks, and logs.
 *
 */
#[CoversClass( Orchestrator::class )]
#[UsesClass( EngineError::class )]
#[UsesClass( FailedRunStore::class )]
#[UsesClass( LatestRunPointer::class )]
#[UsesClass( LockRows::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( Randomizer::class )]
#[UsesClass( RetryPolicy::class )]
#[UsesClass( RunHistory::class )]
#[UsesClass( RunState::class )]
#[UsesClass( RunStatus::class )]
#[UsesClass( RunStore::class )]
#[UsesClass( StoreFactory::class )]
#[UsesClass( BatchRegistry::class )]
#[UsesClass( TaskRegistry::class )]
final class OrchestratorTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const ARGS = array(
		'site_id' => 7,
		'mode'    => 'full',
	);

	private const ARGS_HASH = '7dcca9cc21619f109d6f0423c49b010606457ea4a713721e9ce5134949d72bd2';
	private const NAME      = 'email-digest';
	private const NOW       = 1_700_000_000;
	private const RUN_ID    = '00000000001700000000-0000000000000000042';

	private FixedClock $clock;
	private RecordingBackend $backend;
	private RecordingLogger $logger;
	private RecordingRandomizer $randomizer;
	private RecordingTask $task;
	private TaskRegistry $registry;
	private WpdbLockSpy $wpdb;
	private Orchestrator $orchestrator;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress functions before orchestration classes are instantiated.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__ ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-hook-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-lock-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-time-constant-stubs.php';
		require_once \dirname( __DIR__ ) . '/Scheduling/wp-json-encode-stub.php';
	}

	/**
	 * Resets every observable boundary and constructs one registered task lifecycle.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_options']              = array();
		$GLOBALS['a8csp_bgte_test_option_calls']         = array();
		$GLOBALS['a8csp_bgte_test_option_autoload']      = array();
		$GLOBALS['a8csp_bgte_test_filter_values']        = array();
		$GLOBALS['a8csp_bgte_test_fired_actions']        = array();
		$GLOBALS['a8csp_bgte_test_action_throwables']    = array();
		$GLOBALS['a8csp_bgte_test_hooks']                = array();
		$GLOBALS['a8csp_bgte_test_action_registrations'] = array();
		$GLOBALS['a8csp_bgte_test_blog_id']              = 1;
		$GLOBALS['a8csp_bgte_test_cache']                = array();
		$GLOBALS['a8csp_bgte_test_cache_calls']          = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events']     = array();
		unset( $GLOBALS['a8csp_bgte_test_before_add_option'] );

		$this->clock      = new FixedClock( self::NOW );
		$this->backend    = new RecordingBackend();
		$this->logger     = new RecordingLogger();
		$this->randomizer = new RecordingRandomizer( 42 );
		$this->task       = new RecordingTask( self::NAME );
		$this->registry   = new TaskRegistry();
		$this->registry->register( $this->task );
		$this->wpdb = new WpdbLockSpy();
		$guard      = new OverlapGuard( $this->clock, $this->logger, new LockRows( $this->wpdb ) );
		$stores     = new StoreFactory( $this->clock, new OptionRows( $this->wpdb ) );

		$this->orchestrator = new Orchestrator(
			$this->registry,
			new BatchRegistry(),
			$this->backend,
			$guard,
			$stores,
			$this->logger,
			$this->clock,
			new LockWindows( $this->clock ),
			new TerminalTransitions( $guard, $stores, $this->clock, $this->logger ),
			$this->randomizer,
		);
	}

	// endregion.

	// region TESTS.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Signatures and providers carry test parameter types.

	/**
	 * Hook registration exposes every internal lifecycle action through the orchestrator.
	 *
	 * @return  void
	 */
	public function test_register_hooks_wires_the_internal_lifecycle_actions(): void {
		$this->orchestrator->register_hooks();

		self::assertSame(
			array(
				array(
					'hook_name'     => 'a8csp/background_tasks/start',
					'callback'      => array( $this->orchestrator, 'handle_start_action' ),
					'priority'      => 10,
					'accepted_args' => 3,
				),
				array(
					'hook_name'     => 'a8csp/background_tasks/continue',
					'callback'      => array( $this->orchestrator, 'handle_continue_action' ),
					'priority'      => 10,
					'accepted_args' => 3,
				),
				array(
					'hook_name'     => 'a8csp/background_tasks/run',
					'callback'      => array( $this->orchestrator, 'handle_run_action' ),
					'priority'      => 10,
					'accepted_args' => 4,
				),
				array(
					'hook_name'     => 'a8csp/background_tasks/cleanup',
					'callback'      => array( $this->orchestrator, 'handle_cleanup_action' ),
					'priority'      => 10,
					'accepted_args' => 3,
				),
			),
			$this->action_registrations()
		);
	}

	/**
	 * A fresh enqueue persists the run, records fencing and history, fires hooks, and queues one action.
	 *
	 * @return  void
	 */
	public function test_enqueue_creates_and_dispatches_a_running_task(): void {
		$result = $this->orchestrator->enqueue( self::NAME, self::ARGS, priority: 23 );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		self::assertSame(
			array(
				array(
					'min' => 0,
					'max' => \PHP_INT_MAX,
				),
			),
			$this->randomizer->calls
		);
		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'a8csp/background_tasks/run',
						'args'     => array( self::NAME, self::RUN_ID, 1 ),
						'group'    => self::NAME . '|' . self::RUN_ID,
						'unique'   => false,
						'priority' => 23,
					),
				),
			),
			$this->backend->calls
		);
		self::assertSame(
			array(
				'status'        => 'running',
				'start_args'    => self::ARGS,
				'args_hash'     => self::ARGS_HASH,
				'queue'         => array( self::ARGS ),
				'chunk_retries' => 0,
				'action_seq'    => 1,
				'created_at'    => self::NOW,
				'heartbeat_at'  => self::NOW,
			),
			$this->option( $this->run_option_name() )
		);
		self::assertSame(
			array(
				'all'     => self::RUN_ID,
				'by_hash' => array( self::ARGS_HASH => self::RUN_ID ),
			),
			$this->option( 'a8csp_bgte_latest_' . self::NAME )
		);
		self::assertSame(
			array(
				'started'   => array( self::RUN_ID ),
				'completed' => array(),
				'by_hash'   => array(
					self::ARGS_HASH => array(
						'started'   => array( self::RUN_ID ),
						'completed' => array(),
					),
				),
			),
			$this->option( 'a8csp_bgte_history_' . self::NAME )
		);
		self::assertSame(
			array(
				'run_id'       => self::RUN_ID,
				'claimed_at'   => self::NOW,
				'heartbeat_at' => self::NOW,
			),
			$this->lock()
		);
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp/background_tasks/started/' . self::NAME,
					'args'      => array( self::RUN_ID, self::ARGS ),
				),
				array(
					'hook_name' => 'a8csp/background_tasks/started',
					'args'      => array( self::NAME, self::RUN_ID, self::ARGS ),
				),
			),
			$this->fired_actions()
		);
	}

	/**
	 * A throwing task started listener fails and cleans the already-scheduled run.
	 *
	 * @return  void
	 */
	public function test_enqueue_terminalizes_when_a_task_started_listener_throws(): void {
		$GLOBALS['a8csp_bgte_test_action_throwables'] = array(
			'a8csp/background_tasks/started/' . self::NAME => new \RuntimeException(
				'Started listener exploded.'
			),
		);

		$result = $this->orchestrator->enqueue( self::NAME, self::ARGS );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Task "email-digest" started listener failed: Started listener exploded. Fix the started-hook listener before enqueueing the task again.',
			$result->error->message
		);
		self::assertCount( 1, $this->backend->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		$failed_runs = $this->option( 'a8csp_bgte_failed_' . self::NAME );
		self::assertIsArray( $failed_runs );
		$failed_run = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		$stored_error = $failed_run['error'] ?? null;
		self::assertIsArray( $stored_error );
		self::assertSame( $result->error->message, $stored_error['message'] ?? null );
		self::assertSame(
			array(
				'a8csp/background_tasks/started/' . self::NAME,
				'a8csp/background_tasks/started',
				'a8csp/background_tasks/failed/' . self::NAME,
				'a8csp/background_tasks/failed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
	}

	/**
	 * Real lock outcomes pin the default, filtered, and continue-delay-floored windows.
	 *
	 * @return  void
	 */
	#[DataProvider( 'lock_window_boundaries' )]
	public function test_enqueue_resolves_the_exact_lock_staleness_window(
		?int $staleness_filter,
		?int $continue_filter,
		int $heartbeat_age,
		bool $is_reclaimed
	): void {
		if ( null !== $staleness_filter ) {
			$this->set_filter_value(
				'a8csp/background_tasks/lock_staleness/' . self::NAME,
				$staleness_filter
			);
		}
		if ( null !== $continue_filter ) {
			$this->set_filter_value( 'a8csp/background_tasks/continue_delay', $continue_filter );
		}

		$this->seed_running_lock( $heartbeat_age );

		$result = $this->orchestrator->enqueue( self::NAME, self::ARGS, unique: true );

		if ( $is_reclaimed ) {
			self::assertInstanceOf( Success::class, $result );
			self::assertSame( self::RUN_ID, $result->value );
			self::assertSame( true, $this->backend->calls[0]['args']['unique'] );
			return;
		}

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Task "email-digest" is already running as run "run-running"; wait for that run to finish before dispatching the same arguments.',
			$result->error->message
		);
		self::assertSame( array(), $this->backend->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
	}

	/**
	 * The name-specific lock-staleness filter receives its complete documented payload.
	 *
	 * @return  void
	 */
	public function test_enqueue_passes_all_documented_arguments_to_the_lock_staleness_filter(): void {
		$filter_args = null;
		$this->set_filter_value(
			'a8csp/background_tasks/lock_staleness/' . self::NAME,
			static function ( int $default_staleness ) use ( &$filter_args ): int {
				$filter_args = array(
					'arity' => \func_num_args(),
					'args'  => \func_get_args(),
				);

				return $default_staleness;
			}
		);

		$result = $this->orchestrator->enqueue( self::NAME, self::ARGS );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame(
			array(
				'arity' => 1,
				'args'  => array( 15 * \MINUTE_IN_SECONDS ),
			),
			$filter_args
		);
	}

	/**
	 * Supplies fresh and stale edges for all three staleness-resolution paths.
	 *
	 * @return  array<string, array{staleness_filter: int|null, continue_filter: int|null, heartbeat_age: int, is_reclaimed: bool}>
	 */
	public static function lock_window_boundaries(): array {
		return array(
			'default boundary remains fresh'      => array(
				'staleness_filter' => null,
				'continue_filter'  => null,
				'heartbeat_age'    => 900,
				'is_reclaimed'     => false,
			),
			'default boundary plus one is stale'  => array(
				'staleness_filter' => null,
				'continue_filter'  => null,
				'heartbeat_age'    => 901,
				'is_reclaimed'     => true,
			),
			'filtered boundary remains fresh'     => array(
				'staleness_filter' => 300,
				'continue_filter'  => null,
				'heartbeat_age'    => 300,
				'is_reclaimed'     => false,
			),
			'filtered boundary plus one is stale' => array(
				'staleness_filter' => 300,
				'continue_filter'  => null,
				'heartbeat_age'    => 301,
				'is_reclaimed'     => true,
			),
			'floor boundary remains fresh'        => array(
				'staleness_filter' => 1,
				'continue_filter'  => 75,
				'heartbeat_age'    => 150,
				'is_reclaimed'     => false,
			),
			'floor boundary plus one is stale'    => array(
				'staleness_filter' => 1,
				'continue_filter'  => 75,
				'heartbeat_age'    => 151,
				'is_reclaimed'     => true,
			),
			'zero-delay filtered fresh edge'      => array(
				'staleness_filter' => 1,
				'continue_filter'  => 0,
				'heartbeat_age'    => 1,
				'is_reclaimed'     => false,
			),
			'zero-delay filtered stale edge'      => array(
				'staleness_filter' => 1,
				'continue_filter'  => 0,
				'heartbeat_age'    => 2,
				'is_reclaimed'     => true,
			),
			'zero-delay default fresh edge'       => array(
				'staleness_filter' => null,
				'continue_filter'  => 0,
				'heartbeat_age'    => 900,
				'is_reclaimed'     => false,
			),
			'zero-delay default stale edge'       => array(
				'staleness_filter' => null,
				'continue_filter'  => 0,
				'heartbeat_age'    => 901,
				'is_reclaimed'     => true,
			),
		);
	}

	/**
	 * Positive delay selects single scheduling at the clock-relative timestamp.
	 *
	 * @return  void
	 */
	public function test_enqueue_with_delay_routes_to_single_scheduling(): void {
		$result = $this->orchestrator->enqueue( self::NAME, self::ARGS, delay: 120, unique: true, priority: 31 );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame(
			array(
				array(
					'verb' => 'schedule_single',
					'args' => array(
						'hook'      => 'a8csp/background_tasks/run',
						'timestamp' => self::NOW + 120,
						'args'      => array( self::NAME, self::RUN_ID, 1 ),
						'group'     => self::NAME . '|' . self::RUN_ID,
						'priority'  => 31,
					),
				),
			),
			$this->backend->calls
		);
		$state = $this->option( $this->run_option_name() );
		self::assertIsArray( $state );
		self::assertSame( self::NOW + 120, $state['heartbeat_at'] ?? null );
		self::assertSame( self::NOW + 120, $this->lock()['heartbeat_at'] ?? null );
	}

	/**
	 * A failed delayed heartbeat releases any lock still owned by the provisional run.
	 *
	 * @return  void
	 */
	public function test_enqueue_with_delay_releases_its_lock_when_heartbeat_fails(): void {
		$this->wpdb->script_result( 'update', false );

		$result = $this->orchestrator->enqueue( self::NAME, self::ARGS, delay: 120 );

		self::assertInstanceOf( Failure::class, $result );
		self::assertNull( $this->lock() );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertSame( array(), $this->backend->calls );
	}

	/**
	 * Unique async enqueue reaches the scheduling seam unchanged.
	 *
	 * @return  void
	 */
	public function test_enqueue_passes_unique_to_async_scheduling(): void {
		$result = $this->orchestrator->enqueue( self::NAME, self::ARGS, unique: true );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $this->backend->calls[0]['args']['unique'] );
	}

	/**
	 * An unknown task fails before clocks, randomness, persistence, locks, hooks, or scheduling.
	 *
	 * @return  void
	 */
	public function test_enqueue_rejects_an_unknown_task_without_touching_boundaries(): void {
		$result = $this->orchestrator->enqueue( 'unknown', self::ARGS );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Task "unknown" is not registered; register it before enqueueing.',
			$result->error->message
		);
		$this->assert_enqueue_boundaries_untouched();
	}

	/**
	 * Priority validation names the complete engine range before touching any boundary.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_priorities' )]
	public function test_enqueue_rejects_priority_outside_the_engine_range( int $priority ): void {
		$result = $this->orchestrator->enqueue( self::NAME, self::ARGS, priority: $priority );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			\sprintf(
				'Task "email-digest" priority %d is invalid; pass a value from 0 through 255.',
				$priority
			),
			$result->error->message
		);
		$this->assert_enqueue_boundaries_untouched();
	}

	/**
	 * Supplies values immediately outside both inclusive priority boundaries.
	 *
	 * @return  array<string, array{priority: int}>
	 */
	public static function invalid_priorities(): array {
		return array(
			'below minimum' => array( 'priority' => -1 ),
			'above maximum' => array( 'priority' => 256 ),
		);
	}

	/**
	 * A scheduling failure is returned unchanged after active run state is compensated.
	 *
	 * @return  void
	 */
	public function test_enqueue_surfaces_facade_failure_and_removes_active_state(): void {
		$failure = new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'Restore the scheduling backend before enqueueing the task.'
			)
		);

		$this->backend->results['enqueue_async'] = $failure;

		$result = $this->orchestrator->enqueue( self::NAME, self::ARGS );

		self::assertSame( $failure, $result );
		self::assertArrayNotHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->option( 'a8csp_bgte_history_' . self::NAME ) );
		self::assertSame( array(), $this->fired_actions() );
		self::assertSame(
			array(
				'all'     => self::RUN_ID,
				'by_hash' => array( self::ARGS_HASH => self::RUN_ID ),
			),
			$this->option( 'a8csp_bgte_latest_' . self::NAME )
		);
	}

	/**
	 * Enqueue rejects values that cannot remain portable through JSON and option storage.
	 *
	 * @return  void
	 */
	public function test_enqueue_rejects_non_scalar_argument_trees_before_claiming_a_lock(): void {
		$result = $this->orchestrator->enqueue(
			self::NAME,
			array(
				'callback' => static function (): void {},
			)
		);

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Task "email-digest" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.',
			$result->error->message
		);
		$this->assert_enqueue_boundaries_untouched();
	}

	/**
	 * Delay overflow fails before randomness, locking, persistence, hooks, or scheduling.
	 *
	 * @return  void
	 */
	public function test_enqueue_rejects_a_delay_that_overflows_unix_seconds(): void {
		$this->clock->timestamp = \PHP_INT_MAX - 5;

		$result = $this->orchestrator->enqueue( self::NAME, self::ARGS, delay: 10 );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Task "email-digest" delay 10 exceeds supported Unix seconds; pass a smaller delay.',
			$result->error->message
		);
		self::assertSame( 1, $this->clock->calls );
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->randomizer->calls );
		self::assertSame( array(), $this->wpdb->recorded_queries );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertSame( array(), $this->fired_actions() );
	}

	/**
	 * Enqueue declares its result non-discardable at the engine boundary.
	 *
	 * @return  void
	 */
	public function test_enqueue_declares_no_discard_directly(): void {
		$method = new \ReflectionMethod( Orchestrator::class, 'enqueue' );

		self::assertCount( 1, $method->getAttributes( \NoDiscard::class ) );
	}

	/**
	 * Manual retry enqueues a fresh task run and removes the consumed failed entry.
	 *
	 * @return  void
	 */
	public function test_retry_failed_reenqueues_a_task_and_removes_the_failed_entry(): void {
		$store = new FailedRunStore( self::NAME, new OptionRows( $this->wpdb ) );
		$store->record(
			'failed-run',
			self::NOW - 1,
			self::ARGS,
			2,
			new EngineError( 'Database unavailable.', \RuntimeException::class )
		);
		$this->backend->calls    = array();
		$this->randomizer->calls = array();
		$this->randomizer->value = 43;
		$this->clock->timestamp  = self::NOW + 100;
		$new_run_id              = '00000000001700000100-0000000000000000043';

		$result = $this->orchestrator->retry_failed( self::NAME, 'failed-run' );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $new_run_id, $result->value );
		self::assertSame( array(), $store->all() );
		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'a8csp/background_tasks/run',
						'args'     => array( self::NAME, $new_run_id, 1 ),
						'group'    => self::NAME . '|' . $new_run_id,
						'unique'   => false,
						'priority' => 10,
					),
				),
			),
			$this->backend->calls
		);
		$new_state = $this->option( 'a8csp_bgte_run_' . self::NAME . '_' . $new_run_id );
		self::assertIsArray( $new_state );
		self::assertSame( self::ARGS, $new_state['start_args'] ?? null );
		self::assertSame( 0, $new_state['chunk_retries'] ?? null );
	}

	/**
	 * Manual retry consumes the first retained entry when duplicates share a run identifier.
	 *
	 * @return  void
	 */
	public function test_retry_failed_uses_the_first_entry_matching_the_run_identifier(): void {
		$store = new FailedRunStore( self::NAME, new OptionRows( $this->wpdb ) );
		$store->record(
			'failed-run',
			self::NOW - 2,
			array( 'ordinal' => 'first' ),
			2,
			new EngineError( 'Database unavailable.', \RuntimeException::class )
		);
		$store->record(
			'failed-run',
			self::NOW - 1,
			array( 'ordinal' => 'second' ),
			2,
			new EngineError( 'Database unavailable.', \RuntimeException::class )
		);
		$this->backend->calls    = array();
		$this->randomizer->calls = array();
		$this->randomizer->value = 43;
		$this->clock->timestamp  = self::NOW + 100;
		$new_run_id              = '00000000001700000100-0000000000000000043';

		$result = $this->orchestrator->retry_failed( self::NAME, 'failed-run' );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $new_run_id, $result->value );
		$new_state = $this->option( 'a8csp_bgte_run_' . self::NAME . '_' . $new_run_id );
		self::assertIsArray( $new_state );
		self::assertSame( array( 'ordinal' => 'first' ), $new_state['start_args'] ?? null );
	}

	/**
	 * A missing failed entry names the retained run identifier that can be retried.
	 *
	 * @return  void
	 */
	public function test_retry_failed_rejects_a_missing_entry_and_names_what_exists(): void {
		$store = new FailedRunStore( self::NAME, new OptionRows( $this->wpdb ) );
		$store->record(
			'retained-run',
			self::NOW - 1,
			self::ARGS,
			2,
			new EngineError( 'Database unavailable.', \RuntimeException::class )
		);
		$this->backend->calls    = array();
		$this->randomizer->calls = array();

		$result = $this->orchestrator->retry_failed( self::NAME, 'missing-run' );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Failed run "missing-run" for background-work "email-digest" is not retained; retry one of the retained run identifiers: "retained-run".',
			$result->error->message
		);
		self::assertCount( 1, $store->all() );
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->randomizer->calls );
	}

	/**
	 * A delegated enqueue failure leaves the original failed task entry retryable.
	 *
	 * @return  void
	 */
	public function test_retry_failed_retains_the_task_entry_when_enqueue_fails(): void {
		$store = new FailedRunStore( self::NAME, new OptionRows( $this->wpdb ) );
		$store->record(
			'failed-run',
			self::NOW - 1,
			self::ARGS,
			2,
			new EngineError( 'Database unavailable.', \RuntimeException::class )
		);
		$expected_entries = $store->all();
		$failure          = new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'Restore the scheduler before retrying the task.'
			)
		);

		$this->backend->calls                    = array();
		$this->backend->results['enqueue_async'] = $failure;
		$this->randomizer->value                 = 43;
		$this->clock->timestamp                  = self::NOW + 100;

		$result = $this->orchestrator->retry_failed( self::NAME, 'failed-run' );

		self::assertSame( $failure, $result );
		self::assertSame( $expected_entries, $store->all() );
	}

	/**
	 * Run handling refreshes both heartbeats before task execution and completes in terminal order.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_executes_and_completes_the_task(): void {
		$this->prepare_run_action();
		$observed_lock = null;
		$observed_run  = null;

		$this->task->on_handle = function ( array $args ) use ( &$observed_lock, &$observed_run ): void {
			self::assertSame( self::ARGS, $args );
			$observed_lock = $this->lock();
			$observed_run  = $this->option( $this->run_option_name() );
		};

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertIsArray( $observed_lock );
		self::assertSame( self::NOW + 90, $observed_lock['heartbeat_at'] );
		self::assertIsArray( $observed_run );
		self::assertSame( 'running', $observed_run['status'] );
		self::assertSame( self::NOW + 90, $observed_run['heartbeat_at'] );
		self::assertArrayNotHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp/background_tasks/completed/' . self::NAME,
					'args'      => array( self::RUN_ID, self::ARGS ),
				),
				array(
					'hook_name' => 'a8csp/background_tasks/completed',
					'args'      => array( self::NAME, self::RUN_ID, self::ARGS ),
				),
			),
			$this->fired_actions()
		);
		self::assertSame(
			array(
				'lock:update',
				'run:running',
				'task:handle',
				'lock:update',
				'run:completed',
				'hook:completed/' . self::NAME,
				'hook:completed',
				'lock:delete',
				'run:delete',
				'history',
			),
			$this->lifecycle_labels()
		);
		$this->assert_terminal_history();
	}

	/** A terminal winner deleting the run during a live heartbeat CAS silences the stale delivery. */
	public function test_handle_run_action_live_state_cas_cannot_resurrect_a_terminally_deleted_run(): void {
		$this->prepare_run_action();
		$this->wpdb->before_next( 'update', static function (): void {} );
		$this->wpdb->before_next(
			'update',
			function (): void {
				$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );
			}
		);

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
		self::assertSame( array(), $this->logger->records );
		self::assertSame(
			array(
				'a8csp/background_tasks/completed/' . self::NAME,
				'a8csp/background_tasks/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_terminal_history();
	}

	/**
	 * A stale task delivery exits before heartbeats, callbacks, or state writes.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_drops_a_stale_sequence_before_every_side_effect(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::NAME, $this->clock, new OptionRows( $this->wpdb ) );
		$state     = $run_store->get( self::RUN_ID );
		self::assertNotNull( $state );
		self::assertIsString( $run_store->transition_state( self::RUN_ID, $state, $state->with_action_seq( 2 ) ) );
		$expected = $this->option( $this->run_option_name() );

		$GLOBALS['a8csp_bgte_test_option_calls']     = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events'] = array();
		$this->wpdb->recorded_queries                = array();

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, 1 );

		self::assertSame( array(), $this->task->calls );
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( $expected, $this->option( $this->run_option_name() ) );
		self::assertSame( array(), $this->wpdb->recorded_queries );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertSame(
			array(
				array(
					'level'   => 'info',
					'message' => 'Stale lifecycle action delivery dropped.',
					'context' => array(
						'expected' => 2,
						'received' => 1,
						'run_id'   => self::RUN_ID,
					),
				),
			),
			$this->logger->records
		);
	}

	/**
	 * An unregistered task action fails its live run instead of orphaning active state.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_terminalizes_a_live_unregistered_task(): void {
		$this->prepare_run_action();
		$action_seq   = $this->action_seq();
		$guard        = new OverlapGuard( $this->clock, $this->logger, new LockRows( $this->wpdb ) );
		$stores       = new StoreFactory( $this->clock, new OptionRows( $this->wpdb ) );
		$orchestrator = new Orchestrator(
			new TaskRegistry(),
			new BatchRegistry(),
			$this->backend,
			$guard,
			$stores,
			$this->logger,
			$this->clock,
			new LockWindows( $this->clock ),
			new TerminalTransitions( $guard, $stores, $this->clock, $this->logger ),
			$this->randomizer,
		);

		$orchestrator->handle_run_action( self::NAME, self::RUN_ID, $action_seq );

		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		$failed_runs = $this->option( 'a8csp_bgte_failed_' . self::NAME );
		self::assertIsArray( $failed_runs );
		$failed_run = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		$stored_error = $failed_run['error'] ?? null;
		self::assertIsArray( $stored_error );
		self::assertSame(
			'Task name "email-digest" is no longer registered unambiguously for run "00000000001700000000-0000000000000000042"; re-register exactly one task under that name or purge the run.',
			$stored_error['message'] ?? null
		);
		self::assertSame( 1, $failed_run['attempts'] ?? null );
		self::assertSame(
			array(
				'a8csp/background_tasks/failed/' . self::NAME,
				'a8csp/background_tasks/failed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Task run action references an unregistered task; register the task before dispatching its run action.',
					'context' => array(
						'task_name' => self::NAME,
						'run_id'    => self::RUN_ID,
					),
				),
			),
			$this->logger->records
		);
	}

	/**
	 * Ownership loss during task work supersedes the incumbent without touching the replacement.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_after_task_work_loses_ownership(): void {
		$this->prepare_run_action();
		$observed_state        = null;
		$this->task->on_handle = function ( array $args ) use ( &$observed_state ): void {
			$observed_state = $this->option( $this->run_option_name() );
			( new LatestRunPointer( self::NAME ) )->record( 'run-newer', self::ARGS_HASH );
			$this->replace_lock_owner( 'run-newer', self::NOW + 90 );
		};

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertIsArray( $observed_state );
		self::assertSame( 'running', $observed_state['status'] ?? null );
		self::assertSame( self::NOW + 90, $observed_state['heartbeat_at'] ?? null );
		self::assertSame( array(), $this->backend->calls );
		$this->assert_post_callback_superseded_task();
	}

	/**
	 * A throwing task that loses ownership supersedes before entering the retry ladder.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_when_throwing_task_loses_ownership(): void {
		$this->task->throwable = new \RuntimeException( 'Task exploded.' );
		$this->prepare_run_action();
		$this->task->on_handle = function ( array $args ): void {
			( new LatestRunPointer( self::NAME ) )->record( 'run-newer', self::ARGS_HASH );
			$this->replace_lock_owner( 'run-newer', self::NOW + 90 );
		};

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertSame( array(), $this->backend->calls );
		$this->assert_post_callback_superseded_task();
	}

	/**
	 * Ownership loss in the retry-policy filter supersedes before applying the terminal cap.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_before_retry_policy_cap_failure_after_ownership_loss(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Transient failure.' );
		$this->prepare_run_action();
		$this->set_filter_value(
			'a8csp/background_tasks/retry_policy/' . self::NAME,
			function ( RetryPolicy $policy ): RetryPolicy {
				( new LatestRunPointer( self::NAME ) )->record( 'run-newer', self::ARGS_HASH );
				$this->replace_lock_owner( 'run-newer', self::NOW + 90 );

				return new RetryPolicy( max_attempts: 1 );
			}
		);
		$this->randomizer->value = 7;
		$this->randomizer->calls = array();

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->randomizer->calls );
		$this->assert_post_callback_superseded_task();
	}

	/**
	 * Ownership loss in retrying listeners supersedes before the retry action is scheduled.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_before_retry_schedule_after_retrying_listener_ownership_loss(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Transient failure.' );
		$this->prepare_run_action();
		$this->randomizer->value = 7;
		$this->randomizer->calls = array();
		$this->set_filter_value(
			'a8csp/background_tasks/retry_policy/' . self::NAME,
			function ( RetryPolicy $policy ): RetryPolicy {
				for ( $index = 0; 3 > $index; ++$index ) {
					$this->wpdb->before_next( 'select', static function ( WpdbLockSpy $lock_spy ): void {} );
				}
				$this->wpdb->before_next(
					'select',
					function ( WpdbLockSpy $lock_spy ): void {
						( new LatestRunPointer( self::NAME ) )->record( 'run-newer', self::ARGS_HASH );
						$this->replace_lock_owner( 'run-newer', self::NOW + 90 );
					}
				);

				return $policy;
			}
		);

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array(), $this->backend->calls );
		self::assertSame(
			array(
				'a8csp/background_tasks/retrying/' . self::NAME,
				'a8csp/background_tasks/retrying',
				'a8csp/background_tasks/superseded/' . self::NAME,
				'a8csp/background_tasks/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_post_callback_superseded_task();
	}

	/**
	 * An ordinary throwable below the cap persists retry state and reschedules the same run.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_reschedules_an_ordinary_failure_below_the_cap(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->prepare_run_action();
		$this->randomizer->value = 17;
		$this->randomizer->calls = array();

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		$state = $this->option( $this->run_option_name() );
		self::assertIsArray( $state );
		self::assertSame( 'running', $state['status'] ?? null );
		self::assertSame( 1, $state['chunk_retries'] ?? null );
		self::assertSame( 2, $state['action_seq'] ?? null );
		self::assertSame( self::NOW + 107, $state['heartbeat_at'] ?? null );
		self::assertSame( self::NOW + 107, $this->lock()['heartbeat_at'] ?? null );
		self::assertSame(
			array(
				array(
					'verb' => 'schedule_single',
					'args' => array(
						'hook'      => 'a8csp/background_tasks/run',
						'timestamp' => self::NOW + 107,
						'args'      => array( self::NAME, self::RUN_ID, 2 ),
						'group'     => self::NAME . '|' . self::RUN_ID,
						'priority'  => 10,
					),
				),
			),
			$this->backend->calls
		);
		self::assertSame(
			array(
				array(
					'min' => 0,
					'max' => 30,
				),
			),
			$this->randomizer->calls
		);
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp/background_tasks/retrying/' . self::NAME,
					'args'      => array( self::RUN_ID, self::ARGS, 1, 17 ),
				),
				array(
					'hook_name' => 'a8csp/background_tasks/retrying',
					'args'      => array( self::NAME, self::RUN_ID, self::ARGS, 1, 17 ),
				),
			),
			$this->fired_actions()
		);
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
	}

	/**
	 * A two-attempt policy executes exactly twice and records the exhausted cap.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_stops_exactly_at_the_max_attempts_boundary(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->prepare_run_action();
		$this->randomizer->value = 5;
		$this->randomizer->calls = array();

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );
		$this->clock->timestamp = self::NOW + 95;
		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS, self::ARGS ), $this->task->calls );
		self::assertCount( 1, $this->backend->calls );
		self::assertSame( 'schedule_single', $this->backend->calls[0]['verb'] );
		self::assertSame(
			array(
				array(
					'min' => 0,
					'max' => 30,
				),
			),
			$this->randomizer->calls
		);
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		$failed_runs = $this->option( 'a8csp_bgte_failed_' . self::NAME );
		self::assertIsArray( $failed_runs );
		$failed_run = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		self::assertSame( 2, $failed_run['attempts'] ?? null );
		self::assertSame( self::NOW + 95, $failed_run['failed_at'] ?? null );
	}

	/**
	 * A successful retry clears the invocation's failed-attempt counter before completion.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_resets_the_counter_after_a_successful_retry(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Transient failure.' );
		$this->prepare_run_action();
		$this->randomizer->value = 5;
		$this->randomizer->calls = array();

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );
		$this->task->throwable  = null;
		$this->clock->timestamp = self::NOW + 95;
		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( 0, $this->recorded_run_state( 'completed' )['chunk_retries'] );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
	}

	/**
	 * A name-specific RetryPolicy replacement controls the cap for that task.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_honors_the_name_specific_retry_policy_filter(): void {
		$contract_policy = new RetryPolicy( max_attempts: 3 );

		$this->task->retry_policy = $contract_policy;
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );

		$filter_args = null;
		$this->set_filter_value(
			'a8csp/background_tasks/retry_policy/' . self::NAME,
			static function ( RetryPolicy $policy ) use ( &$filter_args ): RetryPolicy {
				$filter_args = array(
					'arity' => \func_num_args(),
					'args'  => \func_get_args(),
				);

				return new RetryPolicy( max_attempts: 1 );
			}
		);
		$this->prepare_run_action();

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame(
			array(
				'arity' => 1,
				'args'  => array( $contract_policy ),
			),
			$filter_args
		);
		self::assertSame( array(), $this->backend->calls );
		$failed_runs = $this->option( 'a8csp_bgte_failed_' . self::NAME );
		self::assertIsArray( $failed_runs );
		$failed_run = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		self::assertSame( 1, $failed_run['attempts'] ?? null );
	}

	/**
	 * A foreign policy-filter return falls back to the contract policy and names the correction.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_falls_back_and_warns_for_a_foreign_retry_policy(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->set_filter_value( 'a8csp/background_tasks/retry_policy/' . self::NAME, 'invalid-policy' );
		$this->prepare_run_action();
		$this->randomizer->value = 7;
		$this->randomizer->calls = array();

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertCount( 1, $this->backend->calls );
		self::assertSame( self::NOW + 97, $this->backend->calls[0]['args']['timestamp'] ?? null );
		self::assertSame(
			array(
				array(
					'min' => 0,
					'max' => 30,
				),
			),
			$this->randomizer->calls
		);
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Retry policy filter returned an invalid value; return a RetryPolicy instance to override the contract policy.',
					'context' => array(
						'name'          => self::NAME,
						'returned_type' => 'string',
					),
				),
			),
			$this->logger->records
		);
	}

	/**
	 * A throwing retry-policy filter terminalizes the run instead of leaving it stalled.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_terminalizes_a_throwing_retry_policy_filter(): void {
		$this->task->retry_policy = new RetryPolicy( max_attempts: 2 );
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->set_filter_value(
			'a8csp/background_tasks/retry_policy/' . self::NAME,
			static function ( RetryPolicy $policy ): RetryPolicy {
				throw new \DomainException( 'Retry policy filter exploded.' );
			}
		);
		$this->prepare_run_action();
		$this->randomizer->calls = array();

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->randomizer->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		$failed_runs = $this->option( 'a8csp_bgte_failed_' . self::NAME );
		self::assertIsArray( $failed_runs );
		$failed_run = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		self::assertSame( 1, $failed_run['attempts'] ?? null );
		$stored_error = $failed_run['error'] ?? null;
		self::assertIsArray( $stored_error );
		self::assertSame( \DomainException::class, $stored_error['class'] ?? null );
		self::assertSame(
			'Task "email-digest" could not resolve the retry policy: Retry policy filter exploded. Fix the retry policy provider or filter before retrying the failed run manually.',
			$stored_error['message'] ?? null
		);
		self::assertSame(
			array(
				'a8csp/background_tasks/failed/' . self::NAME,
				'a8csp/background_tasks/failed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
	}

	/**
	 * A throwing retry-policy filter that loses ownership supersedes instead of recording failure.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_when_throwing_retry_policy_filter_loses_ownership(): void {
		$this->task->retry_policy = new RetryPolicy( max_attempts: 2 );
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->set_filter_value(
			'a8csp/background_tasks/retry_policy/' . self::NAME,
			function ( RetryPolicy $policy ): RetryPolicy {
				( new LatestRunPointer( self::NAME ) )->record( 'run-newer', self::ARGS_HASH );
				$this->replace_lock_owner( 'run-newer', self::NOW + 90 );

				throw new \DomainException( 'Retry policy filter exploded.' );
			}
		);
		$this->prepare_run_action();
		$this->randomizer->calls = array();

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->randomizer->calls );
		$this->assert_post_callback_superseded_task();
	}

	/**
	 * A throwing retrying listener terminalizes after both retrying hooks without scheduling.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_terminalizes_a_throwing_retrying_listener(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->prepare_run_action();
		$this->randomizer->value = 7;
		$this->randomizer->calls = array();

		$GLOBALS['a8csp_bgte_test_action_throwables'] = array(
			'a8csp/background_tasks/retrying/' . self::NAME => new \RuntimeException(
				'Retrying listener exploded.'
			),
		);

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array(), $this->backend->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		$failed_runs = $this->option( 'a8csp_bgte_failed_' . self::NAME );
		self::assertIsArray( $failed_runs );
		$failed_run = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		self::assertSame( 1, $failed_run['attempts'] ?? null );
		$stored_error = $failed_run['error'] ?? null;
		self::assertIsArray( $stored_error );
		self::assertSame( \RuntimeException::class, $stored_error['class'] ?? null );
		self::assertSame(
			'Task "email-digest" could not prepare the retry action: Retrying listener exploded. Fix the retry policy, randomness source, retrying hook, or scheduler before retrying the failed run manually.',
			$stored_error['message'] ?? null
		);
		self::assertSame(
			array(
				'a8csp/background_tasks/retrying/' . self::NAME,
				'a8csp/background_tasks/retrying',
				'a8csp/background_tasks/failed/' . self::NAME,
				'a8csp/background_tasks/failed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
	}

	/**
	 * Ownership loss after a retrying-listener error supersedes before terminal failure is recorded.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_after_retry_preparation_error_loses_ownership(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->prepare_run_action();
		$this->randomizer->value = 7;
		$this->randomizer->calls = array();
		$this->set_filter_value(
			'a8csp/background_tasks/retry_policy/' . self::NAME,
			function ( RetryPolicy $policy ): RetryPolicy {
				for ( $index = 0; 3 > $index; ++$index ) {
					$this->wpdb->before_next( 'select', static function ( WpdbLockSpy $lock_spy ): void {} );
				}
				$this->wpdb->before_next(
					'select',
					function ( WpdbLockSpy $lock_spy ): void {
						( new LatestRunPointer( self::NAME ) )->record( 'run-newer', self::ARGS_HASH );
						$this->replace_lock_owner( 'run-newer', self::NOW + 90 );
					}
				);

				return $policy;
			}
		);
		$GLOBALS['a8csp_bgte_test_action_throwables'] = array(
			'a8csp/background_tasks/retrying/' . self::NAME => new \RuntimeException(
				'Retrying listener exploded.'
			),
		);

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array(), $this->backend->calls );
		self::assertSame(
			array(
				'a8csp/background_tasks/retrying/' . self::NAME,
				'a8csp/background_tasks/retrying',
				'a8csp/background_tasks/superseded/' . self::NAME,
				'a8csp/background_tasks/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_post_callback_superseded_task();
	}

	/**
	 * A retry scheduling failure terminalizes the run and identifies the failed stage.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_terminalizes_a_retry_reschedule_failure(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->prepare_run_action();
		$this->randomizer->value = 7;
		$this->randomizer->calls = array();

		$this->backend->results['schedule_single'] = new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'Restore the scheduler before retrying the task.'
			)
		);

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		$failed_runs = $this->option( 'a8csp_bgte_failed_' . self::NAME );
		self::assertIsArray( $failed_runs );
		$failed_run = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		self::assertSame( 1, $failed_run['attempts'] ?? null );
		$stored_error = $failed_run['error'] ?? null;
		self::assertIsArray( $stored_error );
		self::assertSame(
			'Task "email-digest" could not schedule the retry action: Restore the scheduler before retrying the task.',
			$stored_error['message'] ?? null
		);
		self::assertSame(
			array(
				'a8csp/background_tasks/retrying/' . self::NAME,
				'a8csp/background_tasks/retrying',
				'a8csp/background_tasks/failed/' . self::NAME,
				'a8csp/background_tasks/failed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
	}

	/**
	 * A one-attempt ordinary policy enters the existing terminal failure path.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_fails_terminally_when_the_policy_has_no_retry(): void {
		$this->task->retry_policy = new RetryPolicy( max_attempts: 1 );

		$this->assert_terminal_task_failure( new \RuntimeException( 'Database unavailable.' ) );
	}

	/**
	 * A non-retryable throwable enters the same immediate terminal failure path.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_fails_terminally_for_a_non_retryable_exception(): void {
		$this->assert_terminal_task_failure( new NonRetryableTaskException( 'The request is permanently invalid.' ) );
	}

	/**
	 * A retry action that loses replacement-lock ownership exits as Superseded before re-execution.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_before_a_scheduled_retry_executes(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Transient failure.' );
		$this->prepare_run_action();
		$this->randomizer->value = 5;
		$this->randomizer->calls = array();
		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );
		( new LatestRunPointer( self::NAME ) )->record( 'run-newer', self::ARGS_HASH );
		$this->replace_lock_owner( 'run-newer', self::NOW + 95 );
		$this->backend->calls = array();

		$GLOBALS['a8csp_bgte_test_fired_actions'] = array();

		$this->clock->timestamp = self::NOW + 95;
		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertSame( array(), $this->backend->calls );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		self::assertSame(
			array(
				'a8csp/background_tasks/superseded/' . self::NAME,
				'a8csp/background_tasks/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertSame(
			array(
				array(
					'level'   => 'info',
					'message' => 'Superseded task run after its ownership fence failed.',
					'context' => array(
						'task_name'     => self::NAME,
						'run_id'        => self::RUN_ID,
						'latest_run_id' => 'run-newer',
					),
				),
			),
			$this->logger->records
		);
	}

	/**
	 * Moved replacement ownership fences the run before task execution and uses quiet cleanup.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_a_run_that_lost_replacement_ownership(): void {
		$this->prepare_run_action();
		( new LatestRunPointer( self::NAME ) )->record( 'run-newer', self::ARGS_HASH );
		$this->replace_lock_owner( 'run-newer', self::NOW + 90 );
		$GLOBALS['a8csp_bgte_test_option_calls']     = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events'] = array();

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array(), $this->task->calls );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp/background_tasks/superseded/' . self::NAME,
					'args'      => array( self::RUN_ID, self::ARGS ),
				),
				array(
					'hook_name' => 'a8csp/background_tasks/superseded',
					'args'      => array( self::NAME, self::RUN_ID, self::ARGS ),
				),
			),
			$this->fired_actions()
		);
		self::assertSame(
			array(
				array(
					'level'   => 'info',
					'message' => 'Superseded task run after its ownership fence failed.',
					'context' => array(
						'task_name'     => self::NAME,
						'run_id'        => self::RUN_ID,
						'latest_run_id' => 'run-newer',
					),
				),
			),
			$this->logger->records
		);
		self::assertSame(
			array(
				'run:superseded',
				'hook:superseded/' . self::NAME,
				'hook:superseded',
				'run:delete',
				'history',
			),
			$this->lifecycle_labels()
		);
		$this->assert_terminal_history();
	}

	/**
	 * The lock winner repairs a pointer overwritten by a losing concurrent starter and still executes.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_keeps_the_lock_winner_when_pointer_commit_lags(): void {
		$this->prepare_run_action();
		( new LatestRunPointer( self::NAME ) )->record( 'run-losing-starter', self::ARGS_HASH );

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertSame(
			array(
				'a8csp/background_tasks/completed/' . self::NAME,
				'a8csp/background_tasks/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertSame(
			array(
				'all'     => self::RUN_ID,
				'by_hash' => array( self::ARGS_HASH => self::RUN_ID ),
			),
			$this->option( 'a8csp_bgte_latest_' . self::NAME )
		);
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		self::assertSame( array(), $this->logger->records );
		$this->assert_terminal_history();
	}

	/**
	 * Confirmed lock ownership keeps a valid run executable after its bounded pointer is evicted.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_does_not_supersede_an_owned_run_after_pointer_eviction(): void {
		$run_ids = array();
		for ( $index = 0; 21 > $index; ++$index ) {
			$this->randomizer->value = 100 + $index;

			$result = $this->orchestrator->enqueue( self::NAME, array( 'identity' => $index ) );
			self::assertInstanceOf( Success::class, $result );
			$run_id = $result->value;
			self::assertIsString( $run_id );
			$run_ids[] = $run_id;
		}

		$first_run_id = $run_ids[0];

		$this->clock->timestamp = self::NOW + 90;
		$this->task->calls      = array();
		$this->logger->records  = array();

		$GLOBALS['a8csp_bgte_test_fired_actions']    = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events'] = array();

		$this->orchestrator->handle_run_action( self::NAME, $first_run_id, $this->action_seq( $first_run_id ) );

		self::assertSame( array( array( 'identity' => 0 ) ), $this->task->calls );
		self::assertSame(
			array(
				'a8csp/background_tasks/completed/' . self::NAME,
				'a8csp/background_tasks/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertSame(
			$run_ids[20],
			( new LatestRunPointer( self::NAME ) )->get_latest(),
			'Repairing the evicted owner identity must preserve the globally newest run'
		);
	}

	/**
	 * Losing lock ownership fences a run even while its latest pointer has not moved.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_after_lock_ownership_is_lost(): void {
		$this->prepare_run_action();
		$foreign_lock = \maybe_serialize(
			array(
				'run_id'       => 'run-newer',
				'claimed_at'   => self::NOW + 90,
				'heartbeat_at' => self::NOW + 90,
			)
		);
		self::assertIsString( $foreign_lock );
		$this->wpdb->put( $this->lock_option_name(), $foreign_lock );

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array(), $this->task->calls );
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		self::assertSame(
			array(
				'a8csp/background_tasks/superseded/' . self::NAME,
				'a8csp/background_tasks/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertNull( $this->option( $this->run_option_name() ) );
		$this->assert_terminal_history();
	}

	/**
	 * A persisted terminal state never re-enters task execution before reconciliation.
	 *
	 * @return  void
	 */
	#[DataProvider( 'terminal_statuses' )]
	public function test_handle_run_action_does_not_execute_a_persisted_terminal_state( string $status ): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::NAME, $this->clock, new OptionRows( $this->wpdb ) );
		$state     = $run_store->get( self::RUN_ID );
		self::assertNotNull( $state );
		self::assertIsString(
			$run_store->transition_state( self::RUN_ID, $state, $state->with_status( RunStatus::from( $status ) ) )
		);

		$this->logger->records = array();

		$GLOBALS['a8csp_bgte_test_fired_actions']    = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events'] = array();

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array(), $this->task->calls );
		self::assertSame( array(), $this->fired_actions() );
		self::assertSame( array(), $this->lifecycle_labels() );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Task run is already terminal; allow the reconciliation sweep to finish its cleanup.',
					'context' => array(
						'task_name' => self::NAME,
						'run_id'    => self::RUN_ID,
						'status'    => $status,
					),
				),
			),
			$this->logger->records
		);
	}

	/**
	 * Supplies every terminal state accepted by persisted run data.
	 *
	 * @return  array<string, array{status: string}>
	 */
	public static function terminal_statuses(): array {
		return array(
			'completed'  => array( 'status' => 'completed' ),
			'failed'     => array( 'status' => 'failed' ),
			'stopped'    => array( 'status' => 'stopped' ),
			'superseded' => array( 'status' => 'superseded' ),
		);
	}

	/**
	 * A missing or corrupt run logs reconciliation guidance without creating another transition.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_warns_and_returns_when_run_state_is_missing(): void {
		$GLOBALS['a8csp_bgte_test_lifecycle_events'] = array();

		$this->orchestrator->handle_run_action( self::NAME, 'missing-run', 1 );

		self::assertSame( array(), $this->task->calls );
		self::assertSame( array(), $this->fired_actions() );
		self::assertSame( array(), $this->lifecycle_labels() );
		self::assertSame( array(), $this->wpdb->recorded_queries );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Task run state is missing or corrupt; allow the reconciliation sweep to release any remaining lock.',
					'context' => array(
						'task_name' => self::NAME,
						'run_id'    => 'missing-run',
					),
				),
			),
			$this->logger->records
		);
	}

	// phpcs:enable Squiz.Commenting.FunctionComment.MissingParamTag
	// endregion.

	// region HELPERS.

	/**
	 * Returns the internal run option name for the deterministic enqueue.
	 *
	 * @return  string
	 */
	private function run_option_name(): string {
		return 'a8csp_bgte_run_' . self::NAME . '_' . self::RUN_ID;
	}

	/**
	 * Returns the newest scheduled lifecycle action sequence for one live run.
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @return  int
	 */
	private function action_seq( string $run_id = self::RUN_ID ): int {
		$state = $this->option( 'a8csp_bgte_run_' . self::NAME . '_' . $run_id );
		self::assertIsArray( $state );
		$action_seq = $state['action_seq'] ?? null;
		self::assertIsInt( $action_seq );

		return $action_seq;
	}

	/**
	 * Enqueues the deterministic run and clears enqueue observations before action handling.
	 *
	 * @return  void
	 */
	private function prepare_run_action(): void {
		$result = $this->orchestrator->enqueue( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );

		$this->clock->timestamp       = self::NOW + 90;
		$this->backend->calls         = array();
		$this->logger->records        = array();
		$this->wpdb->recorded_queries = array();

		$GLOBALS['a8csp_bgte_test_fired_actions']    = array();
		$GLOBALS['a8csp_bgte_test_option_calls']     = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events'] = array();
	}

	/**
	 * Asserts one throwable's failed-store entry, hooks, cleanup, and global transition order.
	 *
	 * @param   \Throwable $throwable Task failure.
	 *
	 * @return  void
	 */
	private function assert_terminal_task_failure( \Throwable $throwable ): void {
		$this->task->throwable = $throwable;
		$this->prepare_run_action();
		$this->randomizer->calls = array();

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->randomizer->calls );
		self::assertArrayNotHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertSame(
			array(
				array(
					'run_id'     => self::RUN_ID,
					'failed_at'  => self::NOW + 90,
					'start_args' => self::ARGS,
					'attempts'   => 1,
					'error'      => array(
						'class'   => $throwable::class,
						'message' => $throwable->getMessage(),
					),
				),
			),
			$this->option( 'a8csp_bgte_failed_' . self::NAME )
		);

		$actions = $this->fired_actions();
		self::assertCount( 2, $actions );
		self::assertSame( 'a8csp/background_tasks/failed/' . self::NAME, $actions[0]['hook_name'] );
		self::assertSame( self::RUN_ID, $actions[0]['args'][0] );
		self::assertSame( self::ARGS, $actions[0]['args'][1] );
		self::assertInstanceOf( EngineError::class, $actions[0]['args'][2] );
		self::assertSame( $throwable->getMessage(), $actions[0]['args'][2]->message );
		self::assertSame( $throwable::class, $actions[0]['args'][2]->exception_class );
		self::assertSame( 'a8csp/background_tasks/failed', $actions[1]['hook_name'] );
		self::assertSame(
			array( self::NAME, self::RUN_ID, self::ARGS, $actions[0]['args'][2] ),
			$actions[1]['args']
		);
		self::assertSame(
			array(
				'lock:update',
				'run:running',
				'task:handle',
				'lock:update',
				...( $throwable instanceof NonRetryableTaskException ? array() : array( 'lock:update' ) ),
				'run:failed',
				'failed-store',
				'hook:failed/' . self::NAME,
				'hook:failed',
				'lock:delete',
				'run:delete',
				'history',
			),
			$this->lifecycle_labels()
		);
		$this->assert_terminal_history();
	}

	/**
	 * Asserts a post-callback fence loss terminalizes only the incumbent as Superseded.
	 *
	 * @return  void
	 */
	private function assert_post_callback_superseded_task(): void {
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp/background_tasks/superseded/' . self::NAME,
					'args'      => array( self::RUN_ID, self::ARGS ),
				),
				array(
					'hook_name' => 'a8csp/background_tasks/superseded',
					'args'      => array( self::NAME, self::RUN_ID, self::ARGS ),
				),
			),
			\array_slice( $this->fired_actions(), -2 )
		);
		$this->assert_terminal_history();
	}

	/**
	 * Asserts that the existing completed buffer records the terminal exit.
	 *
	 * @return  void
	 */
	private function assert_terminal_history(): void {
		self::assertSame(
			array(
				'started'   => array( self::RUN_ID ),
				'completed' => array( self::RUN_ID ),
				'by_hash'   => array(
					self::ARGS_HASH => array(
						'started'   => array( self::RUN_ID ),
						'completed' => array( self::RUN_ID ),
					),
				),
			),
			$this->option( 'a8csp_bgte_history_' . self::NAME )
		);
	}

	/**
	 * Returns the recorded run-state write for one lifecycle status.
	 *
	 * @param   string $status Expected lifecycle status.
	 *
	 * @return  array{
	 *     status: mixed,
	 *     start_args: mixed,
	 *     args_hash: mixed,
	 *     queue: mixed,
	 *     chunk_retries: mixed,
	 *     action_seq: mixed,
	 *     created_at: mixed,
	 *     heartbeat_at: mixed
	 * }
	 */
	private function recorded_run_state( string $status ): array {
		$events = $GLOBALS['a8csp_bgte_test_lifecycle_events'] ?? null;
		self::assertIsArray( $events );
		foreach ( $events as $event ) {
			if ( ! \is_array( $event ) || 'update' !== ( $event['operation'] ?? null ) ) {
				continue;
			}
			if ( $this->run_option_name() !== ( $event['key'] ?? null ) ) {
				continue;
			}

			$state = \maybe_unserialize( $event['raw'] ?? null );
			if ( \is_array( $state ) && ( $state['status'] ?? null ) === $status ) {
				return array(
					'status'        => $state['status'] ?? null,
					'start_args'    => $state['start_args'] ?? null,
					'args_hash'     => $state['args_hash'] ?? null,
					'queue'         => $state['queue'] ?? null,
					'chunk_retries' => $state['chunk_retries'] ?? null,
					'action_seq'    => $state['action_seq'] ?? null,
					'created_at'    => $state['created_at'] ?? null,
					'heartbeat_at'  => $state['heartbeat_at'] ?? null,
				);
			}
		}

		$calls = $GLOBALS['a8csp_bgte_test_option_calls'] ?? null;
		self::assertIsArray( $calls );
		foreach ( $calls as $call ) {
			self::assertIsArray( $call );
			if ( 'update_option' !== ( $call['function'] ?? null ) ) {
				continue;
			}

			$args = $call['args'] ?? null;
			self::assertIsArray( $args );
			if ( $this->run_option_name() !== ( $args[0] ?? null ) ) {
				continue;
			}

			$state = $args[1] ?? null;
			if ( \is_array( $state ) && ( $state['status'] ?? null ) === $status ) {
				return array(
					'status'        => $state['status'] ?? null,
					'start_args'    => $state['start_args'] ?? null,
					'args_hash'     => $state['args_hash'] ?? null,
					'queue'         => $state['queue'] ?? null,
					'chunk_retries' => $state['chunk_retries'] ?? null,
					'action_seq'    => $state['action_seq'] ?? null,
					'created_at'    => $state['created_at'] ?? null,
					'heartbeat_at'  => $state['heartbeat_at'] ?? null,
				);
			}
		}

		self::fail( 'The run never persisted the expected lifecycle state.' );
	}

	/**
	 * Reduces the unified boundary ledger to lifecycle-significant labels.
	 *
	 * @return  list<string>
	 */
	private function lifecycle_labels(): array {
		$events = $GLOBALS['a8csp_bgte_test_lifecycle_events'] ?? null;
		self::assertIsArray( $events );
		$labels = array();

		foreach ( $events as $event ) {
			self::assertIsArray( $event );
			$type = $event['type'] ?? null;
			if ( 'lock' === $type ) {
				$operation = $event['operation'] ?? null;
				self::assertIsString( $operation );
				if ( $this->run_option_name() === ( $event['key'] ?? null ) ) {
					if ( 'delete' === $operation ) {
						$labels[] = 'run:delete';
					} elseif ( 'update' === $operation ) {
						$value = \maybe_unserialize( $event['raw'] ?? null );
						self::assertIsArray( $value );
						self::assertIsString( $value['status'] ?? null );
						$labels[] = 'run:' . $value['status'];
					}

					continue;
				}
				$labels[] = 'lock:' . $operation;
				continue;
			}

			if ( 'task' === $type ) {
				$labels[] = 'task:handle';
				continue;
			}

			if ( 'action' === $type ) {
				$hook_name = $event['hook_name'];
				self::assertIsString( $hook_name );
				$labels[] = 'hook:' . \str_replace( 'a8csp/background_tasks/', '', $hook_name );
				continue;
			}

			if ( 'option' !== $type ) {
				continue;
			}

			$function = $event['function'] ?? null;
			$args     = $event['args'] ?? null;
			self::assertIsArray( $args );
			$option_name = $args[0] ?? null;
			self::assertIsString( $option_name );
			if ( 'delete_option' === $function && $this->run_option_name() === $option_name ) {
				$labels[] = 'run:delete';
				continue;
			}

			if ( 'update_option' !== $function ) {
				continue;
			}

			if ( $this->run_option_name() === $option_name ) {
				$value = $args[1] ?? null;
				self::assertIsArray( $value );
				self::assertIsString( $value['status'] ?? null );
				$labels[] = 'run:' . $value['status'];
			} elseif ( 'a8csp_bgte_failed_' . self::NAME === $option_name ) {
				$labels[] = 'failed-store';
			} elseif ( 'a8csp_bgte_history_' . self::NAME === $option_name ) {
				$labels[] = 'history';
			}
		}

		return $labels;
	}

	/**
	 * Returns the argument-identity lock option name.
	 *
	 * @return  string
	 */
	private function lock_option_name(): string {
		return 'a8csp_bgte_lock_' . self::NAME . '_' . self::ARGS_HASH;
	}

	/**
	 * Stores a foreign running lock and its latest-run pointer.
	 *
	 * @param   int $heartbeat_age Existing heartbeat age in seconds.
	 *
	 * @return  void
	 */
	private function seed_running_lock( int $heartbeat_age ): void {
		$raw_lock = \maybe_serialize(
			array(
				'run_id'       => 'run-running',
				'claimed_at'   => self::NOW - $heartbeat_age,
				'heartbeat_at' => self::NOW - $heartbeat_age,
			)
		);
		self::assertIsString( $raw_lock );
		$this->wpdb->put(
			$this->lock_option_name(),
			$raw_lock
		);
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );
		$options[ 'a8csp_bgte_latest_' . self::NAME ] = array(
			'all'     => 'run-running',
			'by_hash' => array( self::ARGS_HASH => 'run-running' ),
		);

		$GLOBALS['a8csp_bgte_test_options'] = $options;
	}

	/**
	 * Replaces the current lock with one foreign owner.
	 *
	 * @param   string $run_id       Foreign run identifier.
	 * @param   int    $heartbeat_at Foreign heartbeat timestamp.
	 *
	 * @return  void
	 */
	private function replace_lock_owner( string $run_id, int $heartbeat_at ): void {
		$raw = \maybe_serialize(
			array(
				'run_id'       => $run_id,
				'claimed_at'   => $heartbeat_at,
				'heartbeat_at' => $heartbeat_at,
			)
		);
		self::assertIsString( $raw );
		$this->wpdb->put( $this->lock_option_name(), $raw );
	}

	/**
	 * Returns the decoded lock row for the deterministic argument identity.
	 *
	 * @return  array{run_id: string, claimed_at: int, heartbeat_at: int}|null
	 */
	private function lock(): ?array {
		$raw = $this->wpdb->rows[ $this->lock_option_name() ] ?? null;
		if ( ! \is_string( $raw ) ) {
			return null;
		}

		$value = \maybe_unserialize( $raw );
		if (
			! \is_array( $value )
			|| ! \is_string( $value['run_id'] ?? null )
			|| ! \is_int( $value['claimed_at'] ?? null )
			|| ! \is_int( $value['heartbeat_at'] ?? null )
		) {
			return null;
		}

		return array(
			'run_id'       => $value['run_id'],
			'claimed_at'   => $value['claimed_at'],
			'heartbeat_at' => $value['heartbeat_at'],
		);
	}

	/**
	 * Returns one persisted option value.
	 *
	 * @param   string $name Option name.
	 *
	 * @return  mixed
	 */
	private function option( string $name ): mixed {
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );

		return $options[ $name ] ?? null;
	}

	/**
	 * Returns fired lifecycle actions.
	 *
	 * @return  list<array{hook_name: string, args: list<mixed>}>
	 */
	private function fired_actions(): array {
		$actions = $GLOBALS['a8csp_bgte_test_fired_actions'] ?? null;
		self::assertIsArray( $actions );
		$typed_actions = array();
		foreach ( $actions as $action ) {
			self::assertIsArray( $action );
			$hook_name = $action['hook_name'] ?? null;
			$args      = $action['args'] ?? null;
			self::assertIsString( $hook_name );
			self::assertIsArray( $args );
			$typed_actions[] = array(
				'hook_name' => $hook_name,
				'args'      => \array_values( $args ),
			);
		}

		return $typed_actions;
	}

	/**
	 * Returns internal action registrations.
	 *
	 * @return  list<array{hook_name: string, callback: mixed, priority: int, accepted_args: int}>
	 */
	private function action_registrations(): array {
		$registrations = $GLOBALS['a8csp_bgte_test_action_registrations'] ?? null;
		self::assertIsArray( $registrations );
		$typed_registrations = array();
		foreach ( $registrations as $registration ) {
			self::assertIsArray( $registration );
			$hook_name     = $registration['hook_name'] ?? null;
			$priority      = $registration['priority'] ?? null;
			$accepted_args = $registration['accepted_args'] ?? null;
			self::assertIsString( $hook_name );
			self::assertIsInt( $priority );
			self::assertIsInt( $accepted_args );
			$typed_registrations[] = array(
				'hook_name'     => $hook_name,
				'callback'      => $registration['callback'] ?? null,
				'priority'      => $priority,
				'accepted_args' => $accepted_args,
			);
		}

		return $typed_registrations;
	}

	/**
	 * Scripts one WordPress filter value through a typed global boundary.
	 *
	 * @param   string $hook_name Hook name.
	 * @param   mixed  $value     Scripted value.
	 *
	 * @return  void
	 */
	private function set_filter_value( string $hook_name, mixed $value ): void {
		$filters = $GLOBALS['a8csp_bgte_test_filter_values'] ?? null;
		self::assertIsArray( $filters );
		$filters[ $hook_name ] = $value;

		$GLOBALS['a8csp_bgte_test_filter_values'] = $filters;
	}

	/**
	 * Asserts that validation returned before every observable enqueue boundary.
	 *
	 * @return  void
	 */
	private function assert_enqueue_boundaries_untouched(): void {
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->randomizer->calls );
		self::assertSame( 0, $this->clock->calls );
		self::assertSame( array(), $this->wpdb->recorded_queries );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertSame( array(), $this->fired_actions() );
	}

	// endregion.
}

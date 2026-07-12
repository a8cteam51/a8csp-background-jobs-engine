<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Orchestration;

use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\NonRetryableTaskException;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LockRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Orchestrator;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Randomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\LatestRunPointer;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\StoreFactory;
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
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Orchestrator::class )]
#[UsesClass( EngineError::class )]
#[UsesClass( FailedRunStore::class )]
#[UsesClass( LatestRunPointer::class )]
#[UsesClass( LockRows::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( Randomizer::class )]
#[UsesClass( RunHistory::class )]
#[UsesClass( RunState::class )]
#[UsesClass( RunStatus::class )]
#[UsesClass( RunStore::class )]
#[UsesClass( StoreFactory::class )]
#[UsesClass( TaskRegistry::class )]
final class OrchestratorTest extends TestCase {
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

	/**
	 * Loads guarded WordPress functions before orchestration classes are instantiated.
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

		require_once \dirname( __DIR__ ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-hook-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-lock-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-time-constant-stubs.php';
		require_once \dirname( __DIR__ ) . '/Scheduling/wp-json-encode-stub.php';
	}

	/**
	 * Resets every observable boundary and constructs one registered task lifecycle.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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

		$this->orchestrator = new Orchestrator(
			$this->registry,
			$this->backend,
			new OverlapGuard( $this->clock, $this->logger, new LockRows( $this->wpdb ) ),
			new StoreFactory( $this->clock ),
			$this->logger,
			$this->clock,
			$this->randomizer,
		);
	}

	/**
	 * Hook registration exposes only the two-argument internal lifecycle action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_hooks_wires_the_internal_run_action(): void {
		$this->orchestrator->register_hooks();

		self::assertSame(
			array(
				array(
					'hook_name'     => 'a8csp/background_tasks/run',
					'callback'      => array( $this->orchestrator, 'handle_run_action' ),
					'priority'      => 10,
					'accepted_args' => 2,
				),
			),
			$this->action_registrations()
		);
	}

	/**
	 * A fresh enqueue persists the run, records fencing and history, fires hooks, and queues one action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
						'args'     => array( self::NAME, self::RUN_ID ),
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
	 * Real lock outcomes pin the default, filtered, and continue-delay-floored windows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int|null $staleness_filter Scripted per-task staleness value.
	 * @param   int|null $continue_filter  Scripted continue-delay value.
	 * @param   int      $heartbeat_age    Existing lock heartbeat age.
	 * @param   bool     $is_reclaimed     Whether the age exceeds the resolved window.
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
			'Task "email-digest" is already running as run "run-running"; wait for that run to finish before enqueueing the same arguments.',
			$result->error->message
		);
		self::assertSame( array(), $this->backend->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
	}

	/**
	 * Supplies fresh and stale edges for all three staleness-resolution paths.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
		);
	}

	/**
	 * Positive delay selects single scheduling at the clock-relative timestamp.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
						'args'      => array( self::NAME, self::RUN_ID ),
						'group'     => self::NAME . '|' . self::RUN_ID,
						'priority'  => 31,
					),
				),
			),
			$this->backend->calls
		);
	}

	/**
	 * Unique async enqueue reaches the scheduling seam unchanged.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $priority Rejected priority.
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_declares_no_discard_directly(): void {
		$method = new \ReflectionMethod( Orchestrator::class, 'enqueue' );

		self::assertCount( 1, $method->getAttributes( \NoDiscard::class ) );
	}

	/**
	 * Run handling refreshes both heartbeats before task execution and completes in terminal order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID );

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

	/**
	 * An ordinary throwable enters the complete terminal failure path without retry scheduling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_fails_terminally_for_an_ordinary_exception(): void {
		$this->assert_terminal_task_failure( new \RuntimeException( 'Database unavailable.' ) );
	}

	/**
	 * A non-retryable throwable enters the same immediate terminal failure path.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_fails_terminally_for_a_non_retryable_exception(): void {
		$this->assert_terminal_task_failure( new NonRetryableTaskException( 'The request is permanently invalid.' ) );
	}

	/**
	 * A moved latest pointer fences the run before task execution and uses quiet terminal cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_a_run_that_is_no_longer_latest(): void {
		$this->prepare_run_action();
		( new LatestRunPointer( self::NAME ) )->record( 'run-newer', self::ARGS_HASH );
		$GLOBALS['a8csp_bgte_test_option_calls']     = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events'] = array();

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID );

		self::assertSame( array(), $this->task->calls );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
		self::assertArrayNotHasKey( $this->lock_option_name(), $this->wpdb->rows );
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
					'message' => 'Superseded task run before execution.',
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
				'lock:update',
				'run:running',
				'run:superseded',
				'hook:superseded/' . self::NAME,
				'hook:superseded',
				'lock:delete',
				'run:delete',
				'history',
			),
			$this->lifecycle_labels()
		);
		$this->assert_terminal_history();
	}

	/**
	 * Confirmed lock ownership keeps a valid run executable after its bounded pointer is evicted.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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

		$this->orchestrator->handle_run_action( self::NAME, $first_run_id );

		self::assertSame( array( array( 'identity' => 0 ) ), $this->task->calls );
		self::assertSame(
			array(
				'a8csp/background_tasks/completed/' . self::NAME,
				'a8csp/background_tasks/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
	}

	/**
	 * Losing lock ownership fences a run even while its latest pointer has not moved.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID );

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
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $status Persisted terminal status.
	 *
	 * @return  void
	 */
	#[DataProvider( 'terminal_statuses' )]
	public function test_handle_run_action_does_not_execute_a_persisted_terminal_state( string $status ): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::NAME, $this->clock );
		$state     = $run_store->get( self::RUN_ID );
		self::assertNotNull( $state );
		$run_store->save( self::RUN_ID, $state->with_status( RunStatus::from( $status ) ) );

		$this->logger->records = array();

		$GLOBALS['a8csp_bgte_test_fired_actions']    = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events'] = array();

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID );

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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_warns_and_returns_when_run_state_is_missing(): void {
		$GLOBALS['a8csp_bgte_test_lifecycle_events'] = array();

		$this->orchestrator->handle_run_action( self::NAME, 'missing-run' );

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

	/**
	 * Returns the internal run option name for the deterministic enqueue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function run_option_name(): string {
		return 'a8csp_bgte_run_' . self::NAME . '_' . self::RUN_ID;
	}

	/**
	 * Enqueues the deterministic run and clears enqueue observations before action handling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Throwable $throwable Task failure.
	 *
	 * @return  void
	 */
	private function assert_terminal_task_failure( \Throwable $throwable ): void {
		$this->task->throwable = $throwable;
		$this->prepare_run_action();

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID );

		self::assertSame( array( self::ARGS ), $this->task->calls );
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
	 * Asserts that the existing completed buffer records the terminal exit.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * Reduces the unified boundary ledger to lifecycle-significant labels.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function lock_option_name(): string {
		return 'a8csp_bgte_lock_' . self::NAME . '_' . self::ARGS_HASH;
	}

	/**
	 * Stores a foreign running lock and its latest-run pointer.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * Returns the decoded lock row for the deterministic argument identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
}

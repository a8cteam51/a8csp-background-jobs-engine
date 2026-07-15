<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\WorkInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Retry\FailureLifecycle;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\HeartbeatOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Randomization\Randomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Retry\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\LatestRunPointer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\TerminalTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Tasks\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;
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
 * Pins the observable single-task delivery lifecycle across storage, hooks, locks, and logs.
 *
 */
#[CoversClass( ActionDeliveries::class )]
#[UsesClass( EngineError::class )]
#[UsesClass( FailedRunStore::class )]
#[UsesClass( LatestRunPointer::class )]
#[UsesClass( Dispatcher::class )]
#[UsesClass( HeartbeatOutcome::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
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
final class ActionDeliveriesTest extends TestCase {
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
	private ActionDeliveries $lifecycle_deliveries;
	private RecordingLogger $logger;
	private RecordingRandomizer $randomizer;
	private OptionRows $rows;
	private RecordingTask $task;
	private TaskRegistry $registry;
	private WpdbLockSpy $wpdb;
	private Dispatcher $dispatcher;

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

		require_once \dirname( __DIR__, 2 ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/wp-hook-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/wp-lock-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/wp-time-constant-stubs.php';
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
		$this->wpdb           = new WpdbLockSpy();
		$this->rows           = new OptionRows( $this->wpdb );
		$batches              = new BatchRegistry();
		$guard                = new OverlapGuard( $this->clock, $this->logger, $this->rows );
		$stores               = new StoreFactory( $this->clock, $this->rows );
		$lock_windows         = new LockWindows( $this->clock );
		$terminal_transitions = new TerminalTransitions( $guard, $stores, $this->clock, $lock_windows, $this->logger );
		$failure_lifecycle    = new FailureLifecycle(
			$this->backend,
			$this->clock,
			$this->randomizer,
			$this->logger,
			$terminal_transitions
		);

		$this->lifecycle_deliveries = new ActionDeliveries(
			$this->registry,
			$batches,
			$this->backend,
			$stores,
			$this->logger,
			$this->clock,
			$lock_windows,
			$terminal_transitions,
			$failure_lifecycle,
		);

		$this->dispatcher = new Dispatcher(
			$this->registry,
			$batches,
			$this->backend,
			$guard,
			$stores,
			$this->clock,
			$this->randomizer,
			$this->logger,
			$lock_windows,
			$terminal_transitions,
		);
	}

	// endregion.

	// region TESTS.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Signatures and providers carry test parameter types.

	/**
	 * Hook registration exposes every internal lifecycle action through the delivery registrar.
	 *
	 * @return  void
	 */
	public function test_register_hooks_wires_the_internal_lifecycle_actions(): void {
		$this->lifecycle_deliveries->register_hooks();

		self::assertSame(
			array(
				array(
					'hook_name'     => 'a8csp_background_tasks/start',
					'callback'      => array( $this->lifecycle_deliveries, 'handle_start_action' ),
					'priority'      => 10,
					'accepted_args' => 3,
				),
				array(
					'hook_name'     => 'a8csp_background_tasks/continue',
					'callback'      => array( $this->lifecycle_deliveries, 'handle_continue_action' ),
					'priority'      => 10,
					'accepted_args' => 3,
				),
				array(
					'hook_name'     => 'a8csp_background_tasks/run',
					'callback'      => array( $this->lifecycle_deliveries, 'handle_run_action' ),
					'priority'      => 10,
					'accepted_args' => 4,
				),
				array(
					'hook_name'     => 'a8csp_background_tasks/cleanup',
					'callback'      => array( $this->lifecycle_deliveries, 'handle_cleanup_action' ),
					'priority'      => 10,
					'accepted_args' => 3,
				),
			),
			$this->action_registrations()
		);
	}

	/**
	 * Run handling refreshes both heartbeats before task execution and completes in terminal order.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_executes_and_completes_the_task(): void {
		$this->prepare_run_action();
		$this->task->max_runtime = 1_200;

		$observed_lock = null;
		$observed_run  = null;

		$this->task->on_handle = function ( array $args ) use ( &$observed_lock, &$observed_run ): void {
			self::assertSame( self::ARGS, $args );
			$observed_lock = $this->lock();
			$observed_run  = $this->option( $this->run_option_name() );
		};

		$this->lifecycle_deliveries->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertIsArray( $observed_lock );
		self::assertSame( self::NOW + 90 + 1_200, $observed_lock['heartbeat_at'] );
		self::assertIsArray( $observed_run );
		self::assertSame( 'running', $observed_run['status'] );
		self::assertTrue( $observed_run['executing'] );
		self::assertSame( self::NOW + 90 + 1_200, $observed_run['heartbeat_at'] );
		self::assertArrayNotHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_background_tasks/completed/' . self::NAME,
					'args'      => array( self::RUN_ID, self::ARGS ),
				),
				array(
					'hook_name' => 'a8csp_background_tasks/completed',
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
		$this->assert_terminal_history( RunStatus::Completed );
	}

	/**
	 * A task without an override receives the shared callback liveness credit.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_credits_the_default_runtime_before_task_execution(): void {
		$this->prepare_run_action();
		$observed_lock = null;
		$observed_run  = null;

		$this->task->on_handle = function ( array $args ) use ( &$observed_lock, &$observed_run ): void {
			$observed_lock = $this->lock();
			$observed_run  = $this->option( $this->run_option_name() );
		};

		$this->lifecycle_deliveries->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertIsArray( $observed_lock );
		self::assertSame( self::NOW + 90 + 300, $observed_lock['heartbeat_at'] );
		self::assertIsArray( $observed_run );
		self::assertSame( self::NOW + 90 + 300, $observed_run['heartbeat_at'] ?? null );
	}

	/**
	 * Delivery clamps invalid and runaway declarations before crediting callback liveness.
	 *
	 * @return  void
	 */
	#[DataProvider( 'bounded_runtime_values' )]
	public function test_handle_run_action_bounds_the_declared_runtime( int $declared, int $expected_lease ): void {
		$this->prepare_run_action();
		$this->task->max_runtime = $declared;
		$observed_lock           = null;
		$observed_run            = null;
		$this->task->on_handle   = function ( array $args ) use ( &$observed_lock, &$observed_run ): void {
			$observed_lock = $this->lock();
			$observed_run  = $this->option( $this->run_option_name() );
		};

		$this->lifecycle_deliveries->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertIsArray( $observed_lock );
		self::assertSame( self::NOW + 90 + $expected_lease, $observed_lock['heartbeat_at'] );
		self::assertIsArray( $observed_run );
		self::assertSame( self::NOW + 90 + $expected_lease, $observed_run['heartbeat_at'] ?? null );
	}

	/**
	 * A throwing runtime declaration falls back to the default lease instead of escaping the delivery.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_defaults_the_lease_when_the_declared_runtime_throws(): void {
		$this->prepare_run_action();
		$this->task->max_runtime_throwable = new \RuntimeException( 'Runtime ceiling lookup exploded.' );
		$observed_lock                     = null;
		$this->task->on_handle             = function ( array $args ) use ( &$observed_lock ): void {
			$observed_lock = $this->lock();
		};

		$this->lifecycle_deliveries->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertIsArray( $observed_lock );
		self::assertSame( self::NOW + 90 + 300, $observed_lock['heartbeat_at'] );
		self::assertCount( 1, $this->task->calls );
	}

	/**
	 * An indeterminate admission fence aborts before task execution without terminal bookkeeping.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_aborts_before_task_execution_when_lock_heartbeat_read_fails(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::NAME, $this->clock, new OptionRows( $this->wpdb ) );
		$before    = $run_store->inspect( self::RUN_ID );
		if ( $before->is_failure() ) {
			self::fail( 'The running state could not be inspected before the delivery.' );
		}
		$before_snapshot = $before->value;
		self::assertNotNull( $before_snapshot );
		$expected_run_raw = $before_snapshot['raw'];
		$expected_lock    = $this->wpdb->rows[ $this->lock_option_name() ] ?? null;
		$expected_history = $this->option( 'a8csp_bgte_history_' . self::NAME );
		self::assertIsString( $expected_lock );

		$this->wpdb->recorded_queries                = array();
		$GLOBALS['a8csp_bgte_test_option_calls']     = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events'] = array();
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient heartbeat read failure';
			}
		);

		$this->lifecycle_deliveries->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array(), $this->task->calls );
		self::assertSame( array(), $this->backend->calls );
		$after = $run_store->inspect( self::RUN_ID );
		if ( $after->is_failure() ) {
			self::fail( 'The running state could not be inspected after the delivery.' );
		}
		$after_snapshot = $after->value;
		self::assertNotNull( $after_snapshot );
		self::assertInstanceOf( RunState::class, $after_snapshot['state'] );
		$after_state = $after_snapshot['state'];
		self::assertSame( $expected_run_raw, $after_snapshot['raw'] );
		self::assertSame( RunStatus::Running, $after_state->status );
		self::assertFalse( $after_state->executing );
		self::assertSame( self::NOW, $after_state->heartbeat_at );
		self::assertSame( $expected_lock, $this->wpdb->rows[ $this->lock_option_name() ] ?? null );
		self::assertSame( $expected_history, $this->option( 'a8csp_bgte_history_' . self::NAME ) );
		self::assertSame( array(), $this->fired_actions() );
		self::assertSame( array(), $this->lifecycle_labels() );
		foreach ( $this->wpdb->recorded_queries as $query ) {
			self::assertStringStartsWith( 'SELECT ', $query );
		}
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Execution-overlap lock heartbeat could not read the authoritative lock row; ownership is indeterminate and the caller aborts without a terminal claim.',
					'context' => array(
						'key'       => $this->lock_option_name(),
						'name'      => self::NAME,
						'args_hash' => self::ARGS_HASH,
						'run_id'    => self::RUN_ID,
					),
				),
				array(
					'level'   => 'debug',
					'message' => 'Task ownership fence is indeterminate; the delivery aborts without a terminal transition.',
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
	 * A reentrant same-sequence task delivery cannot enter user code under a fresh marker.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_drops_a_reentrant_same_sequence_delivery(): void {
		$this->prepare_run_action();
		$action_seq = $this->action_seq();
		$reentered  = false;

		$this->task->on_handle = function ( array $args ) use ( $action_seq, &$reentered ): void {
			self::assertSame( self::ARGS, $args );
			if ( $reentered ) {
				return;
			}

			$reentered = true;
			$this->lifecycle_deliveries->handle_run_action( self::NAME, self::RUN_ID, $action_seq );
		};

		$this->lifecycle_deliveries->handle_run_action( self::NAME, self::RUN_ID, $action_seq );

		self::assertTrue( $reentered );
		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertSame(
			array(
				array(
					'level'   => 'debug',
					'message' => 'Duplicate lifecycle action delivery dropped while the current delivery is still executing.',
					'context' => array(
						'task_name'  => self::NAME,
						'run_id'     => self::RUN_ID,
						'action_seq' => $action_seq,
					),
				),
			),
			$this->logger->records
		);
		$this->assert_terminal_history( RunStatus::Completed );
	}

	/**
	 * An expired delivery cannot shorten the execution lease credited to its replacement.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_preserves_a_replacement_delivery_execution_lease(): void {
		$this->prepare_run_action();
		$action_seq         = $this->action_seq();
		$replacement_credit = null;

		$this->task->on_handle = function ( array $args ) use ( $action_seq, &$replacement_credit ): void {
			self::assertSame( self::ARGS, $args );
			$replacement_credit = $this->admit_replacement_delivery( $action_seq );
		};

		$this->lifecycle_deliveries->handle_run_action( self::NAME, self::RUN_ID, $action_seq );

		self::assertIsInt( $replacement_credit );
		$this->assert_replacement_delivery_preserved( $replacement_credit );
	}

	/**
	 * An expired throwing delivery cannot shorten the execution lease credited to its replacement.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_failure_preserves_a_replacement_delivery_execution_lease(): void {
		$this->prepare_run_action();
		$action_seq         = $this->action_seq();
		$replacement_credit = null;

		$this->task->on_handle = function ( array $args ) use ( $action_seq, &$replacement_credit ): void {
			self::assertSame( self::ARGS, $args );
			$replacement_credit = $this->admit_replacement_delivery( $action_seq );

			throw new \RuntimeException( 'Expired attempt failed after its replacement began.' );
		};

		$this->lifecycle_deliveries->handle_run_action( self::NAME, self::RUN_ID, $action_seq );

		self::assertIsInt( $replacement_credit );
		$this->assert_replacement_delivery_preserved( $replacement_credit );
	}

	/**
	 * Expired failure adjudication cannot shorten a replacement admitted during policy resolution.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_retry_policy_preserves_a_replacement_delivery_execution_lease(): void {
		$this->prepare_run_action();
		$action_seq            = $this->action_seq();
		$replacement_credit    = null;
		$this->task->throwable = new \RuntimeException( 'Attempt failed before retry-policy resolution.' );
		$this->set_filter_value(
			'a8csp_background_tasks/retry_policy/' . self::NAME,
			function ( RetryPolicy $policy ) use ( $action_seq, &$replacement_credit ): RetryPolicy {
				$replacement_credit = $this->admit_replacement_delivery( $action_seq );

				return $policy;
			}
		);

		$this->lifecycle_deliveries->handle_run_action( self::NAME, self::RUN_ID, $action_seq );

		self::assertIsInt( $replacement_credit );
		$this->assert_replacement_delivery_preserved( $replacement_credit );
	}

	/**
	 * Batch arguments on a task delivery clear its marker so the correctly shaped delivery can run.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_clears_the_task_marker_after_batch_argument_misdelivery(): void {
		$this->prepare_run_action();
		$action_seq = $this->action_seq();

		$this->lifecycle_deliveries->handle_run_action(
			self::NAME,
			self::RUN_ID,
			array( 'chunk' => 'misdelivered' ),
			$action_seq
		);

		$state = $this->option( $this->run_option_name() );
		self::assertIsArray( $state );
		self::assertSame( 'running', $state['status'] ?? null );
		self::assertFalse( $state['executing'] ?? true );
		self::assertSame( $action_seq, $state['action_seq'] ?? null );
		self::assertSame( array(), $this->task->calls );
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->fired_actions() );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Task run action carries batch chunk arguments; schedule task runs with only the task name and run identifier.',
					'context' => array(
						'task_name' => self::NAME,
						'run_id'    => self::RUN_ID,
					),
				),
			),
			$this->logger->records
		);

		$this->logger->records = array();
		$this->lifecycle_deliveries->handle_run_action( self::NAME, self::RUN_ID, $action_seq );

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
	}

	/**
	 * An unregistered task action fails its live run instead of orphaning active state.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_terminalizes_a_live_unregistered_task(): void {
		$this->prepare_run_action();
		$action_seq           = $this->action_seq();
		$tasks                = new TaskRegistry();
		$batches              = new BatchRegistry();
		$guard                = new OverlapGuard( $this->clock, $this->logger, new OptionRows( $this->wpdb ) );
		$stores               = new StoreFactory( $this->clock, new OptionRows( $this->wpdb ) );
		$lock_windows         = new LockWindows( $this->clock );
		$terminal_transitions = new TerminalTransitions( $guard, $stores, $this->clock, $lock_windows, $this->logger );
		$failure_lifecycle    = new FailureLifecycle(
			$this->backend,
			$this->clock,
			$this->randomizer,
			$this->logger,
			$terminal_transitions
		);
		$lifecycle_deliveries = new ActionDeliveries(
			$tasks,
			$batches,
			$this->backend,
			$stores,
			$this->logger,
			$this->clock,
			$lock_windows,
			$terminal_transitions,
			$failure_lifecycle,
		);
		$lifecycle_deliveries->handle_run_action( self::NAME, self::RUN_ID, $action_seq );

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
				'a8csp_background_tasks/failed/' . self::NAME,
				'a8csp_background_tasks/failed',
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
		$this->assert_terminal_history( RunStatus::Failed );
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
			self::assertTrue( ( new LatestRunPointer( self::NAME, $this->rows ) )->record( 'run-newer', self::ARGS_HASH ) );
			$this->replace_lock_owner( 'run-newer', self::NOW + 90 );
		};

		$this->lifecycle_deliveries->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertIsArray( $observed_state );
		self::assertSame( 'running', $observed_state['status'] ?? null );
		self::assertTrue( $observed_state['executing'] ?? null );
		self::assertSame( self::NOW + 90 + 300, $observed_state['heartbeat_at'] ?? null );
		self::assertSame( array(), $this->backend->calls );
		$this->assert_post_callback_superseded_task();
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
		$result = $this->dispatcher->enqueue( self::NAME, self::ARGS );
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
	 * Supplies invalid and runaway runtime declarations at the delivery boundary.
	 *
	 * @return  array<string, array{declared: int, expected_lease: int}>
	 */
	public static function bounded_runtime_values(): array {
		return array(
			'zero uses default'      => array(
				'declared'       => 0,
				'expected_lease' => 300,
			),
			'negative uses default'  => array(
				'declared'       => -1,
				'expected_lease' => 300,
			),
			'twenty-four hours caps' => array(
				'declared'       => 24 * 60 * 60,
				'expected_lease' => 6 * 60 * 60,
			),
		);
	}

	/**
	 * Admits a same-run replacement after the current callback credit becomes stale.
	 *
	 * @param   int $action_seq Current lifecycle action sequence.
	 *
	 * @return  int Replacement liveness timestamp.
	 */
	private function admit_replacement_delivery( int $action_seq ): int {
		$guard                  = new OverlapGuard( $this->clock, $this->logger, new OptionRows( $this->wpdb ) );
		$stores                 = new StoreFactory( $this->clock, new OptionRows( $this->wpdb ) );
		$lock_windows           = new LockWindows( $this->clock );
		$terminal_transitions   = new TerminalTransitions( $guard, $stores, $this->clock, $lock_windows, $this->logger );
		$this->clock->timestamp = self::NOW + 90 + WorkInterface::DEFAULT_MAX_RUNTIME + 901;
		$credit                 = $this->clock->timestamp + WorkInterface::DEFAULT_MAX_RUNTIME;
		$replacement_state      = $terminal_transitions->active_run_state(
			'Task',
			self::NAME,
			self::RUN_ID,
			$action_seq,
			$stores->run_store( self::NAME ),
			static fn (): int => $credit
		);
		self::assertInstanceOf( RunState::class, $replacement_state );

		return $credit;
	}

	/**
	 * Asserts that one admitted replacement retains both future heartbeat rows.
	 *
	 * @param   int $replacement_credit Expected replacement liveness timestamp.
	 *
	 * @return  void
	 */
	private function assert_replacement_delivery_preserved( int $replacement_credit ): void {
		$lock = $this->lock();
		self::assertIsArray( $lock );
		self::assertSame( $replacement_credit, $lock['heartbeat_at'] );
		$state = $this->option( $this->run_option_name() );
		self::assertIsArray( $state );
		self::assertSame( 'running', $state['status'] ?? null );
		self::assertTrue( $state['executing'] ?? null );
		self::assertSame( $replacement_credit, $state['heartbeat_at'] ?? null );
	}

	/**
	 * Scripts one value through the unit filter boundary.
	 *
	 * @param   string $hook_name Filter hook name.
	 * @param   mixed  $value     Scripted return value or callable.
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
					'hook_name' => 'a8csp_background_tasks/superseded/' . self::NAME,
					'args'      => array( self::RUN_ID, self::ARGS ),
				),
				array(
					'hook_name' => 'a8csp_background_tasks/superseded',
					'args'      => array( self::NAME, self::RUN_ID, self::ARGS ),
				),
			),
			\array_slice( $this->fired_actions(), -2 )
		);
		$this->assert_terminal_history( RunStatus::Superseded );
	}

	/**
	 * Asserts that both terminal-history buffers record the terminal outcome.
	 *
	 * @param   RunStatus $status Terminal run status.
	 *
	 * @return  void
	 */
	private function assert_terminal_history( RunStatus $status ): void {
		$events = $GLOBALS['a8csp_bgte_test_lifecycle_events'] ?? null;
		self::assertIsArray( $events );
		$terminal_state = null;
		foreach ( $events as $event ) {
			if ( ! \is_array( $event ) || 'update' !== ( $event['operation'] ?? null ) ) {
				continue;
			}
			if ( $this->run_option_name() !== ( $event['key'] ?? null ) ) {
				continue;
			}

			$state = \maybe_unserialize( $event['raw'] ?? null );
			if ( \is_array( $state ) && ( $state['status'] ?? null ) === $status->value ) {
				$terminal_state = $state;
				break;
			}
		}
		self::assertIsArray( $terminal_state );
		self::assertArrayNotHasKey( 'pending', $terminal_state );

		$entry = array(
			'run_id' => self::RUN_ID,
			'status' => $status->value,
		);

		self::assertSame(
			array(
				'started'   => array( self::RUN_ID ),
				'completed' => array( $entry ),
				'by_hash'   => array(
					self::ARGS_HASH => array(
						'started'   => array( self::RUN_ID ),
						'completed' => array( $entry ),
					),
				),
			),
			$this->option( 'a8csp_bgte_history_' . self::NAME )
		);
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
				if ( 'delete' !== $operation && 'a8csp_bgte_failed_' . self::NAME === ( $event['key'] ?? null ) ) {
					$labels[] = 'failed-store';
					continue;
				}
				if ( 'delete' !== $operation && 'a8csp_bgte_history_' . self::NAME === ( $event['key'] ?? null ) ) {
					$labels[] = 'history';
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
				$labels[] = 'hook:' . \str_replace( 'a8csp_background_tasks/', '', $hook_name );
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
		$raw = $this->wpdb->rows[ $name ] ?? null;
		if ( null !== $raw ) {
			self::assertIsString( $raw );

			return RawOptionDecoder::decode( $raw );
		}

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

	// endregion.
}

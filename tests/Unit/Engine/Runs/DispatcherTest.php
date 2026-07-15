<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\HeartbeatOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\Randomization\Randomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\LatestRunPointer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\TerminalTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\WorkRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingErrorReason;
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
 * Pins single-task admission and manual retry across scheduling, storage, hooks, locks, and logs.
 *
 */
#[CoversClass( Dispatcher::class )]
#[UsesClass( EngineError::class )]
#[UsesClass( FailedRunStore::class )]
#[UsesClass( HeartbeatOutcome::class )]
#[UsesClass( LatestRunPointer::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( Randomizer::class )]
#[UsesClass( RetryPolicy::class )]
#[UsesClass( RunHistory::class )]
#[UsesClass( RunState::class )]
#[UsesClass( RunStatus::class )]
#[UsesClass( RunStore::class )]
#[UsesClass( SchedulerFacade::class )]
#[UsesClass( StoreFactory::class )]
#[UsesClass( BatchRegistry::class )]
#[UsesClass( TaskRegistry::class )]
#[UsesClass( WorkRegistry::class )]
final class DispatcherTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const ARGS = array(
		'site_id' => 7,
		'mode'    => 'full',
	);

	private const ARGS_HASH        = '7dcca9cc21619f109d6f0423c49b010606457ea4a713721e9ce5134949d72bd2';
	private const IDENTITY         = self::OWNER . ':' . self::NAME;
	private const NAME             = 'email-digest';
	private const NOW              = 1_700_000_000;
	private const OWNER            = 'runs-tests';
	private const RUN_ID           = '00000000001700000000-0000000000000000042';
	private const UNKNOWN_IDENTITY = self::OWNER . ':unknown';

	private FixedClock $clock;
	private RecordingBackend $backend;
	private RecordingLogger $logger;
	private RecordingRandomizer $randomizer;
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
		require_once \dirname( __DIR__ ) . '/Backends/wp-json-encode-stub.php';
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
		$work             = new WorkRegistry();
		$this->registry   = new TaskRegistry( $work );
		$this->registry->register( self::IDENTITY, $this->task );
		$this->wpdb           = new WpdbLockSpy();
		$batches              = new BatchRegistry( $work );
		$guard                = new OverlapGuard( $this->clock, $this->logger, new OptionRows( $this->wpdb ) );
		$stores               = new StoreFactory( $this->clock, new OptionRows( $this->wpdb ) );
		$lock_windows         = new LockWindows( $this->clock );
		$terminal_transitions = new TerminalTransitions( $guard, $stores, $this->clock, $lock_windows, $this->logger );
		$this->dispatcher     = new Dispatcher(
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
	 * A fresh enqueue persists the run, records fencing and history, fires hooks, and queues one action.
	 *
	 * @return  void
	 */
	public function test_enqueue_creates_and_dispatches_a_running_task(): void {
		$scheduled_state = null;
		$this->backend->before_next(
			'enqueue_async',
			function () use ( &$scheduled_state ): void {
				$scheduled_state = $this->option( $this->run_option_name() );
			}
		);
		$result = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS, priority: 23 );

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
						'hook'     => 'a8csp_background_tasks/run',
						'args'     => array( self::IDENTITY, self::RUN_ID, 1 ),
						'group'    => self::IDENTITY . '|' . self::RUN_ID,
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
				'executing'     => false,
				'start_args'    => self::ARGS,
				'args_hash'     => self::ARGS_HASH,
				'queue'         => array( self::ARGS ),
				'chunk_retries' => 0,
				'action_seq'    => 1,
				'created_at'    => self::NOW,
				'heartbeat_at'  => self::NOW,
				'pending'       => array(
					'stage'    => 'run',
					'mode'     => 'async',
					'fire_at'  => null,
					'unique'   => false,
					'priority' => 23,
				),
			),
			$this->option( $this->run_option_name() )
		);
		self::assertIsArray( $scheduled_state );
		self::assertSame( $this->option( $this->run_option_name() ), $scheduled_state );
		self::assertSame(
			array(
				'all'     => self::RUN_ID,
				'by_hash' => array( self::ARGS_HASH => self::RUN_ID ),
			),
			$this->option( 'a8csp_bgte_latest_' . self::IDENTITY )
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
			$this->option( 'a8csp_bgte_history_' . self::IDENTITY )
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
					'hook_name' => 'a8csp_background_tasks/started/' . self::IDENTITY,
					'args'      => array( self::RUN_ID, self::ARGS ),
				),
				array(
					'hook_name' => 'a8csp_background_tasks/started',
					'args'      => array( self::IDENTITY, self::RUN_ID, self::ARGS ),
				),
			),
			$this->fired_actions()
		);
	}

	/**
	 * Cancellation wraps a raw backend before issuing its group-only clear.
	 *
	 * @return  void
	 */
	public function test_cancel_wraps_a_recording_backend_for_group_clearance(): void {
		$enqueued = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS );
		self::assertInstanceOf( Success::class, $enqueued );
		$this->backend->calls = array();

		$cancelled = $this->dispatcher->cancel( self::IDENTITY, self::RUN_ID );

		self::assertInstanceOf( Success::class, $cancelled );
		self::assertSame( self::RUN_ID, $cancelled->value );
		self::assertSame(
			array(
				array(
					'verb' => 'is_ready',
					'args' => array(),
				),
				array(
					'verb' => 'unschedule',
					'args' => array(
						'hook'  => '',
						'args'  => array(),
						'group' => self::IDENTITY . '|' . self::RUN_ID,
					),
				),
			),
			$this->backend->calls
		);
	}

	/**
	 * A throwing task started listener fails and cleans the already-scheduled run.
	 *
	 * @return  void
	 */
	public function test_enqueue_terminalizes_when_a_task_started_listener_throws(): void {
		$GLOBALS['a8csp_bgte_test_action_throwables'] = array(
			'a8csp_background_tasks/started/' . self::IDENTITY => new \RuntimeException(
				'Started listener exploded.'
			),
		);

		$result = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Task "runs-tests:email-digest" started listener failed because RuntimeException was thrown. Fix the started-hook listener before enqueueing the task again.',
			$result->error->message
		);
		self::assertCount( 1, $this->backend->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		$failed_runs = $this->failed_runs();
		$failed_run  = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		$stored_error = $failed_run['error'] ?? null;
		self::assertIsArray( $stored_error );
		self::assertSame( $result->error->message, $stored_error['message'] ?? null );
		self::assertSame(
			array(
				'a8csp_background_tasks/started/' . self::IDENTITY,
				'a8csp_background_tasks/started',
				'a8csp_background_tasks/failed/' . self::IDENTITY,
				'a8csp_background_tasks/failed',
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
				'a8csp_background_tasks/lock_staleness/' . self::IDENTITY,
				$staleness_filter
			);
		}
		if ( null !== $continue_filter ) {
			$this->set_filter_value( 'a8csp_background_tasks/continue_delay', $continue_filter );
		}

		$this->seed_running_lock( $heartbeat_age );

		$result = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS, unique: true );

		if ( $is_reclaimed ) {
			self::assertInstanceOf( Success::class, $result );
			self::assertSame( self::RUN_ID, $result->value );
			self::assertSame( true, $this->backend->calls[0]['args']['unique'] );
			return;
		}

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Task "runs-tests:email-digest" is already running as run "run-running"; wait for that run to finish before dispatching the same arguments.',
			$result->error->message
		);
		self::assertSame( array(), $this->backend->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
	}

	/** A failed contended-lock owner read declines admission without persisting or scheduling a run. */
	public function test_enqueue_declines_when_the_contended_lock_owner_read_fails(): void {
		$this->seed_running_lock( 0 );
		$incumbent_raw = $this->wpdb->rows[ $this->lock_option_name() ] ?? null;
		self::assertIsString( $incumbent_raw );
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient owner read failure';
			}
		);

		$result = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Task "runs-tests:email-digest" could not confirm the owner of a contended overlap lock; repair database writes and retry the dispatch.',
			$result->error->message
		);
		self::assertSame( $incumbent_raw, $this->wpdb->rows[ $this->lock_option_name() ] ?? null );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertSame( array(), $this->backend->calls );
	}

	/**
	 * The name-specific lock-staleness filter receives its complete documented payload.
	 *
	 * @return  void
	 */
	public function test_enqueue_passes_all_documented_arguments_to_the_lock_staleness_filter(): void {
		$filter_args = null;
		$this->set_filter_value(
			'a8csp_background_tasks/lock_staleness/' . self::IDENTITY,
			static function ( int $default_staleness ) use ( &$filter_args ): int {
				$filter_args = array(
					'arity' => \func_num_args(),
					'args'  => \func_get_args(),
				);

				return $default_staleness;
			}
		);

		$result = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS );

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
		$scheduled_state = null;
		$this->backend->before_next(
			'schedule_single',
			function () use ( &$scheduled_state ): void {
				$scheduled_state = $this->option( $this->run_option_name() );
			}
		);
		$result = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS, delay: 120, unique: true, priority: 31 );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame(
			array(
				array(
					'verb' => 'schedule_single',
					'args' => array(
						'hook'      => 'a8csp_background_tasks/run',
						'timestamp' => self::NOW + 120,
						'args'      => array( self::IDENTITY, self::RUN_ID, 1 ),
						'group'     => self::IDENTITY . '|' . self::RUN_ID,
						'priority'  => 31,
					),
				),
			),
			$this->backend->calls
		);
		$state = $this->option( $this->run_option_name() );
		self::assertIsArray( $state );
		self::assertSame( self::NOW + 120, $state['heartbeat_at'] ?? null );
		self::assertSame(
			array(
				'stage'    => 'run',
				'mode'     => 'single',
				'fire_at'  => self::NOW + 120,
				'unique'   => true,
				'priority' => 31,
			),
			$state['pending'] ?? null
		);
		self::assertSame( $state, $scheduled_state );
		self::assertSame( self::NOW + 120, $this->lock()['heartbeat_at'] ?? null );
	}

	/**
	 * A failed delayed heartbeat releases any lock still owned by the provisional run.
	 *
	 * @return  void
	 */
	public function test_enqueue_with_delay_releases_its_lock_when_heartbeat_fails(): void {
		$this->wpdb->script_result( 'update', false );

		$result = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS, delay: 120 );

		self::assertInstanceOf( Failure::class, $result );
		self::assertNull( $this->lock() );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertSame( array(), $this->backend->calls );
	}

	/**
	 * An indeterminate delayed heartbeat aborts scheduling and removes provisional state.
	 *
	 * @return  void
	 */
	public function test_enqueue_with_delay_aborts_when_heartbeat_read_is_indeterminate(): void {
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient heartbeat read failure';
			}
		);

		$result = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS, delay: 120 );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Task "runs-tests:email-digest" could not confirm lock ownership while preparing its delayed action; enqueue it again after authoritative reads recover.',
			$result->error->message
		);
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
		$result = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS, unique: true );

		self::assertInstanceOf( Success::class, $result );
		$call = $this->backend->calls[0] ?? null;
		self::assertIsArray( $call );
		$backend_args = $call['args'] ?? null;
		self::assertIsArray( $backend_args );
		$backend_unique = $backend_args['unique'] ?? null;
		self::assertIsBool( $backend_unique );
		self::assertTrue( $backend_unique );
		$state = $this->option( $this->run_option_name() );
		self::assertIsArray( $state );
		$pending = $state['pending'] ?? null;
		self::assertIsArray( $pending );
		$persisted_unique = $pending['unique'] ?? null;
		self::assertIsBool( $persisted_unique );
		self::assertTrue( $persisted_unique );
	}

	/**
	 * An unknown task fails before clocks, randomness, persistence, locks, hooks, or scheduling.
	 *
	 * @return  void
	 */
	public function test_enqueue_rejects_an_unknown_task_without_touching_boundaries(): void {
		$result = $this->dispatcher->enqueue( self::UNKNOWN_IDENTITY, self::ARGS );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Task "runs-tests:unknown" is not registered; register it before enqueueing.',
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
		$result = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS, priority: $priority );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			\sprintf(
				'Task "runs-tests:email-digest" priority %d is invalid; pass a value from 0 through 255.',
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

		$result = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS );

		self::assertSame( $failure, $result );
		self::assertArrayNotHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->option( 'a8csp_bgte_history_' . self::IDENTITY ) );
		self::assertSame( array(), $this->fired_actions() );
		self::assertSame(
			array(
				'all'     => self::RUN_ID,
				'by_hash' => array( self::ARGS_HASH => self::RUN_ID ),
			),
			$this->option( 'a8csp_bgte_latest_' . self::IDENTITY )
		);
	}

	/**
	 * Enqueue rejects values that cannot remain portable through JSON and option storage.
	 *
	 * @return  void
	 */
	public function test_enqueue_rejects_non_scalar_argument_trees_before_claiming_a_lock(): void {
		$result = $this->dispatcher->enqueue(
			self::IDENTITY,
			array(
				'callback' => static function (): void {},
			)
		);

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Task "runs-tests:email-digest" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.',
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

		$result = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS, delay: 10 );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Task "runs-tests:email-digest" delay 10 exceeds supported Unix seconds; pass a smaller delay.',
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
		$method = new \ReflectionMethod( Dispatcher::class, 'enqueue' );

		self::assertCount( 1, $method->getAttributes( \NoDiscard::class ) );
	}

	/**
	 * Manual retry enqueues a fresh task run and removes the consumed failed entry.
	 *
	 * @return  void
	 */
	public function test_retry_failed_reenqueues_a_task_and_removes_the_failed_entry(): void {
		$store = new FailedRunStore( self::IDENTITY, new OptionRows( $this->wpdb ) );
		self::assertTrue(
			$store->record(
				'failed-run',
				self::NOW - 1,
				self::ARGS,
				2,
				new EngineError( 'Database unavailable.', \RuntimeException::class ),
				self::retained_failure( 'failed-run', 2 )
			)
		);
		$this->assert_failed_run_storage_is_authoritative();
		$this->backend->calls    = array();
		$this->randomizer->calls = array();
		$this->randomizer->value = 43;
		$this->clock->timestamp  = self::NOW + 100;
		$new_run_id              = '00000000001700000100-0000000000000000043';

		$result = $this->dispatcher->retry_failed( self::IDENTITY, 'failed-run' );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $new_run_id, $result->value );
		$remaining = $store->all();
		if ( $remaining->is_failure() ) {
			self::fail( $remaining->error->message );
		}

		self::assertSame( array(), $remaining->value );
		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'a8csp_background_tasks/run',
						'args'     => array( self::IDENTITY, $new_run_id, 1 ),
						'group'    => self::IDENTITY . '|' . $new_run_id,
						'unique'   => false,
						'priority' => 10,
					),
				),
			),
			$this->backend->calls
		);
		$new_state = $this->option( 'a8csp_bgte_run_' . self::IDENTITY . '_' . $new_run_id );
		self::assertIsArray( $new_state );
		self::assertSame( self::ARGS, $new_state['start_args'] ?? null );
		self::assertSame( 0, $new_state['chunk_retries'] ?? null );
	}

	/** A failed retained-entry removal is logged without changing a successful retry outcome. */
	public function test_retry_failed_logs_a_failed_retained_entry_removal_and_keeps_success(): void {
		$store = new FailedRunStore( self::IDENTITY, new OptionRows( $this->wpdb ) );
		self::assertTrue(
			$store->record(
				'failed-run',
				self::NOW - 1,
				self::ARGS,
				2,
				new EngineError( 'Database unavailable.', \RuntimeException::class ),
				self::retained_failure( 'failed-run', 2 )
			)
		);
		$this->assert_failed_run_storage_is_authoritative();
		$this->backend->calls    = array();
		$this->randomizer->calls = array();
		$this->randomizer->value = 43;
		$this->clock->timestamp  = self::NOW + 100;
		$this->wpdb->script_result( 'update', false );

		$result = $this->dispatcher->retry_failed( self::IDENTITY, 'failed-run' );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( '00000000001700000100-0000000000000000043', $result->value );
		$remaining = $store->all();
		if ( $remaining->is_failure() ) {
			self::fail( $remaining->error->message );
		}
		self::assertSame( array( 'failed-run' ), \array_column( $remaining->value, 'run_id' ) );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Retried run "failed-run" could not be removed from retained failed-run data.',
					'context' => array(
						'name'   => self::IDENTITY,
						'run_id' => 'failed-run',
					),
				),
			),
			$this->logger->records
		);
	}

	/**
	 * Manual retry consumes the first retained entry when duplicates share a run identifier.
	 *
	 * @return  void
	 */
	public function test_retry_failed_uses_the_first_entry_matching_the_run_identifier(): void {
		$store = new FailedRunStore( self::IDENTITY, new OptionRows( $this->wpdb ) );
		self::assertTrue(
			$store->record(
				'failed-run',
				self::NOW - 2,
				array( 'ordinal' => 'first' ),
				2,
				new EngineError( 'Database unavailable.', \RuntimeException::class ),
				self::retained_failure( 'failed-run', 2 )
			)
		);
		self::assertTrue(
			$store->record(
				'failed-run',
				self::NOW - 1,
				array( 'ordinal' => 'second' ),
				2,
				new EngineError( 'Database unavailable.', \RuntimeException::class ),
				self::retained_failure( 'failed-run', 2 )
			)
		);
		$this->assert_failed_run_storage_is_authoritative();
		$this->backend->calls    = array();
		$this->randomizer->calls = array();
		$this->randomizer->value = 43;
		$this->clock->timestamp  = self::NOW + 100;
		$new_run_id              = '00000000001700000100-0000000000000000043';

		$result = $this->dispatcher->retry_failed( self::IDENTITY, 'failed-run' );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $new_run_id, $result->value );
		$new_state = $this->option( 'a8csp_bgte_run_' . self::IDENTITY . '_' . $new_run_id );
		self::assertIsArray( $new_state );
		self::assertSame( array( 'ordinal' => 'first' ), $new_state['start_args'] ?? null );
	}

	/**
	 * An unreadable failed-run store rejects retry before a fresh run can be admitted.
	 *
	 * @return  void
	 */
	public function test_retry_failed_rejects_an_authoritative_store_read_failure(): void {
		$store = new FailedRunStore( self::IDENTITY, new OptionRows( $this->wpdb ) );
		self::assertTrue(
			$store->record(
				'failed-run',
				self::NOW - 1,
				self::ARGS,
				2,
				new EngineError( 'Database unavailable.', \RuntimeException::class ),
				self::retained_failure( 'failed-run', 2 )
			)
		);
		$this->assert_failed_run_storage_is_authoritative();
		$failed_key = 'a8csp_bgte_failed_' . self::IDENTITY;
		$persisted  = $this->wpdb->rows[ $failed_key ] ?? null;
		self::assertIsString( $persisted );
		$this->backend->calls    = array();
		$this->randomizer->calls = array();
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted retry store read failure';
			}
		);

		$result = $this->dispatcher->retry_failed( self::IDENTITY, 'failed-run' );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame( 'Authoritative option-row read failed; repair WordPress option reads and retry.', $result->error->message );
		self::assertSame(
			array(
				'option_name'   => $failed_key,
				'storage_error' => 'scripted retry store read failure',
			),
			$result->error->context
		);
		self::assertSame( $persisted, $this->wpdb->rows[ $failed_key ] ?? null );
		self::assertIsArray( RawOptionDecoder::decode( $persisted ) );
		self::assertSame( 'off', $this->wpdb->autoload[ $failed_key ] ?? null );
		$this->assert_no_failed_run_option_function_writes();
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->randomizer->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
	}

	/**
	 * A missing failed entry names the retained run identifier that can be retried.
	 *
	 * @return  void
	 */
	public function test_retry_failed_rejects_a_missing_entry_and_names_what_exists(): void {
		$store = new FailedRunStore( self::IDENTITY, new OptionRows( $this->wpdb ) );
		self::assertTrue(
			$store->record(
				'retained-run',
				self::NOW - 1,
				self::ARGS,
				2,
				new EngineError( 'Database unavailable.', \RuntimeException::class ),
				self::retained_failure( 'retained-run', 2 )
			)
		);
		$this->assert_failed_run_storage_is_authoritative();
		$this->backend->calls    = array();
		$this->randomizer->calls = array();

		$result = $this->dispatcher->retry_failed( self::IDENTITY, 'missing-run' );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Failed run "missing-run" for background-work "runs-tests:email-digest" is not retained; retry one of the retained run identifiers: "retained-run".',
			$result->error->message
		);
		$remaining = $store->all();
		if ( $remaining->is_failure() ) {
			self::fail( $remaining->error->message );
		}

		self::assertCount( 1, $remaining->value );
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->randomizer->calls );
	}

	/**
	 * A delegated enqueue failure leaves the original failed task entry retryable.
	 *
	 * @return  void
	 */
	public function test_retry_failed_retains_the_task_entry_when_enqueue_fails(): void {
		$store = new FailedRunStore( self::IDENTITY, new OptionRows( $this->wpdb ) );
		self::assertTrue(
			$store->record(
				'failed-run',
				self::NOW - 1,
				self::ARGS,
				2,
				new EngineError( 'Database unavailable.', \RuntimeException::class ),
				self::retained_failure( 'failed-run', 2 )
			)
		);
		$this->assert_failed_run_storage_is_authoritative();
		$expected = $store->all();
		if ( $expected->is_failure() ) {
			self::fail( $expected->error->message );
		}

		$expected_entries = $expected->value;
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

		$result = $this->dispatcher->retry_failed( self::IDENTITY, 'failed-run' );
		$actual = $store->all();
		if ( $actual->is_failure() ) {
			self::fail( $actual->error->message );
		}

		self::assertSame( $failure, $result );
		self::assertSame( $expected_entries, $actual->value );
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
		return 'a8csp_bgte_run_' . self::IDENTITY . '_' . self::RUN_ID;
	}

	/**
	 * Returns the argument-identity lock option name.
	 *
	 * @return  string
	 */
	private function lock_option_name(): string {
		return 'a8csp_bgte_lock_' . self::IDENTITY . '_' . self::ARGS_HASH;
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
		$options[ 'a8csp_bgte_latest_' . self::IDENTITY ] = array(
			'all'     => 'run-running',
			'by_hash' => array( self::ARGS_HASH => 'run-running' ),
		);

		$GLOBALS['a8csp_bgte_test_options'] = $options;
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
	 * Returns failed runs decoded from the authoritative raw option row.
	 *
	 * @return  array<array-key, mixed>
	 */
	private function failed_runs(): array {
		$key = 'a8csp_bgte_failed_' . self::IDENTITY;
		$raw = $this->wpdb->rows[ $key ] ?? null;
		self::assertIsString( $raw );
		$value = RawOptionDecoder::decode( $raw );
		self::assertIsArray( $value );
		self::assertSame( 'off', $this->wpdb->autoload[ $key ] ?? null );
		$this->assert_no_failed_run_option_function_writes();

		return $value;
	}

	/**
	 * Returns complete consumer failure metadata for one retained task run.
	 *
	 * @param   string $run_id   Run identifier.
	 * @param   int    $attempts Consumed attempts.
	 *
	 * @return  RunFailure
	 */
	private static function retained_failure( string $run_id, int $attempts ): RunFailure {
		return new RunFailure(
			name: self::IDENTITY,
			run_id: $run_id,
			attempts: $attempts,
			stage: 'execution',
			code: ApiErrorCode::ExecutionFailed,
			summary: 'Database unavailable.',
			failed_chunk: null,
		);
	}

	/** Asserts that failed-run persistence uses only the authoritative raw-storage seam. */
	private function assert_failed_run_storage_is_authoritative(): void {
		$this->failed_runs();
	}

	/** Asserts that no WordPress option function wrote the failed-run row. */
	private function assert_no_failed_run_option_function_writes(): void {
		$calls = $GLOBALS['a8csp_bgte_test_option_calls'] ?? null;
		self::assertIsArray( $calls );
		$key = 'a8csp_bgte_failed_' . self::IDENTITY;
		foreach ( $calls as $call ) {
			self::assertIsArray( $call );
			$args = $call['args'] ?? null;
			self::assertIsArray( $args );
			self::assertNotSame( $key, $args[0] ?? null );
		}
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
		if ( \is_string( $raw ) ) {
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

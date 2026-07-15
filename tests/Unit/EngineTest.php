<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\TerminalTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\WorkRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\Schedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\MaintenanceTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Tasks;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingRandomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the internal engine facade across registration, scheduling, and persisted run state.
 *
 */
#[CoversClass( Engine::class )]
#[CoversClass( Tasks::class )]
#[CoversClass( Schedules::class )]
#[CoversClass( Batches::class )]
#[UsesClass( EngineError::class )]
#[UsesClass( FailedRunStore::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
#[UsesClass( Dispatcher::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( StoreFactory::class )]
#[UsesClass( BatchRegistry::class )]
#[UsesClass( TaskRegistry::class )]
#[UsesClass( ScheduleRegistry::class )]
#[UsesClass( OccurrenceDelivery::class )]
final class EngineTest extends TestCase {
	private const ARGS                 = array(
		'site_id' => 7,
		'mode'    => 'full',
	);
	private const BATCH_IDENTITY       = 'consumer-plugin:catalog-sync';
	private const MAINTENANCE_IDENTITY = 'a8csp-bgte:maintenance';
	private const NOW                  = 1_700_000_000;
	private const RUN_ID               = '00000000001700000000-0000000000000000042';
	private const TASK_IDENTITY        = 'consumer-plugin:email-digest';

	private RecordingBackend $backend;
	private Engine $engine;
	private WpdbLockSpy $wpdb;

	/**
	 * Loads the guarded WordPress stubs required by the orchestration graph.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once __DIR__ . '/wp-options-stubs.php';
		require_once __DIR__ . '/wp-hook-stubs.php';
		require_once __DIR__ . '/wp-lock-stubs.php';
		require_once __DIR__ . '/wp-time-constant-stubs.php';
		require_once __DIR__ . '/Engine/Backends/wp-json-encode-stub.php';
	}

	/**
	 * Resets observable boundaries and constructs one empty consumer facade.
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

		$clock                = new FixedClock( self::NOW );
		$logger               = new RecordingLogger();
		$work                 = new WorkRegistry();
		$tasks                = new TaskRegistry( $work );
		$batches              = new BatchRegistry( $work );
		$this->backend        = new RecordingBackend();
		$this->wpdb           = new WpdbLockSpy();
		$guard                = new OverlapGuard( $clock, $logger, new OptionRows( $this->wpdb ) );
		$stores               = new StoreFactory( $clock, new OptionRows( $this->wpdb ) );
		$randomizer           = new RecordingRandomizer( 42 );
		$lock_windows         = new LockWindows( $clock );
		$terminal_transitions = new TerminalTransitions( $guard, $stores, $clock, $lock_windows, $logger );

		$dispatcher = new Dispatcher(
			$tasks,
			$batches,
			$this->backend,
			$guard,
			$stores,
			$clock,
			$randomizer,
			$logger,
			$lock_windows,
			$terminal_transitions,
		);
		$registry   = new ScheduleRegistry( new OptionRows( $this->wpdb ) );
		$delivery   = new OccurrenceDelivery(
			$registry,
			$dispatcher,
			new OccurrenceLease( new OptionRows( $this->wpdb ), $clock, new RecordingRandomizer( 42 ) ),
			new SchedulerFacade( array( $this->backend ) ),
			new OptionRows( $this->wpdb ),
			$clock,
			$logger
		);
		$schedules  = new Schedules( $registry, $this->backend, $clock, $delivery );

		$this->engine = new Engine(
			new Tasks( $tasks, $dispatcher ),
			$schedules,
			new Batches( $batches, $dispatcher ),
			$dispatcher,
		);
	}

	/**
	 * Accessors retain the constructor-injected API objects.
	 *
	 * @return  void
	 */
	public function test_accessors_return_the_same_api_objects(): void {
		self::assertSame( $this->engine->tasks(), $this->engine->tasks() );
		self::assertSame( $this->engine->schedules(), $this->engine->schedules() );
		self::assertSame( $this->engine->batches(), $this->engine->batches() );
	}

	/**
	 * Task registration and enqueueing reach the scheduler and persist the returned run.
	 *
	 * @return  void
	 */
	public function test_register_then_enqueue_round_trips_through_the_task_facade(): void {
		$this->engine->tasks()->register( self::TASK_IDENTITY, new RecordingTask( 'email-digest' ) );

		$result = $this->engine->tasks()->enqueue(
			self::TASK_IDENTITY,
			self::ARGS,
			delay: 300,
			unique: true,
			priority: 5
		);

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		self::assertSame(
			array(
				array(
					'verb' => 'schedule_single',
					'args' => array(
						'hook'      => 'a8csp_background_tasks/run',
						'timestamp' => self::NOW + 300,
						'args'      => array( self::TASK_IDENTITY, self::RUN_ID, 1 ),
						'group'     => self::TASK_IDENTITY . '|' . self::RUN_ID,
						'priority'  => 5,
					),
				),
			),
			$this->backend->calls
		);

		$run = $this->option( 'a8csp_bgte_run_' . self::TASK_IDENTITY . '_' . self::RUN_ID );
		self::assertIsArray( $run );
		self::assertSame( 'running', $run['status'] ?? null );
		self::assertSame( self::ARGS, $run['start_args'] ?? null );
		self::assertSame( array( self::ARGS ), $run['queue'] ?? null );
		self::assertSame(
			array(
				'a8csp_background_tasks/started/' . self::TASK_IDENTITY,
				'a8csp_background_tasks/started',
			),
			$this->fired_hook_names()
		);
	}

	/**
	 * Batch registration and starting reach the scheduler and persist the returned run.
	 *
	 * @return  void
	 */
	public function test_register_then_start_round_trips_through_the_batch_facade(): void {
		$this->engine->batches()->register( self::BATCH_IDENTITY, new RecordingBatch( 'catalog-sync' ) );

		$result = $this->engine->batches()->start( self::BATCH_IDENTITY, self::ARGS, unique: true, priority: 23 );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'a8csp_background_tasks/start',
						'args'     => array( self::BATCH_IDENTITY, self::RUN_ID, 1 ),
						'group'    => self::BATCH_IDENTITY . '|' . self::RUN_ID,
						'unique'   => true,
						'priority' => 23,
					),
				),
			),
			$this->backend->calls
		);

		$run = $this->option( 'a8csp_bgte_run_' . self::BATCH_IDENTITY . '_' . self::RUN_ID );
		self::assertIsArray( $run );
		self::assertSame( 'running', $run['status'] ?? null );
		self::assertSame( self::ARGS, $run['start_args'] ?? null );
		self::assertSame( array(), $run['queue'] ?? null );
	}

	/**
	 * An unknown task preserves the orchestration failure at the public boundary.
	 *
	 * @return  void
	 */
	public function test_enqueue_surfaces_an_unregistered_name_failure(): void {
		$result = $this->engine->tasks()->enqueue( 'consumer-plugin:unknown', self::ARGS );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Task "consumer-plugin:unknown" is not registered; register it before enqueueing.',
			$result->error->message
		);
		self::assertSame( array(), $this->backend->calls );
	}

	/**
	 * Cross-kind identity collisions fail at registration before dispatch can observe ambiguity.
	 *
	 * @return  void
	 */
	public function test_cross_kind_collision_fails_during_engine_registration(): void {
		$this->engine->tasks()->register( 'consumer-plugin:shared-work', new RecordingTask( 'shared-work' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs(
			'Background-work identity "consumer-plugin:shared-work" is already registered as a task; it cannot also be registered as a batch.'
		);

		$this->engine->batches()->register( 'consumer-plugin:shared-work', new RecordingBatch( 'shared-work' ) );
	}

	/**
	 * Manual retry declares its result non-discardable at the engine boundary.
	 *
	 * @return  void
	 */
	public function test_retry_failed_declares_no_discard_directly(): void {
		$method = new \ReflectionMethod( Engine::class, 'retry_failed' );

		self::assertCount( 1, $method->getAttributes( \NoDiscard::class ) );
	}

	/**
	 * Cancellation declares its result non-discardable at the engine boundary.
	 *
	 * @return  void
	 */
	public function test_cancel_declares_no_discard_directly(): void {
		$method = new \ReflectionMethod( Engine::class, 'cancel' );

		self::assertCount( 1, $method->getAttributes( \NoDiscard::class ) );
	}

	/**
	 * Cancellation preserves the orchestration failure at the public engine API.
	 *
	 * @return  void
	 */
	public function test_cancel_surfaces_an_unregistered_name_failure(): void {
		$result = $this->engine->cancel( 'consumer-plugin:unknown', 'run-1' );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Background-work "consumer-plugin:unknown" is not registered; register the matching task or batch before cancelling its run.',
			$result->error->message
		);
		self::assertSame( array(), $this->backend->calls );
	}

	/**
	 * Manual retry preserves the orchestration failure at the public engine API.
	 *
	 * @return  void
	 */
	public function test_retry_failed_surfaces_an_unregistered_name_failure(): void {
		$result = $this->engine->retry_failed( 'consumer-plugin:unknown', 'run-1' );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Background-work "consumer-plugin:unknown" is not registered; register the matching task or batch before retrying its failed run.',
			$result->error->message
		);
		self::assertSame( array(), $this->backend->calls );
	}

	/**
	 * Manual retry dispatches a retained failed maintenance run through the internal task seam.
	 *
	 * @return  void
	 */
	public function test_retry_failed_dispatches_the_engine_maintenance_identity(): void {
		$this->engine->tasks()->register( self::MAINTENANCE_IDENTITY, new RecordingTask( MaintenanceTask::NAME ) );
		$store = new FailedRunStore( self::MAINTENANCE_IDENTITY, new OptionRows( $this->wpdb ) );
		self::assertTrue(
			$store->record(
				'failed-maintenance-run',
				self::NOW - 1,
				array(),
				1,
				new EngineError( 'Maintenance failed.' ),
				new RunFailure(
					name: self::MAINTENANCE_IDENTITY,
					run_id: 'failed-maintenance-run',
					attempts: 1,
					stage: 'execution',
					code: ApiErrorCode::ExecutionFailed,
					summary: 'Maintenance failed.',
					failed_chunk: null,
				)
			)
		);
		$failed_key = 'a8csp_bgte_failed_' . self::MAINTENANCE_IDENTITY;
		$failed_raw = $this->wpdb->rows[ $failed_key ] ?? null;
		self::assertIsString( $failed_raw );
		$failed_runs = RawOptionDecoder::decode( $failed_raw );
		self::assertIsArray( $failed_runs );
		self::assertSame( array( 'failed-maintenance-run' ), \array_column( $failed_runs, 'run_id' ) );
		self::assertSame( 'off', $this->wpdb->autoload[ $failed_key ] ?? null );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );

		$result = $this->engine->retry_failed( self::MAINTENANCE_IDENTITY, 'failed-maintenance-run' );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		$remaining = $store->all();
		if ( $remaining->is_failure() ) {
			self::fail( $remaining->error->message );
		}

		self::assertSame( array(), $remaining->value );
		$failed_raw = $this->wpdb->rows[ $failed_key ] ?? null;
		self::assertIsString( $failed_raw );
		self::assertSame( array(), RawOptionDecoder::decode( $failed_raw ) );
		self::assertSame( 'off', $this->wpdb->autoload[ $failed_key ] ?? null );
		$this->assert_no_option_function_write_for( $failed_key );
		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'a8csp_background_tasks/run',
						'args'     => array( self::MAINTENANCE_IDENTITY, self::RUN_ID, 1 ),
						'group'    => self::MAINTENANCE_IDENTITY . '|' . self::RUN_ID,
						'unique'   => false,
						'priority' => 10,
					),
				),
			),
			$this->backend->calls
		);
	}

	/**
	 * Asserts that no WordPress option function wrote one authoritative row.
	 *
	 * @param   string $key Option name.
	 *
	 * @return  void
	 */
	private function assert_no_option_function_write_for( string $key ): void {
		$calls = $GLOBALS['a8csp_bgte_test_option_calls'] ?? null;
		self::assertIsArray( $calls );
		foreach ( $calls as $call ) {
			self::assertIsArray( $call );
			$args = $call['args'] ?? null;
			self::assertIsArray( $args );
			self::assertNotSame( $key, $args[0] ?? null );
		}
	}

	/**
	 * Returns one value from the in-memory WordPress option boundary.
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
	 * Returns fired action names from the WordPress hook boundary.
	 *
	 * @return  list<string>
	 */
	private function fired_hook_names(): array {
		$actions = $GLOBALS['a8csp_bgte_test_fired_actions'] ?? null;
		self::assertIsArray( $actions );
		$hook_names = array();
		foreach ( $actions as $action ) {
			self::assertIsArray( $action );
			$hook_name = $action['hook_name'] ?? null;
			self::assertIsString( $hook_name );
			$hook_names[] = $hook_name;
		}

		return $hook_names;
	}
}

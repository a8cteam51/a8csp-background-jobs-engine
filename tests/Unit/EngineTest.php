<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Batches;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LockRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Orchestrator;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Tasks;
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
 * Exercises the consumer facade across registration, scheduling, and persisted run state.
 *
 */
#[CoversClass( Engine::class )]
#[CoversClass( Tasks::class )]
#[CoversClass( Schedules::class )]
#[CoversClass( Batches::class )]
#[UsesClass( EngineError::class )]
#[UsesClass( LockRows::class )]
#[UsesClass( Orchestrator::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( StoreFactory::class )]
#[UsesClass( BatchRegistry::class )]
#[UsesClass( TaskRegistry::class )]
#[UsesClass( ScheduleRegistry::class )]
final class EngineTest extends TestCase {
	private const ARGS   = array(
		'site_id' => 7,
		'mode'    => 'full',
	);
	private const NOW    = 1_700_000_000;
	private const RUN_ID = '00000000001700000000-0000000000000000042';

	private RecordingBackend $backend;
	private Engine $engine;

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
		require_once __DIR__ . '/Scheduling/wp-json-encode-stub.php';
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

		$clock         = new FixedClock( self::NOW );
		$logger        = new RecordingLogger();
		$tasks         = new TaskRegistry();
		$batches       = new BatchRegistry();
		$this->backend = new RecordingBackend();
		$wpdb          = new WpdbLockSpy();

		$orchestrator = new Orchestrator(
			$tasks,
			$batches,
			$this->backend,
			new OverlapGuard( $clock, $logger, new LockRows( $wpdb ) ),
			new StoreFactory( $clock, new OptionRows( $wpdb ) ),
			$logger,
			$clock,
			new RecordingRandomizer( 42 ),
		);

		$this->engine = new Engine(
			new Tasks( $tasks, $orchestrator ),
			new Schedules(
				new ScheduleRegistry( new OptionRows( $wpdb ) ),
				$this->backend,
				$clock,
				$orchestrator,
				new OccurrenceLease( new LockRows( $wpdb ), $clock, new RecordingRandomizer( 42 ) ),
				$logger
			),
			new Batches( $batches, $orchestrator ),
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
		$this->engine->tasks()->register( new RecordingTask( 'email-digest' ) );

		$result = $this->engine->tasks()->enqueue(
			'email-digest',
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
						'hook'      => 'a8csp/background_tasks/run',
						'timestamp' => self::NOW + 300,
						'args'      => array( 'email-digest', self::RUN_ID, 1 ),
						'group'     => 'email-digest|' . self::RUN_ID,
						'priority'  => 5,
					),
				),
			),
			$this->backend->calls
		);

		$run = $this->option( 'a8csp_bgte_run_email-digest_' . self::RUN_ID );
		self::assertIsArray( $run );
		self::assertSame( 'running', $run['status'] ?? null );
		self::assertSame( self::ARGS, $run['start_args'] ?? null );
		self::assertSame( array( self::ARGS ), $run['queue'] ?? null );
		self::assertSame(
			array(
				'a8csp/background_tasks/started/email-digest',
				'a8csp/background_tasks/started',
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
		$this->engine->batches()->register( new RecordingBatch( 'catalog-sync' ) );

		$result = $this->engine->batches()->start( 'catalog-sync', self::ARGS, unique: true, priority: 23 );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'a8csp/background_tasks/start',
						'args'     => array( 'catalog-sync', self::RUN_ID, 1 ),
						'group'    => 'catalog-sync|' . self::RUN_ID,
						'unique'   => true,
						'priority' => 23,
					),
				),
			),
			$this->backend->calls
		);

		$run = $this->option( 'a8csp_bgte_run_catalog-sync_' . self::RUN_ID );
		self::assertIsArray( $run );
		self::assertSame( 'running', $run['status'] ?? null );
		self::assertSame( self::ARGS, $run['start_args'] ?? null );
		self::assertSame( array(), $run['queue'] ?? null );
	}

	/**
	 * The internal maintenance task identity cannot be shadowed by a consumer batch.
	 *
	 * @return  void
	 */
	public function test_batch_registration_rejects_the_engine_maintenance_identity(): void {
		try {
			$this->engine->batches()->register( new RecordingBatch( 'a8csp-bgte-maintenance' ) );
			self::fail( 'Reserved maintenance batch registration did not throw.' );
		} catch ( \LogicException $exception ) {
			self::assertSame(
				'Batch name "a8csp-bgte-maintenance" is reserved for engine maintenance; choose a consumer-specific batch name.',
				$exception->getMessage()
			);
		}
	}

	/**
	 * An unknown task preserves the orchestration failure at the public boundary.
	 *
	 * @return  void
	 */
	public function test_enqueue_surfaces_an_unregistered_name_failure(): void {
		$result = $this->engine->tasks()->enqueue( 'unknown', self::ARGS );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Task "unknown" is not registered; register it before enqueueing.',
			$result->error->message
		);
		self::assertSame( array(), $this->backend->calls );
	}

	/**
	 * A name shared by a task and batch preserves the orchestration ambiguity failure.
	 *
	 * @return  void
	 */
	public function test_enqueue_surfaces_an_ambiguous_name_failure(): void {
		$this->engine->tasks()->register( new RecordingTask( 'shared-work' ) );
		$this->engine->batches()->register( new RecordingBatch( 'shared-work' ) );

		$result = $this->engine->tasks()->enqueue( 'shared-work', self::ARGS );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Background-work name "shared-work" is registered as both a task and a batch; rename one registration so each name identifies exactly one type.',
			$result->error->message
		);
		self::assertSame( array(), $this->backend->calls );
	}

	/**
	 * Manual retry preserves the orchestration failure at the public task API.
	 *
	 * @return  void
	 */
	public function test_retry_failed_surfaces_an_unregistered_name_failure(): void {
		$result = $this->engine->tasks()->retry_failed( 'unknown', 'run-1' );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Background-work "unknown" is not registered; register the matching task or batch before retrying its failed run.',
			$result->error->message
		);
		self::assertSame( array(), $this->backend->calls );
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

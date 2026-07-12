<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Orchestration;

use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\BatchContextInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\NonRetryableExceptionInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\NonRetryableTaskException;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\BatchContext;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LockRows;
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
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\SchedulingErrorReason;
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
 * Pins the observable batch lifecycle across scheduling, storage, hooks, locks, and callbacks.
 *
 */
#[CoversClass( Orchestrator::class )]
#[UsesClass( BatchContext::class )]
#[UsesClass( BatchRegistry::class )]
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
#[UsesClass( TaskRegistry::class )]
final class OrchestratorBatchTest extends TestCase {
	private const ARGS = array(
		'site_id' => 7,
		'mode'    => 'full',
	);

	private const ARGS_HASH = '7dcca9cc21619f109d6f0423c49b010606457ea4a713721e9ce5134949d72bd2';
	private const NAME      = 'catalog-sync';
	private const NOW       = 1_700_000_000;
	private const RUN_ID    = '00000000001700000000-0000000000000000042';

	private FixedClock $clock;
	private RecordingBackend $backend;
	private RecordingBatch $batch;
	private RecordingLogger $logger;
	private RecordingRandomizer $randomizer;
	private BatchRegistry $batches;
	private TaskRegistry $tasks;
	private WpdbLockSpy $wpdb;
	private Orchestrator $orchestrator;

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
	 * Resets every observable boundary and constructs one registered batch lifecycle.
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
		$this->batch      = new RecordingBatch( self::NAME );
		$this->logger     = new RecordingLogger();
		$this->randomizer = new RecordingRandomizer( 42 );
		$this->batches    = new BatchRegistry();
		$this->tasks      = new TaskRegistry();
		$this->wpdb       = new WpdbLockSpy();

		$this->batches->register( $this->batch );
		$this->orchestrator = new Orchestrator(
			$this->tasks,
			$this->batches,
			$this->backend,
			new OverlapGuard( $this->clock, $this->logger, new LockRows( $this->wpdb ) ),
			new StoreFactory( $this->clock ),
			$this->logger,
			$this->clock,
			$this->randomizer,
		);
	}

	/**
	 * Hook registration exposes each backend-isolated batch stage and one shared run dispatcher.
	 *
	 * @return  void
	 */
	public function test_register_hooks_wires_all_internal_batch_actions(): void {
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
	 * Starting a batch persists its identity and schedules queue generation as its own action.
	 *
	 * @return  void
	 */
	public function test_start_batch_creates_a_run_and_schedules_the_internal_start_action(): void {
		$result = $this->orchestrator->start_batch( self::NAME, self::ARGS, unique: true, priority: 23 );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'a8csp/background_tasks/start',
						'args'     => array( self::NAME, self::RUN_ID, 1 ),
						'group'    => self::NAME . '|' . self::RUN_ID,
						'unique'   => true,
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
				'queue'         => array(),
				'chunk_retries' => 0,
				'action_seq'    => 1,
				'created_at'    => self::NOW,
				'heartbeat_at'  => self::NOW,
			),
			$this->option( $this->run_option_name() )
		);
		self::assertSame( array(), $this->batch->generate_calls );
		self::assertSame( array(), $this->fired_actions() );
	}

	/**
	 * Manual retry starts a fresh batch run and removes the consumed failed entry.
	 *
	 * @return  void
	 */
	public function test_retry_failed_restarts_a_batch_and_removes_the_failed_entry(): void {
		$store = new FailedRunStore( self::NAME );
		$store->record(
			'failed-run',
			self::NOW - 1,
			self::ARGS,
			2,
			new EngineError( 'Chunk processing exploded.', \RuntimeException::class )
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
						'hook'     => 'a8csp/background_tasks/start',
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
		self::assertSame( array(), $new_state['queue'] ?? null );
		self::assertSame( 0, $new_state['chunk_retries'] ?? null );
		self::assertSame( 1, $new_state['action_seq'] ?? null );
	}

	/**
	 * An initial scheduling failure is returned unchanged after active state is compensated.
	 *
	 * @return  void
	 */
	public function test_start_batch_surfaces_scheduling_failure_and_removes_active_state(): void {
		$failure                                 = $this->scheduling_failure_result();
		$this->backend->results['enqueue_async'] = $failure;

		$result = $this->orchestrator->start_batch( self::NAME, self::ARGS );

		self::assertSame( $failure, $result );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		self::assertNull( $this->option( 'a8csp_bgte_history_' . self::NAME ) );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
		self::assertSame( array(), $this->batch->generate_calls );
		self::assertSame( array(), $this->batch->failure_calls );
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
	 * A held overlap lock rejects manual start without stopping the incumbent run.
	 *
	 * @return  void
	 */
	public function test_start_batch_rejects_a_held_overlap_without_stopping_the_previous_run(): void {
		$this->seed_running_lock();

		$result = $this->orchestrator->start_batch( self::NAME, self::ARGS );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Batch "catalog-sync" is already running as run "run-running"; wait for that run to finish before starting the same arguments.',
			$result->error->message
		);
		self::assertSame( 'run-running', $this->lock()['run_id'] ?? null );
		self::assertSame( array(), $this->backend->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertSame( array(), $this->batch->failure_calls );
	}

	/**
	 * The start action materializes and filters the queue before exposing the started lifecycle.
	 *
	 * @return  void
	 */
	public function test_handle_start_action_persists_the_filtered_queue_and_schedules_continue(): void {
		$this->batch->queue = array(
			'first-key'  => array( 'chunk' => 'first' ),
			'second-key' => array( 'chunk' => 'second' ),
		);
		$filter_call        = null;
		$this->set_filter_value(
			'a8csp/background_tasks/queue/' . self::NAME,
			static function ( array $queue, array $start_args, string $run_id ) use ( &$filter_call ): array {
				$filter_call = array(
					'arity' => \func_num_args(),
					'args'  => \func_get_args(),
				);

				return array(
					array( 'chunk' => 'filtered-first' ),
					...$queue,
					array( 'chunk' => 'filtered-last' ),
				);
			}
		);
		$this->start_batch();
		$this->backend->calls   = array();
		$this->clock->timestamp = self::NOW + 30;

		$this->orchestrator->handle_start_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS ), $this->batch->generate_calls );
		self::assertSame(
			array(
				'arity' => 3,
				'args'  => array(
					array(
						array( 'chunk' => 'first' ),
						array( 'chunk' => 'second' ),
					),
					self::ARGS,
					self::RUN_ID,
				),
			),
			$filter_call
		);
		$state = $this->run_state();
		self::assertSame(
			array(
				array( 'chunk' => 'filtered-first' ),
				array( 'chunk' => 'first' ),
				array( 'chunk' => 'second' ),
				array( 'chunk' => 'filtered-last' ),
			),
			$state['queue']
		);
		self::assertSame( self::NOW + 30, $state['heartbeat_at'] );
		self::assertSame( 2, $state['action_seq'] );
		self::assertSame( self::NOW + 30, $this->lock()['heartbeat_at'] ?? null );
		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'a8csp/background_tasks/continue',
						'args'     => array( self::NAME, self::RUN_ID, 2 ),
						'group'    => self::NAME . '|' . self::RUN_ID,
						'unique'   => false,
						'priority' => 10,
					),
				),
			),
			$this->backend->calls
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
	 * A throwing started listener terminalizes the run before its first continue is scheduled.
	 *
	 * @return  void
	 */
	public function test_handle_start_action_fails_terminally_when_a_started_listener_throws(): void {
		$this->batch->queue = array( array( 'chunk' => 'first' ) );
		$this->start_batch();
		$this->backend->calls = array();
		$this->set_action_throwable(
			'a8csp/background_tasks/started/' . self::NAME,
			new \RuntimeException( 'Started listener exploded.' )
		);
		$this->clock->timestamp = self::NOW + 30;

		$this->orchestrator->handle_start_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array(), $this->backend->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		self::assertCount( 1, $this->batch->failure_calls );
		$error = $this->batch->failure_calls[0]['error'];
		self::assertSame( 'Started listener exploded.', $error->message );
		self::assertSame( \RuntimeException::class, $error->exception_class );
		self::assertSame(
			array(
				'a8csp/background_tasks/started/' . self::NAME,
				'a8csp/background_tasks/started',
				'a8csp/background_tasks/failed/' . self::NAME,
				'a8csp/background_tasks/failed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_terminal_history();
	}

	/**
	 * A queue-generation throwable fails the run without exposing a partial started lifecycle.
	 *
	 * @return  void
	 */
	public function test_handle_start_action_fails_terminally_when_queue_generation_throws(): void {
		$this->batch->generate_throwable = new \RuntimeException( 'Queue generation exploded.' );
		$this->start_batch();
		$this->backend->calls   = array();
		$this->clock->timestamp = self::NOW + 30;

		$this->orchestrator->handle_start_action( self::NAME, self::RUN_ID, $this->action_seq() );

		$this->assert_terminal_start_error( 'Queue generation exploded.', \RuntimeException::class );
	}

	/**
	 * A non-array queue-filter result fails the run before scheduling continue.
	 *
	 * @return  void
	 */
	public function test_handle_start_action_fails_terminally_for_a_non_array_filtered_queue(): void {
		$this->batch->queue = array( array( 'chunk' => 'first' ) );
		$this->set_filter_value( 'a8csp/background_tasks/queue/' . self::NAME, 'invalid queue' );
		$this->start_batch();
		$this->backend->calls   = array();
		$this->clock->timestamp = self::NOW + 30;

		$this->orchestrator->handle_start_action( self::NAME, self::RUN_ID, $this->action_seq() );

		$this->assert_terminal_start_error(
			'Batch queue filter returned a non-array value; return one argument array per chunk.',
			\UnexpectedValueException::class
		);
	}

	/**
	 * Ownership loss during queue generation abandons the queue commit and continuation.
	 *
	 * @return  void
	 */
	public function test_handle_start_action_abandons_queue_commit_after_callback_ownership_loss(): void {
		$this->batch->queue = array( array( 'chunk' => 'generated' ) );
		$this->start_batch();
		$this->backend->calls     = array();
		$this->clock->timestamp   = self::NOW + 30;
		$observed_state           = null;
		$this->batch->on_generate = function ( array $start_args ) use ( &$observed_state ): void {
			$observed_state = $this->option( $this->run_option_name() );
			$this->replace_lock_owner( 'run-newer', self::NOW + 30 );
		};

		$this->orchestrator->handle_start_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS ), $this->batch->generate_calls );
		self::assertIsArray( $observed_state );
		self::assertSame( $observed_state, $this->option( $this->run_option_name() ) );
		self::assertSame( array(), $observed_state['queue'] ?? null );
		self::assertSame( 1, $observed_state['action_seq'] ?? null );
		self::assertSame( self::NOW + 30, $observed_state['heartbeat_at'] ?? null );
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->fired_actions() );
		self::assertSame( array(), $this->batch->failure_calls );
		self::assertSame(
			array(
				array(
					'level'   => 'info',
					'message' => 'Run ownership moved during a user callback; state commit abandoned.',
					'context' => array(
						'batch_name' => self::NAME,
						'run_id'     => self::RUN_ID,
					),
				),
			),
			$this->logger->records
		);
	}

	/**
	 * Continue removes exactly one queue head and carries it into a distinct run action.
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_dequeues_one_chunk_and_schedules_run(): void {
		$first  = array( 'chunk' => 'first' );
		$second = array( 'chunk' => 'second' );
		$this->prepare_started_batch( array( $first, $second ) );
		$this->clock->timestamp = self::NOW + 90;

		$this->orchestrator->handle_continue_action( self::NAME, self::RUN_ID, $this->action_seq() );

		$state = $this->run_state();
		self::assertSame( array( $second ), $state['queue'] );
		self::assertSame( 3, $state['action_seq'] );
		self::assertSame( self::NOW + 90, $state['heartbeat_at'] );
		self::assertSame( self::NOW + 90, $this->lock()['heartbeat_at'] ?? null );
		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'a8csp/background_tasks/run',
						'args'     => array( self::NAME, self::RUN_ID, $first, 3 ),
						'group'    => self::NAME . '|' . self::RUN_ID,
						'unique'   => false,
						'priority' => 10,
					),
				),
			),
			$this->backend->calls
		);
		self::assertSame( array(), $this->batch->process_calls );
	}

	/**
	 * Continue sends a drained run to cleanup without creating a run action.
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_schedules_cleanup_for_an_empty_queue(): void {
		$this->prepare_started_batch( array() );
		$this->clock->timestamp = self::NOW + 90;

		$this->orchestrator->handle_continue_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array(), $this->run_state()['queue'] );
		self::assertSame( 3, $this->run_state()['action_seq'] );
		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'a8csp/background_tasks/cleanup',
						'args'     => array( self::NAME, self::RUN_ID, 3 ),
						'group'    => self::NAME . '|' . self::RUN_ID,
						'unique'   => false,
						'priority' => 10,
					),
				),
			),
			$this->backend->calls
		);
	}

	/**
	 * A normal chunk return commits buffered mutations and delays the next continue action.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_commits_context_mutations_and_schedules_delayed_continue(): void {
		$chunk_args = array( 'chunk' => 'current' );
		$remaining  = array( 'chunk' => 'remaining' );
		$this->prepare_scheduled_chunk( array( $chunk_args, $remaining ) );
		$filter_call = null;
		$this->set_filter_value(
			'a8csp/background_tasks/continue_delay',
			static function ( int $default_delay, string $name, string $run_id ) use ( &$filter_call ): int {
				$filter_call = array(
					'arity' => \func_num_args(),
					'args'  => \func_get_args(),
				);

				return 75;
			}
		);
		$this->batch->on_process = static function (
			array $processed_args,
			BatchContextInterface $context
		) use ( $chunk_args ): void {
			self::assertSame( $chunk_args, $processed_args );
			self::assertSame( self::RUN_ID, $context->get_run_id() );
			self::assertSame( self::ARGS, $context->get_start_args() );
			$context->enqueue( array( 'chunk' => 'appended' ) );
			$context->prepend( array( 'chunk' => 'prepended-1' ) );
			$context->prepend( array( 'chunk' => 'prepended-2' ) );
		};
		$this->clock->timestamp  = self::NOW + 120;

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $chunk_args, $this->action_seq() );

		self::assertCount( 1, $this->batch->process_calls );
		self::assertSame( $chunk_args, $this->batch->process_calls[0]['chunk_args'] );
		self::assertInstanceOf( BatchContext::class, $this->batch->process_calls[0]['context'] );
		$state = $this->run_state();
		self::assertSame(
			array(
				array( 'chunk' => 'prepended-2' ),
				array( 'chunk' => 'prepended-1' ),
				$remaining,
				array( 'chunk' => 'appended' ),
			),
			$state['queue']
		);
		self::assertSame( 0, $state['chunk_retries'] );
		self::assertSame( 4, $state['action_seq'] );
		self::assertSame( self::NOW + 120, $state['heartbeat_at'] );
		self::assertSame(
			array(
				'arity' => 3,
				'args'  => array( 60, self::NAME, self::RUN_ID ),
			),
			$filter_call
		);
		self::assertSame(
			array(
				array(
					'verb' => 'schedule_single',
					'args' => array(
						'hook'      => 'a8csp/background_tasks/continue',
						'timestamp' => self::NOW + 195,
						'args'      => array( self::NAME, self::RUN_ID, 4 ),
						'group'     => self::NAME . '|' . self::RUN_ID,
						'priority'  => 10,
					),
				),
			),
			$this->backend->calls
		);
		self::assertSame( array(), $this->batch->success_calls );
		self::assertSame( array(), $this->batch->failure_calls );
	}

	/**
	 * Ownership loss during chunk work abandons buffered queue mutations and continuation.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_abandons_queue_commit_after_callback_ownership_loss(): void {
		$chunk_args = array( 'chunk' => 'current' );
		$remaining  = array( 'chunk' => 'remaining' );
		$this->prepare_scheduled_chunk( array( $chunk_args, $remaining ) );
		$this->clock->timestamp  = self::NOW + 120;
		$observed_state          = null;
		$this->batch->on_process = function (
			array $processed_args,
			BatchContextInterface $context
		) use ( &$observed_state ): void {
			$observed_state = $this->option( $this->run_option_name() );
			$context->enqueue( array( 'chunk' => 'discarded' ) );
			$this->replace_lock_owner( 'run-newer', self::NOW + 120 );
		};

		$this->orchestrator->handle_run_action(
			self::NAME,
			self::RUN_ID,
			$chunk_args,
			$this->action_seq()
		);

		self::assertCount( 1, $this->batch->process_calls );
		self::assertIsArray( $observed_state );
		self::assertSame( $observed_state, $this->option( $this->run_option_name() ) );
		self::assertSame( array( $remaining ), $observed_state['queue'] ?? null );
		self::assertSame( 3, $observed_state['action_seq'] ?? null );
		self::assertSame( self::NOW + 120, $observed_state['heartbeat_at'] ?? null );
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->fired_actions() );
		self::assertSame( array(), $this->batch->failure_calls );
		self::assertSame(
			array(
				array(
					'level'   => 'info',
					'message' => 'Run ownership moved during a user callback; state commit abandoned.',
					'context' => array(
						'batch_name' => self::NAME,
						'run_id'     => self::RUN_ID,
					),
				),
			),
			$this->logger->records
		);
	}

	/**
	 * A failed delayed-continue schedule terminalizes the committed successful chunk.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_fails_terminally_when_continue_scheduling_fails(): void {
		$chunk_args = array( 'chunk' => 'current' );
		$remaining  = array( 'chunk' => 'remaining' );
		$this->prepare_scheduled_chunk( array( $chunk_args, $remaining ) );
		$this->batch->on_process                   = static function (
			array $processed_args,
			BatchContextInterface $context
		): void {
			$context->prepend( array( 'chunk' => 'committed-front' ) );
			$context->enqueue( array( 'chunk' => 'committed-back' ) );
		};
		$this->backend->results['schedule_single'] = $this->scheduling_failure_result();
		$this->clock->timestamp                    = self::NOW + 120;

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $chunk_args, $this->action_seq() );

		self::assertSame(
			array(
				array( 'chunk' => 'committed-front' ),
				$remaining,
				array( 'chunk' => 'committed-back' ),
			),
			$this->failed_run_state()['queue']
		);
		$this->assert_terminal_scheduling_failure(
			'continue',
			array(
				'verb' => 'schedule_single',
				'args' => array(
					'hook'      => 'a8csp/background_tasks/continue',
					'timestamp' => self::NOW + 180,
					'args'      => array( self::NAME, self::RUN_ID, 4 ),
					'group'     => self::NAME . '|' . self::RUN_ID,
					'priority'  => 10,
				),
			)
		);
	}

	/**
	 * A throwing continue-delay filter terminalizes the committed chunk instead of stalling it.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_fails_terminally_when_continue_delay_filter_throws(): void {
		$chunk_args = array( 'chunk' => 'current' );
		$this->prepare_scheduled_chunk( array( $chunk_args ) );
		$this->batch->on_process = static function (
			array $processed_args,
			BatchContextInterface $context
		): void {
			$context->enqueue( array( 'chunk' => 'committed' ) );
		};
		$this->set_filter_value(
			'a8csp/background_tasks/continue_delay',
			static function ( int $default_delay, string $name, string $run_id ): int {
				throw new \DomainException( 'Continue-delay filter exploded.' );
			}
		);
		$this->clock->timestamp = self::NOW + 120;

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $chunk_args, $this->action_seq() );

		self::assertSame(
			array( array( 'chunk' => 'committed' ) ),
			$this->failed_run_state()['queue']
		);
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		self::assertSame( array(), $this->backend->calls );
		self::assertCount( 1, $this->batch->failure_calls );
		$error = $this->batch->failure_calls[0]['error'];
		self::assertSame( 'Continue-delay filter exploded.', $error->message );
		self::assertSame( \DomainException::class, $error->exception_class );
		$this->assert_terminal_history();
	}

	/**
	 * A throwing chunk discards buffered mutations before rescheduling the same chunk.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_discards_context_mutations_and_reschedules_the_chunk(): void {
		$chunk_args = array( 'chunk' => 'current' );
		$remaining  = array( 'chunk' => 'remaining' );

		$this->batch->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->prepare_scheduled_chunk( array( $chunk_args, $remaining ) );
		$this->batch->on_process        = static function (
			array $processed_args,
			BatchContextInterface $context
		): void {
			$context->prepend( array( 'chunk' => 'discarded-front' ) );
			$context->enqueue( array( 'chunk' => 'discarded-back' ) );
		};
		$this->batch->process_throwable = new \RuntimeException( 'Chunk processing exploded.' );
		$this->clock->timestamp         = self::NOW + 120;
		$this->randomizer->value        = 11;
		$this->randomizer->calls        = array();

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $chunk_args, $this->action_seq() );

		$state = $this->run_state();
		self::assertSame( 'running', $state['status'] );
		self::assertSame( array( $remaining ), $state['queue'] );
		self::assertSame( 1, $state['chunk_retries'] );
		self::assertSame( 4, $state['action_seq'] );
		self::assertSame( self::NOW + 131, $state['heartbeat_at'] );
		self::assertSame( self::NOW + 131, $this->lock()['heartbeat_at'] ?? null );
		self::assertSame( array(), $this->batch->success_calls );
		self::assertSame( array(), $this->batch->failure_calls );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
		self::assertSame(
			array(
				array(
					'verb' => 'schedule_single',
					'args' => array(
						'hook'      => 'a8csp/background_tasks/run',
						'timestamp' => self::NOW + 131,
						'args'      => array( self::NAME, self::RUN_ID, $chunk_args, 4 ),
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
					'hook_name' => 'a8csp/background_tasks/retrying/' . self::NAME,
					'args'      => array( self::RUN_ID, self::ARGS, 1, 11 ),
				),
				array(
					'hook_name' => 'a8csp/background_tasks/retrying',
					'args'      => array( self::NAME, self::RUN_ID, self::ARGS, 1, 11 ),
				),
			),
			$this->fired_actions()
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
	}

	/**
	 * A retry-advanced sequence drops an older continue delivery without touching state or scheduling.
	 *
	 * @return  void
	 */
	public function test_stale_continue_after_retry_advances_sequence_is_side_effect_free(): void {
		$chunk_args                = array( 'chunk' => 'current' );
		$this->batch->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->prepare_scheduled_chunk( array( $chunk_args ) );
		$this->batch->process_throwable = new \RuntimeException( 'Chunk processing exploded.' );
		$this->clock->timestamp         = self::NOW + 120;
		$this->randomizer->value        = 11;

		$this->orchestrator->handle_run_action(
			self::NAME,
			self::RUN_ID,
			$chunk_args,
			$this->action_seq()
		);

		$expected_state = $this->run_state();
		$expected_lock  = $this->lock();
		self::assertSame( 4, $expected_state['action_seq'] );
		$this->clear_action_observations();

		$this->orchestrator->handle_continue_action( self::NAME, self::RUN_ID, 2 );

		self::assertSame( $expected_state, $this->run_state() );
		self::assertSame( $expected_lock, $this->lock() );
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->wpdb->recorded_queries );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertSame(
			array(
				array(
					'level'   => 'info',
					'message' => 'Stale lifecycle action delivery dropped.',
					'context' => array(
						'expected' => 4,
						'received' => 2,
						'run_id'   => self::RUN_ID,
					),
				),
			),
			$this->logger->records
		);
	}

	/**
	 * A successful retry resets the counter before the next chunk executes.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_resets_retries_before_the_next_chunk(): void {
		$chunk_a = array( 'chunk' => 'a' );
		$chunk_b = array( 'chunk' => 'b' );

		$this->batch->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->prepare_scheduled_chunk( array( $chunk_a, $chunk_b ) );
		$this->batch->process_throwable = new \RuntimeException( 'Chunk A failed once.' );
		$this->randomizer->value        = 5;
		$this->randomizer->calls        = array();
		$this->clock->timestamp         = self::NOW + 120;
		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $chunk_a, $this->action_seq() );

		$observed_retries = array();

		$this->batch->process_throwable = null;
		$this->batch->on_process        = function (
			array $chunk_args,
			BatchContextInterface $context
		) use ( &$observed_retries ): void {
			$chunk_name = $chunk_args['chunk'] ?? null;
			self::assertIsString( $chunk_name );
			$observed_retries[ $chunk_name ] = $this->run_state()['chunk_retries'];
		};

		$this->backend->calls   = array();
		$this->clock->timestamp = self::NOW + 125;
		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $chunk_a, $this->action_seq() );

		self::assertSame( 0, $this->run_state()['chunk_retries'] );
		self::assertSame( array( $chunk_b ), $this->run_state()['queue'] );

		$this->backend->calls   = array();
		$this->clock->timestamp = self::NOW + 185;
		$this->orchestrator->handle_continue_action( self::NAME, self::RUN_ID, $this->action_seq() );
		$this->backend->calls   = array();
		$this->clock->timestamp = self::NOW + 190;
		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $chunk_b, $this->action_seq() );

		self::assertSame(
			array(
				'a' => 1,
				'b' => 0,
			),
			$observed_retries
		);
		self::assertSame(
			array( $chunk_a, $chunk_a, $chunk_b ),
			\array_column( $this->batch->process_calls, 'chunk_args' )
		);
	}

	/**
	 * A failed retry schedule terminalizes the batch at the consumed-attempt count.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_terminalizes_a_chunk_retry_schedule_failure(): void {
		$chunk_args = array( 'chunk' => 'current' );

		$this->batch->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->prepare_scheduled_chunk( array( $chunk_args ) );
		$this->batch->process_throwable = new \RuntimeException( 'Chunk processing exploded.' );

		$this->randomizer->value = 7;
		$this->randomizer->calls = array();

		$this->backend->results['schedule_single'] = $this->scheduling_failure_result();

		$this->clock->timestamp = self::NOW + 120;

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $chunk_args, $this->action_seq() );

		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		self::assertCount( 1, $this->batch->failure_calls );
		self::assertSame(
			'Batch "catalog-sync" could not schedule the retry action: Restore the scheduler before retrying this batch.',
			$this->batch->failure_calls[0]['error']->message
		);
		$failed_runs = $this->option( 'a8csp_bgte_failed_' . self::NAME );
		self::assertIsArray( $failed_runs );
		$failed_run = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		self::assertSame( 1, $failed_run['attempts'] ?? null );
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
	 * A non-retryable chunk failure bypasses the policy on its first attempt.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_terminalizes_a_non_retryable_chunk_without_rescheduling(): void {
		$chunk_args = array( 'chunk' => 'current' );
		$this->prepare_scheduled_chunk( array( $chunk_args ) );
		$this->batch->process_throwable = new NonRetryableTaskException( 'Chunk input is permanently invalid.' );
		$this->clock->timestamp         = self::NOW + 120;

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $chunk_args, $this->action_seq() );

		self::assertSame( array(), $this->backend->calls );
		self::assertCount( 1, $this->batch->failure_calls );
		$failed_runs = $this->option( 'a8csp_bgte_failed_' . self::NAME );
		self::assertIsArray( $failed_runs );
		$failed_run = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		self::assertSame( 1, $failed_run['attempts'] ?? null );
		$error = $this->batch->failure_calls[0]['error'];
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp/background_tasks/failed/' . self::NAME,
					'args'      => array( self::RUN_ID, self::ARGS, $error ),
				),
				array(
					'hook_name' => 'a8csp/background_tasks/failed',
					'args'      => array( self::NAME, self::RUN_ID, self::ARGS, $error ),
				),
			),
			$this->fired_actions()
		);
	}

	/**
	 * A throwing named failed listener still permits its generic companion and terminal cleanup.
	 *
	 * @return  void
	 */
	public function test_failed_named_listener_throw_still_fires_generic_hook_and_cleans_up(): void {
		$chunk_args = array( 'chunk' => 'current' );

		$this->batch->retry_policy = new RetryPolicy( max_attempts: 1 );
		$this->prepare_scheduled_chunk( array( $chunk_args ) );
		$this->batch->process_throwable = new \DomainException( 'Chunk failed.' );
		$listener_throwable             = new \RuntimeException( 'Failed listener exploded.' );
		$this->set_action_throwable(
			'a8csp/background_tasks/failed/' . self::NAME,
			$listener_throwable
		);
		$this->clock->timestamp = self::NOW + 120;
		$caught                 = null;

		try {
			$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $chunk_args, $this->action_seq() );
		} catch ( \RuntimeException $throwable ) {
			$caught = $throwable;
		}

		self::assertSame( $listener_throwable, $caught );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		self::assertCount( 1, $this->batch->failure_calls );
		self::assertSame(
			array(
				'a8csp/background_tasks/failed/' . self::NAME,
				'a8csp/background_tasks/failed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_terminal_history();
	}

	/**
	 * Cleanup calls batch success and completed hooks before its terminal-success transition.
	 *
	 * @return  void
	 */
	public function test_handle_cleanup_action_completes_in_callback_hook_transition_order(): void {
		$this->prepare_started_batch( array() );
		$this->clock->timestamp = self::NOW + 90;
		$this->orchestrator->handle_continue_action( self::NAME, self::RUN_ID, $this->action_seq() );
		$this->clear_action_observations();
		$this->clock->timestamp = self::NOW + 120;

		$this->orchestrator->handle_cleanup_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame(
			array(
				array(
					'run_id'     => self::RUN_ID,
					'start_args' => self::ARGS,
				),
			),
			$this->batch->success_calls
		);
		self::assertSame( array(), $this->batch->failure_calls );
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
				'batch:success',
				'hook:completed/' . self::NAME,
				'hook:completed',
				'lock:update',
				'run:completed',
				'lock:delete',
				'run:delete',
				'history',
			),
			$this->lifecycle_labels()
		);
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
		$this->assert_terminal_history();
	}

	/**
	 * A success-callback throwable is logged without changing the completed run outcome.
	 *
	 * @return  void
	 */
	public function test_handle_cleanup_action_completes_and_logs_when_success_callback_throws(): void {
		$this->prepare_started_batch( array() );
		$this->clock->timestamp = self::NOW + 90;
		$this->orchestrator->handle_continue_action( self::NAME, self::RUN_ID, $this->action_seq() );
		$this->clear_action_observations();
		$this->clock->timestamp = self::NOW + 120;
		// An Error carrying the non-retryable marker pins the catch to \Throwable with no marker special-casing.
		$success_throwable              = new class( 'Success callback exploded.' ) extends \Error implements NonRetryableExceptionInterface {};
		$this->batch->success_throwable = $success_throwable;

		$this->orchestrator->handle_cleanup_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame(
			array(
				array(
					'run_id'     => self::RUN_ID,
					'start_args' => self::ARGS,
				),
			),
			$this->batch->success_calls
		);
		self::assertSame( array(), $this->batch->failure_calls );
		self::assertSame(
			array(
				array(
					'level'   => 'error',
					'message' => 'Batch success callback failed after all chunks completed; fix the batch on_success callback.',
					'context' => array(
						'batch_name'        => self::NAME,
						'run_id'            => self::RUN_ID,
						'exception_class'   => $success_throwable::class,
						'exception_message' => 'Success callback exploded.',
					),
				),
			),
			$this->logger->records
		);
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
				'batch:success',
				'hook:completed/' . self::NAME,
				'hook:completed',
				'lock:update',
				'run:completed',
				'lock:delete',
				'run:delete',
				'history',
			),
			$this->lifecycle_labels()
		);
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
		$this->assert_terminal_history();
	}

	/**
	 * A sequential duplicate cleanup delivery cannot repeat success after its run state is consumed.
	 *
	 * @return  void
	 */
	public function test_duplicate_cleanup_delivery_fires_success_once(): void {
		$this->prepare_started_batch( array() );
		$this->clock->timestamp = self::NOW + 90;
		$this->orchestrator->handle_continue_action( self::NAME, self::RUN_ID, $this->action_seq() );
		$cleanup_seq = $this->action_seq();
		$this->clear_action_observations();
		$this->clock->timestamp = self::NOW + 120;

		$this->orchestrator->handle_cleanup_action( self::NAME, self::RUN_ID, $cleanup_seq );
		$this->orchestrator->handle_cleanup_action( self::NAME, self::RUN_ID, $cleanup_seq );

		self::assertCount( 1, $this->batch->success_calls );
		self::assertSame( array(), $this->batch->failure_calls );
		self::assertSame(
			array(
				'a8csp/background_tasks/completed/' . self::NAME,
				'a8csp/background_tasks/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
		$this->assert_terminal_history();
	}

	/**
	 * A failed first-continuation schedule terminates the generated run without leaving active state.
	 *
	 * @return  void
	 */
	public function test_handle_start_action_fails_terminally_when_continue_scheduling_fails(): void {
		$this->batch->queue = array( array( 'chunk' => 'first' ) );
		$this->start_batch();
		$this->backend->calls                    = array();
		$this->backend->results['enqueue_async'] = $this->scheduling_failure_result();
		$this->clock->timestamp                  = self::NOW + 30;

		$this->orchestrator->handle_start_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( $this->batch->queue, $this->failed_run_state()['queue'] );
		$this->assert_terminal_scheduling_failure(
			'continue',
			array(
				'verb' => 'enqueue_async',
				'args' => array(
					'hook'     => 'a8csp/background_tasks/continue',
					'args'     => array( self::NAME, self::RUN_ID, 2 ),
					'group'    => self::NAME . '|' . self::RUN_ID,
					'unique'   => false,
					'priority' => 10,
				),
			)
		);
	}

	/**
	 * A failed run schedule terminates after dequeue so no active chain remains stalled.
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_fails_terminally_when_run_scheduling_fails(): void {
		$this->prepare_started_batch( array( array( 'chunk' => 'first' ) ) );
		$this->backend->results['enqueue_async'] = $this->scheduling_failure_result();
		$this->clock->timestamp                  = self::NOW + 90;

		$this->orchestrator->handle_continue_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array(), $this->failed_run_state()['queue'] );
		$this->assert_terminal_scheduling_failure(
			'run',
			array(
				'verb' => 'enqueue_async',
				'args' => array(
					'hook'     => 'a8csp/background_tasks/run',
					'args'     => array( self::NAME, self::RUN_ID, array( 'chunk' => 'first' ), 3 ),
					'group'    => self::NAME . '|' . self::RUN_ID,
					'unique'   => false,
					'priority' => 10,
				),
			)
		);
	}

	/**
	 * A failed cleanup schedule terminates a drained run instead of orphaning it.
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_fails_terminally_when_cleanup_scheduling_fails(): void {
		$this->prepare_started_batch( array() );
		$this->backend->results['enqueue_async'] = $this->scheduling_failure_result();
		$this->clock->timestamp                  = self::NOW + 90;

		$this->orchestrator->handle_continue_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array(), $this->failed_run_state()['queue'] );
		$this->assert_terminal_scheduling_failure(
			'cleanup',
			array(
				'verb' => 'enqueue_async',
				'args' => array(
					'hook'     => 'a8csp/background_tasks/cleanup',
					'args'     => array( self::NAME, self::RUN_ID, 3 ),
					'group'    => self::NAME . '|' . self::RUN_ID,
					'unique'   => false,
					'priority' => 10,
				),
			)
		);
	}

	/**
	 * Losing latest-run ownership at continue exits through Superseded without batch callbacks.
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_quietly_supersedes_a_non_latest_run(): void {
		$this->prepare_started_batch( array( array( 'chunk' => 'first' ) ) );
		( new LatestRunPointer( self::NAME ) )->record( 'run-newer', self::ARGS_HASH );
		$this->clear_action_observations();
		$this->clock->timestamp = self::NOW + 90;

		$this->orchestrator->handle_continue_action( self::NAME, self::RUN_ID, $this->action_seq() );

		$this->assert_quiet_superseded_run();
		self::assertSame( array(), $this->backend->calls );
	}

	/**
	 * Losing latest-run ownership at chunk execution prevents the chunk and terminal callbacks.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_quietly_supersedes_a_non_latest_run(): void {
		$chunk_args = array( 'chunk' => 'current' );
		$this->prepare_scheduled_chunk( array( $chunk_args ) );
		( new LatestRunPointer( self::NAME ) )->record( 'run-newer', self::ARGS_HASH );
		$this->clear_action_observations();
		$this->clock->timestamp = self::NOW + 120;

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, $chunk_args, $this->action_seq() );

		$this->assert_quiet_superseded_run();
		self::assertSame( array(), $this->batch->process_calls );
		self::assertSame( array(), $this->backend->calls );
	}

	/**
	 * Batch start rejects a name shared with a task before touching runtime boundaries.
	 *
	 * @return  void
	 */
	public function test_start_batch_rejects_a_name_resolvable_in_both_registries(): void {
		$this->tasks->register( new RecordingTask( self::NAME ) );

		$result = $this->orchestrator->start_batch( self::NAME, self::ARGS );

		$this->assert_ambiguous_name_failure( $result );
		$this->assert_start_boundaries_untouched();
	}

	/**
	 * Task enqueue rejects a name shared with a batch before touching runtime boundaries.
	 *
	 * @return  void
	 */
	public function test_enqueue_rejects_a_name_resolvable_in_both_registries(): void {
		$this->tasks->register( new RecordingTask( self::NAME ) );

		$result = $this->orchestrator->enqueue( self::NAME, self::ARGS );

		$this->assert_ambiguous_name_failure( $result );
		$this->assert_start_boundaries_untouched();
	}

	/**
	 * Manual retry rejects and logs a name shared by a task and batch before reading failed state.
	 *
	 * @return  void
	 */
	public function test_retry_failed_rejects_a_name_resolvable_in_both_registries(): void {
		$this->tasks->register( new RecordingTask( self::NAME ) );

		$result = $this->orchestrator->retry_failed( self::NAME, 'failed-run' );

		$this->assert_ambiguous_name_failure( $result );
		$this->assert_start_boundaries_untouched();
	}

	/**
	 * Advances the queue head into a run action and clears its scheduling observations.
	 *
	 * @param   array<array-key, array<array-key, mixed>> $queue Initial chunks.
	 *
	 * @return  void
	 */
	private function prepare_scheduled_chunk( array $queue ): void {
		$this->prepare_started_batch( $queue );
		$this->clock->timestamp = self::NOW + 90;
		$this->orchestrator->handle_continue_action( self::NAME, self::RUN_ID, $this->action_seq() );
		$this->clear_action_observations();
	}

	/**
	 * Clears observations created by the preceding internal action.
	 *
	 * @return  void
	 */
	private function clear_action_observations(): void {
		$this->backend->calls         = array();
		$this->logger->records        = array();
		$this->wpdb->recorded_queries = array();

		$GLOBALS['a8csp_bgte_test_fired_actions']    = array();
		$GLOBALS['a8csp_bgte_test_option_calls']     = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events'] = array();
	}

	/**
	 * Returns the failed status write made before terminal option deletion.
	 *
	 * @return  array{
	 *     status: string,
	 *     start_args: array<array-key, mixed>,
	 *     args_hash: string,
	 *     queue: list<array<array-key, mixed>>,
	 *     chunk_retries: int,
	 *     action_seq: int,
	 *     created_at: int,
	 *     heartbeat_at: int
	 * }
	 */
	private function failed_run_state(): array {
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
			if ( \is_array( $state ) && 'failed' === ( $state['status'] ?? null ) ) {
				return $this->typed_run_state( $state );
			}
		}

		self::fail( 'The batch run never persisted its failed state.' );
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
				$labels[] = 'lock:' . $operation;
				continue;
			}

			if ( 'batch' === $type ) {
				$operation = $event['operation'] ?? null;
				self::assertIsString( $operation );
				$labels[] = 'batch:' . $operation;
				continue;
			}

			if ( 'action' === $type ) {
				$hook_name = $event['hook_name'] ?? null;
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
				$status = $value['status'] ?? null;
				self::assertIsString( $status );
				$labels[] = 'run:' . $status;
			} elseif ( 'a8csp_bgte_failed_' . self::NAME === $option_name ) {
				$labels[] = 'failed-store';
			} elseif ( 'a8csp_bgte_history_' . self::NAME === $option_name ) {
				$labels[] = 'history';
			}
		}

		return $labels;
	}

	/**
	 * Returns a deterministic scheduling failure for one lifecycle action.
	 *
	 * @return  Failure<SchedulingError>
	 */
	private function scheduling_failure_result(): Failure {
		return new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'Restore the scheduler before retrying this batch.'
			)
		);
	}

	/**
	 * Asserts queue startup failed terminally before scheduling a continuation.
	 *
	 * @phpstan-param class-string $exception_class
	 *
	 * @param   string $message         Expected failure message.
	 * @param   string $exception_class Expected throwable class.
	 *
	 * @return  void
	 */
	private function assert_terminal_start_error( string $message, string $exception_class ): void {
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->failed_run_state()['queue'] );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		self::assertSame( array(), $this->batch->success_calls );
		self::assertCount( 1, $this->batch->failure_calls );
		$error = $this->batch->failure_calls[0]['error'];
		self::assertSame( $message, $error->message );
		self::assertSame( $exception_class, $error->exception_class );
		self::assertSame(
			array(
				'a8csp/background_tasks/failed/' . self::NAME,
				'a8csp/background_tasks/failed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_terminal_history();
	}

	/**
	 * Asserts one failed scheduling stage leaves only terminal diagnostics and history.
	 *
	 * @param   'continue'|'run'|'cleanup'                      $stage         Scheduled action stage.
	 * @param   array{verb: string, args: array<string, mixed>} $expected_call Complete scheduling request.
	 *
	 * @return  void
	 */
	private function assert_terminal_scheduling_failure( string $stage, array $expected_call ): void {
		self::assertSame( array( $expected_call ), $this->backend->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		self::assertSame( array(), $this->batch->success_calls );
		self::assertCount( 1, $this->batch->failure_calls );
		$error = $this->batch->failure_calls[0]['error'];
		self::assertSame(
			\sprintf(
				'Batch "catalog-sync" could not schedule the %s action: Restore the scheduler before retrying this batch.',
				$stage
			),
			$error->message
		);
		self::assertSame( SchedulingError::class, $error->exception_class );
		$failed_runs = $this->option( 'a8csp_bgte_failed_' . self::NAME );
		self::assertIsArray( $failed_runs );
		$failed_run = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		$stored_error = $failed_run['error'] ?? null;
		self::assertIsArray( $stored_error );
		self::assertSame( $error->message, $stored_error['message'] ?? null );
		self::assertSame(
			array(
				'a8csp/background_tasks/failed/' . self::NAME,
				'a8csp/background_tasks/failed',
			),
			\array_slice( \array_column( $this->fired_actions(), 'hook_name' ), -2 )
		);
		$this->assert_terminal_history();
	}

	/**
	 * Asserts a fenced run exits without processing or terminal batch callbacks.
	 *
	 * @return  void
	 */
	private function assert_quiet_superseded_run(): void {
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
		self::assertSame( array(), $this->batch->success_calls );
		self::assertSame( array(), $this->batch->failure_calls );
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
		$this->assert_terminal_history();
	}

	/**
	 * Asserts the shared name-space failure identifies the required registration fix.
	 *
	 * @phpstan-param AbstractResult<mixed, mixed> $result
	 *
	 * @param   AbstractResult $result Rejected public operation.
	 *
	 * @return  void
	 */
	private function assert_ambiguous_name_failure( AbstractResult $result ): void {
		$message = 'Background-work name "catalog-sync" is registered as both a task and a batch; rename one registration so each name identifies exactly one type.';

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame( $message, $result->error->message );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => $message,
					'context' => array( 'name' => self::NAME ),
				),
			),
			$this->logger->records
		);
	}

	/**
	 * Asserts validation returns before every observable run-start boundary.
	 *
	 * @return  void
	 */
	private function assert_start_boundaries_untouched(): void {
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->randomizer->calls );
		self::assertSame( 0, $this->clock->calls );
		self::assertSame( array(), $this->wpdb->recorded_queries );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertSame( array(), $this->fired_actions() );
	}

	/**
	 * Asserts both terminal history buffers contain the deterministic run.
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
	 * Starts one deterministic batch run.
	 *
	 * @return  void
	 */
	private function start_batch(): void {
		$result = $this->orchestrator->start_batch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
	}

	/**
	 * Generates a queue and clears observations before its first continue action.
	 *
	 * @param   array<array-key, array<array-key, mixed>> $queue Initial chunks.
	 *
	 * @return  void
	 */
	private function prepare_started_batch( array $queue ): void {
		$this->batch->queue = $queue;
		$this->start_batch();
		$this->clock->timestamp = self::NOW + 30;
		$this->orchestrator->handle_start_action( self::NAME, self::RUN_ID, $this->action_seq() );

		$this->backend->calls                        = array();
		$GLOBALS['a8csp_bgte_test_fired_actions']    = array();
		$GLOBALS['a8csp_bgte_test_option_calls']     = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events'] = array();
	}

	/**
	 * Returns the deterministic run option's complete state.
	 *
	 * @return  array{
	 *     status: string,
	 *     start_args: array<array-key, mixed>,
	 *     args_hash: string,
	 *     queue: list<array<array-key, mixed>>,
	 *     chunk_retries: int,
	 *     action_seq: int,
	 *     created_at: int,
	 *     heartbeat_at: int
	 * }
	 */
	private function run_state(): array {
		return $this->typed_run_state( $this->option( $this->run_option_name() ) );
	}

	/**
	 * Reconstructs a complete typed state from the WordPress option boundary.
	 *
	 * @param   mixed $state Persisted run state.
	 *
	 * @return  array{
	 *     status: string,
	 *     start_args: array<array-key, mixed>,
	 *     args_hash: string,
	 *     queue: list<array<array-key, mixed>>,
	 *     chunk_retries: int,
	 *     action_seq: int,
	 *     created_at: int,
	 *     heartbeat_at: int
	 * }
	 */
	private function typed_run_state( mixed $state ): array {
		self::assertIsArray( $state );
		$status        = $state['status'] ?? null;
		$start_args    = $state['start_args'] ?? null;
		$args_hash     = $state['args_hash'] ?? null;
		$raw_queue     = $state['queue'] ?? null;
		$chunk_retries = $state['chunk_retries'] ?? null;
		$action_seq    = $state['action_seq'] ?? null;
		$created_at    = $state['created_at'] ?? null;
		$heartbeat_at  = $state['heartbeat_at'] ?? null;
		self::assertIsString( $status );
		self::assertIsArray( $start_args );
		self::assertIsString( $args_hash );
		self::assertIsArray( $raw_queue );
		self::assertIsInt( $chunk_retries );
		self::assertIsInt( $action_seq );
		self::assertIsInt( $created_at );
		self::assertIsInt( $heartbeat_at );

		$queue = array();
		foreach ( $raw_queue as $chunk_args ) {
			self::assertIsArray( $chunk_args );
			$queue[] = $chunk_args;
		}

		return array(
			'status'        => $status,
			'start_args'    => $start_args,
			'args_hash'     => $args_hash,
			'queue'         => $queue,
			'chunk_retries' => $chunk_retries,
			'action_seq'    => $action_seq,
			'created_at'    => $created_at,
			'heartbeat_at'  => $heartbeat_at,
		);
	}

	/**
	 * Returns the deterministic run option name.
	 *
	 * @return  string
	 */
	private function run_option_name(): string {
		return 'a8csp_bgte_run_' . self::NAME . '_' . self::RUN_ID;
	}

	/**
	 * Returns the newest scheduled lifecycle action sequence for the live batch run.
	 *
	 * @return  int
	 */
	private function action_seq(): int {
		return $this->run_state()['action_seq'];
	}

	/**
	 * Stores one fresh incumbent lock and its recoverable latest-run pointer.
	 *
	 * @return  void
	 */
	private function seed_running_lock(): void {
		$raw_lock = \maybe_serialize(
			array(
				'run_id'       => 'run-running',
				'claimed_at'   => self::NOW,
				'heartbeat_at' => self::NOW,
			)
		);
		self::assertIsString( $raw_lock );
		$this->wpdb->put( 'a8csp_bgte_lock_' . self::NAME . '_' . self::ARGS_HASH, $raw_lock );

		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );
		$options[ 'a8csp_bgte_latest_' . self::NAME ] = array(
			'all'     => 'run-running',
			'by_hash' => array( self::ARGS_HASH => 'run-running' ),
		);

		$GLOBALS['a8csp_bgte_test_options'] = $options;
	}

	/**
	 * Replaces the current batch lock with one foreign owner.
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
		$this->wpdb->put( 'a8csp_bgte_lock_' . self::NAME . '_' . self::ARGS_HASH, $raw );
	}

	/**
	 * Returns the current deterministic lock row.
	 *
	 * @return  array{run_id: string, claimed_at: int, heartbeat_at: int}|null
	 */
	private function lock(): ?array {
		$name = 'a8csp_bgte_lock_' . self::NAME . '_' . self::ARGS_HASH;
		$raw  = $this->wpdb->rows[ $name ] ?? null;
		if ( ! \is_string( $raw ) ) {
			return null;
		}

		$value = \maybe_unserialize( $raw );
		if ( ! \is_array( $value ) ) {
			return null;
		}

		$run_id       = $value['run_id'] ?? null;
		$claimed_at   = $value['claimed_at'] ?? null;
		$heartbeat_at = $value['heartbeat_at'] ?? null;
		if ( ! \is_string( $run_id ) || ! \is_int( $claimed_at ) || ! \is_int( $heartbeat_at ) ) {
			return null;
		}

		return array(
			'run_id'       => $run_id,
			'claimed_at'   => $claimed_at,
			'heartbeat_at' => $heartbeat_at,
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
	 * Scripts one listener throwable through the unit action boundary.
	 *
	 * @param   string     $hook_name Hook name.
	 * @param   \Throwable $throwable Listener failure.
	 *
	 * @return  void
	 */
	private function set_action_throwable( string $hook_name, \Throwable $throwable ): void {
		$throwables = $GLOBALS['a8csp_bgte_test_action_throwables'] ?? null;
		self::assertIsArray( $throwables );
		$throwables[ $hook_name ] = $throwable;

		$GLOBALS['a8csp_bgte_test_action_throwables'] = $throwables;
	}
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Retry\FailureLifecycle;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
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
 * Pins active-run fencing and terminal task transitions across storage, hooks, locks, and logs.
 *
 */
#[CoversClass( TerminalTransitions::class )]
#[UsesClass( EngineError::class )]
#[UsesClass( FailureLifecycle::class )]
#[UsesClass( FailedRunStore::class )]
#[UsesClass( LatestRunPointer::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( LockWindows::class )]
#[UsesClass( Dispatcher::class )]
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
final class TerminalTransitionsTest extends TestCase {
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
	private FailureLifecycle $failure_lifecycle;
	private RecordingLogger $logger;
	private RecordingRandomizer $randomizer;
	private RecordingTask $task;
	private TerminalTransitions $terminal_transitions;
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
		$this->wpdb                 = new WpdbLockSpy();
		$batches                    = new BatchRegistry();
		$guard                      = new OverlapGuard( $this->clock, $this->logger, new OptionRows( $this->wpdb ) );
		$stores                     = new StoreFactory( $this->clock, new OptionRows( $this->wpdb ) );
		$lock_windows               = new LockWindows( $this->clock );
		$this->terminal_transitions = new TerminalTransitions( $guard, $stores, $this->clock, $lock_windows, $this->logger );
		$this->failure_lifecycle    = new FailureLifecycle(
			$this->backend,
			$this->clock,
			$this->randomizer,
			$this->logger,
			$this->terminal_transitions
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
			$this->terminal_transitions,
		);
	}

	// endregion.

	// region TESTS.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Signatures and providers carry test parameter types.

	/** A terminal winner deleting the run during a live heartbeat CAS silences the stale delivery. */
	public function test_handle_run_action_live_state_cas_cannot_resurrect_a_terminally_deleted_run(): void {
		$this->prepare_run_action();
		$this->wpdb->before_next( 'update', static function (): void {} );
		$this->wpdb->before_next(
			'update',
			function (): void {
				$this->handle_task_run_action( self::RUN_ID, $this->action_seq() );
			}
		);

		$this->handle_task_run_action( self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
		self::assertSame( array(), $this->logger->records );
		self::assertSame(
			array(
				'a8csp_background_tasks/completed/' . self::NAME,
				'a8csp_background_tasks/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_terminal_history( 'completed' );
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

		$this->handle_task_run_action( self::RUN_ID, 1 );

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
	 * A fresh execution marker excludes a same-sequence delivery before another fence write.
	 *
	 * @return  void
	 */
	public function test_active_run_state_drops_a_fresh_same_sequence_delivery(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::NAME, $this->clock, new OptionRows( $this->wpdb ) );
		$first     = $this->terminal_transitions->active_run_state(
			'Task',
			self::NAME,
			self::RUN_ID,
			$this->action_seq(),
			$run_store
		);
		self::assertInstanceOf( RunState::class, $first );
		self::assertTrue( $first->executing );
		$expected_run  = $this->option( $this->run_option_name() );
		$expected_lock = $this->lock();

		$this->logger->records                       = array();
		$this->wpdb->recorded_queries                = array();
		$GLOBALS['a8csp_bgte_test_option_calls']     = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events'] = array();

		$duplicate = $this->terminal_transitions->active_run_state(
			'Task',
			self::NAME,
			self::RUN_ID,
			$this->action_seq(),
			$run_store
		);

		self::assertNull( $duplicate );
		self::assertSame( $expected_run, $this->option( $this->run_option_name() ) );
		self::assertSame( $expected_lock, $this->lock() );
		self::assertSame( array(), $this->wpdb->recorded_queries );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_lifecycle_events'] );
		self::assertSame(
			array(
				array(
					'level'   => 'debug',
					'message' => 'Duplicate lifecycle action delivery dropped while the current delivery is still executing.',
					'context' => array(
						'task_name'  => self::NAME,
						'run_id'     => self::RUN_ID,
						'action_seq' => 1,
					),
				),
			),
			$this->logger->records
		);
	}

	/**
	 * A stale execution marker re-enters the ownership fence and receives a fresh heartbeat.
	 *
	 * @return  void
	 */
	public function test_active_run_state_admits_and_refences_a_stale_execution_marker(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::NAME, $this->clock, new OptionRows( $this->wpdb ) );
		$first     = $this->terminal_transitions->active_run_state(
			'Task',
			self::NAME,
			self::RUN_ID,
			$this->action_seq(),
			$run_store
		);
		self::assertInstanceOf( RunState::class, $first );
		self::assertTrue( $first->executing );

		$this->clock->timestamp = self::NOW + 991;
		$this->logger->records  = array();

		$reclaimed = $this->terminal_transitions->active_run_state(
			'Task',
			self::NAME,
			self::RUN_ID,
			$this->action_seq(),
			$run_store
		);

		self::assertInstanceOf( RunState::class, $reclaimed );
		self::assertTrue( $reclaimed->executing );
		self::assertSame( self::NOW + 991, $reclaimed->heartbeat_at );
		self::assertSame( self::NOW + 991, $this->lock()['heartbeat_at'] ?? null );
		self::assertEquals( $reclaimed, $run_store->get( self::RUN_ID ) );
		self::assertSame( array(), $this->logger->records );
	}

	/**
	 * A throwing group-clear listener cannot strand cancellation state or suppress lifecycle hooks.
	 *
	 * @return  void
	 */
	public function test_cancel_run_finishes_terminal_state_when_group_clear_throws(): void {
		$this->prepare_run_action();
		$run_store  = new RunStore( self::NAME, $this->clock, new OptionRows( $this->wpdb ) );
		$inspection = $run_store->inspect( self::RUN_ID );
		if ( $inspection->is_failure() ) {
			self::fail( 'The cancellable run snapshot could not be read.' );
		}
		$snapshot = $inspection->value;
		self::assertNotNull( $snapshot );
		self::assertInstanceOf( RunState::class, $snapshot['state'] );
		$throwable = new \RuntimeException( 'Group-clear listener failed.' );

		try {
			$this->terminal_transitions->cancel_run(
				self::NAME,
				self::RUN_ID,
				$snapshot['state'],
				$run_store,
				$snapshot['raw'],
				static function () use ( $throwable ): void {
					throw $throwable;
				}
			);
			self::fail( 'The group-clear listener exception must propagate to the caller.' );
		} catch ( \RuntimeException $caught ) {
			self::assertSame( $throwable, $caught );
		}

		$missing = $run_store->inspect( self::RUN_ID );
		if ( $missing->is_failure() ) {
			self::fail( 'The cancelled run snapshot could not be read.' );
		}
		self::assertNull( $missing->value );
		self::assertNull( $this->lock() );
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_background_tasks/cancelled/' . self::NAME,
					'args'      => array( self::RUN_ID, self::ARGS ),
				),
				array(
					'hook_name' => 'a8csp_background_tasks/cancelled',
					'args'      => array( self::NAME, self::RUN_ID, self::ARGS ),
				),
			),
			$this->fired_actions()
		);
		$this->assert_terminal_history( 'cancelled' );
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

		$this->handle_task_run_action( self::RUN_ID, $this->action_seq() );
		$this->task->throwable  = null;
		$this->clock->timestamp = self::NOW + 95;
		$this->handle_task_run_action( self::RUN_ID, $this->action_seq() );

		self::assertSame( 0, $this->recorded_run_state( 'completed' )['chunk_retries'] );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
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
		$this->handle_task_run_action( self::RUN_ID, $this->action_seq() );
		( new LatestRunPointer( self::NAME ) )->record( 'run-newer', self::ARGS_HASH );
		$this->replace_lock_owner( 'run-newer', self::NOW + 95 );
		$this->backend->calls = array();

		$GLOBALS['a8csp_bgte_test_fired_actions'] = array();

		$this->clock->timestamp = self::NOW + 95;
		$this->handle_task_run_action( self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertSame( array(), $this->backend->calls );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		self::assertSame(
			array(
				'a8csp_background_tasks/superseded/' . self::NAME,
				'a8csp_background_tasks/superseded',
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

		$this->handle_task_run_action( self::RUN_ID, $this->action_seq() );

		self::assertSame( array(), $this->task->calls );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		self::assertNull( $this->option( $this->run_option_name() ) );
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
		$this->assert_terminal_history( 'superseded' );
	}

	/**
	 * The lock winner repairs a pointer overwritten by a losing concurrent starter and still executes.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_keeps_the_lock_winner_when_pointer_commit_lags(): void {
		$this->prepare_run_action();
		( new LatestRunPointer( self::NAME ) )->record( 'run-losing-starter', self::ARGS_HASH );

		$this->handle_task_run_action( self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertSame(
			array(
				'a8csp_background_tasks/completed/' . self::NAME,
				'a8csp_background_tasks/completed',
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
		$this->assert_terminal_history( 'completed' );
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

			$result = $this->dispatcher->enqueue( self::NAME, array( 'identity' => $index ) );
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

		$this->handle_task_run_action( $first_run_id, $this->action_seq( $first_run_id ) );

		self::assertSame( array( array( 'identity' => 0 ) ), $this->task->calls );
		self::assertSame(
			array(
				'a8csp_background_tasks/completed/' . self::NAME,
				'a8csp_background_tasks/completed',
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

		$this->handle_task_run_action( self::RUN_ID, $this->action_seq() );

		self::assertSame( array(), $this->task->calls );
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		self::assertSame(
			array(
				'a8csp_background_tasks/superseded/' . self::NAME,
				'a8csp_background_tasks/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertNull( $this->option( $this->run_option_name() ) );
		$this->assert_terminal_history( 'superseded' );
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

		$this->handle_task_run_action( self::RUN_ID, $this->action_seq() );

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
			'cancelled'  => array( 'status' => 'cancelled' ),
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

		$this->handle_task_run_action( 'missing-run', 1 );

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
	 * Runs one task attempt through the terminal-transition product services.
	 *
	 * @param   string $run_id     Run identifier.
	 * @param   int    $action_seq Received lifecycle action sequence.
	 *
	 * @return  void
	 */
	private function handle_task_run_action( string $run_id, int $action_seq ): void {
		$run_store = new RunStore( self::NAME, $this->clock, new OptionRows( $this->wpdb ) );
		$state     = $this->terminal_transitions->active_run_state(
			'Task',
			self::NAME,
			$run_id,
			$action_seq,
			$run_store
		);
		if ( null === $state ) {
			return;
		}

		try {
			$this->task->handle( $state->start_args );
		} catch ( \Throwable $throwable ) {
			$this->failure_lifecycle->handle_failed_attempt(
				'Task',
				self::NAME,
				$run_id,
				$state,
				$run_store,
				$throwable,
				fn (): RetryPolicy => $this->task->get_retry_policy(),
				function ( RunState $failure_state, EngineError $error, int $attempts_used ) use ( $run_id, $run_store ): void {
					$this->terminal_transitions->fail_run(
						self::NAME,
						$run_id,
						$failure_state,
						$run_store,
						$error,
						$attempts_used
					);
				}
			);

			return;
		}

		if ( $this->terminal_transitions->supersede_if_fence_lost( 'Task', self::NAME, $run_id, $state, $run_store ) ) {
			return;
		}

		$this->terminal_transitions->complete_run( self::NAME, $run_id, $state, $run_store );
	}

	/**
	 * Asserts that the terminal buffer records the run outcome.
	 *
	 * @phpstan-param 'completed'|'cancelled'|'superseded' $status
	 *
	 * @param   string $status Expected terminal status.
	 *
	 * @return  void
	 */
	private function assert_terminal_history( string $status ): void {
		self::assertSame(
			array(
				'started'   => array( self::RUN_ID ),
				'completed' => array(
					array(
						'run_id' => self::RUN_ID,
						'status' => $status,
					),
				),
				'by_hash'   => array(
					self::ARGS_HASH => array(
						'started'   => array( self::RUN_ID ),
						'completed' => array(
							array(
								'run_id' => self::RUN_ID,
								'status' => $status,
							),
						),
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
	 *     executing: mixed,
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
					'executing'     => $state['executing'] ?? null,
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
					'executing'     => $state['executing'] ?? null,
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

	// endregion.
}

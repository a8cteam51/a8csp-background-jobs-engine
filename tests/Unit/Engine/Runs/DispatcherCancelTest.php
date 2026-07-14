<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\LockRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\TerminalTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Tasks\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;
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
 * Pins cancellation fencing, refusals, durable outcome, and scheduler-group isolation.
 *
 */
#[CoversClass( Dispatcher::class )]
#[UsesClass( EngineError::class )]
#[UsesClass( LockRows::class )]
#[UsesClass( LockWindows::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( RunHistory::class )]
#[UsesClass( RunState::class )]
#[UsesClass( RunStatus::class )]
#[UsesClass( RunStore::class )]
#[UsesClass( SchedulerFacade::class )]
#[UsesClass( StoreFactory::class )]
#[UsesClass( TerminalTransitions::class )]
final class DispatcherCancelTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const ARGS = array(
		'site_id' => 7,
		'mode'    => 'full',
	);

	private const ARGS_HASH  = '7dcca9cc21619f109d6f0423c49b010606457ea4a713721e9ce5134949d72bd2';
	private const BATCH_NAME = 'catalog-sync';
	private const NOW        = 1_700_000_000;
	private const RUN_ID     = '00000000001700000000-0000000000000000042';
	private const TASK_NAME  = 'email-digest';

	private BatchRegistry $batches;
	private FixedClock $clock;
	private Dispatcher $dispatcher;
	private RecordingLogger $logger;
	private RecordingBackend $primary_backend;
	private RecordingBackend $secondary_backend;
	private StoreFactory $stores;
	private RecordingTask $task;
	private TaskRegistry $tasks;
	private TerminalTransitions $terminal_transitions;
	private WpdbLockSpy $wpdb;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress seams before orchestration classes are instantiated.
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
	 * Constructs registered task and batch identities over the real fencing stores and facade fan-out.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_options']               = array();
		$GLOBALS['a8csp_bgte_test_option_calls']          = array();
		$GLOBALS['a8csp_bgte_test_option_autoload']       = array();
		$GLOBALS['a8csp_bgte_test_update_option_results'] = array();
		$GLOBALS['a8csp_bgte_test_update_option_values']  = array();
		$GLOBALS['a8csp_bgte_test_delete_option_results'] = array();
		$GLOBALS['a8csp_bgte_test_filter_values']         = array();
		$GLOBALS['a8csp_bgte_test_fired_actions']         = array();
		$GLOBALS['a8csp_bgte_test_action_throwables']     = array();
		$GLOBALS['a8csp_bgte_test_hooks']                 = array();
		$GLOBALS['a8csp_bgte_test_action_registrations']  = array();
		$GLOBALS['a8csp_bgte_test_blog_id']               = 1;
		$GLOBALS['a8csp_bgte_test_cache']                 = array();
		$GLOBALS['a8csp_bgte_test_cache_calls']           = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events']      = array();
		unset( $GLOBALS['a8csp_bgte_test_before_add_option'] );

		$this->clock             = new FixedClock( self::NOW );
		$this->logger            = new RecordingLogger();
		$this->primary_backend   = new RecordingBackend();
		$this->secondary_backend = new RecordingBackend();
		$this->tasks             = new TaskRegistry();
		$this->batches           = new BatchRegistry();
		$this->task              = new RecordingTask( self::TASK_NAME );
		$this->tasks->register( $this->task );
		$this->batches->register( new RecordingBatch( self::BATCH_NAME ) );
		$this->wpdb = new WpdbLockSpy();

		$guard                      = new OverlapGuard( $this->clock, $this->logger, new LockRows( $this->wpdb ) );
		$option_rows                = new OptionRows( $this->wpdb );
		$this->stores               = new StoreFactory( $this->clock, $option_rows );
		$this->terminal_transitions = new TerminalTransitions(
			$guard,
			$this->stores,
			$this->clock,
			$this->logger
		);
		$scheduler                  = new SchedulerFacade(
			array( $this->primary_backend, $this->secondary_backend )
		);
		$this->dispatcher           = new Dispatcher(
			$this->tasks,
			$this->batches,
			$scheduler,
			$guard,
			$this->stores,
			$this->clock,
			new RecordingRandomizer( 42 ),
			$this->logger,
			new LockWindows( $this->clock ),
			$this->terminal_transitions,
		);
	}

	// endregion.

	// region TESTS.

	/** A pending task cancels through one exact terminal claim and clears only its per-run group. */
	public function test_cancel_pending_task_records_the_outcome_hooks_and_group_clear(): void {
		$run_id = $this->enqueue_task();

		$result = $this->dispatcher->cancel( self::TASK_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::TASK_NAME, $run_id );
	}

	/** A backend clear failure does not change the already-fenced cancellation outcome. */
	public function test_cancel_finishes_after_a_group_clear_failure(): void {
		$run_id = $this->enqueue_task();

		$this->primary_backend->results['unschedule'] = new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'Repair the preferred backend before retrying the clear.'
			)
		);

		$result = $this->dispatcher->cancel( self::TASK_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::TASK_NAME, $run_id );
	}

	/** A missing run names both identities and says that no retained work remains. */
	public function test_cancel_rejects_a_missing_run(): void {
		$result = $this->dispatcher->cancel( self::TASK_NAME, 'missing-run' );

		$this->assert_engine_failure(
			$result,
			'Run "missing-run" for background-work "email-digest" is not retained; nothing remains to cancel.'
		);
		$this->assert_no_scheduler_or_hook_effects();
	}

	/** A corrupt run is indistinguishable from absent retained state at the public boundary. */
	public function test_cancel_rejects_a_corrupt_run(): void {
		$this->wpdb->put( $this->run_option_name( self::TASK_NAME, 'corrupt-run' ), 'corrupt' );

		$result = $this->dispatcher->cancel( self::TASK_NAME, 'corrupt-run' );

		$this->assert_engine_failure(
			$result,
			'Run "corrupt-run" for background-work "email-digest" is not retained; nothing remains to cancel.'
		);
		$this->assert_no_scheduler_or_hook_effects();
	}

	/** A retained terminal snapshot reports its exact outcome without attempting another transition. */
	public function test_cancel_rejects_an_already_terminal_run(): void {
		$run_id = $this->enqueue_task();
		$this->replace_state(
			self::TASK_NAME,
			$run_id,
			static fn ( RunState $state ): RunState => $state->with_status( RunStatus::Completed )
		);
		$this->reset_observations();

		$result = $this->dispatcher->cancel( self::TASK_NAME, $run_id );

		$this->assert_engine_failure(
			$result,
			'Run "00000000001700000000-0000000000000000042" is already terminal (completed); a finished run cannot be cancelled.'
		);
		$this->assert_no_scheduler_or_hook_effects();
	}

	/** An executing marker refuses cancellation before the terminal compare-and-swap. */
	public function test_cancel_rejects_an_executing_run_before_any_write(): void {
		$run_id = $this->enqueue_task();
		$this->replace_state(
			self::TASK_NAME,
			$run_id,
			static fn ( RunState $state ): RunState => $state->with_executing( true )
		);
		$this->reset_observations();

		$result = $this->dispatcher->cancel( self::TASK_NAME, $run_id );

		$this->assert_executing_failure( $result, $run_id );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_lifecycle_events'] );
		$this->assert_no_scheduler_or_hook_effects();
	}

	/** A non-marker state change produces the neutral lost-CAS correction without winner effects. */
	public function test_cancel_reports_a_neutral_failure_after_losing_its_terminal_cas(): void {
		$run_id = $this->enqueue_task();
		$this->wpdb->before_next(
			'update',
			function (): void {
				$this->replace_state(
					self::TASK_NAME,
					self::RUN_ID,
					static fn ( RunState $state ): RunState => $state->with_heartbeat_at( self::NOW + 1 )
				);
			}
		);

		$result = $this->dispatcher->cancel( self::TASK_NAME, $run_id );

		$this->assert_engine_failure(
			$result,
			'Run "00000000001700000000-0000000000000000042" changed state while the cancel was in flight; re-inspect the run before retrying.'
		);
		$state = $this->run_state( self::TASK_NAME, $run_id );
		self::assertSame( self::NOW + 1, $state->heartbeat_at );
		self::assertFalse( $state->executing );
		$this->assert_no_scheduler_or_hook_effects();
	}

	/** A delivery marker that wins the shared-row CAS changes the cancellation result to executing. */
	public function test_cancel_reports_executing_when_the_delivery_marker_wins_the_race(): void {
		$run_id    = $this->enqueue_task();
		$run_store = $this->stores->run_store( self::TASK_NAME );
		$admitted  = null;
		$this->wpdb->before_next(
			'update',
			function () use ( &$admitted, $run_id, $run_store ): void {
				$admitted = $this->terminal_transitions->active_run_state(
					'Task',
					self::TASK_NAME,
					$run_id,
					1,
					$run_store
				);
			}
		);

		$result = $this->dispatcher->cancel( self::TASK_NAME, $run_id );

		self::assertInstanceOf( RunState::class, $admitted );
		self::assertTrue( $admitted->executing );
		$this->assert_executing_failure( $result, $run_id );
		self::assertTrue( $this->run_state( self::TASK_NAME, $run_id )->executing );
		self::assertSame( array(), $this->task->calls );
		$this->assert_no_scheduler_or_hook_effects();
	}

	/** A cancellation that wins first deletes the row before delivery admission can expose user code. */
	public function test_delivery_admission_drops_when_cancel_wins_the_marker_race(): void {
		$run_id        = $this->enqueue_task();
		$run_store     = $this->stores->run_store( self::TASK_NAME );
		$cancel_result = null;
		$this->wpdb->before_next( 'update', static function (): void {} );
		$this->wpdb->before_next(
			'update',
			function () use ( &$cancel_result, $run_id ): void {
				$cancel_result = $this->dispatcher->cancel( self::TASK_NAME, $run_id );
			}
		);

		$admitted = $this->terminal_transitions->active_run_state(
			'Task',
			self::TASK_NAME,
			$run_id,
			1,
			$run_store
		);

		self::assertNull( $admitted );
		self::assertInstanceOf( Success::class, $cancel_result );
		$this->assert_successful_cancel( $cancel_result, self::TASK_NAME, $run_id );
		self::assertSame( array(), $this->task->calls );
	}

	/** Cancellation mirrors retry_failed's corrective refusal for an unregistered name. */
	public function test_cancel_rejects_an_unregistered_name_with_retry_failed_wording(): void {
		$result = $this->dispatcher->cancel( 'unknown', 'run-1' );

		$this->assert_engine_failure(
			$result,
			'Background-work "unknown" is not registered; register the matching task or batch before retrying its failed run.'
		);
		$this->assert_no_scheduler_or_hook_effects();
	}

	/** Cancellation mirrors retry_failed's logged refusal for an ambiguous registration. */
	public function test_cancel_rejects_a_name_resolvable_in_both_registries(): void {
		$this->batches->register( new RecordingBatch( self::TASK_NAME ) );
		$message = 'Background-work name "email-digest" is registered as both a task and a batch; rename one registration so each name identifies exactly one type.';

		$result = $this->dispatcher->cancel( self::TASK_NAME, 'run-1' );

		$this->assert_engine_failure( $result, $message );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => $message,
					'context' => array( 'name' => self::TASK_NAME ),
				),
			),
			$this->logger->records
		);
		$this->assert_no_scheduler_or_hook_effects( keep_logs: true );
	}

	/** An unmaterialized batch remains cancellable while its empty queue still has sequence one. */
	public function test_cancel_accepts_a_pre_start_batch_with_an_empty_queue(): void {
		$run_id = $this->start_batch();
		$state  = $this->run_state( self::BATCH_NAME, $run_id );
		self::assertSame( array(), $state->queue );
		self::assertSame( 1, $state->action_seq );

		$result = $this->dispatcher->cancel( self::BATCH_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::BATCH_NAME, $run_id );
	}

	/** An advanced batch with an empty queue preserves its pending cleanup and success callback. */
	public function test_cancel_rejects_an_empty_materialized_batch(): void {
		$run_id = $this->start_batch();
		$this->replace_state(
			self::BATCH_NAME,
			$run_id,
			static fn ( RunState $state ): RunState => $state->with_action_seq( 2 )
		);
		$this->reset_observations();

		$result = $this->dispatcher->cancel( self::BATCH_NAME, $run_id );

		$this->assert_engine_failure(
			$result,
			'Run "00000000001700000000-0000000000000000042" has processed its queue; the pending cleanup completes it.'
		);
		self::assertSame( array(), $this->run_state( self::BATCH_NAME, $run_id )->queue );
		$this->assert_no_scheduler_or_hook_effects();
	}

	/** A retained next chunk makes the between-chunks batch state cancellable. */
	public function test_cancel_accepts_a_batch_between_chunks(): void {
		$run_id = $this->start_batch();
		$chunk  = array( 'chunk' => 'next' );
		$this->replace_state(
			self::BATCH_NAME,
			$run_id,
			static fn ( RunState $state ): RunState => $state
				->with_queue( array( $chunk ) )
				->with_action_seq( 3 )
				->with_executing( false )
		);
		$this->reset_observations();

		$result = $this->dispatcher->cancel( self::BATCH_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::BATCH_NAME, $run_id );
	}

	/** A task retry state clears its executing marker and remains cancellable during backoff. */
	public function test_cancel_accepts_a_task_in_retry_backoff(): void {
		$run_id = $this->enqueue_task();
		$state  = $this->replace_state(
			self::TASK_NAME,
			$run_id,
			static fn ( RunState $current ): RunState => $current
				->with_chunk_retries( 1 )
				->with_heartbeat_at( self::NOW + 30 )
				->with_action_seq( 2 )
				->with_executing( false )
		);
		self::assertSame( 1, $state->chunk_retries );
		self::assertSame( self::NOW + 30, $state->heartbeat_at );
		self::assertFalse( $state->executing );
		$this->reset_observations();

		$result = $this->dispatcher->cancel( self::TASK_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::TASK_NAME, $run_id );
	}

	/** A sequential second cancellation observes the row deletion and returns the missing-state refusal. */
	public function test_second_cancel_reports_that_the_run_is_not_retained(): void {
		$run_id = $this->enqueue_task();
		$first  = $this->dispatcher->cancel( self::TASK_NAME, $run_id );
		self::assertInstanceOf( Success::class, $first );
		$this->reset_observations();

		$second = $this->dispatcher->cancel( self::TASK_NAME, $run_id );

		$this->assert_engine_failure(
			$second,
			'Run "00000000001700000000-0000000000000000042" for background-work "email-digest" is not retained; nothing remains to cancel.'
		);
		$this->assert_no_scheduler_or_hook_effects();
		$this->assert_cancelled_history( self::TASK_NAME, $run_id );
	}

	/** Cancellation declares its result non-discardable at the dispatcher boundary. */
	public function test_cancel_declares_no_discard_directly(): void {
		$method = new \ReflectionMethod( Dispatcher::class, 'cancel' );

		self::assertCount( 1, $method->getAttributes( \NoDiscard::class ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Enqueues the deterministic task and clears admission observations.
	 *
	 * @return  string
	 */
	private function enqueue_task(): string {
		$result = $this->dispatcher->enqueue( self::TASK_NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		$this->reset_observations();

		return self::RUN_ID;
	}

	/**
	 * Starts the deterministic batch and clears admission observations.
	 *
	 * @return  string
	 */
	private function start_batch(): string {
		$result = $this->dispatcher->start_batch( self::BATCH_NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		$this->reset_observations();

		return self::RUN_ID;
	}

	/**
	 * Replaces one retained run through its exact raw snapshot.
	 *
	 * @phpstan-param \Closure(RunState): RunState $replacement
	 *
	 * @param   string   $name        Stable task or batch name.
	 * @param   string   $run_id      Run identifier.
	 * @param   \Closure $replacement State replacement.
	 *
	 * @return  RunState
	 */
	private function replace_state( string $name, string $run_id, \Closure $replacement ): RunState {
		$run_store = $this->stores->run_store( $name );
		$snapshot  = $run_store->inspect( $run_id );
		self::assertNotNull( $snapshot );
		self::assertInstanceOf( RunState::class, $snapshot['state'] );
		$state = $replacement( $snapshot['state'] );
		self::assertIsString( $run_store->transition( $run_id, $snapshot['raw'], $state ) );

		return $state;
	}

	/**
	 * Returns one retained typed state from the authoritative raw row.
	 *
	 * @param   string $name   Stable task or batch name.
	 * @param   string $run_id Run identifier.
	 *
	 * @return  RunState
	 */
	private function run_state( string $name, string $run_id ): RunState {
		$snapshot = $this->stores->run_store( $name )->inspect( $run_id );
		self::assertNotNull( $snapshot );
		self::assertInstanceOf( RunState::class, $snapshot['state'] );

		return $snapshot['state'];
	}

	/**
	 * Asserts the complete public and durable outcome of a winning cancellation.
	 *
	 * @phpstan-param AbstractResult<string, EngineError|SchedulingError> $result
	 *
	 * @param   AbstractResult $result Successful cancellation result.
	 * @param   string         $name   Stable task or batch name.
	 * @param   string         $run_id Run identifier.
	 *
	 * @return  void
	 */
	private function assert_successful_cancel( AbstractResult $result, string $name, string $run_id ): void {
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $run_id, $result->value );
		self::assertNull( $this->stores->run_store( $name )->inspect( $run_id ) );
		self::assertArrayNotHasKey( $this->lock_option_name( $name ), $this->wpdb->rows );
		$this->assert_group_clear( $name . '|' . $run_id );
		$this->assert_cancelled_history( $name, $run_id );
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp/background_tasks/cancelled/' . $name,
					'args'      => array( $run_id, self::ARGS ),
				),
				array(
					'hook_name' => 'a8csp/background_tasks/cancelled',
					'args'      => array( $name, $run_id, self::ARGS ),
				),
			),
			$this->fired_actions()
		);
	}

	/**
	 * Asserts one exact corrective engine failure.
	 *
	 * @phpstan-param AbstractResult<string, EngineError|SchedulingError> $result
	 *
	 * @param   AbstractResult $result  Rejected cancellation result.
	 * @param   string         $message Expected corrective message.
	 *
	 * @return  EngineError
	 */
	private function assert_engine_failure( AbstractResult $result, string $message ): EngineError {
		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame( $message, $result->error->message );

		return $result->error;
	}

	/**
	 * Asserts the exact executing refusal.
	 *
	 * @phpstan-param AbstractResult<string, EngineError|SchedulingError> $result
	 *
	 * @param   AbstractResult $result Rejected cancellation result.
	 * @param   string         $run_id Run identifier.
	 *
	 * @return  void
	 */
	private function assert_executing_failure( AbstractResult $result, string $run_id ): void {
		$this->assert_engine_failure(
			$result,
			\sprintf(
				'Run "%s" is executing; a run in flight completes or fails on its own.',
				$run_id
			)
		);
	}

	/**
	 * Asserts both ready backends received only the group-clear identity.
	 *
	 * @param   string $group Per-run scheduler group.
	 *
	 * @return  void
	 */
	private function assert_group_clear( string $group ): void {
		$expected = array(
			array(
				'verb' => 'is_ready',
				'args' => array(),
			),
			array(
				'verb' => 'unschedule',
				'args' => array(
					'hook'  => '',
					'args'  => array(),
					'group' => $group,
				),
			),
		);

		self::assertSame( $expected, $this->primary_backend->calls );
		self::assertSame( $expected, $this->secondary_backend->calls );
	}

	/**
	 * Asserts the terminal history carries the Cancelled backed value globally and by argument hash.
	 *
	 * @param   string $name   Stable task or batch name.
	 * @param   string $run_id Run identifier.
	 *
	 * @return  void
	 */
	private function assert_cancelled_history( string $name, string $run_id ): void {
		$entry = array(
			'run_id' => $run_id,
			'status' => 'cancelled',
		);

		self::assertSame(
			array(
				'started'   => array( $run_id ),
				'completed' => array( $entry ),
				'by_hash'   => array(
					self::ARGS_HASH => array(
						'started'   => array( $run_id ),
						'completed' => array( $entry ),
					),
				),
			),
			$this->option( 'a8csp_bgte_history_' . $name )
		);
	}

	/**
	 * Asserts a refusal did not reach scheduling or lifecycle hooks.
	 *
	 * @param   bool $keep_logs Whether a refusal is expected to log diagnostics.
	 *
	 * @return  void
	 */
	private function assert_no_scheduler_or_hook_effects( bool $keep_logs = false ): void {
		self::assertSame( array(), $this->primary_backend->calls );
		self::assertSame( array(), $this->secondary_backend->calls );
		self::assertSame( array(), $this->fired_actions() );
		if ( ! $keep_logs ) {
			self::assertSame( array(), $this->logger->records );
		}
	}

	/** Clears observations without changing retained state or scripted backend outcomes. */
	private function reset_observations(): void {
		$this->primary_backend->calls   = array();
		$this->secondary_backend->calls = array();
		$this->logger->records          = array();
		$this->wpdb->recorded_queries   = array();

		$GLOBALS['a8csp_bgte_test_option_calls']     = array();
		$GLOBALS['a8csp_bgte_test_fired_actions']    = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events'] = array();
	}

	/**
	 * Returns one run option name.
	 *
	 * @param   string $name   Stable task or batch name.
	 * @param   string $run_id Run identifier.
	 *
	 * @return  string
	 */
	private function run_option_name( string $name, string $run_id ): string {
		return 'a8csp_bgte_run_' . $name . '_' . $run_id;
	}

	/**
	 * Returns one argument-identity lock option name.
	 *
	 * @param   string $name Stable task or batch name.
	 *
	 * @return  string
	 */
	private function lock_option_name( string $name ): string {
		return 'a8csp_bgte_lock_' . $name . '_' . self::ARGS_HASH;
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

	// endregion.
}

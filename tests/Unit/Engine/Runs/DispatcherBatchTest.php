<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Error\ErrorInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches\BatchContext;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\LockRows;
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
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingRandomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins batch admission and manual retry across scheduling, storage, locks, and logs.
 *
 */
#[CoversClass( Dispatcher::class )]
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
final class DispatcherBatchTest extends TestCase {
	// region FIELDS AND CONSTANTS.

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

		$this->clock          = new FixedClock( self::NOW );
		$this->backend        = new RecordingBackend();
		$this->batch          = new RecordingBatch( self::NAME );
		$this->logger         = new RecordingLogger();
		$this->randomizer     = new RecordingRandomizer( 42 );
		$this->batches        = new BatchRegistry();
		$this->tasks          = new TaskRegistry();
		$this->wpdb           = new WpdbLockSpy();
		$guard                = new OverlapGuard( $this->clock, $this->logger, new LockRows( $this->wpdb ) );
		$stores               = new StoreFactory( $this->clock, new OptionRows( $this->wpdb ) );
		$lock_windows         = new LockWindows( $this->clock );
		$terminal_transitions = new TerminalTransitions( $guard, $stores, $this->clock, $lock_windows, $this->logger );
		$this->batches->register( $this->batch );
		$this->dispatcher = new Dispatcher(
			$this->tasks,
			$this->batches,
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
	 * Starting a batch persists its identity and schedules queue generation as its own action.
	 *
	 * @return  void
	 */
	public function test_start_batch_creates_a_run_and_schedules_the_internal_start_action(): void {
		$result = $this->dispatcher->start_batch( self::NAME, self::ARGS, unique: true, priority: 23 );

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
				'executing'     => false,
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
	 * Priority validation names the complete engine range before touching any boundary.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_priorities' )]
	public function test_start_batch_rejects_priority_outside_the_engine_range( int $priority ): void {
		$result = $this->dispatcher->start_batch( self::NAME, self::ARGS, priority: $priority );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			\sprintf(
				'Batch "catalog-sync" priority %d is invalid; pass a value from 0 through 255.',
				$priority
			),
			$result->error->message
		);
		$this->assert_start_boundaries_untouched();
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
	 * Manual retry starts a fresh batch run and removes the consumed failed entry.
	 *
	 * @return  void
	 */
	public function test_retry_failed_restarts_a_batch_and_removes_the_failed_entry(): void {
		$store = new FailedRunStore( self::NAME, new OptionRows( $this->wpdb ) );
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

		$result = $this->dispatcher->retry_failed( self::NAME, 'failed-run' );

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

		$result = $this->dispatcher->start_batch( self::NAME, self::ARGS );

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
	 * A held overlap lock rejects a unique start without stopping the incumbent run.
	 *
	 * @return  void
	 */
	public function test_start_batch_rejects_a_unique_held_overlap_without_stopping_the_previous_run(): void {
		$this->seed_running_lock();

		$result = $this->dispatcher->start_batch( self::NAME, self::ARGS, unique: true );

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
	 * A unique held-overlap failure identifies the lock owner without a latest pointer.
	 *
	 * @return  void
	 */
	public function test_start_batch_names_the_lock_owner_when_a_unique_held_overlap_has_no_latest_pointer(): void {
		$this->seed_running_lock();
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );
		unset( $options[ 'a8csp_bgte_latest_' . self::NAME ] );
		$GLOBALS['a8csp_bgte_test_options'] = $options;

		$result = $this->dispatcher->start_batch( self::NAME, self::ARGS, unique: true );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Batch "catalog-sync" is already running as run "run-running"; wait for that run to finish before starting the same arguments.',
			$result->error->message
		);
		self::assertSame( 'run-running', $this->lock()['run_id'] ?? null );
		self::assertSame( array(), $this->backend->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
	}

	/**
	 * A unique held-overlap failure identifies the lock owner when the latest pointer lags.
	 *
	 * @return  void
	 */
	public function test_start_batch_names_the_lock_owner_when_a_unique_held_overlap_has_a_stale_latest_pointer(): void {
		$this->seed_running_lock();
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );
		$options[ 'a8csp_bgte_latest_' . self::NAME ] = array(
			'all'     => 'run-stale',
			'by_hash' => array( self::ARGS_HASH => 'run-stale' ),
		);
		$GLOBALS['a8csp_bgte_test_options']           = $options;

		$result = $this->dispatcher->start_batch( self::NAME, self::ARGS, unique: true );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Batch "catalog-sync" is already running as run "run-running"; wait for that run to finish before starting the same arguments.',
			$result->error->message
		);
		self::assertSame( 'run-running', $this->lock()['run_id'] ?? null );
		self::assertSame( array(), $this->backend->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
	}

	/**
	 * A normal start replaces a held lock and schedules a replacement run.
	 *
	 * @return  void
	 */
	public function test_start_batch_replaces_a_held_incumbent(): void {
		$this->seed_running_lock();

		$result = $this->dispatcher->start_batch( self::NAME, self::ARGS );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		self::assertSame( self::RUN_ID, $this->lock()['run_id'] ?? null );
		self::assertSame(
			array(
				'all'     => self::RUN_ID,
				'by_hash' => array( self::ARGS_HASH => self::RUN_ID ),
			),
			$this->option( 'a8csp_bgte_latest_' . self::NAME )
		);
		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'a8csp/background_tasks/start',
						'args'     => array( self::NAME, self::RUN_ID, 1 ),
						'group'    => self::NAME . '|' . self::RUN_ID,
						'unique'   => false,
						'priority' => 10,
					),
				),
			),
			$this->backend->calls
		);
		self::assertIsArray( $this->option( $this->run_option_name() ) );
	}

	/**
	 * A normal start replaces the current lock owner without relying on the bounded latest pointer.
	 *
	 * @return  void
	 */
	public function test_start_batch_replaces_a_held_incumbent_after_its_latest_pointer_is_evicted(): void {
		$this->seed_running_lock();
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );
		unset( $options[ 'a8csp_bgte_latest_' . self::NAME ] );
		$GLOBALS['a8csp_bgte_test_options'] = $options;

		$result = $this->dispatcher->start_batch( self::NAME, self::ARGS );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		self::assertSame( self::RUN_ID, $this->lock()['run_id'] ?? null );
		self::assertSame(
			array(
				'all'     => self::RUN_ID,
				'by_hash' => array( self::ARGS_HASH => self::RUN_ID ),
			),
			$this->option( 'a8csp_bgte_latest_' . self::NAME )
		);
		self::assertCount( 1, $this->backend->calls );
		self::assertIsArray( $this->option( $this->run_option_name() ) );
	}

	/**
	 * A failed replacement schedule releases its owner without resurrecting the incumbent lock.
	 *
	 * @return  void
	 */
	public function test_start_batch_does_not_restore_the_incumbent_after_replacement_scheduling_fails(): void {
		$failure                                 = $this->scheduling_failure_result();
		$this->backend->results['enqueue_async'] = $failure;
		$this->seed_running_lock();

		$result = $this->dispatcher->start_batch( self::NAME, self::ARGS );

		self::assertSame( $failure, $result );
		self::assertNull( $this->lock() );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertSame(
			array(
				'all'     => self::RUN_ID,
				'by_hash' => array( self::ARGS_HASH => self::RUN_ID ),
			),
			$this->option( 'a8csp_bgte_latest_' . self::NAME )
		);
	}

	/**
	 * A replacement persistence failure leaves the incumbent lock and pointer untouched.
	 *
	 * @return  void
	 */
	public function test_start_batch_persists_replacement_state_before_taking_the_incumbent_lock(): void {
		$this->seed_running_lock();
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );
		$options[ $this->run_option_name() ] = array( 'collision' => true );
		$GLOBALS['a8csp_bgte_test_options']  = $options;

		$result = $this->dispatcher->start_batch( self::NAME, self::ARGS );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			\sprintf(
				'Run "%1$s" for batch "%2$s" could not be persisted; remove the conflicting run option before retrying.',
				self::RUN_ID,
				self::NAME
			),
			$result->error->message
		);
		self::assertSame( 'run-running', $this->lock()['run_id'] ?? null );
		self::assertSame(
			array(
				'all'     => 'run-running',
				'by_hash' => array( self::ARGS_HASH => 'run-running' ),
			),
			$this->option( 'a8csp_bgte_latest_' . self::NAME )
		);
		self::assertSame( array( 'collision' => true ), $this->option( $this->run_option_name() ) );
		self::assertSame( array(), $this->backend->calls );
	}

	/**
	 * A lost replacement CAS removes the provisional run and identifies the retry correction.
	 *
	 * @return  void
	 */
	public function test_start_batch_removes_provisional_state_when_replacement_ownership_changes(): void {
		$this->seed_running_lock();
		$this->wpdb->before_next(
			'update',
			function ( WpdbLockSpy $wpdb ): void {
				$raw = \maybe_serialize(
					array(
						'run_id'       => 'run-concurrent-owner',
						'claimed_at'   => self::NOW,
						'heartbeat_at' => self::NOW,
					)
				);
				self::assertIsString( $raw );
				$wpdb->put( 'a8csp_bgte_lock_' . self::NAME . '_' . self::ARGS_HASH, $raw );
			}
		);

		$result = $this->dispatcher->start_batch( self::NAME, self::ARGS );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame(
			'Batch "catalog-sync" lock ownership changed while the replacement was claiming it; retry the start against the current owner.',
			$result->error->message
		);
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertSame( 'run-concurrent-owner', $this->lock()['run_id'] ?? null );
		self::assertSame( array(), $this->backend->calls );
	}

	/**
	 * Batch start rejects a name shared with a task before touching runtime boundaries.
	 *
	 * @return  void
	 */
	public function test_start_batch_rejects_a_name_resolvable_in_both_registries(): void {
		$this->tasks->register( new RecordingTask( self::NAME ) );

		$result = $this->dispatcher->start_batch( self::NAME, self::ARGS );

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

		$result = $this->dispatcher->enqueue( self::NAME, self::ARGS );

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

		$result = $this->dispatcher->retry_failed( self::NAME, 'failed-run' );

		$this->assert_ambiguous_name_failure( $result );
		$this->assert_start_boundaries_untouched();
	}

	// phpcs:enable Squiz.Commenting.FunctionComment.MissingParamTag
	// endregion.

	// region HELPERS.

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
	 * Asserts the shared name-space failure identifies the required registration fix.
	 *
	 * @phpstan-param AbstractResult<mixed, ErrorInterface> $result
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
	 * Returns the deterministic run option name.
	 *
	 * @return  string
	 */
	private function run_option_name(): string {
		return 'a8csp_bgte_run_' . self::NAME . '_' . self::RUN_ID;
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

	// endregion.
}

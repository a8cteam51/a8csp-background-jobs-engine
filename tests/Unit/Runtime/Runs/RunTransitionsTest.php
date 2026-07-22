<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\HeartbeatOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\JobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\KindHandlerInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Randomizer;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\LatestRunPointer;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingRandomizer;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins active-run fencing and terminal job transitions across storage, hooks, locks, and logs.
 *
 * @load-bearing concurrency
 * @pin-rationale Terminal ownership is decided by exact run-state and lock-row races whose losing transitions are not observable or controllable through the public run facade.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 */
#[CoversClass( RunTransitions::class )]
#[UsesClass( EngineError::class )]
#[UsesClass( FailureLifecycle::class )]
#[UsesClass( FailedRunStore::class )]
#[UsesClass( LatestRunPointer::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
#[UsesClass( HeartbeatOutcome::class )]
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
#[UsesClass( LifecycleEffects::class )]
#[UsesClass( JobRegistry::class )]
#[UsesClass( JobKindHandler::class )]
final class RunTransitionsTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array ARGS = array(
		'site_id' => 7,
		'mode'    => 'full',
	);

	private const string ARGS_HASH = '7dcca9cc21619f109d6f0423c49b010606457ea4a713721e9ce5134949d72bd2';
	private const string IDENTITY  = self::OWNER . ':' . self::NAME;
	private const string NAME      = 'email-digest';
	private const int NOW          = 1_700_000_000;
	private const string OWNER     = 'runs-tests';
	private const string RUN_ID    = '00000000001700000000-0000000000000000042';

	private FixedClock $clock;
	private RecordingBackend $backend;
	private FailureLifecycle $failure_lifecycle;
	private RecordingLogger $logger;
	private RecordingRandomizer $randomizer;
	private OptionRows $rows;
	private RecordingJob $job;
	private JobKindHandler $handler;
	/** @var array<string, KindHandlerInterface> */
	private array $handlers;
	private RunTransitions $terminal_transitions;
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
	 * Resets every observable boundary and constructs one registered job lifecycle.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgje_test_options']              = array();
		$GLOBALS['a8csp_bgje_test_option_calls']         = array();
		$GLOBALS['a8csp_bgje_test_option_autoload']      = array();
		$GLOBALS['a8csp_bgje_test_filter_values']        = array();
		$GLOBALS['a8csp_bgje_test_filter_registrations'] = array();
		$GLOBALS['a8csp_bgje_test_fired_actions']        = array();
		$GLOBALS['a8csp_bgje_test_action_throwables']    = array();
		$GLOBALS['a8csp_bgje_test_hooks']                = array();
		$GLOBALS['a8csp_bgje_test_action_registrations'] = array();
		$GLOBALS['a8csp_bgje_test_blog_id']              = 1;
		$GLOBALS['a8csp_bgje_test_cache']                = array();
		$GLOBALS['a8csp_bgje_test_cache_calls']          = array();
		$GLOBALS['a8csp_bgje_test_lifecycle_events']     = array();
		unset( $GLOBALS['a8csp_bgje_test_before_add_option'] );

		$this->clock      = new FixedClock( self::NOW );
		$this->backend    = new RecordingBackend();
		$this->logger     = new RecordingLogger();
		$this->randomizer = new RecordingRandomizer( 42 );
		$this->job        = new RecordingJob( self::NAME );
		$work             = new JobRegistry();
		$work->register_job( self::IDENTITY, $this->job );
		$this->wpdb                 = new WpdbLockSpy();
		$this->rows                 = new OptionRows( $this->wpdb );
		$guard                      = new OverlapGuard( $this->clock, $this->logger, $this->rows );
		$stores                     = new StoreFactory( $this->clock, $this->rows, $this->logger );
		$lock_windows               = new LockWindows( $this->clock, $this->logger );
		$terminal_effects           = new LifecycleEffects( $guard, $stores, $this->logger );
		$this->terminal_transitions = new RunTransitions( $guard, $stores, $this->clock, $lock_windows, $this->logger, $terminal_effects );
		$this->failure_lifecycle    = new FailureLifecycle( $this->backend, $this->clock, $this->randomizer, $this->logger, $this->terminal_transitions );
		$this->handler              = new JobKindHandler( $work, $this->logger, $this->clock, $lock_windows, $this->terminal_transitions, $terminal_effects, $this->failure_lifecycle );
		$this->handlers             = array( $this->handler->key() => $this->handler );

		$this->dispatcher = new Dispatcher( $work, $this->handlers, $this->backend, $guard, $stores, $this->clock, $this->randomizer, $this->logger, $lock_windows, $this->terminal_transitions );
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
				$this->handle_job_run_action( self::RUN_ID, $this->action_sequence() );
			}
		);

		$this->handle_job_run_action( self::RUN_ID, $this->action_sequence() );

		self::assertSame( array( self::ARGS ), $this->job->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->option( FailedRunStore::OPTION_PREFIX . self::IDENTITY ) );
		self::assertSame( array(), $this->logger->records );
		self::assertSame(
			array(
				'a8csp_jobs_engine/completed/' . self::IDENTITY,
				'a8csp_jobs_engine/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_terminal_history( 'completed' );
	}

	/**
	 * A stale job delivery exits after its authoritative read and before heartbeats, callbacks, or writes.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_drops_a_stale_sequence_before_every_side_effect(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$state     = $run_store->get( self::RUN_ID );
		self::assertNotNull( $state );
		self::assertIsString( $run_store->replace_if_state_matches( self::RUN_ID, $state, $state->with_action_sequence( 2 ) ) );
		$expected = $this->option( $this->run_option_name() );

		$GLOBALS['a8csp_bgje_test_option_calls']     = array();
		$GLOBALS['a8csp_bgje_test_lifecycle_events'] = array();
		$this->wpdb->recorded_queries                = array();
		$this->job->max_callback_runtime_throwable   = new \RuntimeException( 'Stale delivery must not resolve callback liveness.' );

		$this->handle_job_run_action( self::RUN_ID, 1 );

		self::assertSame( array(), $this->job->calls );
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( $expected, $this->option( $this->run_option_name() ) );
		$this->assert_only_authoritative_run_read( self::RUN_ID );
		self::assertSame( array(), $GLOBALS['a8csp_bgje_test_option_calls'] );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'info', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->logger->records[0]['context']['name'] ?? null );
		self::assertSame( 2, $this->logger->records[0]['context']['expected'] ?? null );
		self::assertSame( 1, $this->logger->records[0]['context']['received'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
	}

	/**
	 * A fresh execution marker excludes a same-sequence delivery after its read and before another fence write.
	 *
	 * @return  void
	 */
	public function test_claim_delivery_ownership_drops_a_fresh_same_sequence_delivery(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$first     = $this->claim_delivery_ownership( self::RUN_ID, $this->action_sequence(), $run_store );
		self::assertInstanceOf( RunState::class, $first );
		self::assertTrue( $first->executing );
		$expected_run  = $this->option( $this->run_option_name() );
		$expected_lock = $this->lock();

		$this->logger->records                       = array();
		$this->wpdb->recorded_queries                = array();
		$GLOBALS['a8csp_bgje_test_option_calls']     = array();
		$GLOBALS['a8csp_bgje_test_lifecycle_events'] = array();
		$this->job->max_callback_runtime_throwable   = new \RuntimeException( 'Duplicate delivery must not resolve callback liveness.' );

		$duplicate = $this->claim_delivery_ownership( self::RUN_ID, $this->action_sequence(), $run_store );

		self::assertNull( $duplicate );
		self::assertSame( $expected_run, $this->option( $this->run_option_name() ) );
		self::assertSame( $expected_lock, $this->lock() );
		$this->assert_only_authoritative_run_read( self::RUN_ID );
		self::assertSame( array(), $GLOBALS['a8csp_bgje_test_option_calls'] );
		self::assertSame( array(), $GLOBALS['a8csp_bgje_test_lifecycle_events'] );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'debug', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->logger->records[0]['context']['job_name'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
		self::assertSame( 1, $this->logger->records[0]['context']['action_sequence'] ?? null );
	}

	/**
	 * A stale execution marker re-enters the ownership fence and receives a fresh heartbeat.
	 *
	 * @return  void
	 */
	public function test_claim_delivery_ownership_admits_and_refences_a_stale_execution_marker(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$first     = $this->claim_delivery_ownership( self::RUN_ID, $this->action_sequence(), $run_store );
		self::assertInstanceOf( RunState::class, $first );
		self::assertTrue( $first->executing );

		$this->clock->timestamp = self::NOW + 1_291;
		$this->logger->records  = array();

		$reclaimed = $this->claim_delivery_ownership( self::RUN_ID, $this->action_sequence(), $run_store );

		self::assertInstanceOf( RunState::class, $reclaimed );
		self::assertTrue( $reclaimed->executing );
		self::assertSame( self::NOW + 1_591, $reclaimed->heartbeat_at );
		self::assertSame( self::NOW + 1_591, $this->lock()['heartbeat_at'] ?? null );
		self::assertEquals( $reclaimed, $run_store->get( self::RUN_ID ) );
		self::assertSame( array(), $this->logger->records );
	}

	/**
	 * A stale admission cannot orphan its lock credit after the incumbent advances the run row.
	 *
	 * @return  void
	 */
	public function test_claim_delivery_ownership_drops_a_stale_sequence_after_the_incumbent_advances(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$credit_at = self::NOW + 390;
		$incumbent = $this->claim_delivery_ownership( self::RUN_ID, $this->action_sequence(), $run_store );
		self::assertInstanceOf( RunState::class, $incumbent );

		$reset_at               = $credit_at + 901;
		$advanced               = $incumbent->with_heartbeat_at( $reset_at )->with_action_sequence( $incumbent->action_sequence + 1 );
		$this->clock->timestamp = $reset_at;
		$this->wpdb->before_next(
			'select',
			function () use ( $advanced, $incumbent, $reset_at, $run_store ): void {
				self::assertFalse( $this->terminal_transitions->enforce_delivery_fence( $this->handler, self::IDENTITY, self::RUN_ID, $incumbent, $run_store, $reset_at, $incumbent->heartbeat_at ) );
				self::assertIsString( $run_store->replace_if_state_matches( self::RUN_ID, $incumbent, $advanced ) );
			}
		);

		$reclaimed = $this->claim_delivery_ownership( self::RUN_ID, $incumbent->action_sequence, $run_store );

		self::assertNull( $reclaimed );
		self::assertSame( $reset_at, $this->lock()['heartbeat_at'] ?? null );
		self::assertEquals( $advanced, $run_store->get( self::RUN_ID ) );
	}

	/**
	 * An indeterminate ownership fence aborts without claiming a terminal transition.
	 *
	 * @return  void
	 */
	public function test_enforce_delivery_fence_aborts_without_a_terminal_claim_when_heartbeat_is_indeterminate(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$before    = $run_store->inspect( self::RUN_ID );
		if ( $before->is_failure() ) {
			self::fail( 'The running state could not be inspected before the indeterminate fence.' );
		}
		$before_snapshot = $before->value;
		self::assertNotNull( $before_snapshot );
		self::assertInstanceOf( RunState::class, $before_snapshot['state'] );
		$state            = $before_snapshot['state'];
		$expected_run_raw = $before_snapshot['raw'];
		$expected_lock    = $this->wpdb->rows[ $this->lock_option_name() ] ?? null;
		$expected_history = $this->option( 'a8csp_bgje_history_' . self::IDENTITY );
		self::assertIsString( $expected_lock );

		$this->logger->records                       = array();
		$this->wpdb->recorded_queries                = array();
		$GLOBALS['a8csp_bgje_test_option_calls']     = array();
		$GLOBALS['a8csp_bgje_test_lifecycle_events'] = array();
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient heartbeat read failure';
			}
		);

		$must_abort = $this->terminal_transitions->enforce_delivery_fence( $this->handler, self::IDENTITY, self::RUN_ID, $state, $run_store );

		self::assertTrue( $must_abort );
		$after = $run_store->inspect( self::RUN_ID );
		if ( $after->is_failure() ) {
			self::fail( 'The running state could not be inspected after the indeterminate fence.' );
		}
		$after_snapshot = $after->value;
		self::assertNotNull( $after_snapshot );
		self::assertInstanceOf( RunState::class, $after_snapshot['state'] );
		$after_state = $after_snapshot['state'];
		self::assertSame( $expected_run_raw, $after_snapshot['raw'] );
		self::assertSame( RunStatus::Running, $after_state->status );
		self::assertSame( self::NOW, $after_state->heartbeat_at );
		self::assertSame( $expected_lock, $this->wpdb->rows[ $this->lock_option_name() ] ?? null );
		self::assertSame( $expected_history, $this->option( 'a8csp_bgje_history_' . self::IDENTITY ) );
		self::assertSame( array(), $this->fired_actions() );
		self::assertSame( array(), $this->lifecycle_labels() );
		foreach ( $this->wpdb->recorded_queries as $query ) {
			self::assertStringStartsWith( 'SELECT ', $query );
		}
		self::assertCount( 2, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( $this->lock_option_name(), $this->logger->records[0]['context']['key'] ?? null );
		self::assertSame( self::IDENTITY, $this->logger->records[0]['context']['name'] ?? null );
		self::assertSame( self::ARGS_HASH, $this->logger->records[0]['context']['args_hash'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
		self::assertSame( 'debug', $this->logger->records[1]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->logger->records[1]['context']['job_name'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[1]['context']['run_id'] ?? null );
	}

	/**
	 * A throwing group-clear listener cannot strand cancellation state or suppress lifecycle hooks.
	 *
	 * @return  void
	 */
	public function test_cancel_run_finishes_terminal_state_when_group_clear_throws(): void {
		$this->prepare_run_action();
		$run_store  = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
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
				$this->handler,
				self::IDENTITY,
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
		$actions       = $this->fired_actions();
		$public_run_id = $actions[0]['args'][0] ?? null;
		self::assertInstanceOf( RunId::class, $public_run_id );
		self::assertSame( self::RUN_ID, (string) $public_run_id );
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_jobs_engine/cancelled/' . self::IDENTITY,
					'args'      => array( $public_run_id, self::ARGS ),
				),
				array(
					'hook_name' => 'a8csp_jobs_engine/cancelled',
					'args'      => array( self::IDENTITY, $public_run_id, self::ARGS ),
				),
			),
			$actions
		);
		$this->assert_terminal_history( 'cancelled' );
	}

	/**
	 * A successful retry clears the invocation's failed-attempt counter before completion.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_resets_the_counter_after_a_successful_retry(): void {
		$this->job->retry_policy = new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 );
		$this->job->throwable    = new \RuntimeException( 'Transient failure.' );
		$this->prepare_run_action();
		$this->randomizer->value = 5;
		$this->randomizer->calls = array();

		$this->handle_job_run_action( self::RUN_ID, $this->action_sequence() );
		$this->job->throwable   = null;
		$this->clock->timestamp = self::NOW + 95;
		$this->handle_job_run_action( self::RUN_ID, $this->action_sequence() );

		self::assertSame( 0, $this->recorded_run_state( 'completed' )['failed_attempts'] );
		self::assertNull( $this->option( FailedRunStore::OPTION_PREFIX . self::IDENTITY ) );
	}

	/**
	 * A retry action that loses replacement-lock ownership exits as Superseded before re-execution.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_before_a_scheduled_retry_executes(): void {
		$this->job->retry_policy = new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 );
		$this->job->throwable    = new \RuntimeException( 'Transient failure.' );
		$this->prepare_run_action();
		$this->randomizer->value = 5;
		$this->randomizer->calls = array();
		$this->handle_job_run_action( self::RUN_ID, $this->action_sequence() );
		self::assertTrue( ( new LatestRunPointer( self::IDENTITY, $this->rows ) )->record( 'run-newer', self::ARGS_HASH ) );
		$this->replace_lock_owner( 'run-newer', self::NOW + 95 );
		$this->backend->calls = array();

		$GLOBALS['a8csp_bgje_test_fired_actions'] = array();

		$this->clock->timestamp = self::NOW + 95;
		$this->handle_job_run_action( self::RUN_ID, $this->action_sequence() );

		self::assertSame( array( self::ARGS ), $this->job->calls );
		self::assertSame( array(), $this->backend->calls );
		self::assertNull( $this->option( FailedRunStore::OPTION_PREFIX . self::IDENTITY ) );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		self::assertSame(
			array(
				'a8csp_jobs_engine/superseded/' . self::IDENTITY,
				'a8csp_jobs_engine/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertCount( 2, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->logger->records[0]['context']['name'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
		self::assertSame( 'info', $this->logger->records[1]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->logger->records[1]['context']['job_name'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[1]['context']['run_id'] ?? null );
		self::assertSame( 'run-newer', $this->logger->records[1]['context']['latest_run_id'] ?? null );
	}

	/**
	 * Moved replacement ownership fences the run before job execution and uses quiet cleanup.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_a_run_that_lost_replacement_ownership(): void {
		$this->prepare_run_action();
		self::assertTrue( ( new LatestRunPointer( self::IDENTITY, $this->rows ) )->record( 'run-newer', self::ARGS_HASH ) );
		$this->replace_lock_owner( 'run-newer', self::NOW + 90 );
		$GLOBALS['a8csp_bgje_test_option_calls']     = array();
		$GLOBALS['a8csp_bgje_test_lifecycle_events'] = array();

		$this->handle_job_run_action( self::RUN_ID, $this->action_sequence() );

		self::assertSame( array(), $this->job->calls );
		self::assertNull( $this->option( FailedRunStore::OPTION_PREFIX . self::IDENTITY ) );
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		self::assertNull( $this->option( $this->run_option_name() ) );
		$actions       = $this->fired_actions();
		$public_run_id = $actions[0]['args'][0] ?? null;
		self::assertInstanceOf( RunId::class, $public_run_id );
		self::assertSame( self::RUN_ID, (string) $public_run_id );
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_jobs_engine/superseded/' . self::IDENTITY,
					'args'      => array( $public_run_id, self::ARGS ),
				),
				array(
					'hook_name' => 'a8csp_jobs_engine/superseded',
					'args'      => array( self::IDENTITY, $public_run_id, self::ARGS ),
				),
			),
			$actions
		);
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'info', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->logger->records[0]['context']['job_name'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
		self::assertSame( 'run-newer', $this->logger->records[0]['context']['latest_run_id'] ?? null );
		self::assertSame(
			array(
				'run:superseded',
				'hook:superseded/' . self::IDENTITY,
				'hook:superseded',
				'run:superseded:hooks',
				'history',
				'run:superseded:hooks,history',
				'run:delete',
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
		self::assertTrue( ( new LatestRunPointer( self::IDENTITY, $this->rows ) )->record( 'run-losing-starter', self::ARGS_HASH ) );

		$this->handle_job_run_action( self::RUN_ID, $this->action_sequence() );

		self::assertSame( array( self::ARGS ), $this->job->calls );
		self::assertSame(
			array(
				'a8csp_jobs_engine/completed/' . self::IDENTITY,
				'a8csp_jobs_engine/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertSame(
			array(
				'all'     => self::RUN_ID,
				'by_hash' => array( self::ARGS_HASH => self::RUN_ID ),
			),
			$this->option( 'a8csp_bgje_latest_run_' . self::IDENTITY )
		);
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		self::assertSame( array(), $this->logger->records );
		$this->assert_terminal_history( 'completed' );
	}

	/** A latest-pointer write failure is logged without rejecting an otherwise accepted run. */
	public function test_enqueue_logs_a_latest_pointer_write_failure_and_continues(): void {
		$this->wpdb->before_next( 'insert', static function (): void {} );
		for ( $attempt = 0; 5 > $attempt; ++$attempt ) {
			$this->wpdb->before_next(
				'insert',
				static function ( WpdbLockSpy $wpdb ): void {
					$wpdb->script_result( 'insert', false );
				}
			);
		}

		$result = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->logger->records[0]['context']['name'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
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

			$result = $this->dispatcher->enqueue( self::IDENTITY, array( 'identity' => $index ) );
			self::assertInstanceOf( Success::class, $result );
			$run_id = $result->value;
			self::assertIsString( $run_id );
			$run_ids[] = $run_id;
		}

		$first_run_id = $run_ids[0];

		$this->clock->timestamp = self::NOW + 90;
		$this->job->calls       = array();
		$this->logger->records  = array();

		$GLOBALS['a8csp_bgje_test_fired_actions']    = array();
		$GLOBALS['a8csp_bgje_test_lifecycle_events'] = array();

		$this->handle_job_run_action( $first_run_id, $this->action_sequence( $first_run_id ) );

		self::assertSame( array( array( 'identity' => 0 ) ), $this->job->calls );
		self::assertSame(
			array(
				'a8csp_jobs_engine/completed/' . self::IDENTITY,
				'a8csp_jobs_engine/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertSame( $run_ids[20], ( new LatestRunPointer( self::IDENTITY, $this->rows ) )->get_latest(), 'Repairing the evicted owner identity must preserve the globally newest run' );
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

		$this->handle_job_run_action( self::RUN_ID, $this->action_sequence() );

		self::assertSame( array(), $this->job->calls );
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		self::assertSame(
			array(
				'a8csp_jobs_engine/superseded/' . self::IDENTITY,
				'a8csp_jobs_engine/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertNull( $this->option( $this->run_option_name() ) );
		$this->assert_terminal_history( 'superseded' );
	}

	/**
	 * A confirmed foreign owner still claims and records the Superseded transition.
	 *
	 * @return  void
	 */
	public function test_enforce_delivery_fence_persists_superseded_after_confirmed_foreign_owner(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$state     = $run_store->get( self::RUN_ID );
		self::assertNotNull( $state );
		$this->replace_lock_owner( 'run-newer', self::NOW + 90 );

		$must_abort = $this->terminal_transitions->enforce_delivery_fence( $this->handler, self::IDENTITY, self::RUN_ID, $state, $run_store );

		self::assertTrue( $must_abort );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		self::assertSame( 'superseded', $this->recorded_run_state( 'superseded' )['status'] );
		self::assertSame(
			array(
				'a8csp_jobs_engine/superseded/' . self::IDENTITY,
				'a8csp_jobs_engine/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_terminal_history( 'superseded' );
	}

	/**
	 * A persisted terminal state never re-enters job execution before reconciliation.
	 *
	 * @return  void
	 */
	#[DataProvider( 'terminal_statuses' )]
	public function test_handle_run_action_does_not_execute_a_persisted_terminal_state( string $status ): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$state     = $run_store->get( self::RUN_ID );
		self::assertNotNull( $state );
		self::assertIsString( $run_store->replace_if_state_matches( self::RUN_ID, $state, $state->with_status( RunStatus::from( $status ) ) ) );

		$this->logger->records = array();

		$GLOBALS['a8csp_bgje_test_fired_actions']    = array();
		$GLOBALS['a8csp_bgje_test_lifecycle_events'] = array();

		$this->handle_job_run_action( self::RUN_ID, $this->action_sequence() );

		self::assertSame( array(), $this->job->calls );
		self::assertSame( array(), $this->fired_actions() );
		self::assertSame( array(), $this->lifecycle_labels() );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->logger->records[0]['context']['job_name'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
		self::assertSame( $status, $this->logger->records[0]['context']['status'] ?? null );
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
	 * Only the winning failed-state compare-and-swap emits the permanent-failure event.
	 *
	 * @return  void
	 */
	public function test_terminal_failure_logs_once_for_the_winning_transition(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$state     = $run_store->get( self::RUN_ID );
		self::assertNotNull( $state );
		$error = EngineError::from_throwable( new \RuntimeException( 'Permanent database failure.' ) );

		$this->terminal_transitions->fail_run( $this->handler, $this->job, self::IDENTITY, self::RUN_ID, $state, $run_store, $error, 3, RunFailureStage::execution(), ErrorCode::ExecutionFailed );
		$this->terminal_transitions->fail_run( $this->handler, $this->job, self::IDENTITY, self::RUN_ID, $state, $run_store, $error, 3, RunFailureStage::execution(), ErrorCode::ExecutionFailed );

		$error_records = \array_values( \array_filter( $this->logger->records, static fn ( array $record ): bool => 'error' === $record['level'] ) );
		self::assertCount( 1, $error_records );
		self::assertSame( self::IDENTITY, $error_records[0]['context']['name'] ?? null );
		self::assertSame( self::RUN_ID, $error_records[0]['context']['run_id'] ?? null );
		self::assertSame( 3, $error_records[0]['context']['attempts'] ?? null );
		self::assertSame( RunFailureStage::execution()->value, $error_records[0]['context']['stage'] ?? null );
		self::assertSame( \RuntimeException::class, $error_records[0]['context']['error_class'] ?? null );
	}

	/**
	 * An absent run is a benign survivor from a finished or cancelled delivery.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_drops_an_absent_run_as_stale(): void {
		$GLOBALS['a8csp_bgje_test_lifecycle_events'] = array();

		$this->handle_job_run_action( 'missing-run', 1 );

		self::assertSame( array(), $this->job->calls );
		self::assertSame( array(), $this->fired_actions() );
		self::assertSame( array(), $this->lifecycle_labels() );
		$this->assert_only_authoritative_run_read( 'missing-run' );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'debug', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->logger->records[0]['context']['name'] ?? null );
		self::assertSame( 'missing-run', $this->logger->records[0]['context']['run_id'] ?? null );
		self::assertStringContainsString( 'finished or cancelled', $this->logger->records[0]['message'] ?? '' );
	}

	/**
	 * A corrupt run warns without creating another lifecycle transition.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_warns_when_run_state_is_corrupt(): void {
		$this->wpdb->put( $this->run_option_name(), 'corrupt-run-state' );

		$this->handle_job_run_action( self::RUN_ID, 1 );

		self::assertSame( array(), $this->job->calls );
		self::assertSame( array(), $this->fired_actions() );
		$this->assert_only_authoritative_run_read( self::RUN_ID );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->logger->records[0]['context']['name'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
		self::assertStringContainsString( 'corrupt', $this->logger->records[0]['message'] ?? '' );
	}

	/**
	 * An authoritative run read failure warns without claiming delivery ownership.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_warns_when_run_state_cannot_be_read(): void {
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted run-state read failure';
			}
		);

		$this->handle_job_run_action( self::RUN_ID, 1 );

		self::assertSame( array(), $this->job->calls );
		self::assertSame( array(), $this->fired_actions() );
		$this->assert_only_authoritative_run_read( self::RUN_ID );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->logger->records[0]['context']['name'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
		self::assertStringContainsString( 'could not be read', $this->logger->records[0]['message'] ?? '' );
		$read_error = $this->logger->records[0]['context']['error'] ?? null;
		self::assertIsString( $read_error );
		self::assertStringContainsString( 'read failed', $read_error );
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
		return RunStore::OPTION_PREFIX . self::IDENTITY . '_' . self::RUN_ID;
	}

	/**
	 * Asserts that one delivery performed only the required authoritative run-state read.
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @return  void
	 */
	private function assert_only_authoritative_run_read( string $run_id ): void {
		self::assertCount( 1, $this->wpdb->recorded_queries );
		$query = $this->wpdb->recorded_queries[0];
		self::assertStringStartsWith( 'SELECT `option_value` FROM ', $query );
		self::assertStringContainsString( 'a8csp_bgje_run_' . self::IDENTITY . '_' . $run_id, $query );
		self::assertStringEndsWith( ' LIMIT 1', $query );
	}

	/**
	 * Returns the newest scheduled lifecycle action sequence for one live run.
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @return  int
	 */
	private function action_sequence( string $run_id = self::RUN_ID ): int {
		$state = $this->option( 'a8csp_bgje_run_' . self::IDENTITY . '_' . $run_id );
		self::assertIsArray( $state );
		$action_sequence = $state['action_sequence'] ?? null;
		self::assertIsInt( $action_sequence );

		return $action_sequence;
	}

	/**
	 * Enqueues the deterministic run and clears enqueue observations before action handling.
	 *
	 * @return  void
	 */
	private function prepare_run_action(): void {
		$result = $this->dispatcher->enqueue( self::IDENTITY, self::ARGS );
		self::assertInstanceOf( Success::class, $result );

		$this->clock->timestamp       = self::NOW + 90;
		$this->backend->calls         = array();
		$this->logger->records        = array();
		$this->wpdb->recorded_queries = array();

		$GLOBALS['a8csp_bgje_test_fired_actions']    = array();
		$GLOBALS['a8csp_bgje_test_option_calls']     = array();
		$GLOBALS['a8csp_bgje_test_lifecycle_events'] = array();
	}

	/**
	 * Runs one job attempt through the terminal-transition product services.
	 *
	 * @param   string $run_id     Run identifier.
	 * @param   int    $action_sequence Received lifecycle action sequence.
	 *
	 * @return  void
	 */
	private function handle_job_run_action( string $run_id, int $action_sequence ): void {
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$state     = $this->claim_delivery_ownership( $run_id, $action_sequence, $run_store );
		if ( null === $state ) {
			return;
		}

		$this->handler->deliver( self::IDENTITY, $run_id, $state, $run_store );
	}

	/**
	 * Claims one job delivery and returns its fenced state.
	 *
	 * @param   string   $run_id          Run identifier.
	 * @param   int|null $action_sequence Received lifecycle action sequence.
	 * @param   RunStore $run_store       Active-run store.
	 *
	 * @return  RunState|null
	 */
	private function claim_delivery_ownership( string $run_id, ?int $action_sequence, RunStore $run_store ): ?RunState {
		$claimed = $this->terminal_transitions->claim_delivery_ownership( $this->handlers, self::IDENTITY, $run_id, $action_sequence, $run_store );

		return $claimed?->state;
	}

	/**
	 * Asserts that the terminal buffer records the run outcome.
	 *
	 * @phpstan-param 'completed'|'failed'|'cancelled'|'superseded' $status
	 *
	 * @param   string $status Expected terminal status.
	 *
	 * @return  void
	 */
	private function assert_terminal_history( string $status ): void {
		$this->recorded_run_state( $status );

		self::assertSame(
			array(
				'started'  => array( self::RUN_ID ),
				'terminal' => array(
					array(
						'run_id' => self::RUN_ID,
						'status' => $status,
					),
				),
				'by_hash'  => array(
					self::ARGS_HASH => array(
						'started'  => array( self::RUN_ID ),
						'terminal' => array(
							array(
								'run_id' => self::RUN_ID,
								'status' => $status,
							),
						),
					),
				),
			),
			$this->option( 'a8csp_bgje_history_' . self::IDENTITY )
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
	 *     kind_state: mixed,
	 *     failed_attempts: mixed,
	 *     action_sequence: mixed,
	 *     created_at: mixed,
	 *     heartbeat_at: mixed
	 * }
	 */
	private function recorded_run_state( string $status ): array {
		$events = $GLOBALS['a8csp_bgje_test_lifecycle_events'] ?? null;
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
				self::assertArrayNotHasKey( 'pending', $state );

				return array(
					'status'          => $state['status'] ?? null,
					'executing'       => $state['executing'] ?? null,
					'start_args'      => $state['start_args'] ?? null,
					'args_hash'       => $state['args_hash'] ?? null,
					'kind_state'      => $state['kind_state'] ?? null,
					'failed_attempts' => $state['failed_attempts'] ?? null,
					'action_sequence' => $state['action_sequence'] ?? null,
					'created_at'      => $state['created_at'] ?? null,
					'heartbeat_at'    => $state['heartbeat_at'] ?? null,
				);
			}
		}

		$calls = $GLOBALS['a8csp_bgje_test_option_calls'] ?? null;
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
				self::assertArrayNotHasKey( 'pending', $state );

				return array(
					'status'          => $state['status'] ?? null,
					'executing'       => $state['executing'] ?? null,
					'start_args'      => $state['start_args'] ?? null,
					'args_hash'       => $state['args_hash'] ?? null,
					'kind_state'      => $state['kind_state'] ?? null,
					'failed_attempts' => $state['failed_attempts'] ?? null,
					'action_sequence' => $state['action_sequence'] ?? null,
					'created_at'      => $state['created_at'] ?? null,
					'heartbeat_at'    => $state['heartbeat_at'] ?? null,
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
		$events = $GLOBALS['a8csp_bgje_test_lifecycle_events'] ?? null;
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
						$labels[] = self::run_state_label( $value );
					}

					continue;
				}
				if ( 'delete' !== $operation && 'a8csp_bgje_failed_runs_' . self::IDENTITY === ( $event['key'] ?? null ) ) {
					$labels[] = 'failed-store';
					continue;
				}
				if ( 'delete' !== $operation && 'a8csp_bgje_history_' . self::IDENTITY === ( $event['key'] ?? null ) ) {
					$labels[] = 'history';
					continue;
				}
				$labels[] = 'lock:' . $operation;
				continue;
			}

			if ( 'job' === $type ) {
				$labels[] = 'job:handle';
				continue;
			}

			if ( 'action' === $type ) {
				$hook_name = $event['hook_name'];
				self::assertIsString( $hook_name );
				$labels[] = 'hook:' . \str_replace( 'a8csp_jobs_engine/', '', $hook_name );
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
				$labels[] = self::run_state_label( $value );
			} elseif ( 'a8csp_bgje_failed_runs_' . self::IDENTITY === $option_name ) {
				$labels[] = 'failed-store';
			} elseif ( 'a8csp_bgje_history_' . self::IDENTITY === $option_name ) {
				$labels[] = 'history';
			}
		}

		return $labels;
	}

	/**
	 * Returns a lifecycle label that includes monotonic terminal effect progress.
	 *
	 * @param   array<array-key, mixed> $state Persisted run state.
	 *
	 * @return  string
	 */
	private static function run_state_label( array $state ): string {
		$status = $state['status'] ?? null;
		self::assertIsString( $status );
		$effects       = $state['effects'] ?? array();
		$typed_effects = array();
		self::assertIsArray( $effects );
		foreach ( $effects as $effect ) {
			self::assertIsString( $effect );
			$typed_effects[] = $effect;
		}

		return 'run:' . $status . ( array() === $typed_effects ? '' : ':' . \implode( ',', $typed_effects ) );
	}

	/**
	 * Returns the argument-identity lock option name.
	 *
	 * @return  string
	 */
	private function lock_option_name(): string {
		return OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . self::ARGS_HASH;
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

		$options = $GLOBALS['a8csp_bgje_test_options'] ?? null;
		self::assertIsArray( $options );

		return $options[ $name ] ?? null;
	}

	/**
	 * Returns fired lifecycle actions.
	 *
	 * @return  list<array{hook_name: string, args: list<mixed>}>
	 */
	private function fired_actions(): array {
		$actions = $GLOBALS['a8csp_bgje_test_fired_actions'] ?? null;
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

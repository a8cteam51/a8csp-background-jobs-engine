<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockClaimOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RunStoreInspector;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins durable terminal effects, replay progress, and exact cleanup.
 *
 * @load-bearing durability
 * @pin-rationale Partial effect ledgers and failed exact deletes are crash-recovery states that public lifecycle operations intentionally hide after terminal cleanup.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 */
#[CoversClass( LifecycleEffects::class )]
#[UsesClass( EngineError::class )]
#[UsesClass( FailedRunStore::class )]
#[UsesClass( LockClaimOutcome::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
#[UsesClass( RunFailure::class )]
#[UsesClass( RunId::class )]
#[UsesClass( RunHistory::class )]
#[UsesClass( RunState::class )]
#[UsesClass( RunStatus::class )]
#[UsesClass( RunStore::class )]
#[UsesClass( StoreFactory::class )]
final class LifecycleEffectsTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array ARGS = array(
		'site_id' => 7,
		'mode'    => 'full',
	);

	private const string ARGS_HASH       = '7dcca9cc21619f109d6f0423c49b010606457ea4a713721e9ce5134949d72bd2';
	private const string IDENTITY        = self::SCOPE . ':' . self::NAME;
	private const string NAME            = 'email-digest';
	private const int NOW                = 1_700_000_000;
	private const string SCOPE           = 'runs-tests';
	private const string PREVIOUS_RUN_ID = '00000000001699999999-0000000000000000041';
	private const string RUN_ID          = '00000000001700000000-0000000000000000042';

	private FixedClock $clock;
	private OverlapGuard $guard;
	private Identity $identity;
	private RecordingLogger $logger;
	private StoreFactory $stores;
	private LifecycleEffects $terminal_effects;
	private WpdbLockSpy $wpdb;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress functions before effect services are instantiated.
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
	 * Resets every observable boundary and constructs the claimed-transition executor.
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
		$GLOBALS['a8csp_bgje_test_action_callbacks']     = array();
		$GLOBALS['a8csp_bgje_test_action_throwables']    = array();
		$GLOBALS['a8csp_bgje_test_hooks']                = array();
		$GLOBALS['a8csp_bgje_test_action_registrations'] = array();
		$GLOBALS['a8csp_bgje_test_blog_id']              = 1;
		$GLOBALS['a8csp_bgje_test_cache']                = array();
		$GLOBALS['a8csp_bgje_test_cache_calls']          = array();
		$GLOBALS['a8csp_bgje_test_lifecycle_events']     = array();
		unset( $GLOBALS['a8csp_bgje_test_before_add_option'] );

		$this->clock    = new FixedClock( self::NOW );
		$this->identity = Identity::compose( self::SCOPE, self::NAME );
		$this->logger   = new RecordingLogger();
		$this->wpdb     = new WpdbLockSpy();
		$rows           = new OptionRows( $this->wpdb );
		$this->guard    = new OverlapGuard( $this->clock, $this->logger, $rows, new LockWindows( $this->clock, $this->logger ) );

		$this->stores           = new StoreFactory( $this->clock, $rows, $this->logger );
		$this->terminal_effects = new LifecycleEffects( $this->guard, $this->stores, $this->logger );
	}

	// endregion.

	// region TESTS.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Signatures and providers carry test parameter types.

	/** Non-Failed terminal replay completes generic hooks and history without kind-owned context. */
	public function test_execute_claimed_transition_replays_non_failed_effects_without_failure_detail(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$running   = RunStoreInspector::state( $run_store, self::RUN_ID );
		self::assertNotNull( $running );
		$terminal     = $running->with_status( RunStatus::Superseded )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null );
		$terminal_raw = $this->claim_terminal_state( $run_store, $running, $terminal );

		$finished = $this->terminal_effects->execute_claimed_transition( $this->identity, self::RUN_ID, $terminal, $terminal_raw, $run_store, null );

		self::assertTrue( $finished );
		self::assertNull( RunStoreInspector::state( $run_store, self::RUN_ID ) );
		self::assertNull( $this->option( FailedRunStore::OPTION_PREFIX . self::IDENTITY ) );
		self::assertSame(
			array(
				'a8csp_bgje/superseded/' . self::IDENTITY,
				'a8csp_bgje/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_terminal_history( 'superseded' );
	}

	/** Failed terminal replay consumes an already-resolved failure detail for retention and hooks. */
	public function test_execute_claimed_transition_replays_failed_retention_with_resolved_failure_detail(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$running   = RunStoreInspector::state( $run_store, self::RUN_ID );
		self::assertNotNull( $running );
		$terminal       = $running->with_status( RunStatus::Failed )->with_failed_attempts( 2 )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null )->with_error(
			array(
				'class'   => \RuntimeException::class,
				'message' => 'Persisted terminal failure.',
				'stage'   => RunFailureStage::execution()->value,
				'code'    => ErrorCode::ExecutionFailed->value,
				'details' => array( 'site_id' => 7 ),
			)
		);
		$terminal_raw   = $this->claim_terminal_state( $run_store, $running, $terminal );
		$failure_detail = $this->terminal_effects->resolve_failure_detail( $this->identity, self::RUN_ID, $terminal, null );

		$finished = $this->terminal_effects->execute_claimed_transition( $this->identity, self::RUN_ID, $terminal, $terminal_raw, $run_store, $failure_detail );

		self::assertTrue( $finished );
		self::assertNull( RunStoreInspector::state( $run_store, self::RUN_ID ) );
		$failed = $this->option( FailedRunStore::OPTION_PREFIX . self::IDENTITY );
		self::assertIsArray( $failed );
		$failed_entry = $failed[0] ?? null;
		self::assertIsArray( $failed_entry );
		self::assertSame( 2, $failed_entry['attempts'] ?? null );
		$failed_error = $failed_entry['error'] ?? null;
		self::assertIsArray( $failed_error );
		self::assertSame( array( 'site_id' => 7 ), $failed_error['details'] ?? null );
		$actions = $this->fired_actions();
		$failure = $actions[0]['args'][0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( array( 'site_id' => 7 ), $failure->details );
		$this->assert_terminal_history( 'failed' );
	}

	/** Failed-run retention failure is logged without skipping terminal hooks or history. */
	public function test_failed_run_retention_failure_is_logged_and_later_effects_continue(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$state     = RunStoreInspector::state( $run_store, self::RUN_ID );
		self::assertNotNull( $state );
		for ( $attempt = 0; 5 > $attempt; ++$attempt ) {
			$this->wpdb->before_next(
				'insert',
				static function ( WpdbLockSpy $wpdb ): void {
					$wpdb->script_result( 'insert', false );
				}
			);
		}
		$error          = new EngineError( 'Terminal failure.' );
		$terminal_state = $state->with_status( RunStatus::Failed )->with_failed_attempts( 1 )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null )->with_error(
			array(
				'class'   => $error->exception_class,
				'message' => $error->message,
				'stage'   => RunFailureStage::execution()->value,
				'code'    => ErrorCode::ExecutionFailed->value,
			)
		);
		$terminal_raw   = $this->claim_terminal_state( $run_store, $state, $terminal_state );
		$failure_detail = $this->terminal_effects->resolve_failure_detail( $this->identity, self::RUN_ID, $terminal_state, null );

		$finished = $this->terminal_effects->execute_claimed_transition( $this->identity, self::RUN_ID, $terminal_state, $terminal_raw, $run_store, $failure_detail );

		self::assertFalse( $finished );
		$remaining = RunStoreInspector::state( $run_store, self::RUN_ID );
		self::assertNotNull( $remaining );
		self::assertSame( RunStatus::Failed, $remaining->status );
		self::assertSame( array( 'hooks', 'history' ), $remaining->effects );
		self::assertNull( $this->lock() );
		self::assertNull( $this->option( FailedRunStore::OPTION_PREFIX . self::IDENTITY ) );
		$actions = $this->fired_actions();
		self::assertSame(
			array( 'a8csp_bgje/failed/' . self::IDENTITY, 'a8csp_bgje/failed' ),
			\array_column( $actions, 'hook_name' )
		);
		$failure = $actions[0]['args'][0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( self::RUN_ID, (string) $failure->run_id );
		self::assertSame( self::IDENTITY, $failure->identity );
		$this->assert_terminal_history( 'failed' );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->logger->records[0]['context']['identity'] ?? null );
		self::assertSame( 'job', $this->logger->records[0]['context']['kind'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
	}

	/** Terminal-history failure leaves a marked claim for reconciliation after active lock cleanup. */
	public function test_terminal_history_failure_keeps_the_claim_for_replay(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$state     = RunStoreInspector::state( $run_store, self::RUN_ID );
		self::assertNotNull( $state );
		$this->wpdb->before_next( 'update', static function (): void {} );
		$this->wpdb->before_next( 'update', static function (): void {} );
		$this->wpdb->before_next(
			'update',
			function ( WpdbLockSpy $wpdb ): void {
				$terminal = $this->option( $this->run_option_name() );
				self::assertIsArray( $terminal );
				self::assertSame( 'completed', $terminal['status'] ?? null );
				self::assertSame( array( 'hooks' ), $terminal['effects'] ?? null );
				$wpdb->script_result( 'update', false );
			}
		);
		$terminal_state = $state->with_failed_attempts( 0 )->with_status( RunStatus::Completed )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null )->with_previous_completed_run_id( self::PREVIOUS_RUN_ID );
		$terminal_raw   = $this->claim_terminal_state( $run_store, $state, $terminal_state );

		$finished = $this->terminal_effects->execute_claimed_transition( $this->identity, self::RUN_ID, $terminal_state, $terminal_raw, $run_store, null );

		self::assertFalse( $finished );
		$remaining = RunStoreInspector::state( $run_store, self::RUN_ID );
		self::assertNotNull( $remaining );
		self::assertSame( RunStatus::Completed, $remaining->status );
		self::assertSame( array( 'hooks' ), $remaining->effects );
		self::assertNull( $this->lock() );
		$actions = $this->fired_actions();
		self::assertSame(
			array(
				'a8csp_bgje/completed/' . self::IDENTITY,
				'a8csp_bgje/completed',
			),
			\array_column( $actions, 'hook_name' )
		);
		$named_run_id = $actions[0]['args'][0] ?? null;
		self::assertInstanceOf( RunId::class, $named_run_id );
		self::assertSame( self::RUN_ID, (string) $named_run_id );
		$named_previous_run_id = $actions[0]['args'][2] ?? null;
		self::assertInstanceOf( RunId::class, $named_previous_run_id );
		self::assertSame( self::PREVIOUS_RUN_ID, (string) $named_previous_run_id );
		self::assertSame( $named_run_id, $actions[1]['args'][1] ?? null );
		self::assertSame( $named_previous_run_id, $actions[1]['args'][3] ?? null );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->logger->records[0]['context']['identity'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
	}

	/** Only the exact terminal snapshot carrying every required effect marker may be deleted. */
	public function test_finish_claimed_transition_requires_every_effect_and_the_exact_latest_raw(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$running   = RunStoreInspector::state( $run_store, self::RUN_ID );
		self::assertNotNull( $running );
		$terminal  = $running->with_status( RunStatus::Completed )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null );
		$claim_raw = $run_store->replace_if_state_matches( self::RUN_ID, $running, $terminal );
		self::assertIsString( $claim_raw );

		self::assertFalse( $this->terminal_effects->finish_claimed_transition( $this->identity, self::RUN_ID, $terminal, $claim_raw, $run_store ) );
		self::assertEquals( $terminal, RunStoreInspector::state( $run_store, self::RUN_ID ) );
		self::assertNull( $this->lock() );

		$hooks = $run_store->append_terminal_effect( self::RUN_ID, $terminal, $claim_raw, 'hooks' );
		self::assertIsArray( $hooks );
		self::assertFalse( $this->terminal_effects->finish_claimed_transition( $this->identity, self::RUN_ID, $hooks['state'], $hooks['raw'], $run_store ) );

		$complete = $run_store->append_terminal_effect( self::RUN_ID, $hooks['state'], $hooks['raw'], 'history' );
		self::assertIsArray( $complete );
		self::assertFalse( $this->terminal_effects->finish_claimed_transition( $this->identity, self::RUN_ID, $complete['state'], $hooks['raw'], $run_store ) );
		self::assertEquals( $complete['state'], RunStoreInspector::state( $run_store, self::RUN_ID ) );
		self::assertTrue( $this->terminal_effects->finish_claimed_transition( $this->identity, self::RUN_ID, $complete['state'], $complete['raw'], $run_store ) );
		self::assertNull( RunStoreInspector::state( $run_store, self::RUN_ID ) );
		self::assertSame( array(), $this->logger->records );
	}

	/**
	 * Durable terminal effects are derived from one outcome table.
	 *
	 * @phpstan-param list<string> $effects
	 */
	#[DataProvider( 'terminal_effect_rows' )]
	public function test_expected_terminal_effects( string $status, array $effects ): void {
		self::assertSame( $effects, LifecycleEffects::expected_effects( RunStatus::from( $status ) ) );
	}

	/** The four terminal outcomes retain their public hook names, ordering, and payloads. */
	#[DataProvider( 'terminal_hook_statuses' )]
	public function test_terminal_states_fire_unchanged_hook_payloads( string $status ): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$running   = RunStoreInspector::state( $run_store, self::RUN_ID );
		self::assertNotNull( $running );
		$terminal = $running
			->with_status( RunStatus::from( $status ) )
			->with_heartbeat_at( $this->clock->now()->getTimestamp() )
			->with_pending( null );
		if ( 'completed' === $status ) {
			$terminal = $terminal->with_previous_completed_run_id( self::PREVIOUS_RUN_ID );
		} elseif ( 'failed' === $status ) {
			$terminal = $terminal->with_failed_attempts( 1 )->with_error(
				array(
					'class'   => \RuntimeException::class,
					'message' => 'Terminal failure.',
					'stage'   => RunFailureStage::execution()->value,
					'code'    => ErrorCode::ExecutionFailed->value,
				)
			);
		}
		$terminal_raw   = $this->claim_terminal_state( $run_store, $running, $terminal );
		$failure_detail = 'failed' === $status
			? $this->terminal_effects->resolve_failure_detail( $this->identity, self::RUN_ID, $terminal, null )
			: null;

		self::assertTrue( $this->terminal_effects->execute_claimed_transition( $this->identity, self::RUN_ID, $terminal, $terminal_raw, $run_store, $failure_detail ) );

		$actions = $this->fired_actions();
		if ( 'failed' === $status ) {
			self::assertSame( array( 'a8csp_bgje/failed/' . self::IDENTITY, 'a8csp_bgje/failed' ), \array_column( $actions, 'hook_name' ) );
			$failure = $actions[0]['args'][0] ?? null;
			self::assertInstanceOf( RunFailure::class, $failure );
			self::assertSame( self::IDENTITY, $failure->identity );
			self::assertSame( self::RUN_ID, (string) $failure->run_id );
			self::assertSame( 1, $failure->attempts );
			return;
		}

		$hook = 'a8csp_bgje/' . $status;
		self::assertSame( array( $hook . '/' . self::IDENTITY, $hook ), \array_column( $actions, 'hook_name' ) );
		$run_id = $actions[0]['args'][0] ?? null;
		self::assertInstanceOf( RunId::class, $run_id );
		self::assertSame( self::RUN_ID, (string) $run_id );
		self::assertSame( self::ARGS, $actions[0]['args'][1] ?? null );
		self::assertSame( self::IDENTITY, $actions[1]['args'][0] ?? null );
		self::assertSame( $run_id, $actions[1]['args'][1] ?? null );
		self::assertSame( self::ARGS, $actions[1]['args'][2] ?? null );
		if ( 'completed' === $status ) {
			$previous_run_id = $actions[0]['args'][2] ?? null;
			self::assertInstanceOf( RunId::class, $previous_run_id );
			self::assertSame( self::PREVIOUS_RUN_ID, (string) $previous_run_id );
			self::assertSame( $previous_run_id, $actions[1]['args'][3] ?? null );
		}
	}

	/** Terminal hook payloads share no reference containers with persisted start arguments. */
	public function test_terminal_hooks_detach_referenced_start_arguments_before_listener_access(): void {
		$value      = 'accepted';
		$start_args = array(
			'value'  => &$value,
			'mirror' => &$value,
		);
		$expected   = array(
			'value'  => 'accepted',
			'mirror' => 'accepted',
		);
		$this->prepare_run_action( $start_args );
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$running   = RunStoreInspector::state( $run_store, self::RUN_ID );
		self::assertNotNull( $running );
		$terminal     = $running->with_status( RunStatus::Completed )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null );
		$terminal_raw = $this->claim_terminal_state( $run_store, $running, $terminal );
		$callbacks    = $GLOBALS['a8csp_bgje_test_action_callbacks'] ?? null;
		self::assertIsArray( $callbacks );
		$callbacks[ 'a8csp_bgje/completed/' . self::IDENTITY ] = static function ( RunId $run_id, array $hook_args ): void {
			$hook_args['value'] = 'listener-mutated';
		};

		$GLOBALS['a8csp_bgje_test_action_callbacks'] = $callbacks;

		self::assertTrue( $this->terminal_effects->execute_claimed_transition( $this->identity, self::RUN_ID, $terminal, $terminal_raw, $run_store, null ) );

		$actions = $this->fired_actions();
		self::assertSame( $expected, $actions[0]['args'][1] ?? null );
		self::assertSame( $expected, $actions[1]['args'][2] ?? null );
	}

	/** Retry-scheduled hook payloads share no reference containers with persisted start arguments. */
	public function test_retry_scheduled_hooks_detach_referenced_start_arguments_before_listener_access(): void {
		$value      = 'accepted';
		$start_args = array(
			'value'  => &$value,
			'mirror' => &$value,
		);
		$expected   = array(
			'value'  => 'accepted',
			'mirror' => 'accepted',
		);

		$callbacks = $GLOBALS['a8csp_bgje_test_action_callbacks'] ?? null;
		self::assertIsArray( $callbacks );
		$callbacks[ 'a8csp_bgje/retry_scheduled/' . self::IDENTITY ] = static function ( RunId $run_id, array $hook_args ): void {
			$hook_args['value'] = 'listener-mutated';
		};

		$GLOBALS['a8csp_bgje_test_action_callbacks'] = $callbacks;

		$this->terminal_effects->fire_retry_scheduled( $this->identity, self::RUN_ID, $start_args, 1, 30 );

		$actions = $this->fired_actions();
		self::assertSame( 'accepted', $value );
		self::assertSame( $expected, $actions[0]['args'][1] ?? null );
		self::assertSame( $expected, $actions[1]['args'][2] ?? null );
	}

	/**
	 * Supplies all terminal statuses whose hook contracts are public.
	 *
	 * @return  array<string, array{status: string}>
	 */
	public static function terminal_hook_statuses(): array {
		return array(
			'completed'  => array( 'status' => 'completed' ),
			'failed'     => array( 'status' => 'failed' ),
			'cancelled'  => array( 'status' => 'cancelled' ),
			'superseded' => array( 'status' => 'superseded' ),
		);
	}

	/**
	 * Supplies every terminal outcome.
	 *
	 * @return  array<string, array{status: string, effects: list<string>}>
	 */
	public static function terminal_effect_rows(): array {
		return array(
			'failed'     => array(
				'status'  => 'failed',
				'effects' => array( 'retention', 'hooks', 'history' ),
			),
			'completed'  => array(
				'status'  => 'completed',
				'effects' => array( 'hooks', 'history' ),
			),
			'cancelled'  => array(
				'status'  => 'cancelled',
				'effects' => array( 'hooks', 'history' ),
			),
			'superseded' => array(
				'status'  => 'superseded',
				'effects' => array( 'hooks', 'history' ),
			),
		);
	}

	// phpcs:enable Squiz.Commenting.FunctionComment.MissingParamTag
	// endregion.

	// region HELPERS.

	/**
	 * Creates one running row, owned lock, and started-history entry before clearing setup observations.
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 *
	 * @return  void
	 */
	private function prepare_run_action( array $start_args = self::ARGS ): void {
		$claim = $this->guard->claim( $this->identity, self::ARGS_HASH, self::RUN_ID );
		self::assertSame( LockClaimOutcome::Claimed, $claim->outcome );

		$run_store = $this->stores->run_store( $this->identity );
		if ( null === $run_store->create( self::RUN_ID, 'job', $start_args, self::ARGS_HASH, array() ) ) {
			throw new \RuntimeException( 'The terminal-effect fixture could not create its running row.' );
		}
		if ( ! $this->stores->run_history( $this->identity )->record_started( self::RUN_ID, self::ARGS_HASH ) ) {
			throw new \RuntimeException( 'The terminal-effect fixture could not record its started history.' );
		}

		$this->clock->timestamp       = self::NOW + 90;
		$this->logger->records        = array();
		$this->wpdb->recorded_queries = array();

		$GLOBALS['a8csp_bgje_test_fired_actions']    = array();
		$GLOBALS['a8csp_bgje_test_option_calls']     = array();
		$GLOBALS['a8csp_bgje_test_lifecycle_events'] = array();
	}

	/**
	 * Claims one prepared terminal state and returns its exact persisted bytes.
	 *
	 * @param   RunStore $run_store Name-bound run store.
	 * @param   RunState $running   Complete running state.
	 * @param   RunState $terminal  Terminal replacement state.
	 *
	 * @return  string
	 */
	private function claim_terminal_state( RunStore $run_store, RunState $running, RunState $terminal ): string {
		$terminal_raw = $run_store->replace_if_state_matches( self::RUN_ID, $running, $terminal );
		if ( ! \is_string( $terminal_raw ) ) {
			throw new \RuntimeException( 'The terminal-effect fixture lost its terminal claim.' );
		}

		return $terminal_raw;
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
			$this->option( 'a8csp_bgje_run_history_' . self::IDENTITY )
		);
	}

	/**
	 * Returns the recorded run-state write for one lifecycle status.
	 *
	 * @param   string $status Expected lifecycle status.
	 *
	 * @return  array<array-key, mixed>
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

				return $state;
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

				return $state;
			}
		}

		self::fail( 'The run never persisted the expected lifecycle state.' );
	}

	/**
	 * Returns the internal run option name for the deterministic run.
	 *
	 * @return  string
	 */
	private function run_option_name(): string {
		return 'a8csp_bgje_active_run_' . self::IDENTITY . '_' . self::RUN_ID;
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
	 * Returns the decoded lock row for the deterministic argument identity.
	 *
	 * @return  array{run_id: string, heartbeat_at: int}|null
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
			|| ! \is_int( $value['heartbeat_at'] ?? null )
		) {
			return null;
		}

		return array(
			'run_id'       => $value['run_id'],
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

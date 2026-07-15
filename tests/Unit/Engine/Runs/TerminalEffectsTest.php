<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockClaimOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\TerminalEffects;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins durable terminal effects, replay progress, and exact cleanup.
 *
 */
#[CoversClass( TerminalEffects::class )]
#[UsesClass( EngineError::class )]
#[UsesClass( FailedRunStore::class )]
#[UsesClass( LockClaimOutcome::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
#[UsesClass( RunFailure::class )]
#[UsesClass( RunHistory::class )]
#[UsesClass( RunState::class )]
#[UsesClass( RunStatus::class )]
#[UsesClass( RunStore::class )]
#[UsesClass( StoreFactory::class )]
final class TerminalEffectsTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const ARGS = array(
		'site_id' => 7,
		'mode'    => 'full',
	);

	private const ARGS_HASH = '7dcca9cc21619f109d6f0423c49b010606457ea4a713721e9ce5134949d72bd2';
	private const IDENTITY  = self::OWNER . ':' . self::NAME;
	private const NAME      = 'email-digest';
	private const NOW       = 1_700_000_000;
	private const OWNER     = 'runs-tests';
	private const RUN_ID    = '00000000001700000000-0000000000000000042';

	private FixedClock $clock;
	private OverlapGuard $guard;
	private RecordingLogger $logger;
	private StoreFactory $stores;
	private TerminalEffects $terminal_effects;
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

		$this->clock            = new FixedClock( self::NOW );
		$this->logger           = new RecordingLogger();
		$this->wpdb             = new WpdbLockSpy();
		$rows                   = new OptionRows( $this->wpdb );
		$this->guard            = new OverlapGuard( $this->clock, $this->logger, $rows );
		$this->stores           = new StoreFactory( $this->clock, $rows );
		$this->terminal_effects = new TerminalEffects( $this->guard, $this->stores, $this->logger );
	}

	// endregion.

	// region TESTS.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Signatures and providers carry test parameter types.

	/** Failed-run retention failure is logged without skipping terminal hooks or history. */
	public function test_failed_run_retention_failure_is_logged_and_later_effects_continue(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$state     = $run_store->get( self::RUN_ID );
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
				'stage'   => 'execution',
				'code'    => ApiErrorCode::ExecutionFailed->value,
			)
		);
		$terminal_raw   = $this->claim_terminal_state( $run_store, $state, $terminal_state );

		$finished = $this->terminal_effects->execute_claimed_transition( self::IDENTITY, self::RUN_ID, $terminal_state, $terminal_raw, $run_store, 'Task' );

		self::assertFalse( $finished );
		$remaining = $run_store->get( self::RUN_ID );
		self::assertNotNull( $remaining );
		self::assertSame( RunStatus::Failed, $remaining->status );
		self::assertSame( array( 'hooks', 'history' ), $remaining->effects );
		self::assertNull( $this->lock() );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::IDENTITY ) );
		self::assertSame(
			array(
				'a8csp_background_tasks/failed/' . self::IDENTITY,
				'a8csp_background_tasks/failed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_terminal_history( 'failed' );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Failed run "00000000001700000000-0000000000000000042" could not be retained for manual retry.',
					'context' => array(
						'task_name' => self::IDENTITY,
						'run_id'    => self::RUN_ID,
					),
				),
			),
			$this->logger->records
		);
	}

	/** Terminal-history failure leaves a marked claim for reconciliation after active lock cleanup. */
	public function test_terminal_history_failure_keeps_the_claim_for_replay(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$state     = $run_store->get( self::RUN_ID );
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
		$terminal_state = $state->with_failed_attempts( 0 )->with_status( RunStatus::Completed )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null );
		$terminal_raw   = $this->claim_terminal_state( $run_store, $state, $terminal_state );

		$finished = $this->terminal_effects->execute_claimed_transition( self::IDENTITY, self::RUN_ID, $terminal_state, $terminal_raw, $run_store, 'Task' );

		self::assertFalse( $finished );
		$remaining = $run_store->get( self::RUN_ID );
		self::assertNotNull( $remaining );
		self::assertSame( RunStatus::Completed, $remaining->status );
		self::assertSame( array( 'hooks' ), $remaining->effects );
		self::assertNull( $this->lock() );
		self::assertSame(
			array(
				'a8csp_background_tasks/completed/' . self::IDENTITY,
				'a8csp_background_tasks/completed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Terminal run history could not be persisted; inspection data may be incomplete.',
					'context' => array(
						'name'   => self::IDENTITY,
						'run_id' => self::RUN_ID,
					),
				),
			),
			$this->logger->records
		);
	}

	/** Only the exact terminal snapshot carrying every required effect marker may be deleted. */
	public function test_finish_claimed_transition_requires_every_effect_and_the_exact_latest_raw(): void {
		$this->prepare_run_action();
		$run_store = new RunStore( self::IDENTITY, $this->clock, new OptionRows( $this->wpdb ) );
		$running   = $run_store->get( self::RUN_ID );
		self::assertNotNull( $running );
		$terminal  = $running->with_status( RunStatus::Completed )->with_heartbeat_at( $this->clock->now()->getTimestamp() )->with_pending( null );
		$claim_raw = $run_store->transition_state( self::RUN_ID, $running, $terminal );
		self::assertIsString( $claim_raw );

		self::assertFalse( $this->terminal_effects->finish_claimed_transition( self::IDENTITY, self::RUN_ID, $terminal, $claim_raw, $run_store, 'Task' ) );
		self::assertEquals( $terminal, $run_store->get( self::RUN_ID ) );
		self::assertNull( $this->lock() );

		$hooks = $run_store->append_terminal_effect( self::RUN_ID, $terminal, $claim_raw, 'hooks' );
		self::assertNotNull( $hooks );
		self::assertFalse( $this->terminal_effects->finish_claimed_transition( self::IDENTITY, self::RUN_ID, $hooks['state'], $hooks['raw'], $run_store, 'Task' ) );

		$complete = $run_store->append_terminal_effect( self::RUN_ID, $hooks['state'], $hooks['raw'], 'history' );
		self::assertNotNull( $complete );
		self::assertFalse( $this->terminal_effects->finish_claimed_transition( self::IDENTITY, self::RUN_ID, $complete['state'], $hooks['raw'], $run_store, 'Task' ) );
		self::assertEquals( $complete['state'], $run_store->get( self::RUN_ID ) );
		self::assertTrue( $this->terminal_effects->finish_claimed_transition( self::IDENTITY, self::RUN_ID, $complete['state'], $complete['raw'], $run_store, 'Task' ) );
		self::assertNull( $run_store->get( self::RUN_ID ) );
		self::assertSame( array(), $this->logger->records );
	}

	/**
	 * Durable terminal effects are derived from one outcome-by-work-kind table.
	 *
	 * @phpstan-param 'Task'|'Batch' $work_type
	 * @phpstan-param list<string> $effects
	 */
	#[DataProvider( 'terminal_effect_rows' )]
	public function test_expected_terminal_effects( string $status, string $work_type, array $effects ): void {
		self::assertSame( $effects, TerminalEffects::expected_effects( RunStatus::from( $status ), $work_type ) );
	}

	/**
	 * Supplies every terminal outcome and work-kind combination.
	 *
	 * @return  array<string, array{status: string, work_type: 'Task'|'Batch', effects: list<string>}>
	 */
	public static function terminal_effect_rows(): array {
		return array(
			'failed batch'     => array(
				'status'    => 'failed',
				'work_type' => 'Batch',
				'effects'   => array( 'retention', 'callbacks', 'hooks', 'history' ),
			),
			'failed task'      => array(
				'status'    => 'failed',
				'work_type' => 'Task',
				'effects'   => array( 'retention', 'hooks', 'history' ),
			),
			'completed batch'  => array(
				'status'    => 'completed',
				'work_type' => 'Batch',
				'effects'   => array( 'callbacks', 'hooks', 'history' ),
			),
			'completed task'   => array(
				'status'    => 'completed',
				'work_type' => 'Task',
				'effects'   => array( 'hooks', 'history' ),
			),
			'cancelled batch'  => array(
				'status'    => 'cancelled',
				'work_type' => 'Batch',
				'effects'   => array( 'hooks', 'history' ),
			),
			'cancelled task'   => array(
				'status'    => 'cancelled',
				'work_type' => 'Task',
				'effects'   => array( 'hooks', 'history' ),
			),
			'superseded batch' => array(
				'status'    => 'superseded',
				'work_type' => 'Batch',
				'effects'   => array( 'hooks', 'history' ),
			),
			'superseded task'  => array(
				'status'    => 'superseded',
				'work_type' => 'Task',
				'effects'   => array( 'hooks', 'history' ),
			),
		);
	}

	// phpcs:enable Squiz.Commenting.FunctionComment.MissingParamTag
	// endregion.

	// region HELPERS.

	/**
	 * Creates one running row, owned lock, and started-history entry before clearing setup observations.
	 *
	 * @return  void
	 */
	private function prepare_run_action(): void {
		$claim = $this->guard->claim( self::IDENTITY, self::ARGS_HASH, self::RUN_ID, 900 );
		self::assertSame( LockClaimOutcome::Claimed, $claim );

		$run_store = $this->stores->run_store( self::IDENTITY );
		if ( null === $run_store->create( self::RUN_ID, self::ARGS, self::ARGS_HASH, array() ) ) {
			throw new \RuntimeException( 'The terminal-effect fixture could not create its running row.' );
		}
		if ( ! $this->stores->run_history( self::IDENTITY )->record_started( self::RUN_ID, self::ARGS_HASH ) ) {
			throw new \RuntimeException( 'The terminal-effect fixture could not record its started history.' );
		}

		$this->clock->timestamp       = self::NOW + 90;
		$this->logger->records        = array();
		$this->wpdb->recorded_queries = array();

		$GLOBALS['a8csp_bgte_test_fired_actions']    = array();
		$GLOBALS['a8csp_bgte_test_option_calls']     = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events'] = array();
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
		$terminal_raw = $run_store->transition_state( self::RUN_ID, $running, $terminal );
		if ( null === $terminal_raw ) {
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
			$this->option( 'a8csp_bgte_history_' . self::IDENTITY )
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
				self::assertArrayNotHasKey( 'pending', $state );

				return $state;
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

	// endregion.
}

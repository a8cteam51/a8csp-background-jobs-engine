<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Orchestration;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\FailureLifecycle;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LifecycleDeliveries;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LockRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Orchestrator;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\TaskDispatchSkipped;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\TerminalTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\SchedulingErrorReason;
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
 * Pins schedule-only overlap dispatch without changing the public task API.
 *
 */
#[CoversClass( Orchestrator::class )]
#[UsesClass( LockRows::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( StoreFactory::class )]
#[UsesClass( TaskRegistry::class )]
#[UsesClass( BatchRegistry::class )]
final class OrchestratorScheduleDispatchTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const ARGS      = array( 'site_id' => 7 );
	private const ARGS_HASH = 'd3e2a7f3f4041a96ec4e9d3de1622dea7c050a65d9ee0b77a49a76848fdd9737';
	private const NAME      = 'email-digest';
	private const NOW       = 1_700_000_000;
	private const RUN_ID    = '00000000001700000000-0000000000000000042';

	private RecordingBackend $backend;
	private Orchestrator $orchestrator;
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

		require_once \dirname( __DIR__ ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-hook-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-lock-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-time-constant-stubs.php';
		require_once \dirname( __DIR__ ) . '/Scheduling/wp-json-encode-stub.php';
	}

	/**
	 * Constructs one registered task with observable lock and scheduling seams.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_options']           = array();
		$GLOBALS['a8csp_bgte_test_option_calls']      = array();
		$GLOBALS['a8csp_bgte_test_option_autoload']   = array();
		$GLOBALS['a8csp_bgte_test_filter_values']     = array();
		$GLOBALS['a8csp_bgte_test_fired_actions']     = array();
		$GLOBALS['a8csp_bgte_test_action_throwables'] = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events']  = array();
		$GLOBALS['a8csp_bgte_test_blog_id']           = 1;
		$GLOBALS['a8csp_bgte_test_cache']             = array();
		$GLOBALS['a8csp_bgte_test_cache_calls']       = array();
		unset( $GLOBALS['a8csp_bgte_test_before_add_option'] );

		$clock         = new FixedClock( self::NOW );
		$logger        = new RecordingLogger();
		$tasks         = new TaskRegistry();
		$batches       = new BatchRegistry();
		$this->backend = new RecordingBackend();
		$this->wpdb    = new WpdbLockSpy();
		$tasks->register( new RecordingTask( self::NAME ) );
		$guard                = new OverlapGuard( $clock, $logger, new LockRows( $this->wpdb ) );
		$stores               = new StoreFactory( $clock, new OptionRows( $this->wpdb ) );
		$randomizer           = new RecordingRandomizer( 42 );
		$terminal_transitions = new TerminalTransitions( $guard, $stores, $clock, $logger );
		$failure_lifecycle    = new FailureLifecycle(
			$this->backend,
			$clock,
			$randomizer,
			$logger,
			$terminal_transitions
		);
		$lock_windows         = new LockWindows( $clock );
		$lifecycle_deliveries = new LifecycleDeliveries(
			$tasks,
			$batches,
			$this->backend,
			$stores,
			$logger,
			$clock,
			$lock_windows,
			$terminal_transitions,
			$failure_lifecycle
		);

		$this->orchestrator = new Orchestrator(
			$tasks,
			$batches,
			$this->backend,
			$guard,
			$stores,
			$logger,
			$clock,
			$lock_windows,
			$terminal_transitions,
			$lifecycle_deliveries,
			$randomizer,
		);
	}

	// endregion.

	// region TESTS.

	/**
	 * Every policy dispatches against an open lock with its required backend uniqueness.
	 *
	 * @param   string $policy_value   Schedule overlap-policy value.
	 * @param   bool   $backend_unique Whether backend uniqueness is required.
	 *
	 * @return  void
	 */
	#[DataProvider( 'open_lock_policies' )]
	public function test_policy_dispatch_enqueues_against_an_open_lock(
		string $policy_value,
		bool $backend_unique
	): void {
		$policy = OverlapPolicy::from( $policy_value );
		$result = $this->orchestrator->dispatch_scheduled_task( self::NAME, self::ARGS, $policy, 23 );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		self::assertSame( $backend_unique, $this->backend->calls[0]['args']['unique'] ?? null );
		self::assertSame( 23, $this->backend->calls[0]['args']['priority'] ?? null );
	}

	/**
	 * Supplies every policy's backend-uniqueness mapping.
	 *
	 * @return  array<string, array{policy_value: string, backend_unique: bool}>
	 */
	public static function open_lock_policies(): array {
		return array(
			'allow'   => array(
				'policy_value'   => 'allow',
				'backend_unique' => false,
			),
			'skip'    => array(
				'policy_value'   => 'skip',
				'backend_unique' => true,
			),
			'replace' => array(
				'policy_value'   => 'replace',
				'backend_unique' => false,
			),
		);
	}

	/**
	 * The accepted callback runs after backend acceptance and before history filters or started hooks.
	 *
	 * @return  void
	 */
	public function test_accepted_callback_runs_before_history_and_started_hooks(): void {
		$called = false;

		$result = $this->orchestrator->dispatch_scheduled_task(
			self::NAME,
			self::ARGS,
			OverlapPolicy::Allow,
			10,
			function () use ( &$called ): void {
				$called  = true;
				$history = $this->option( 'a8csp_bgte_history_' . self::NAME );
				self::assertNull( $history );
				self::assertSame( array(), $GLOBALS['a8csp_bgte_test_fired_actions'] ?? null );
			}
		);

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $called );
		$actions = $GLOBALS['a8csp_bgte_test_fired_actions'] ?? null;
		self::assertIsArray( $actions );
		self::assertSame(
			array(
				'a8csp/background_tasks/started/' . self::NAME,
				'a8csp/background_tasks/started',
			),
			\array_column( $actions, 'hook_name' )
		);
	}

	/**
	 * Allow salts the fence identity while retaining the original arguments for task execution.
	 *
	 * @return  void
	 */
	public function test_allow_dispatch_does_not_contend_with_a_held_shared_identity(): void {
		$this->seed_held_lock();

		$result = $this->orchestrator->dispatch_scheduled_task(
			self::NAME,
			self::ARGS,
			OverlapPolicy::Allow,
			10
		);

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		self::assertSame( 'run-incumbent', $this->lock_owner( self::ARGS_HASH ) );

		$run = $this->option( 'a8csp_bgte_run_' . self::NAME . '_' . self::RUN_ID );
		self::assertIsArray( $run );
		self::assertSame( self::ARGS, $run['start_args'] ?? null );
		self::assertSame( array( self::ARGS ), $run['queue'] ?? null );
		$salted_hash = $run['args_hash'] ?? null;
		self::assertIsString( $salted_hash );
		self::assertNotSame( self::ARGS_HASH, $salted_hash );
		self::assertSame( self::RUN_ID, $this->lock_owner( $salted_hash ) );
	}

	/**
	 * A forced Allow run-id collision reaches the duplicate per-run identity failure.
	 *
	 * @return  void
	 */
	public function test_allow_dispatch_reports_a_forced_run_id_collision(): void {
		$first = $this->orchestrator->dispatch_scheduled_task(
			self::NAME,
			self::ARGS,
			OverlapPolicy::Allow,
			10
		);
		self::assertInstanceOf( Success::class, $first );
		$run = $this->option( 'a8csp_bgte_run_' . self::NAME . '_' . self::RUN_ID );
		self::assertIsArray( $run );
		$salted_hash = $run['args_hash'] ?? null;
		self::assertIsString( $salted_hash );
		$raw = \maybe_serialize(
			array(
				'run_id'       => 'collision-rival',
				'claimed_at'   => self::NOW,
				'heartbeat_at' => self::NOW,
			)
		);
		self::assertIsString( $raw );
		$this->wpdb->put( 'a8csp_bgte_lock_' . self::NAME . '_' . $salted_hash, $raw );

		$collision = $this->orchestrator->dispatch_scheduled_task(
			self::NAME,
			self::ARGS,
			OverlapPolicy::Allow,
			10
		);

		self::assertInstanceOf( Failure::class, $collision );
		$error = $collision->error;
		self::assertInstanceOf( EngineError::class, $error );
		self::assertStringContainsString( 'duplicate per-run overlap identity', $error->message );
		self::assertCount( 1, $this->backend->calls );
	}

	/**
	 * Skip returns a typed benign outcome and leaves the incumbent untouched.
	 *
	 * @return  void
	 */
	public function test_skip_dispatch_returns_a_typed_held_outcome(): void {
		$this->seed_held_lock();
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );
		unset( $options[ 'a8csp_bgte_latest_' . self::NAME ] );
		$GLOBALS['a8csp_bgte_test_options'] = $options;

		$accepted = false;
		$result   = $this->orchestrator->dispatch_scheduled_task(
			self::NAME,
			self::ARGS,
			OverlapPolicy::Skip,
			10,
			static function () use ( &$accepted ): void {
				$accepted = true;
			}
		);

		self::assertInstanceOf( Success::class, $result );
		self::assertInstanceOf( TaskDispatchSkipped::class, $result->value );
		self::assertSame( 'run-incumbent', $result->value->running_run_id );
		self::assertSame( array(), $this->backend->calls );
		self::assertFalse( $accepted );
		self::assertSame( 'run-incumbent', $this->lock_owner( self::ARGS_HASH ) );
		self::assertNull( $this->option( 'a8csp_bgte_run_' . self::NAME . '_' . self::RUN_ID ) );
	}

	/**
	 * Skip returns a failure when contention cannot be tied to an authoritative lock owner.
	 *
	 * @return  void
	 */
	public function test_skip_dispatch_does_not_consume_an_unconfirmed_held_outcome(): void {
		$this->wpdb->script_result( 'insert', false );

		$result = $this->orchestrator->dispatch_scheduled_task(
			self::NAME,
			self::ARGS,
			OverlapPolicy::Skip,
			10
		);

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertStringContainsString( 'could not confirm the owner', $result->error->message );
		self::assertSame( array(), $this->backend->calls );
		self::assertNull( $this->option( 'a8csp_bgte_run_' . self::NAME . '_' . self::RUN_ID ) );
	}

	/**
	 * Replace takes the held shared-identity lock before dispatching the replacement run.
	 *
	 * @return  void
	 */
	public function test_replace_dispatch_takes_over_a_held_lock(): void {
		$this->seed_held_lock();

		$result = $this->orchestrator->dispatch_scheduled_task(
			self::NAME,
			self::ARGS,
			OverlapPolicy::Replace,
			10
		);

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		self::assertSame( self::RUN_ID, $this->lock_owner( self::ARGS_HASH ) );
		self::assertSame( 'enqueue_async', $this->backend->calls[0]['verb'] ?? null );
	}

	/**
	 * A lost Replace CAS removes provisional state and leaves the new winner untouched.
	 *
	 * @return  void
	 */
	public function test_replace_dispatch_compensates_when_lock_ownership_changes_during_takeover(): void {
		$this->seed_held_lock();
		$this->wpdb->before_next(
			'update',
			function ( WpdbLockSpy $wpdb ): void {
				$raw = \maybe_serialize(
					array(
						'run_id'       => 'run-rival',
						'claimed_at'   => self::NOW,
						'heartbeat_at' => self::NOW,
					)
				);
				self::assertIsString( $raw );
				$wpdb->put( 'a8csp_bgte_lock_' . self::NAME . '_' . self::ARGS_HASH, $raw );
			}
		);

		$result = $this->orchestrator->dispatch_scheduled_task(
			self::NAME,
			self::ARGS,
			OverlapPolicy::Replace,
			10
		);

		self::assertInstanceOf( Failure::class, $result );
		self::assertSame( 'run-rival', $this->lock_owner( self::ARGS_HASH ) );
		self::assertNull( $this->option( 'a8csp_bgte_run_' . self::NAME . '_' . self::RUN_ID ) );
		self::assertSame( array(), $this->backend->calls );
	}

	/**
	 * A scheduling failure after Replace takeover leaves no replacement lock or run state.
	 *
	 * @return  void
	 */
	public function test_replace_dispatch_releases_takeover_when_scheduling_fails(): void {
		$this->seed_held_lock();
		$failure                                 = new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'Restore the scheduler before dispatching the replacement.'
			)
		);
		$this->backend->results['enqueue_async'] = $failure;

		$accepted = false;
		$result   = $this->orchestrator->dispatch_scheduled_task(
			self::NAME,
			self::ARGS,
			OverlapPolicy::Replace,
			10,
			static function () use ( &$accepted ): void {
				$accepted = true;
			}
		);

		self::assertSame( $failure, $result );
		self::assertNull( $this->lock_owner( self::ARGS_HASH ) );
		self::assertNull( $this->option( 'a8csp_bgte_run_' . self::NAME . '_' . self::RUN_ID ) );
		self::assertFalse( $accepted );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Stores a fresh incumbent under the ordinary argument identity.
	 *
	 * @return  void
	 */
	private function seed_held_lock(): void {
		$raw = \maybe_serialize(
			array(
				'run_id'       => 'run-incumbent',
				'claimed_at'   => self::NOW,
				'heartbeat_at' => self::NOW,
			)
		);
		self::assertIsString( $raw );
		$this->wpdb->put( 'a8csp_bgte_lock_' . self::NAME . '_' . self::ARGS_HASH, $raw );

		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );
		$options[ 'a8csp_bgte_latest_' . self::NAME ] = array(
			'all'     => 'run-incumbent',
			'by_hash' => array( self::ARGS_HASH => 'run-incumbent' ),
		);
		$GLOBALS['a8csp_bgte_test_options']           = $options;
	}

	/**
	 * Returns one lock owner from the raw options-table seam.
	 *
	 * @param   string $args_hash Lock argument identity.
	 *
	 * @return  string|null
	 */
	private function lock_owner( string $args_hash ): ?string {
		$raw = $this->wpdb->rows[ 'a8csp_bgte_lock_' . self::NAME . '_' . $args_hash ] ?? null;
		if ( ! \is_string( $raw ) ) {
			return null;
		}

		$lock = \maybe_unserialize( $raw );

		return \is_array( $lock ) && \is_string( $lock['run_id'] ?? null )
			? $lock['run_id']
			: null;
	}

	/**
	 * Returns one in-memory option value.
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

	// endregion.
}

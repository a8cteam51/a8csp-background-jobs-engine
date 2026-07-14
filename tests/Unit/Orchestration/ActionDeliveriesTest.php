<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Orchestration;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\ActionDeliveries;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\FailureLifecycle;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LockRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OptionRows;
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
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\TerminalTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingRandomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the observable single-task delivery lifecycle across storage, hooks, locks, and logs.
 *
 */
#[CoversClass( ActionDeliveries::class )]
#[UsesClass( EngineError::class )]
#[UsesClass( FailedRunStore::class )]
#[UsesClass( LatestRunPointer::class )]
#[UsesClass( Dispatcher::class )]
#[UsesClass( LockRows::class )]
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
final class ActionDeliveriesTest extends TestCase {
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
	private ActionDeliveries $lifecycle_deliveries;
	private RecordingLogger $logger;
	private RecordingRandomizer $randomizer;
	private RecordingTask $task;
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

		require_once \dirname( __DIR__ ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-hook-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-lock-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-time-constant-stubs.php';
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
		$this->wpdb           = new WpdbLockSpy();
		$batches              = new BatchRegistry();
		$guard                = new OverlapGuard( $this->clock, $this->logger, new LockRows( $this->wpdb ) );
		$stores               = new StoreFactory( $this->clock, new OptionRows( $this->wpdb ) );
		$lock_windows         = new LockWindows( $this->clock );
		$terminal_transitions = new TerminalTransitions( $guard, $stores, $this->clock, $this->logger );
		$failure_lifecycle    = new FailureLifecycle(
			$this->backend,
			$this->clock,
			$this->randomizer,
			$this->logger,
			$terminal_transitions
		);

		$this->lifecycle_deliveries = new ActionDeliveries(
			$this->registry,
			$batches,
			$this->backend,
			$stores,
			$this->logger,
			$this->clock,
			$lock_windows,
			$terminal_transitions,
			$failure_lifecycle,
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
			$terminal_transitions,
		);
	}

	// endregion.

	// region TESTS.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Signatures and providers carry test parameter types.

	/**
	 * Hook registration exposes every internal lifecycle action through the delivery registrar.
	 *
	 * @return  void
	 */
	public function test_register_hooks_wires_the_internal_lifecycle_actions(): void {
		$this->lifecycle_deliveries->register_hooks();

		self::assertSame(
			array(
				array(
					'hook_name'     => 'a8csp/background_tasks/start',
					'callback'      => array( $this->lifecycle_deliveries, 'handle_start_action' ),
					'priority'      => 10,
					'accepted_args' => 3,
				),
				array(
					'hook_name'     => 'a8csp/background_tasks/continue',
					'callback'      => array( $this->lifecycle_deliveries, 'handle_continue_action' ),
					'priority'      => 10,
					'accepted_args' => 3,
				),
				array(
					'hook_name'     => 'a8csp/background_tasks/run',
					'callback'      => array( $this->lifecycle_deliveries, 'handle_run_action' ),
					'priority'      => 10,
					'accepted_args' => 4,
				),
				array(
					'hook_name'     => 'a8csp/background_tasks/cleanup',
					'callback'      => array( $this->lifecycle_deliveries, 'handle_cleanup_action' ),
					'priority'      => 10,
					'accepted_args' => 3,
				),
			),
			$this->action_registrations()
		);
	}

	/**
	 * Run handling refreshes both heartbeats before task execution and completes in terminal order.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_executes_and_completes_the_task(): void {
		$this->prepare_run_action();
		$observed_lock = null;
		$observed_run  = null;

		$this->task->on_handle = function ( array $args ) use ( &$observed_lock, &$observed_run ): void {
			self::assertSame( self::ARGS, $args );
			$observed_lock = $this->lock();
			$observed_run  = $this->option( $this->run_option_name() );
		};

		$this->lifecycle_deliveries->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertIsArray( $observed_lock );
		self::assertSame( self::NOW + 90, $observed_lock['heartbeat_at'] );
		self::assertIsArray( $observed_run );
		self::assertSame( 'running', $observed_run['status'] );
		self::assertSame( self::NOW + 90, $observed_run['heartbeat_at'] );
		self::assertArrayNotHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
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
				'task:handle',
				'lock:update',
				'run:completed',
				'hook:completed/' . self::NAME,
				'hook:completed',
				'lock:delete',
				'run:delete',
				'history',
			),
			$this->lifecycle_labels()
		);
		$this->assert_terminal_history();
	}

	/**
	 * An unregistered task action fails its live run instead of orphaning active state.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_terminalizes_a_live_unregistered_task(): void {
		$this->prepare_run_action();
		$action_seq           = $this->action_seq();
		$tasks                = new TaskRegistry();
		$batches              = new BatchRegistry();
		$guard                = new OverlapGuard( $this->clock, $this->logger, new LockRows( $this->wpdb ) );
		$stores               = new StoreFactory( $this->clock, new OptionRows( $this->wpdb ) );
		$lock_windows         = new LockWindows( $this->clock );
		$terminal_transitions = new TerminalTransitions( $guard, $stores, $this->clock, $this->logger );
		$failure_lifecycle    = new FailureLifecycle(
			$this->backend,
			$this->clock,
			$this->randomizer,
			$this->logger,
			$terminal_transitions
		);
		$lifecycle_deliveries = new ActionDeliveries(
			$tasks,
			$batches,
			$this->backend,
			$stores,
			$this->logger,
			$this->clock,
			$lock_windows,
			$terminal_transitions,
			$failure_lifecycle,
		);
		$lifecycle_deliveries->handle_run_action( self::NAME, self::RUN_ID, $action_seq );

		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		$failed_runs = $this->option( 'a8csp_bgte_failed_' . self::NAME );
		self::assertIsArray( $failed_runs );
		$failed_run = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		$stored_error = $failed_run['error'] ?? null;
		self::assertIsArray( $stored_error );
		self::assertSame(
			'Task name "email-digest" is no longer registered unambiguously for run "00000000001700000000-0000000000000000042"; re-register exactly one task under that name or purge the run.',
			$stored_error['message'] ?? null
		);
		self::assertSame( 1, $failed_run['attempts'] ?? null );
		self::assertSame(
			array(
				'a8csp/background_tasks/failed/' . self::NAME,
				'a8csp/background_tasks/failed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Task run action references an unregistered task; register the task before dispatching its run action.',
					'context' => array(
						'task_name' => self::NAME,
						'run_id'    => self::RUN_ID,
					),
				),
			),
			$this->logger->records
		);
	}

	/**
	 * Ownership loss during task work supersedes the incumbent without touching the replacement.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_after_task_work_loses_ownership(): void {
		$this->prepare_run_action();
		$observed_state        = null;
		$this->task->on_handle = function ( array $args ) use ( &$observed_state ): void {
			$observed_state = $this->option( $this->run_option_name() );
			( new LatestRunPointer( self::NAME ) )->record( 'run-newer', self::ARGS_HASH );
			$this->replace_lock_owner( 'run-newer', self::NOW + 90 );
		};

		$this->lifecycle_deliveries->handle_run_action( self::NAME, self::RUN_ID, $this->action_seq() );

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertIsArray( $observed_state );
		self::assertSame( 'running', $observed_state['status'] ?? null );
		self::assertSame( self::NOW + 90, $observed_state['heartbeat_at'] ?? null );
		self::assertSame( array(), $this->backend->calls );
		$this->assert_post_callback_superseded_task();
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
	 * Asserts a post-callback fence loss terminalizes only the incumbent as Superseded.
	 *
	 * @return  void
	 */
	private function assert_post_callback_superseded_task(): void {
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
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
			\array_slice( $this->fired_actions(), -2 )
		);
		$this->assert_terminal_history();
	}

	/**
	 * Asserts that the existing completed buffer records the terminal exit.
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

	// endregion.
}

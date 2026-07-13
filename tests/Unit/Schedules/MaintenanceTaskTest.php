<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Schedules;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\FailureLifecycle;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LockRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Orchestrator;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\TerminalTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\MaintenanceTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingRandomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins periodic reconciliation of abandoned lock and run state.
 *
 */
#[CoversClass( MaintenanceTask::class )]
#[UsesClass( Orchestrator::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( LockRows::class )]
#[UsesClass( StoreFactory::class )]
final class MaintenanceTaskTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const ARGS      = array( 'site_id' => 7 );
	private const ARGS_HASH = 'd3e2a7f3f4041a96ec4e9d3de1622dea7c050a65d9ee0b77a49a76848fdd9737';
	private const NAME      = 'crashed-task';
	private const NOW       = 1_700_000_000;
	private const RUN_ID    = '00000000001700000000-0000000000000000042';

	private FixedClock $clock;
	private BatchRegistry $batches;
	private RecordingLogger $logger;
	private MaintenanceTask $maintenance;
	private Orchestrator $orchestrator;
	private WpdbLockSpy $wpdb;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress seams before maintenance classes are instantiated.
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
	 * Constructs one maintenance task over the real orchestration stores and lock guard.
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
		$GLOBALS['a8csp_bgte_test_lifecycle_events']      = array();
		$GLOBALS['a8csp_bgte_test_blog_id']               = 1;
		$GLOBALS['a8csp_bgte_test_cache']                 = array();
		$GLOBALS['a8csp_bgte_test_cache_calls']           = array();
		unset( $GLOBALS['a8csp_bgte_test_before_add_option'] );

		$this->clock   = new FixedClock( self::NOW );
		$this->batches = new BatchRegistry();
		$this->logger  = new RecordingLogger();
		$this->wpdb    = new WpdbLockSpy();
		$tasks         = new TaskRegistry();
		$tasks->register( new RecordingTask( self::NAME ) );
		$backend              = new RecordingBackend();
		$guard                = new OverlapGuard( $this->clock, $this->logger, new LockRows( $this->wpdb ) );
		$stores               = new StoreFactory( $this->clock, new OptionRows( $this->wpdb ) );
		$randomizer           = new RecordingRandomizer( 42 );
		$terminal_transitions = new TerminalTransitions( $guard, $stores, $this->clock, $this->logger );
		$failure_lifecycle    = new FailureLifecycle(
			$backend,
			$this->clock,
			$randomizer,
			$this->logger,
			$terminal_transitions
		);
		$this->orchestrator   = new Orchestrator(
			$tasks,
			$this->batches,
			$backend,
			$guard,
			$stores,
			$this->logger,
			$this->clock,
			new LockWindows( $this->clock ),
			$terminal_transitions,
			$failure_lifecycle,
			$randomizer,
		);
		$this->maintenance    = new MaintenanceTask(
			$this->wpdb,
			$this->orchestrator,
			$guard,
			$this->logger
		);
	}

	// endregion.

	// region TESTS.

	/**
	 * The maintenance task exposes one stable engine-owned task name.
	 *
	 * @return  void
	 */
	public function test_task_name_is_engine_reserved(): void {
		self::assertSame( 'a8csp-bgte-maintenance', $this->maintenance->get_name() );
	}

	/**
	 * A stale lock whose owning run option is gone is deleted and logged.
	 *
	 * @return  void
	 */
	public function test_sweep_deletes_a_stale_orphaned_lock(): void {
		$lock_name = 'a8csp_bgte_lock_orphan-task_' . \str_repeat( 'a', 64 );
		$this->put_lock( $lock_name, self::RUN_ID, self::NOW - 901 );

		$this->maintenance->handle( array() );

		self::assertArrayNotHasKey( $lock_name, $this->wpdb->rows );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'orphan-task', $this->logger->records[0]['context']['name'] ?? null );
		self::assertSame( self::RUN_ID, $this->logger->records[0]['context']['run_id'] ?? null );
	}

	/**
	 * A fresh orphan lock survives the claim-to-run-option creation window.
	 *
	 * @return  void
	 */
	public function test_sweep_leaves_a_fresh_orphaned_lock_untouched(): void {
		$lock_name = 'a8csp_bgte_lock_orphan-task_' . \str_repeat( 'a', 64 );
		$this->put_lock( $lock_name, self::RUN_ID, self::NOW );

		$this->maintenance->handle( array() );

		self::assertArrayHasKey( $lock_name, $this->wpdb->rows );
		self::assertSame( array(), $this->logger->records );
	}

	/**
	 * A running run with a stale owned lock follows the crash-failure terminal path.
	 *
	 * @return  void
	 */
	public function test_sweep_terminalizes_a_running_run_with_a_stale_lock(): void {
		$this->create_running_run();
		$this->clock->timestamp = self::NOW + 901;

		$this->maintenance->handle( array() );

		$this->assert_crashed_run_terminalized();
	}

	/**
	 * A running run with no owned lock follows the same crash-failure terminal path.
	 *
	 * @return  void
	 */
	public function test_sweep_terminalizes_a_running_run_with_a_missing_lock(): void {
		$this->create_running_run();
		unset( $this->wpdb->rows[ $this->lock_option_name() ] );

		$this->maintenance->handle( array() );

		$this->assert_crashed_run_terminalized();
	}

	/**
	 * A lock read failure cannot be mistaken for authoritative absence during crash reconciliation.
	 *
	 * @return  void
	 */
	public function test_sweep_leaves_a_running_run_untouched_when_lock_read_fails(): void {
		$this->create_running_run();
		unset( $this->wpdb->rows[ $this->lock_option_name() ] );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient read failure';
			}
		);

		$this->maintenance->handle( array() );

		self::assertArrayHasKey( $this->run_option_name(), $this->options() );
		self::assertArrayNotHasKey( 'a8csp_bgte_failed_' . self::NAME, $this->options() );
		self::assertSame( array(), $this->logger->records );
	}

	/**
	 * A stale running run fenced by a replacement records supersession without failure state.
	 *
	 * @return  void
	 */
	public function test_sweep_supersedes_a_stale_running_run_whose_lock_has_transferred(): void {
		$this->create_running_run();
		$this->clock->timestamp = self::NOW + 901;
		$replacement_run_id     = '00000000001700000001-0000000000000000043';
		$this->put_lock( $this->lock_option_name(), $replacement_run_id, $this->clock->timestamp );

		$this->maintenance->handle( array() );

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		self::assertArrayNotHasKey( 'a8csp_bgte_failed_' . self::NAME, $options );
		self::assertSame(
			array(
				'a8csp/background_tasks/superseded/' . self::NAME,
				'a8csp/background_tasks/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$history = $options[ 'a8csp_bgte_history_' . self::NAME ] ?? null;
		self::assertIsArray( $history );
		self::assertSame( array( self::RUN_ID ), $history['completed'] ?? null );
		$lock = $this->wpdb->rows[ $this->lock_option_name() ] ?? null;
		self::assertIsString( $lock );
		$lock_row = \maybe_unserialize( $lock );
		self::assertIsArray( $lock_row );
		self::assertSame( $replacement_run_id, $lock_row['run_id'] ?? null );
	}

	/**
	 * Transfer evidence classifies the old run before its stale orphaned replacement lock is reclaimed.
	 *
	 * @return  void
	 */
	public function test_sweep_preserves_supersession_when_the_transferred_lock_is_also_orphaned(): void {
		$this->create_running_run();
		$this->clock->timestamp = self::NOW + 901;
		$replacement_run_id     = '00000000001700000001-0000000000000000043';
		$this->put_lock( $this->lock_option_name(), $replacement_run_id, self::NOW );

		$this->maintenance->handle( array() );

		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		self::assertArrayNotHasKey( 'a8csp_bgte_failed_' . self::NAME, $options );
		self::assertArrayNotHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertSame(
			array(
				'a8csp/background_tasks/superseded/' . self::NAME,
				'a8csp/background_tasks/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$history = $options[ 'a8csp_bgte_history_' . self::NAME ] ?? null;
		self::assertIsArray( $history );
		self::assertSame( array( self::RUN_ID ), $history['completed'] ?? null );
	}

	/**
	 * A fresh running run survives a transferred lock until its own worker reaches a fence.
	 *
	 * @return  void
	 */
	public function test_sweep_leaves_a_fresh_running_run_untouched_when_lock_has_transferred(): void {
		$this->create_running_run();
		$replacement_run_id = '00000000001700000001-0000000000000000043';
		$this->put_lock( $this->lock_option_name(), $replacement_run_id, self::NOW );

		$this->maintenance->handle( array() );

		self::assertArrayHasKey( $this->run_option_name(), $this->options() );
		self::assertArrayNotHasKey( 'a8csp_bgte_failed_' . self::NAME, $this->options() );
		self::assertSame( array(), $this->fired_actions() );
		self::assertSame( array(), $this->logger->records );
	}

	/**
	 * A fresh displaced run protects takeover evidence across unequal run-specific stale windows.
	 *
	 * @return  void
	 */
	public function test_sweep_preserves_transferred_lock_until_the_displaced_run_is_stale(): void {
		$this->create_running_run();
		$replacement_run_id = '00000000001700000001-0000000000000000043';
		$this->put_lock( $this->lock_option_name(), $replacement_run_id, self::NOW );
		$GLOBALS['a8csp_bgte_test_filter_values'] = array(
			'a8csp/background_tasks/continue_delay' => static function (
				int $delay,
				string $name,
				string $run_id
			): int {
				return self::RUN_ID === $run_id ? 1_000 : $delay;
			},
		);

		$this->clock->timestamp = self::NOW + 901;

		$this->maintenance->handle( array() );

		self::assertArrayHasKey( $this->run_option_name(), $this->options() );
		self::assertArrayHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertSame( array(), $this->fired_actions() );

		$this->clock->timestamp = self::NOW + 2_001;
		$this->maintenance->handle( array() );

		self::assertArrayNotHasKey( $this->run_option_name(), $this->options() );
		self::assertArrayNotHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertArrayNotHasKey( 'a8csp_bgte_failed_' . self::NAME, $this->options() );
		self::assertSame(
			array(
				'a8csp/background_tasks/superseded/' . self::NAME,
				'a8csp/background_tasks/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
	}

	/**
	 * A live heartbeat written after the stale transfer gate makes the sweep lose its exact run fence.
	 *
	 * @return  void
	 */
	public function test_sweep_loses_when_a_transferred_run_refreshes_after_the_stale_gate(): void {
		$this->create_running_run();
		$this->clock->timestamp = self::NOW + 901;
		$replacement_run_id     = '00000000001700000901-0000000000000000043';
		$this->put_lock( $this->lock_option_name(), $replacement_run_id, $this->clock->timestamp );
		$this->wpdb->before_next(
			'update',
			function (): void {
				$options = $this->options();
				$state   = $options[ $this->run_option_name() ] ?? null;
				self::assertIsArray( $state );
				$state['heartbeat_at']               = $this->clock->timestamp;
				$options[ $this->run_option_name() ] = $state;
				$GLOBALS['a8csp_bgte_test_options']  = $options;
			}
		);

		$this->maintenance->handle( array() );

		$options = $this->options();
		$state   = $options[ $this->run_option_name() ] ?? null;
		self::assertIsArray( $state );
		self::assertSame( $this->clock->timestamp, $state['heartbeat_at'] ?? null );
		self::assertArrayNotHasKey( 'a8csp_bgte_failed_' . self::NAME, $options );
		$history = $options[ 'a8csp_bgte_history_' . self::NAME ] ?? null;
		self::assertIsArray( $history );
		self::assertSame( array(), $history['completed'] ?? null );
		self::assertSame( array(), $this->fired_actions() );
	}

	/**
	 * A crashed batch follows its registered failure callback before common terminal cleanup.
	 *
	 * @return  void
	 */
	public function test_sweep_terminalizes_a_running_batch_through_batch_failure_machinery(): void {
		$name  = 'crashed-batch';
		$batch = new RecordingBatch( $name );
		$this->batches->register( $batch );
		$result = $this->orchestrator->start_batch( $name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );

		$lock_name = 'a8csp_bgte_lock_' . $name . '_' . self::ARGS_HASH;
		unset( $this->wpdb->rows[ $lock_name ] );
		$this->logger->records                    = array();
		$GLOBALS['a8csp_bgte_test_fired_actions'] = array();

		$this->maintenance->handle( array() );

		self::assertCount( 1, $batch->failure_calls );
		self::assertSame( self::RUN_ID, $batch->failure_calls[0]['run_id'] ?? null );
		self::assertSame( self::ARGS, $batch->failure_calls[0]['start_args'] ?? null );
		self::assertStringContainsString(
			'maintenance crash-reclaim path',
			$batch->failure_calls[0]['error']->message
		);
		$options = $this->options();
		self::assertArrayNotHasKey( 'a8csp_bgte_run_' . $name . '_' . self::RUN_ID, $options );
		self::assertArrayHasKey( 'a8csp_bgte_failed_' . $name, $options );
		self::assertSame(
			array(
				'a8csp/background_tasks/failed/' . $name,
				'a8csp/background_tasks/failed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
	}

	/**
	 * A schema-invalid lock row is deleted without trusting its serialized fields.
	 *
	 * @return  void
	 */
	public function test_sweep_deletes_a_schema_invalid_lock_row(): void {
		$lock_name = 'a8csp_bgte_lock_corrupt-task_' . \str_repeat( 'b', 64 );
		$this->wpdb->put( $lock_name, 'not-serialized' );

		$this->maintenance->handle( array() );

		self::assertArrayNotHasKey( $lock_name, $this->wpdb->rows );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'corrupt-task', $this->logger->records[0]['context']['name'] ?? null );
		self::assertNull( $this->logger->records[0]['context']['run_id'] ?? null );
	}

	/**
	 * A corrupt run row cannot preserve a stale lock that names it as owner.
	 *
	 * @return  void
	 */
	public function test_sweep_reclaims_a_stale_lock_whose_run_row_is_corrupt(): void {
		$name                               = 'corrupt-task';
		$args_hash                          = \str_repeat( 'b', 64 );
		$run_name                           = 'a8csp_bgte_run_' . $name . '_' . self::RUN_ID;
		$options                            = $this->options();
		$options[ $run_name ]               = 'corrupt-run';
		$GLOBALS['a8csp_bgte_test_options'] = $options;
		$lock_name                          = 'a8csp_bgte_lock_' . $name . '_' . $args_hash;
		$this->put_lock( $lock_name, self::RUN_ID, self::NOW - 901 );

		$this->maintenance->handle( array() );

		self::assertArrayNotHasKey( $run_name, $this->options() );
		self::assertArrayNotHasKey( $lock_name, $this->wpdb->rows );
		self::assertSame(
			array( 'warning', 'warning' ),
			\array_column( $this->logger->records, 'level' )
		);
	}

	/**
	 * A valid run owns only the lock whose hash matches its persisted fencing identity.
	 *
	 * @return  void
	 */
	public function test_sweep_reclaims_a_stale_lock_when_the_valid_run_has_a_different_fencing_hash(): void {
		$this->create_running_run();
		$mismatched_hash = \str_repeat( 'f', 64 );
		$mismatched_lock = 'a8csp_bgte_lock_' . self::NAME . '_' . $mismatched_hash;
		$this->put_lock( $mismatched_lock, self::RUN_ID, self::NOW - 901 );

		$this->maintenance->handle( array() );

		self::assertArrayNotHasKey( $mismatched_lock, $this->wpdb->rows );
		self::assertArrayHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertArrayHasKey( $this->run_option_name(), $this->options() );
	}

	/**
	 * A corrupt run row without any lock is exact-deleted by the run-prefix pass.
	 *
	 * @return  void
	 */
	public function test_sweep_exact_deletes_an_unpaired_corrupt_run_row(): void {
		$run_name                           = 'a8csp_bgte_run_corrupt-task_' . self::RUN_ID;
		$options                            = $this->options();
		$options[ $run_name ]               = 'corrupt-run';
		$GLOBALS['a8csp_bgte_test_options'] = $options;

		$this->maintenance->handle( array() );

		self::assertArrayNotHasKey( $run_name, $this->options() );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
	}

	/**
	 * Task crash reconciliation saturates the failed-attempt count at PHP_INT_MAX.
	 *
	 * @return  void
	 */
	public function test_sweep_saturates_task_failure_attempts_at_php_int_max(): void {
		$this->create_running_run();
		$this->set_run_chunk_retries( $this->run_option_name(), \PHP_INT_MAX );
		unset( $this->wpdb->rows[ $this->lock_option_name() ] );

		$this->maintenance->handle( array() );

		$failed = $this->options()[ 'a8csp_bgte_failed_' . self::NAME ] ?? null;
		self::assertIsArray( $failed );
		$failure = $failed[0] ?? null;
		self::assertIsArray( $failure );
		self::assertSame( \PHP_INT_MAX, $failure['attempts'] ?? null );
	}

	/**
	 * Batch crash reconciliation saturates the failed-attempt count at PHP_INT_MAX.
	 *
	 * @return  void
	 */
	public function test_sweep_saturates_batch_failure_attempts_at_php_int_max(): void {
		$name = 'crashed-batch';
		$this->batches->register( new RecordingBatch( $name ) );
		$result = $this->orchestrator->start_batch( $name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		$run_name = 'a8csp_bgte_run_' . $name . '_' . self::RUN_ID;
		$this->set_run_chunk_retries( $run_name, \PHP_INT_MAX );
		unset( $this->wpdb->rows[ 'a8csp_bgte_lock_' . $name . '_' . self::ARGS_HASH ] );

		$this->maintenance->handle( array() );

		$failed = $this->options()[ 'a8csp_bgte_failed_' . $name ] ?? null;
		self::assertIsArray( $failed );
		$failure = $failed[0] ?? null;
		self::assertIsArray( $failure );
		self::assertSame( \PHP_INT_MAX, $failure['attempts'] ?? null );
	}

	/**
	 * A sweep and live fence loss emit terminal hooks only from the raw-CAS winner.
	 *
	 * @return  void
	 */
	public function test_sweep_and_live_terminalization_emit_only_the_cas_winner_hooks(): void {
		$this->create_running_run();
		$this->clock->timestamp = self::NOW + 901;
		$replacement_run_id     = '00000000001700000901-0000000000000000043';
		$this->put_lock( $this->lock_option_name(), $replacement_run_id, $this->clock->timestamp );
		$this->wpdb->before_next(
			'update',
			function (): void {
				$this->maintenance->handle( array() );
			}
		);

		$this->orchestrator->handle_run_action( self::NAME, self::RUN_ID, 1 );

		self::assertSame(
			array(
				'a8csp/background_tasks/superseded/' . self::NAME,
				'a8csp/background_tasks/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertArrayNotHasKey( 'a8csp_bgte_failed_' . self::NAME, $this->options() );
		$history = $this->options()[ 'a8csp_bgte_history_' . self::NAME ] ?? null;
		self::assertIsArray( $history );
		self::assertSame( array( self::RUN_ID ), $history['completed'] ?? null );
		$lock = \maybe_unserialize( $this->wpdb->rows[ $this->lock_option_name() ] ?? '' );
		self::assertIsArray( $lock );
		self::assertSame( $replacement_run_id, $lock['run_id'] ?? null );
	}

	/**
	 * A terminal run beyond the sweep grace is deleted and appended to history without hooks.
	 *
	 * @return  void
	 */
	public function test_sweep_deletes_an_old_terminal_run_option(): void {
		$run_name                           = 'a8csp_bgte_run_terminal-task_' . self::RUN_ID;
		$options                            = $this->options();
		$options[ $run_name ]               = array(
			'status'        => 'completed',
			'start_args'    => self::ARGS,
			'args_hash'     => self::ARGS_HASH,
			'queue'         => array(),
			'chunk_retries' => 0,
			'action_seq'    => 1,
			'created_at'    => self::NOW - 7_201,
			'heartbeat_at'  => self::NOW - 3_601,
		);
		$GLOBALS['a8csp_bgte_test_options'] = $options;

		$this->maintenance->handle( array() );

		$options = $this->options();
		self::assertArrayNotHasKey( $run_name, $options );
		$history = $options['a8csp_bgte_history_terminal-task'] ?? null;
		self::assertIsArray( $history );
		self::assertSame(
			array( self::RUN_ID ),
			$history['completed'] ?? null
		);
		self::assertSame( array(), $this->fired_actions() );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
	}

	/**
	 * A failed terminal delete remains retryable without a false warning or duplicate history.
	 *
	 * @return  void
	 */
	public function test_sweep_does_not_report_or_record_a_failed_terminal_delete(): void {
		$run_name                                    = 'a8csp_bgte_run_terminal-task_' . self::RUN_ID;
		$options                                     = $this->options();
		$options[ $run_name ]                        = array(
			'status'        => 'completed',
			'start_args'    => self::ARGS,
			'args_hash'     => self::ARGS_HASH,
			'queue'         => array(),
			'chunk_retries' => 0,
			'action_seq'    => 1,
			'created_at'    => self::NOW - 7_201,
			'heartbeat_at'  => self::NOW - 3_601,
		);
		$options['a8csp_bgte_history_terminal-task'] = array(
			'started'   => array( 'existing-run' ),
			'completed' => array( 'existing-run' ),
			'by_hash'   => array(),
		);
		$GLOBALS['a8csp_bgte_test_options']          = $options;
		$this->wpdb->script_result( 'delete', false );

		$this->maintenance->handle( array() );

		$options = $this->options();
		self::assertArrayHasKey( $run_name, $options );
		$history = $options['a8csp_bgte_history_terminal-task'] ?? null;
		self::assertIsArray( $history );
		self::assertSame(
			array( 'existing-run' ),
			$history['completed'] ?? null
		);
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'error', $this->logger->records[0]['level'] ?? null );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Creates one ordinary running task through the orchestrator.
	 *
	 * @return  void
	 */
	private function create_running_run(): void {
		$result = $this->orchestrator->enqueue( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );

		$this->logger->records                    = array();
		$GLOBALS['a8csp_bgte_test_fired_actions'] = array();
	}

	/**
	 * Asserts the complete crash-reclaim terminal effect.
	 *
	 * @param   bool $lock_survives Whether a replacement lock remains after terminalization.
	 *
	 * @return  void
	 */
	private function assert_crashed_run_terminalized( bool $lock_survives = false ): void {
		$options = $this->options();
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
		if ( $lock_survives ) {
			self::assertArrayHasKey( $this->lock_option_name(), $this->wpdb->rows );
		} else {
			self::assertArrayNotHasKey( $this->lock_option_name(), $this->wpdb->rows );
		}
		$failed = $options[ 'a8csp_bgte_failed_' . self::NAME ] ?? null;
		self::assertIsArray( $failed );
		self::assertCount( 1, $failed );
		$failed_entry = $failed[0] ?? null;
		self::assertIsArray( $failed_entry );
		$error = $failed_entry['error'] ?? null;
		self::assertIsArray( $error );
		$message = $error['message'] ?? null;
		self::assertIsString( $message );
		self::assertStringContainsString( 'maintenance crash-reclaim path', $message );
		self::assertNull( $error['class'] ?? null );
		$actions = $this->fired_actions();
		self::assertSame(
			array(
				'a8csp/background_tasks/failed/' . self::NAME,
				'a8csp/background_tasks/failed',
			),
			\array_column( $actions, 'hook_name' )
		);
		self::assertSame( self::RUN_ID, $actions[0]['args'][0] ?? null );
		self::assertSame( self::ARGS, $actions[0]['args'][1] ?? null );
		self::assertInstanceOf( EngineError::class, $actions[0]['args'][2] ?? null );
		self::assertSame(
			array( self::NAME, ...( $actions[0]['args'] ?? array() ) ),
			$actions[1]['args'] ?? null
		);
		$history = $options[ 'a8csp_bgte_history_' . self::NAME ] ?? null;
		self::assertIsArray( $history );
		self::assertSame(
			array( self::RUN_ID ),
			$history['completed'] ?? null
		);
	}

	/**
	 * Stores one raw valid lock row.
	 *
	 * @param   string $option_name Lock option name.
	 * @param   string $run_id     Owning run identifier.
	 * @param   int    $heartbeat  Latest heartbeat timestamp.
	 *
	 * @return  void
	 */
	private function put_lock( string $option_name, string $run_id, int $heartbeat ): void {
		$raw = \maybe_serialize(
			array(
				'run_id'       => $run_id,
				'claimed_at'   => $heartbeat,
				'heartbeat_at' => $heartbeat,
			)
		);
		self::assertIsString( $raw );
		$this->wpdb->put( $option_name, $raw );
	}

	/**
	 * Sets one persisted run's retry counter without changing any other field.
	 *
	 * @param   string $option_name    Run option name.
	 * @param   int    $chunk_retries  Retry count to persist.
	 *
	 * @return  void
	 */
	private function set_run_chunk_retries( string $option_name, int $chunk_retries ): void {
		$options = $this->options();
		$state   = $options[ $option_name ] ?? null;
		self::assertIsArray( $state );
		$state['chunk_retries']             = $chunk_retries;
		$options[ $option_name ]            = $state;
		$GLOBALS['a8csp_bgte_test_options'] = $options;
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
	 * Returns the deterministic lock option name.
	 *
	 * @return  string
	 */
	private function lock_option_name(): string {
		return 'a8csp_bgte_lock_' . self::NAME . '_' . self::ARGS_HASH;
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

	/**
	 * Returns the in-memory option store through its typed boundary.
	 *
	 * @return  array<array-key, mixed>
	 */
	private function options(): array {
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );

		return $options;
	}

	// endregion.
}

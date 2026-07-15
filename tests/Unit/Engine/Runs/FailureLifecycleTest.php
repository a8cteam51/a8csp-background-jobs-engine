<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\InvalidBatchChunkException;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\NonRetryableTaskException;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\Randomization\Randomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\Randomization\RandomizerInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\LatestRunPointer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\TerminalTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingErrorReason;
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
 * Pins retry adjudication, persistence, scheduling, and failure transitions.
 *
 */
#[CoversClass( FailureLifecycle::class )]
#[UsesClass( InvalidBatchChunkException::class )]
#[UsesClass( EngineError::class )]
#[UsesClass( RunFailure::class )]
#[UsesClass( FailedRunStore::class )]
#[UsesClass( LatestRunPointer::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
#[UsesClass( Dispatcher::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( Randomizer::class )]
#[UsesClass( RetryPolicy::class )]
#[UsesClass( RunHistory::class )]
#[UsesClass( RunState::class )]
#[UsesClass( RunStatus::class )]
#[UsesClass( RunStore::class )]
#[UsesClass( StoreFactory::class )]
#[UsesClass( TerminalTransitions::class )]
#[UsesClass( BatchRegistry::class )]
#[UsesClass( TaskRegistry::class )]
final class FailureLifecycleTest extends TestCase {
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
	private OptionRows $rows;
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
		require_once \dirname( __DIR__ ) . '/Backends/wp-json-encode-stub.php';
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
		$this->rows                 = new OptionRows( $this->wpdb );
		$batches                    = new BatchRegistry();
		$guard                      = new OverlapGuard( $this->clock, $this->logger, $this->rows );
		$stores                     = new StoreFactory( $this->clock, $this->rows );
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

	/**
	 * A throwing task that loses ownership supersedes before entering the retry ladder.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_when_throwing_task_loses_ownership(): void {
		$this->task->throwable = new \RuntimeException( 'Task exploded.' );
		$this->prepare_run_action();
		$this->task->on_handle = function ( array $args ): void {
			self::assertTrue( ( new LatestRunPointer( self::NAME, $this->rows ) )->record( 'run-newer', self::ARGS_HASH ) );
			$this->replace_lock_owner( 'run-newer', self::NOW + 90 );
		};

		$this->handle_failed_task_attempt();

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertSame( array(), $this->backend->calls );
		$this->assert_post_callback_superseded_task();
	}

	/**
	 * Ownership loss in the retry-policy filter supersedes before applying the terminal cap.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_before_retry_policy_cap_failure_after_ownership_loss(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Transient failure.' );
		$this->prepare_run_action();
		$this->set_filter_value(
			'a8csp_background_tasks/retry_policy/' . self::NAME,
			function ( RetryPolicy $policy ): RetryPolicy {
				self::assertTrue( ( new LatestRunPointer( self::NAME, $this->rows ) )->record( 'run-newer', self::ARGS_HASH ) );
				$this->replace_lock_owner( 'run-newer', self::NOW + 90 );

				return new RetryPolicy( max_attempts: 1 );
			}
		);
		$this->randomizer->value = 7;
		$this->randomizer->calls = array();

		$this->handle_failed_task_attempt();

		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->randomizer->calls );
		$this->assert_post_callback_superseded_task();
	}

	/**
	 * Ownership loss in retrying listeners supersedes before the retry action is scheduled.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_before_retry_schedule_after_retrying_listener_ownership_loss(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Transient failure.' );
		$this->prepare_run_action();
		$this->randomizer->value = 7;
		$this->randomizer->calls = array();
		$this->set_filter_value(
			'a8csp_background_tasks/retry_policy/' . self::NAME,
			function ( RetryPolicy $policy ): RetryPolicy {
				for ( $index = 0; 3 > $index; ++$index ) {
					$this->wpdb->before_next( 'select', static function ( WpdbLockSpy $lock_spy ): void {} );
				}
				$this->wpdb->before_next(
					'select',
					function ( WpdbLockSpy $lock_spy ): void {
						self::assertTrue( ( new LatestRunPointer( self::NAME, $this->rows ) )->record( 'run-newer', self::ARGS_HASH ) );
						$this->replace_lock_owner( 'run-newer', self::NOW + 90 );
					}
				);

				return $policy;
			}
		);

		$this->handle_failed_task_attempt();

		self::assertSame( array(), $this->backend->calls );
		self::assertSame(
			array(
				'a8csp_background_tasks/retrying/' . self::NAME,
				'a8csp_background_tasks/retrying',
				'a8csp_background_tasks/superseded/' . self::NAME,
				'a8csp_background_tasks/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_post_callback_superseded_task();
	}

	/**
	 * An ordinary throwable below the cap persists retry state and reschedules the same run.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_reschedules_an_ordinary_failure_below_the_cap(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->prepare_run_action();
		$this->randomizer->value = 17;
		$this->randomizer->calls = array();
		$scheduled_state         = null;
		$this->backend->before_next(
			'schedule_single',
			function () use ( &$scheduled_state ): void {
				$scheduled_state = $this->option( $this->run_option_name() );
			}
		);

		$this->handle_failed_task_attempt();

		$state = $this->option( $this->run_option_name() );
		self::assertIsArray( $state );
		self::assertSame( 'running', $state['status'] ?? null );
		self::assertFalse( $state['executing'] ?? null );
		self::assertSame( 1, $state['chunk_retries'] ?? null );
		self::assertSame( 2, $state['action_seq'] ?? null );
		self::assertSame( self::NOW + 107, $state['heartbeat_at'] ?? null );
		self::assertSame(
			array(
				'stage'    => 'run',
				'mode'     => 'single',
				'fire_at'  => self::NOW + 107,
				'unique'   => false,
				'priority' => 10,
			),
			$state['pending'] ?? null
		);
		self::assertSame( $state, $scheduled_state );
		self::assertSame( self::NOW + 107, $this->lock()['heartbeat_at'] ?? null );
		self::assertSame(
			array(
				array(
					'verb' => 'schedule_single',
					'args' => array(
						'hook'      => 'a8csp_background_tasks/run',
						'timestamp' => self::NOW + 107,
						'args'      => array( self::NAME, self::RUN_ID, 2 ),
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
					'min' => 0,
					'max' => 30,
				),
			),
			$this->randomizer->calls
		);
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_background_tasks/retrying/' . self::NAME,
					'args'      => array( self::RUN_ID, self::ARGS, 1, 17 ),
				),
				array(
					'hook_name' => 'a8csp_background_tasks/retrying',
					'args'      => array( self::NAME, self::RUN_ID, self::ARGS, 1, 17 ),
				),
			),
			$this->fired_actions()
		);
		self::assertNull( $this->option( 'a8csp_bgte_failed_' . self::NAME ) );
	}

	/**
	 * Seeded full jitter reproduces the exact delays of a [0, base_delay] draw.
	 *
	 * @return  void
	 */
	#[DataProvider( 'seeded_jitter_delays' )]
	public function test_retry_call_site_preserves_seeded_full_jitter_distribution( int $seed, int $expected_delay ): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->prepare_run_action();

		$randomizer = new class( $seed ) implements RandomizerInterface {
			private \Random\Randomizer $randomizer;

			/**
			 * Seeds one deterministic random source.
			 *
			 * @param   int $seed MT19937 seed.
			 */
			public function __construct( int $seed ) {
				$this->randomizer = new \Random\Randomizer( new \Random\Engine\Mt19937( $seed ) );
			}

			/**
			 * Draws one integer from the seeded source.
			 *
			 * @param   int $min Inclusive lower boundary.
			 * @param   int $max Inclusive upper boundary.
			 *
			 * @return  int
			 */
			#[\Override]
			public function int( int $min, int $max ): int {
				return $this->randomizer->getInt( $min, $max );
			}
		};

		$this->failure_lifecycle = new FailureLifecycle(
			$this->backend,
			$this->clock,
			$randomizer,
			$this->logger,
			$this->terminal_transitions
		);

		$this->handle_failed_task_attempt();

		self::assertSame( self::NOW + 90 + $expected_delay, $this->backend->calls[0]['args']['timestamp'] ?? null );
		self::assertSame( $expected_delay, $this->fired_actions()[0]['args'][3] ?? null );
	}

	/**
	 * Supplies byte-pinned MT19937 draws from the former in-policy jitter call.
	 *
	 * @return  array<string, array{seed: int, expected_delay: int}>
	 */
	public static function seeded_jitter_delays(): array {
		return array(
			'seed zero'      => array(
				'seed'           => 0,
				'expected_delay' => 18,
			),
			'seed one'       => array(
				'seed'           => 1,
				'expected_delay' => 10,
			),
			'seed forty-two' => array(
				'seed'           => 42,
				'expected_delay' => 19,
			),
			'seed phrase'    => array(
				'seed'           => 8_675_309,
				'expected_delay' => 11,
			),
		);
	}

	/**
	 * A two-attempt policy executes exactly twice and records the exhausted cap.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_stops_exactly_at_the_max_attempts_boundary(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->prepare_run_action();
		$this->randomizer->value = 5;
		$this->randomizer->calls = array();

		$this->handle_failed_task_attempt();
		$this->clock->timestamp = self::NOW + 95;
		$this->handle_failed_task_attempt();

		self::assertSame( array( self::ARGS, self::ARGS ), $this->task->calls );
		self::assertCount( 1, $this->backend->calls );
		self::assertSame( 'schedule_single', $this->backend->calls[0]['verb'] );
		self::assertSame(
			array(
				array(
					'min' => 0,
					'max' => 30,
				),
			),
			$this->randomizer->calls
		);
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		$failed_runs = $this->option( 'a8csp_bgte_failed_' . self::NAME );
		self::assertIsArray( $failed_runs );
		$failed_run = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		self::assertSame( 2, $failed_run['attempts'] ?? null );
		self::assertSame( self::NOW + 95, $failed_run['failed_at'] ?? null );
	}

	/**
	 * A name-specific RetryPolicy replacement controls the cap for that task.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_honors_the_name_specific_retry_policy_filter(): void {
		$contract_policy = new RetryPolicy( max_attempts: 3 );

		$this->task->retry_policy = $contract_policy;
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );

		$filter_args = null;
		$this->set_filter_value(
			'a8csp_background_tasks/retry_policy/' . self::NAME,
			static function ( RetryPolicy $policy ) use ( &$filter_args ): RetryPolicy {
				$filter_args = array(
					'arity' => \func_num_args(),
					'args'  => \func_get_args(),
				);

				return new RetryPolicy( max_attempts: 1 );
			}
		);
		$this->prepare_run_action();

		$this->handle_failed_task_attempt();

		self::assertSame(
			array(
				'arity' => 1,
				'args'  => array( $contract_policy ),
			),
			$filter_args
		);
		self::assertSame( array(), $this->backend->calls );
		$failed_runs = $this->option( 'a8csp_bgte_failed_' . self::NAME );
		self::assertIsArray( $failed_runs );
		$failed_run = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		self::assertSame( 1, $failed_run['attempts'] ?? null );
	}

	/**
	 * Failure adjudication resets both credited heartbeats before resolving retry policy.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_resets_callback_credit_before_retry_policy_resolution(): void {
		$this->task->max_runtime  = 1_200;
		$this->task->retry_policy = new RetryPolicy( max_attempts: 1 );
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$observed_lock            = null;
		$observed_run             = null;
		$this->set_filter_value(
			'a8csp_background_tasks/retry_policy/' . self::NAME,
			function ( RetryPolicy $policy ) use ( &$observed_lock, &$observed_run ): RetryPolicy {
				$observed_lock = $this->lock();
				$observed_run  = $this->option( $this->run_option_name() );

				return $policy;
			}
		);
		$this->prepare_run_action();

		$this->handle_failed_task_attempt();

		self::assertIsArray( $observed_lock );
		self::assertSame( self::NOW + 90, $observed_lock['heartbeat_at'] );
		self::assertIsArray( $observed_run );
		self::assertSame( self::NOW + 90, $observed_run['heartbeat_at'] ?? null );
		self::assertTrue( $observed_run['executing'] ?? null );
	}

	/**
	 * A foreign policy-filter return falls back to the contract policy and names the correction.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_falls_back_and_warns_for_a_foreign_retry_policy(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->set_filter_value( 'a8csp_background_tasks/retry_policy/' . self::NAME, 'invalid-policy' );
		$this->prepare_run_action();
		$this->randomizer->value = 7;
		$this->randomizer->calls = array();

		$this->handle_failed_task_attempt();

		self::assertCount( 1, $this->backend->calls );
		self::assertSame( self::NOW + 97, $this->backend->calls[0]['args']['timestamp'] ?? null );
		self::assertSame(
			array(
				array(
					'min' => 0,
					'max' => 30,
				),
			),
			$this->randomizer->calls
		);
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Retry policy filter returned an invalid value; return a RetryPolicy instance to override the contract policy.',
					'context' => array(
						'name'          => self::NAME,
						'returned_type' => 'string',
					),
				),
			),
			$this->logger->records
		);
	}

	/**
	 * A throwing retry-policy filter terminalizes the run instead of leaving it stalled.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_terminalizes_a_throwing_retry_policy_filter(): void {
		$this->task->retry_policy = new RetryPolicy( max_attempts: 2 );
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->set_filter_value(
			'a8csp_background_tasks/retry_policy/' . self::NAME,
			static function ( RetryPolicy $policy ): RetryPolicy {
				throw new \DomainException( 'Retry policy filter exploded.' );
			}
		);
		$this->prepare_run_action();
		$this->randomizer->calls = array();

		$this->handle_failed_task_attempt();

		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->randomizer->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		$failed_runs = $this->option( 'a8csp_bgte_failed_' . self::NAME );
		self::assertIsArray( $failed_runs );
		$failed_run = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		self::assertSame( 1, $failed_run['attempts'] ?? null );
		$stored_error = $failed_run['error'] ?? null;
		self::assertIsArray( $stored_error );
		self::assertSame( \DomainException::class, $stored_error['class'] ?? null );
		self::assertSame(
			'Task "email-digest" could not resolve the retry policy because DomainException was thrown. Fix the retry policy provider or filter before retrying the failed run manually.',
			$stored_error['message'] ?? null
		);
		self::assertSame(
			array(
				'a8csp_background_tasks/failed/' . self::NAME,
				'a8csp_background_tasks/failed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
	}

	/**
	 * A throwing retry-policy filter that loses ownership supersedes instead of recording failure.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_when_throwing_retry_policy_filter_loses_ownership(): void {
		$this->task->retry_policy = new RetryPolicy( max_attempts: 2 );
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->set_filter_value(
			'a8csp_background_tasks/retry_policy/' . self::NAME,
			function ( RetryPolicy $policy ): RetryPolicy {
				self::assertTrue( ( new LatestRunPointer( self::NAME, $this->rows ) )->record( 'run-newer', self::ARGS_HASH ) );
				$this->replace_lock_owner( 'run-newer', self::NOW + 90 );

				throw new \DomainException( 'Retry policy filter exploded.' );
			}
		);
		$this->prepare_run_action();
		$this->randomizer->calls = array();

		$this->handle_failed_task_attempt();

		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->randomizer->calls );
		$this->assert_post_callback_superseded_task();
	}

	/**
	 * A throwing retrying listener terminalizes after both retrying hooks without scheduling.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_terminalizes_a_throwing_retrying_listener(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->prepare_run_action();
		$this->randomizer->value = 7;
		$this->randomizer->calls = array();

		$GLOBALS['a8csp_bgte_test_action_throwables'] = array(
			'a8csp_background_tasks/retrying/' . self::NAME => new \RuntimeException(
				'Retrying listener exploded.'
			),
		);

		$this->handle_failed_task_attempt();

		self::assertSame( array(), $this->backend->calls );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		$failed_runs = $this->option( 'a8csp_bgte_failed_' . self::NAME );
		self::assertIsArray( $failed_runs );
		$failed_run = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		self::assertSame( 1, $failed_run['attempts'] ?? null );
		$stored_error = $failed_run['error'] ?? null;
		self::assertIsArray( $stored_error );
		self::assertSame( \RuntimeException::class, $stored_error['class'] ?? null );
		self::assertSame(
			'Task "email-digest" could not prepare the retry action because RuntimeException was thrown. Fix the retry policy, randomness source, retrying hook, or scheduler before retrying the failed run manually.',
			$stored_error['message'] ?? null
		);
		self::assertSame(
			array(
				'a8csp_background_tasks/retrying/' . self::NAME,
				'a8csp_background_tasks/retrying',
				'a8csp_background_tasks/failed/' . self::NAME,
				'a8csp_background_tasks/failed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
	}

	/**
	 * Ownership loss after a retrying-listener error supersedes before terminal failure is recorded.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_after_retry_preparation_error_loses_ownership(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->prepare_run_action();
		$this->randomizer->value = 7;
		$this->randomizer->calls = array();
		$this->set_filter_value(
			'a8csp_background_tasks/retry_policy/' . self::NAME,
			function ( RetryPolicy $policy ): RetryPolicy {
				for ( $index = 0; 3 > $index; ++$index ) {
					$this->wpdb->before_next( 'select', static function ( WpdbLockSpy $lock_spy ): void {} );
				}
				$this->wpdb->before_next(
					'select',
					function ( WpdbLockSpy $lock_spy ): void {
						self::assertTrue( ( new LatestRunPointer( self::NAME, $this->rows ) )->record( 'run-newer', self::ARGS_HASH ) );
						$this->replace_lock_owner( 'run-newer', self::NOW + 90 );
					}
				);

				return $policy;
			}
		);
		$GLOBALS['a8csp_bgte_test_action_throwables'] = array(
			'a8csp_background_tasks/retrying/' . self::NAME => new \RuntimeException(
				'Retrying listener exploded.'
			),
		);

		$this->handle_failed_task_attempt();

		self::assertSame( array(), $this->backend->calls );
		self::assertSame(
			array(
				'a8csp_background_tasks/retrying/' . self::NAME,
				'a8csp_background_tasks/retrying',
				'a8csp_background_tasks/superseded/' . self::NAME,
				'a8csp_background_tasks/superseded',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		$this->assert_post_callback_superseded_task();
	}

	/**
	 * A retry scheduling failure terminalizes the run and identifies the failed stage.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_terminalizes_a_retry_reschedule_failure(): void {
		$this->task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 30,
			max_delay: 120
		);
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->prepare_run_action();
		$this->randomizer->value = 7;
		$this->randomizer->calls = array();

		$this->backend->results['schedule_single'] = new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'Restore the scheduler before retrying the task.'
			)
		);

		$this->handle_failed_task_attempt();

		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertNull( $this->lock() );
		$failed_runs = $this->option( 'a8csp_bgte_failed_' . self::NAME );
		self::assertIsArray( $failed_runs );
		$failed_run = $failed_runs[0] ?? null;
		self::assertIsArray( $failed_run );
		self::assertSame( 1, $failed_run['attempts'] ?? null );
		$stored_error = $failed_run['error'] ?? null;
		self::assertIsArray( $stored_error );
		self::assertSame(
			'Task "email-digest" could not schedule the retry action: Restore the scheduler before retrying the task.',
			$stored_error['message'] ?? null
		);
		self::assertSame(
			array(
				'a8csp_background_tasks/retrying/' . self::NAME,
				'a8csp_background_tasks/retrying',
				'a8csp_background_tasks/failed/' . self::NAME,
				'a8csp_background_tasks/failed',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
	}

	/**
	 * A one-attempt ordinary policy enters the existing terminal failure path.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_fails_terminally_when_the_policy_has_no_retry(): void {
		$this->task->retry_policy = new RetryPolicy( max_attempts: 1 );

		$this->assert_terminal_task_failure( new \RuntimeException( 'Database unavailable.' ) );
	}

	/**
	 * A non-retryable throwable enters the same immediate terminal failure path.
	 *
	 * @return  void
	 */
	public function test_handle_run_action_fails_terminally_for_a_non_retryable_exception(): void {
		$this->assert_terminal_task_failure( new NonRetryableTaskException( 'The request is permanently invalid.' ) );
	}

	/**
	 * A batch-only validation subtype remains a generic consumer failure on the task path.
	 *
	 * @return  void
	 */
	public function test_batch_validation_exception_is_not_reclassified_on_the_task_path(): void {
		$this->task->retry_policy = new RetryPolicy( max_attempts: 1 );

		$this->assert_terminal_task_failure( new InvalidBatchChunkException() );
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
	 * Applies failure adjudication to one fenced task attempt.
	 *
	 * @return  void
	 */
	private function handle_failed_task_attempt(): void {
		$run_store = new RunStore( self::NAME, $this->clock, new OptionRows( $this->wpdb ) );
		$state     = $this->terminal_transitions->active_run_state(
			'Task',
			self::NAME,
			self::RUN_ID,
			$this->action_seq(),
			$run_store,
			fn (): int => $this->clock->timestamp + $this->task->max_runtime()
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
				self::RUN_ID,
				$state,
				$run_store,
				$throwable,
				fn (): RetryPolicy => $this->task->get_retry_policy(),
				function ( RunState $failure_state, EngineError $error, int $attempts_used, string $stage, ApiErrorCode $code, ?array $failed_chunk ) use ( $run_store ): void {
					$this->terminal_transitions->fail_run(
						self::NAME,
						self::RUN_ID,
						$failure_state,
						$run_store,
						$error,
						$attempts_used,
						$stage,
						$code,
						$failed_chunk
					);
				}
			);
		}
	}

	/**
	 * Asserts one throwable's failed-store entry, hooks, cleanup, and global transition order.
	 *
	 * @param   \Throwable $throwable Task failure.
	 *
	 * @return  void
	 */
	private function assert_terminal_task_failure( \Throwable $throwable ): void {
		$this->task->throwable = $throwable;
		$this->prepare_run_action();
		$this->randomizer->calls = array();
		$expected_message        = \sprintf(
			'Background-work execution failed because %s was thrown.',
			\get_debug_type( $throwable )
		);

		$this->handle_failed_task_attempt();

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( array(), $this->randomizer->calls );
		self::assertArrayNotHasKey( $this->lock_option_name(), $this->wpdb->rows );
		self::assertNull( $this->option( $this->run_option_name() ) );
		self::assertSame(
			array(
				array(
					'run_id'     => self::RUN_ID,
					'failed_at'  => self::NOW + 90,
					'start_args' => self::ARGS,
					'attempts'   => 1,
					'error'      => array(
						'class'   => $throwable::class,
						'message' => $expected_message,
						'stage'   => 'execution',
						'code'    => ApiErrorCode::ExecutionFailed->value,
					),
				),
			),
			$this->option( 'a8csp_bgte_failed_' . self::NAME )
		);

		$actions = $this->fired_actions();
		self::assertCount( 2, $actions );
		self::assertSame( 'a8csp_background_tasks/failed/' . self::NAME, $actions[0]['hook_name'] );
		self::assertSame( self::RUN_ID, $actions[0]['args'][0] );
		self::assertSame( self::ARGS, $actions[0]['args'][1] );
		self::assertInstanceOf( RunFailure::class, $actions[0]['args'][2] );
		self::assertSame( self::NAME, $actions[0]['args'][2]->name );
		self::assertSame( self::RUN_ID, $actions[0]['args'][2]->run_id );
		self::assertSame( 1, $actions[0]['args'][2]->attempts );
		self::assertSame( 'execution', $actions[0]['args'][2]->stage );
		self::assertSame( ApiErrorCode::ExecutionFailed, $actions[0]['args'][2]->code );
		self::assertSame( $expected_message, $actions[0]['args'][2]->summary );
		self::assertNull( $actions[0]['args'][2]->failed_chunk );
		self::assertSame( 'a8csp_background_tasks/failed', $actions[1]['hook_name'] );
		self::assertSame(
			array( self::NAME, self::RUN_ID, self::ARGS, $actions[0]['args'][2] ),
			$actions[1]['args']
		);
		self::assertSame(
			array(
				'lock:update',
				'run:running',
				'task:handle',
				'lock:update',
				'run:running',
				...( $throwable instanceof NonRetryableTaskException ? array() : array( 'lock:update' ) ),
				'run:failed',
				'failed-store',
				'run:failed',
				'hook:failed/' . self::NAME,
				'hook:failed',
				'run:failed',
				'history',
				'run:failed',
				'lock:delete',
				'run:delete',
			),
			$this->lifecycle_labels()
		);
		$this->assert_terminal_history( RunStatus::Failed );
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
					'hook_name' => 'a8csp_background_tasks/superseded/' . self::NAME,
					'args'      => array( self::RUN_ID, self::ARGS ),
				),
				array(
					'hook_name' => 'a8csp_background_tasks/superseded',
					'args'      => array( self::NAME, self::RUN_ID, self::ARGS ),
				),
			),
			\array_slice( $this->fired_actions(), -2 )
		);
		$this->assert_terminal_history( RunStatus::Superseded );
	}

	/**
	 * Asserts that both terminal-history buffers record the terminal outcome.
	 *
	 * @param   RunStatus $status Terminal run status.
	 *
	 * @return  void
	 */
	private function assert_terminal_history( RunStatus $status ): void {
		$entry = array(
			'run_id' => self::RUN_ID,
			'status' => $status->value,
		);

		self::assertSame(
			array(
				'started'   => array( self::RUN_ID ),
				'completed' => array( $entry ),
				'by_hash'   => array(
					self::ARGS_HASH => array(
						'started'   => array( self::RUN_ID ),
						'completed' => array( $entry ),
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
				if ( 'delete' !== $operation && 'a8csp_bgte_failed_' . self::NAME === ( $event['key'] ?? null ) ) {
					$labels[] = 'failed-store';
					continue;
				}
				if ( 'delete' !== $operation && 'a8csp_bgte_history_' . self::NAME === ( $event['key'] ?? null ) ) {
					$labels[] = 'history';
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

	// endregion.
}

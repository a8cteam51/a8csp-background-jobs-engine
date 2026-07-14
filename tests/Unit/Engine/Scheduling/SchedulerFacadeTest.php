<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Scheduling;

use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\ClearanceResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins ordered routing and payload protection at the scheduling facade boundary.
 *
 */
#[CoversClass( SchedulerFacade::class )]
#[UsesClass( BackendInterface::class )]
#[UsesClass( WPCronBackend::class )]
#[UsesClass( ClearanceResult::class )]
#[UsesClass( Success::class )]
#[UsesClass( Failure::class )]
#[UsesClass( SchedulingError::class )]
#[UsesClass( SchedulingErrorReason::class )]
final class SchedulerFacadeTest extends TestCase {
	private const HOOK = 'a8csp_bgte_test_hook';

	/**
	 * Keeps facade tests independent of a WordPress bootstrap while satisfying production guards.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once __DIR__ . '/wp-json-encode-stub.php';
		require_once \dirname( __DIR__, 2 ) . '/wp-cron-stubs.php';
	}

	/**
	 * Keeps the WP-Cron fallback isolated from process-global fake state.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_cron_array']          = array();
		$GLOBALS['a8csp_bgte_test_cron_calls']          = array();
		$GLOBALS['a8csp_bgte_test_cron_results']        = array();
		$GLOBALS['a8csp_bgte_test_cron_event_sequence'] = 0;
	}

	/**
	 * Empty configuration identifies the baseline backend the caller must supply.
	 *
	 * @return  void
	 */
	public function test_constructor_rejects_an_empty_backend_list_with_the_fix(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs(
			'SchedulerFacade requires at least one backend; pass the WP-Cron backend as the final fallback.'
		);

		new SchedulerFacade( array() );
	}

	/**
	 * Cron capability requires one backend that is both ready and capable.
	 *
	 * @return  void
	 */
	public function test_cron_capability_consults_only_ready_backends(): void {
		$unready_supported                 = new RecordingBackend();
		$unready_supported->ready          = false;
		$unready_supported->cron_supported = true;
		$ready_unsupported                 = new RecordingBackend();
		$ready_supported                   = new RecordingBackend();
		$ready_supported->cron_supported   = true;
		$unused                            = new RecordingBackend();

		$facade = new SchedulerFacade(
			array( $unready_supported, $ready_unsupported, $ready_supported, $unused )
		);

		self::assertTrue( $facade->supports_cron_expressions() );
		self::assertSame( array( 'is_ready' ), $this->call_verbs( $unready_supported ) );
		self::assertSame( array( 'is_ready', 'supports_cron_expressions' ), $this->call_verbs( $ready_unsupported ) );
		self::assertSame( array( 'is_ready', 'supports_cron_expressions' ), $this->call_verbs( $ready_supported ) );
		self::assertSame( array(), $unused->calls );
	}

	/**
	 * Constructor keys do not affect declaration order or first-ready routing.
	 *
	 * @return  void
	 */
	public function test_constructor_ignores_string_and_non_sequential_backend_keys(): void {
		$first        = new RecordingBackend();
		$first->ready = false;
		$second       = new RecordingBackend();
		$third        = new RecordingBackend();

		$result = ( new SchedulerFacade(
			array(
				'preferred' => $first,
				42          => $second,
				'later'     => $third,
			)
		) )->enqueue_async( self::HOOK );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( array( 'is_ready' ), $this->call_verbs( $first ) );
		self::assertSame( array( 'is_ready', 'enqueue_async' ), $this->call_verbs( $second ) );
		self::assertSame( array(), $third->calls );
	}

	/**
	 * Sparse constructor keys leave the declared final backend available for fallback routing.
	 *
	 * @return  void
	 */
	public function test_constructor_reindexes_sparse_keys_for_fallback_lookup(): void {
		$first                          = new RecordingBackend();
		$last                           = new RecordingBackend();
		$expected                       = new Success( true );
		$first->ready                   = false;
		$last->ready                    = false;
		$last->results['enqueue_async'] = $expected;

		$result = ( new SchedulerFacade(
			array(
				'preferred' => $first,
				42          => $last,
			)
		) )->enqueue_async( self::HOOK );

		self::assertSame( $expected, $result );
		self::assertSame( array( 'is_ready' ), $this->call_verbs( $first ) );
		self::assertSame( array( 'is_ready', 'enqueue_async' ), $this->call_verbs( $last ) );
	}

	/**
	 * A write reaches the first ready backend and leaves every later backend untouched.
	 *
	 * @return  void
	 */
	public function test_write_uses_only_the_first_ready_backend(): void {
		$first    = new RecordingBackend();
		$second   = new RecordingBackend();
		$expected = new Success( true );

		$first->results['schedule_recurring'] = $expected;

		$result = ( new SchedulerFacade( array( $first, $second ) ) )->schedule_recurring(
			self::HOOK,
			300,
			array( 'run-17' ),
			1_700_000_000,
			'reports',
			true,
			20
		);

		self::assertSame( $expected, $result );
		self::assertSame(
			array(
				array(
					'verb' => 'is_ready',
					'args' => array(),
				),
				array(
					'verb' => 'schedule_recurring',
					'args' => array(
						'hook'                => self::HOOK,
						'interval'            => 300,
						'args'                => array( 'run-17' ),
						'first_run_timestamp' => 1_700_000_000,
						'group'               => 'reports',
						'unique'              => true,
						'priority'            => 20,
					),
				),
			),
			$first->calls
		);
		self::assertSame( array(), $second->calls );
	}

	/**
	 * Write preference advances past an unready backend without invoking its write API.
	 *
	 * @return  void
	 */
	public function test_write_uses_the_second_backend_when_the_first_is_not_ready(): void {
		$first    = new RecordingBackend();
		$second   = new RecordingBackend();
		$expected = new Success( true );

		$first->ready = false;

		$second->results['schedule_single'] = $expected;

		$result = ( new SchedulerFacade( array( $first, $second ) ) )->schedule_single(
			self::HOOK,
			1_700_000_000,
			array( 'run-18' ),
			'imports',
			30
		);

		self::assertSame( $expected, $result );
		self::assertSame( array( 'is_ready' ), $this->call_verbs( $first ) );
		self::assertSame( array( 'is_ready', 'schedule_single' ), $this->call_verbs( $second ) );
		self::assertSame(
			array(
				'hook'      => self::HOOK,
				'timestamp' => 1_700_000_000,
				'args'      => array( 'run-18' ),
				'group'     => 'imports',
				'priority'  => 30,
			),
			$second->calls[1]['args']
		);
	}

	/**
	 * A grouped async write reaches WP-Cron when the preferred backend is unavailable.
	 *
	 * @return  void
	 */
	public function test_grouped_enqueue_async_falls_back_to_wp_cron(): void {
		$preferred        = new RecordingBackend();
		$preferred->ready = false;
		$args             = array( 'run-19' );

		$result = ( new SchedulerFacade( array( $preferred, new WPCronBackend() ) ) )->enqueue_async(
			self::HOOK,
			$args,
			'reports|run-19',
			true,
			40
		);

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame( array( 'is_ready' ), $this->call_verbs( $preferred ) );
		self::assertIsInt( \wp_next_scheduled( self::HOOK, $args ) );
	}

	/**
	 * With no ready backend, the last backend receives the write and supplies its own failure.
	 *
	 * @return  void
	 */
	public function test_write_falls_back_to_the_last_backend_when_none_are_ready(): void {
		$first    = new RecordingBackend();
		$last     = new RecordingBackend();
		$expected = new Failure(
			new SchedulingError(
				SchedulingErrorReason::BackendNotReady,
				'Load the baseline scheduler before enqueueing the hook.'
			)
		);

		$first->ready = false;
		$last->ready  = false;

		$last->results['enqueue_async'] = $expected;

		$result = ( new SchedulerFacade( array( $first, $last ) ) )->enqueue_async(
			self::HOOK,
			array( 'run-19' ),
			'exports',
			true,
			40
		);

		self::assertSame( $expected, $result );
		self::assertSame( array( 'is_ready' ), $this->call_verbs( $first ) );
		self::assertSame( array( 'is_ready', 'enqueue_async' ), $this->call_verbs( $last ) );
		self::assertSame(
			array(
				'hook'     => self::HOOK,
				'args'     => array( 'run-19' ),
				'group'    => 'exports',
				'unique'   => true,
				'priority' => 40,
			),
			$last->calls[1]['args']
		);
	}

	/**
	 * A backend that becomes unready during its write yields to the next ready backend.
	 *
	 * @return  void
	 */
	public function test_write_falls_through_when_the_selected_backend_becomes_unready(): void {
		$first                    = new RecordingBackend();
		$first->readiness_results = array( true, false );
		$second                   = new RecordingBackend();
		$expected                 = new Success( true );

		$second->results['enqueue_async'] = $expected;

		$result = ( new SchedulerFacade( array( $first, $second ) ) )->enqueue_async(
			self::HOOK,
			array( 'run-20' ),
			'exports',
			true,
			40
		);

		self::assertSame( $expected, $result );
		self::assertSame( array( 'is_ready', 'enqueue_async', 'is_ready' ), $this->call_verbs( $first ) );
		self::assertSame( array( 'is_ready', 'enqueue_async' ), $this->call_verbs( $second ) );
		self::assertSame(
			array(
				'hook'     => self::HOOK,
				'args'     => array( 'run-20' ),
				'group'    => 'exports',
				'unique'   => true,
				'priority' => 40,
			),
			$second->calls[1]['args']
		);
	}

	/**
	 * Only backend-readiness failures permit a write to reach another backend.
	 *
	 * @param   string $reason_value Non-readiness failure backing value.
	 *
	 * @return  void
	 */
	#[DataProvider( 'non_readiness_failure_provider' )]
	public function test_write_returns_every_non_readiness_failure_unchanged( string $reason_value ): void {
		$first    = new RecordingBackend();
		$second   = new RecordingBackend();
		$expected = new Failure(
			new SchedulingError(
				SchedulingErrorReason::from( $reason_value ),
				'Correct the rejected scheduling request before retrying.'
			)
		);

		$first->results['enqueue_async'] = $expected;

		$result = ( new SchedulerFacade( array( $first, $second ) ) )->enqueue_async( self::HOOK );

		self::assertSame( $expected, $result );
		self::assertSame( array( 'is_ready', 'enqueue_async' ), $this->call_verbs( $first ) );
		self::assertSame( array(), $second->calls );
	}

	/**
	 * When every selected backend declines a write, the last decline retains diagnostic precedence.
	 *
	 * @return  void
	 */
	public function test_write_returns_the_last_failure_when_every_backend_declines(): void {
		$first        = new RecordingBackend();
		$second       = new RecordingBackend();
		$first_result = new Failure(
			new SchedulingError(
				SchedulingErrorReason::BackendNotReady,
				'Initialize the first backend before retrying the write.'
			)
		);
		$last_result  = new Failure(
			new SchedulingError(
				SchedulingErrorReason::BackendNotReady,
				'Initialize the fallback backend before retrying the write.'
			)
		);

		$first->results['schedule_single']  = $first_result;
		$second->results['schedule_single'] = $last_result;

		$result = ( new SchedulerFacade( array( $first, $second ) ) )->schedule_single(
			self::HOOK,
			1_700_000_000
		);

		self::assertSame( $last_result, $result );
		self::assertSame( array( 'is_ready', 'schedule_single' ), $this->call_verbs( $first ) );
		self::assertSame( array( 'is_ready', 'schedule_single' ), $this->call_verbs( $second ) );
	}

	/**
	 * Scheduled state is the union of the currently ready backends.
	 *
	 * @return  void
	 */
	public function test_is_scheduled_returns_true_when_only_the_second_backend_reports_it(): void {
		$first             = new RecordingBackend();
		$second            = new RecordingBackend();
		$second->scheduled = true;

		$result = ( new SchedulerFacade( array( $first, $second ) ) )->is_scheduled(
			self::HOOK,
			array( 'run-20' ),
			'reports'
		);

		self::assertTrue( $result );
		self::assertSame( array( 'is_ready', 'is_scheduled' ), $this->call_verbs( $first ) );
		self::assertSame( array( 'is_ready', 'is_scheduled' ), $this->call_verbs( $second ) );
		self::assertSame(
			array(
				'hook'  => self::HOOK,
				'args'  => array( 'run-20' ),
				'group' => 'reports',
			),
			$first->calls[1]['args']
		);
		self::assertSame( $first->calls[1]['args'], $second->calls[1]['args'] );
	}

	/**
	 * The next run is the earliest concrete answer across every ready backend.
	 *
	 * @return  void
	 */
	public function test_get_next_scheduled_returns_the_minimum_and_ignores_null_answers(): void {
		$first                 = new RecordingBackend();
		$first->next_scheduled = 1_700_000_300;
		$second                = new RecordingBackend();
		$third                 = new RecordingBackend();
		$third->next_scheduled = 1_700_000_100;

		$result = ( new SchedulerFacade( array( $first, $second, $third ) ) )->get_next_scheduled(
			self::HOOK,
			array( 'run-21' ),
			'imports'
		);

		self::assertSame( 1_700_000_100, $result );
		foreach ( array( $first, $second, $third ) as $backend ) {
			self::assertSame( array( 'is_ready', 'get_next_scheduled' ), $this->call_verbs( $backend ) );
			self::assertSame(
				array(
					'hook'  => self::HOOK,
					'args'  => array( 'run-21' ),
					'group' => 'imports',
				),
				$backend->calls[1]['args']
			);
		}
	}

	/**
	 * The next run remains absent when every ready backend has no timestamp.
	 *
	 * @return  void
	 */
	public function test_get_next_scheduled_returns_null_when_all_answers_are_null(): void {
		$first  = new RecordingBackend();
		$second = new RecordingBackend();

		$result = ( new SchedulerFacade( array( $first, $second ) ) )->get_next_scheduled( self::HOOK );

		self::assertNull( $result );
	}

	/**
	 * Read operations test readiness but never query an unready backend.
	 *
	 * @return  void
	 */
	public function test_reads_never_query_an_unready_backend(): void {
		$unready                 = new RecordingBackend();
		$unready->ready          = false;
		$unready->scheduled      = true;
		$unready->next_scheduled = 1_700_000_000;
		$ready                   = new RecordingBackend();
		$facade                  = new SchedulerFacade( array( $unready, $ready ) );

		self::assertFalse( $facade->is_scheduled( self::HOOK ) );
		self::assertNull( $facade->get_next_scheduled( self::HOOK ) );
		self::assertSame( array( 'is_ready', 'is_ready' ), $this->call_verbs( $unready ) );
		self::assertSame(
			array( 'is_ready', 'is_scheduled', 'is_ready', 'get_next_scheduled' ),
			$this->call_verbs( $ready )
		);
	}

	/**
	 * A successful clear reaches every ready backend.
	 *
	 * @return  void
	 */
	public function test_unschedule_clears_every_ready_backend(): void {
		$first          = new RecordingBackend();
		$unready        = new RecordingBackend();
		$second         = new RecordingBackend();
		$unready->ready = false;

		$result = ( new SchedulerFacade( array( $first, $unready, $second ) ) )->unschedule(
			self::HOOK,
			array( 'run-22' ),
			'cleanup'
		);

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame( array( 'is_ready', 'unschedule' ), $this->call_verbs( $first ) );
		self::assertSame( array( 'is_ready' ), $this->call_verbs( $unready ) );
		self::assertSame( array( 'is_ready', 'unschedule' ), $this->call_verbs( $second ) );
		self::assertSame(
			array(
				'hook'  => self::HOOK,
				'args'  => array( 'run-22' ),
				'group' => 'cleanup',
			),
			$first->calls[1]['args']
		);
		self::assertSame( $first->calls[1]['args'], $second->calls[1]['args'] );
	}

	/**
	 * A group-only clear reaches every ready backend with no hook or argument identity.
	 *
	 * @return  void
	 */
	public function test_unschedule_group_clears_the_exact_group_across_every_ready_backend(): void {
		$first          = new RecordingBackend();
		$unready        = new RecordingBackend();
		$second         = new RecordingBackend();
		$unready->ready = false;

		$result = ( new SchedulerFacade( array( $first, $unready, $second ) ) )
			->unschedule_group( 'reports|run-22' );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame( array( 'is_ready', 'unschedule' ), $this->call_verbs( $first ) );
		self::assertSame( array( 'is_ready' ), $this->call_verbs( $unready ) );
		self::assertSame( array( 'is_ready', 'unschedule' ), $this->call_verbs( $second ) );
		self::assertSame(
			array(
				'hook'  => '',
				'args'  => array(),
				'group' => 'reports|run-22',
			),
			$first->calls[1]['args']
		);
		self::assertSame( $first->calls[1]['args'], $second->calls[1]['args'] );
	}

	/**
	 * The earliest failure wins after every ready backend receives the clear.
	 *
	 * @return  void
	 */
	public function test_unschedule_returns_the_first_failure_after_clearing_remaining_ready_backends(): void {
		$first          = new RecordingBackend();
		$second         = new RecordingBackend();
		$first_failure  = new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'Repair the first backend schedule and retry the clear.'
			)
		);
		$second_failure = new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'Repair the second backend schedule and retry the clear.'
			)
		);

		$first->results['unschedule']  = $first_failure;
		$second->results['unschedule'] = $second_failure;

		$result = ( new SchedulerFacade( array( $first, $second ) ) )->unschedule( self::HOOK );

		self::assertSame( $first_failure, $result );
		self::assertSame( array( 'is_ready', 'unschedule' ), $this->call_verbs( $first ) );
		self::assertSame( array( 'is_ready', 'unschedule' ), $this->call_verbs( $second ) );
	}

	/**
	 * A later failure is returned after every ready backend receives the clear.
	 *
	 * @return  void
	 */
	public function test_unschedule_returns_a_later_failure_after_clearing_remaining_ready_backends(): void {
		$first    = new RecordingBackend();
		$second   = new RecordingBackend();
		$third    = new RecordingBackend();
		$expected = new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'Repair the second backend schedule and retry the clear.'
			)
		);

		$second->results['unschedule'] = $expected;

		$result = ( new SchedulerFacade( array( $first, $second, $third ) ) )->unschedule( self::HOOK );

		self::assertSame( $expected, $result );
		self::assertSame( array( 'is_ready', 'unschedule' ), $this->call_verbs( $first ) );
		self::assertSame( array( 'is_ready', 'unschedule' ), $this->call_verbs( $second ) );
		self::assertSame( array( 'is_ready', 'unschedule' ), $this->call_verbs( $third ) );
	}

	/**
	 * With no ready backend, the final backend supplies the clear failure diagnostics.
	 *
	 * @return  void
	 */
	public function test_unschedule_falls_back_to_the_last_backend_when_none_are_ready(): void {
		$first    = new RecordingBackend();
		$last     = new RecordingBackend();
		$expected = new Failure(
			new SchedulingError(
				SchedulingErrorReason::BackendNotReady,
				'Load the baseline scheduler before clearing the hook.'
			)
		);

		$first->ready = false;
		$last->ready  = false;

		$last->results['unschedule'] = $expected;

		$result = ( new SchedulerFacade( array( $first, $last ) ) )->unschedule( self::HOOK );

		self::assertSame( $expected, $result );
		self::assertSame( array( 'is_ready' ), $this->call_verbs( $first ) );
		self::assertSame( array( 'is_ready', 'unschedule' ), $this->call_verbs( $last ) );
	}

	/**
	 * Facade readiness follows whether any configured backend is ready.
	 *
	 * @return  void
	 */
	public function test_is_ready_returns_true_when_any_backend_is_ready(): void {
		$first        = new RecordingBackend();
		$first->ready = false;
		$second       = new RecordingBackend();

		self::assertTrue( ( new SchedulerFacade( array( $first, $second ) ) )->is_ready() );

		$second->ready = false;
		self::assertFalse( ( new SchedulerFacade( array( $first, $second ) ) )->is_ready() );
	}

	/**
	 * Convergence authority stays bound to the readiness snapshot used by the clear.
	 *
	 * @return  void
	 */
	public function test_convergence_clear_authority_uses_the_cleared_readiness_snapshot(): void {
		$absent         = new RecordingBackend();
		$absent->ready  = false;
		$absent->absent = true;
		$ready          = new RecordingBackend();

		$authoritative = ( new SchedulerFacade( array( $absent, $ready ) ) )->unschedule_for_convergence(
			self::HOOK
		);

		self::assertTrue( $authoritative->authoritative );
		self::assertInstanceOf( Success::class, $authoritative->result );

		$transitioning                    = new RecordingBackend();
		$transitioning->readiness_results = array( false, true );
		$not_authoritative                = ( new SchedulerFacade( array( $transitioning, $ready ) ) )
			->unschedule_for_convergence( self::HOOK );

		self::assertFalse( $not_authoritative->authoritative );
		self::assertTrue( $transitioning->is_ready() );
	}

	/**
	 * Hook registration reaches every backend without consulting readiness.
	 *
	 * @return  void
	 */
	public function test_register_hooks_reaches_every_backend_including_unready_backends(): void {
		$first        = new RecordingBackend();
		$first->ready = false;
		$second       = new RecordingBackend();
		$facade       = new SchedulerFacade( array( $first, $second ) );

		$facade->register_hooks();

		self::assertSame( array( 'register_hooks' ), $this->call_verbs( $first ) );
		self::assertSame( array( 'register_hooks' ), $this->call_verbs( $second ) );
	}

	/**
	 * Every scheduling write rejects oversized arguments before backend selection.
	 *
	 * @param   'schedule_recurring'|'schedule_single'|'enqueue_async' $verb Write method to exercise.
	 *
	 * @return  void
	 */
	#[DataProvider( 'guarded_write_provider' )]
	public function test_args_guard_blocks_every_write_before_routing( string $verb ): void {
		$backend = new RecordingBackend();
		$facade  = new SchedulerFacade( array( $backend ) );
		$result  = $this->invoke_guarded_write( $facade, $verb, $this->args_with_json_length( 8_001 ) );
		$error   = $this->assert_payload_too_large( $result );

		self::assertSame(
			'Scheduling hook "a8csp_bgte_test_hook" has arguments that cannot be JSON-encoded within the 8000-byte limit; pass identifying keys and load bulk data from storage inside the handler.',
			$error->message
		);
		self::assertSame( array(), $backend->calls );
	}

	/**
	 * Nested objects fail the portable-storage shape guard before JSON size validation.
	 *
	 * @return  void
	 */
	public function test_args_guard_rejects_an_object_nested_inside_arrays(): void {
		$backend = new RecordingBackend();
		$result  = ( new SchedulerFacade( array( $backend ) ) )->enqueue_async(
			self::HOOK,
			array(
				array(
					'payload' => new \stdClass(),
				),
			)
		);
		$error   = $this->assert_payload_too_large( $result );

		self::assertStringContainsString( 'tree of scalars and arrays', $error->message );
		self::assertStringContainsString( 'store objects by identifier', $error->message );
		self::assertSame( array(), $backend->calls );
	}

	/**
	 * Nested closures fail the portable-storage shape guard before backend selection.
	 *
	 * @return  void
	 */
	public function test_args_guard_rejects_a_nested_closure(): void {
		$backend = new RecordingBackend();
		$result  = ( new SchedulerFacade( array( $backend ) ) )->enqueue_async(
			self::HOOK,
			array( array( static fn (): string => 'not portable' ) )
		);
		$error   = $this->assert_payload_too_large( $result );

		self::assertStringContainsString( 'tree of scalars and arrays', $error->message );
		self::assertStringContainsString( 'store objects by identifier', $error->message );
		self::assertSame( array(), $backend->calls );
	}

	/**
	 * Resources fail the same portable-storage shape guard before backend selection.
	 *
	 * @return  void
	 */
	public function test_args_guard_rejects_a_nested_resource(): void {
		$resource = \STDIN;

		$backend = new RecordingBackend();
		$result  = ( new SchedulerFacade( array( $backend ) ) )->enqueue_async(
			self::HOOK,
			array( array( $resource ) )
		);
		$error   = $this->assert_payload_too_large( $result );

		self::assertStringContainsString( 'tree of scalars and arrays', $error->message );
		self::assertSame( array(), $backend->calls );
	}

	/**
	 * Argument nesting beyond the JSON encoder's depth fails before backend selection.
	 *
	 * @return  void
	 */
	public function test_args_guard_rejects_nesting_beyond_the_portable_depth(): void {
		$args = array( null );
		for ( $depth = 1; 513 > $depth; ++$depth ) {
			$args = array( $args );
		}

		$backend = new RecordingBackend();
		$result  = ( new SchedulerFacade( array( $backend ) ) )->enqueue_async( self::HOOK, $args );
		$error   = $this->assert_payload_too_large( $result );

		self::assertStringContainsString( 'keep nesting within 512 levels', $error->message );
		self::assertSame( array(), $backend->calls );
	}

	/**
	 * Self-referential arrays fail the finite-tree guard before backend selection.
	 *
	 * @return  void
	 */
	public function test_args_guard_rejects_a_self_referential_array(): void {
		$recursive         = array();
		$recursive['self'] =& $recursive;

		try {
			$backend = new RecordingBackend();
			$result  = ( new SchedulerFacade( array( $backend ) ) )->enqueue_async(
				self::HOOK,
				array( $recursive )
			);
			$error   = $this->assert_payload_too_large( $result );

			self::assertStringContainsString( 'tree of scalars and arrays', $error->message );
			self::assertSame( array(), $backend->calls );
		} finally {
			unset( $recursive['self'] );
		}
	}

	/**
	 * Nested arrays containing scalar and null leaves remain portable.
	 *
	 * @return  void
	 */
	public function test_args_guard_accepts_nested_scalar_and_null_values(): void {
		$backend = new RecordingBackend();
		$args    = array(
			null,
			array(
				'string',
				42,
				1.5,
				true,
				false,
				array( 'nullable' => null ),
			),
		);

		$result = ( new SchedulerFacade( array( $backend ) ) )->enqueue_async( self::HOOK, $args );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $args, $backend->calls[1]['args']['args'] );
	}

	/**
	 * Non-positive recurring first-run timestamps fail before backend selection.
	 *
	 * @param   int $timestamp Invalid first-run timestamp.
	 *
	 * @return  void
	 */
	#[DataProvider( 'non_positive_timestamp_provider' )]
	public function test_schedule_recurring_rejects_a_non_positive_first_run_timestamp( int $timestamp ): void {
		$backend = new RecordingBackend();
		$result  = ( new SchedulerFacade( array( $backend ) ) )->schedule_recurring(
			self::HOOK,
			300,
			first_run_timestamp: $timestamp
		);
		$error   = $this->assert_invalid_interval( $result );

		self::assertStringContainsString( 'positive UNIX seconds', $error->message );
		self::assertSame( array( 'first_run_timestamp' => $timestamp ), $error->context );
		self::assertSame( array(), $backend->calls );
	}

	/**
	 * Non-positive single-run timestamps fail before backend selection.
	 *
	 * @param   int $timestamp Invalid run timestamp.
	 *
	 * @return  void
	 */
	#[DataProvider( 'non_positive_timestamp_provider' )]
	public function test_schedule_single_rejects_a_non_positive_timestamp( int $timestamp ): void {
		$backend = new RecordingBackend();
		$result  = ( new SchedulerFacade( array( $backend ) ) )->schedule_single( self::HOOK, $timestamp );
		$error   = $this->assert_invalid_interval( $result );

		self::assertStringContainsString( 'positive UNIX seconds', $error->message );
		self::assertSame( array( 'timestamp' => $timestamp ), $error->context );
		self::assertSame( array(), $backend->calls );
	}

	/**
	 * The documented ceiling remains accepted and routes normally.
	 *
	 * @return  void
	 */
	public function test_args_guard_accepts_an_exactly_8000_byte_json_payload(): void {
		$backend = new RecordingBackend();
		$args    = $this->args_with_json_length( 8_000 );

		$result = ( new SchedulerFacade( array( $backend ) ) )->enqueue_async( self::HOOK, $args );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( array( 'is_ready', 'enqueue_async' ), $this->call_verbs( $backend ) );
	}

	/**
	 * JSON encoding failures use the same corrective payload failure as oversized arguments.
	 *
	 * @return  void
	 */
	public function test_args_guard_rejects_unencodable_arguments_before_routing(): void {
		$backend = new RecordingBackend();
		$result  = ( new SchedulerFacade( array( $backend ) ) )->schedule_single(
			self::HOOK,
			1_700_000_000,
			array( \INF )
		);

		$this->assert_payload_too_large( $result );
		self::assertSame( array(), $backend->calls );
	}

	/**
	 * Oversized identities remain available to clear and query operations.
	 *
	 * @return  void
	 */
	public function test_oversized_arguments_do_not_block_unschedule_or_reads(): void {
		$backend                 = new RecordingBackend();
		$backend->scheduled      = true;
		$backend->next_scheduled = 1_700_000_000;
		$facade                  = new SchedulerFacade( array( $backend ) );
		$args                    = $this->args_with_json_length( 8_001 );

		$clear = $facade->unschedule( self::HOOK, $args );

		self::assertInstanceOf( Success::class, $clear );
		self::assertTrue( $facade->is_scheduled( self::HOOK, $args ) );
		self::assertSame( 1_700_000_000, $facade->get_next_scheduled( self::HOOK, $args ) );
		self::assertSame(
			array(
				'is_ready',
				'unschedule',
				'is_ready',
				'is_scheduled',
				'is_ready',
				'get_next_scheduled',
			),
			$this->call_verbs( $backend )
		);
		self::assertSame( $args, $backend->calls[1]['args']['args'] );
		self::assertSame( $args, $backend->calls[3]['args']['args'] );
		self::assertSame( $args, $backend->calls[5]['args']['args'] );
	}

	/**
	 * Supplies every scheduling write guarded by the facade.
	 *
	 * @return  array<string, array{0: 'schedule_recurring'|'schedule_single'|'enqueue_async'}>
	 */
	public static function guarded_write_provider(): array {
		return array(
			'schedule recurring' => array( 'schedule_recurring' ),
			'schedule single'    => array( 'schedule_single' ),
			'enqueue async'      => array( 'enqueue_async' ),
		);
	}

	/**
	 * Supplies every failure reason that must not fall through write routing.
	 *
	 * @return  array<string, array{0: string}>
	 */
	public static function non_readiness_failure_provider(): array {
		return array(
			'unsupported group'      => array( 'unsupported_group' ),
			'unsupported recurrence' => array( 'unsupported_recurrence' ),
			'invalid interval'       => array( 'invalid_interval' ),
			'payload too large'      => array( 'payload_too_large' ),
			'schedule failed'        => array( 'schedule_failed' ),
		);
	}

	/**
	 * Supplies timestamps outside the positive UNIX-seconds domain.
	 *
	 * @return  array<string, array{0: int}>
	 */
	public static function non_positive_timestamp_provider(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -1 ),
		);
	}

	/**
	 * Invokes one guarded write with the supplied hook arguments.
	 *
	 * @phpstan-param 'schedule_recurring'|'schedule_single'|'enqueue_async' $verb
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   SchedulerFacade $facade Facade under test.
	 * @param   string          $verb   Write method to exercise.
	 * @param   array           $args   Hook arguments.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	private function invoke_guarded_write( SchedulerFacade $facade, string $verb, array $args ): AbstractResult {
		return match ( $verb ) {
			'schedule_recurring' => $facade->schedule_recurring( self::HOOK, 300, $args ),
			'schedule_single'    => $facade->schedule_single( self::HOOK, 1_700_000_000, $args ),
			'enqueue_async'      => $facade->enqueue_async( self::HOOK, $args ),
		};
	}

	/**
	 * Builds one list whose default JSON encoding has the requested length.
	 *
	 * @param   int $json_length Requested encoded length.
	 *
	 * @return  list<string>
	 */
	private function args_with_json_length( int $json_length ): array {
		$args    = array( \str_repeat( 'x', $json_length - 4 ) );
		$encoded = \wp_json_encode( $args );

		if ( ! \is_string( $encoded ) || \strlen( $encoded ) !== $json_length ) {
			throw new \LogicException( 'The test fixture must produce the requested JSON length.' );
		}

		return $args;
	}

	/**
	 * Returns a payload failure after checking its machine-readable reason.
	 *
	 * @phpstan-param AbstractResult<true, SchedulingError> $result
	 *
	 * @param   AbstractResult $result Result to inspect.
	 *
	 * @return  SchedulingError
	 */
	private function assert_payload_too_large( AbstractResult $result ): SchedulingError {
		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::PayloadTooLarge, $result->error->reason );

		return $result->error;
	}

	/**
	 * Returns a timing failure after checking its machine-readable reason.
	 *
	 * @phpstan-param AbstractResult<true, SchedulingError> $result
	 *
	 * @param   AbstractResult $result Result to inspect.
	 *
	 * @return  SchedulingError
	 */
	private function assert_invalid_interval( AbstractResult $result ): SchedulingError {
		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::InvalidInterval, $result->error->reason );

		return $result->error;
	}

	/**
	 * Returns a backend's recorded verb sequence.
	 *
	 * @param   RecordingBackend $backend Backend to inspect.
	 *
	 * @return  list<string>
	 */
	private function call_verbs( RecordingBackend $backend ): array {
		return \array_column( $backend->calls, 'verb' );
	}
}

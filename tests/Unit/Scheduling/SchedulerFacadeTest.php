<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Scheduling;

use A8C\SpecialProjects\BackgroundTasksEngine\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins ordered routing and payload protection at the scheduling facade boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( SchedulerFacade::class )]
#[UsesClass( BackendInterface::class )]
#[UsesClass( Success::class )]
#[UsesClass( Failure::class )]
#[UsesClass( SchedulingError::class )]
#[UsesClass( SchedulingErrorReason::class )]
final class SchedulerFacadeTest extends TestCase {
	private const HOOK = 'a8csp_bgte_test_hook';

	/**
	 * Satisfies production boot guards before the facade is autoloaded.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once __DIR__ . '/wp-json-encode-stub.php';
	}

	/**
	 * Empty configuration identifies the baseline backend the caller must supply.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * A write reaches the first ready backend and leaves every later backend untouched.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * With no ready backend, the last backend receives the write and supplies its own failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	}

	/**
	 * Scheduled state is the union of the currently ready backends.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	}

	/**
	 * The next run is the earliest concrete answer across every ready backend.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
		}
	}

	/**
	 * The next run remains absent when every ready backend has no timestamp.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	}

	/**
	 * First-failure precedence stops clearing before later ready backends are mutated.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unschedule_returns_the_first_failure_without_clearing_remaining_backends(): void {
		$first    = new RecordingBackend();
		$second   = new RecordingBackend();
		$expected = new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'Repair the first backend schedule and retry the clear.'
			)
		);

		$first->results['unschedule'] = $expected;

		$result = ( new SchedulerFacade( array( $first, $second ) ) )->unschedule( self::HOOK );

		self::assertSame( $expected, $result );
		self::assertSame( array( 'is_ready', 'unschedule' ), $this->call_verbs( $first ) );
		self::assertSame( array( 'is_ready' ), $this->call_verbs( $second ) );
	}

	/**
	 * Failure precedence retains earlier clears but stops before every later ready backend.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unschedule_returns_a_later_failure_without_clearing_remaining_backends(): void {
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
		self::assertSame( array( 'is_ready' ), $this->call_verbs( $third ) );
	}

	/**
	 * With no ready backend, the final backend supplies the clear failure diagnostics.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * Hook registration reaches every backend without consulting readiness.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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

		self::assertStringContainsString( self::HOOK, $error->message );
		self::assertStringContainsString(
			'store bulk data in the run option and pass identifying keys only',
			$error->message
		);
		self::assertSame( array(), $backend->calls );
	}

	/**
	 * The documented ceiling remains accepted and routes normally.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_args_guard_accepts_an_exactly_8000_character_json_payload(): void {
		$backend = new RecordingBackend();
		$args    = $this->args_with_json_length( 8_000 );

		$result = ( new SchedulerFacade( array( $backend ) ) )->enqueue_async( self::HOOK, $args );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( array( 'is_ready', 'enqueue_async' ), $this->call_verbs( $backend ) );
	}

	/**
	 * JSON encoding failures use the same corrective payload failure as oversized arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * Invokes one guarded write with the supplied hook arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * Returns a backend's recorded verb sequence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RecordingBackend $backend Backend to inspect.
	 *
	 * @return  list<string>
	 */
	private function call_verbs( RecordingBackend $backend ): array {
		return \array_column( $backend->calls, 'verb' );
	}
}

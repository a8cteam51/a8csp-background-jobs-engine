<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBackend;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the reusable scheduling backend recorder used by facade tests.
 *
 */
#[CoversNothing]
final class RecordingBackendTest extends TestCase {
	/**
	 * Satisfies production boot guards before the backend interface is autoloaded.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}
	}

	/**
	 * Write verbs default to successful results and retain every supplied argument.
	 *
	 * @return  void
	 */
	public function test_write_verbs_record_calls_and_default_to_success(): void {
		$backend = new RecordingBackend();
		$results = array(
			$backend->schedule_recurring( 'recurring', 300, array( 'a' ), 1_700_000_000, 'reports', 20 ),
			$backend->schedule_single( 'single', 1_700_000_100, array( 'b' ), 'imports', 30 ),
			$backend->enqueue_async( 'async', array( 'c' ), 'exports', 40 ),
			$backend->unschedule( 'clear', array( 'd' ), 'cleanup' ),
		);

		foreach ( $results as $result ) {
			self::assertInstanceOf( Success::class, $result );
			self::assertTrue( $result->value );
		}

		self::assertSame(
			array(
				array(
					'verb' => 'schedule_recurring',
					'args' => array(
						'hook'                => 'recurring',
						'interval'            => 300,
						'args'                => array( 'a' ),
						'first_run_timestamp' => 1_700_000_000,
						'group'               => 'reports',
						'priority'            => 20,
					),
				),
				array(
					'verb' => 'schedule_single',
					'args' => array(
						'hook'      => 'single',
						'timestamp' => 1_700_000_100,
						'args'      => array( 'b' ),
						'group'     => 'imports',
						'priority'  => 30,
					),
				),
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => 'async',
						'args'     => array( 'c' ),
						'group'    => 'exports',
						'priority' => 40,
					),
				),
				array(
					'verb' => 'unschedule',
					'args' => array(
						'hook'  => 'clear',
						'args'  => array( 'd' ),
						'group' => 'cleanup',
					),
				),
			),
			$backend->calls
		);
	}

	/**
	 * Each write verb returns only the result scripted for that verb.
	 *
	 * @return  void
	 */
	public function test_write_results_are_scripted_independently(): void {
		$backend   = new RecordingBackend();
		$recurring = new Failure( new SchedulingError( SchedulingErrorReason::InvalidTimeInput, 'Use a positive interval.' ) );
		$single    = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Repair the single schedule and retry.' ) );
		$async     = new Success( true );
		$clear     = new Failure( new SchedulingError( SchedulingErrorReason::UnsupportedGroup, 'Drop the unsupported group.' ) );

		$backend->results = array(
			'schedule_recurring' => $recurring,
			'schedule_single'    => $single,
			'enqueue_async'      => $async,
			'unschedule'         => $clear,
		);

		self::assertSame( $recurring, $backend->schedule_recurring( 'recurring', 0 ) );
		self::assertSame( $single, $backend->schedule_single( 'single', 1_700_000_000 ) );
		self::assertSame( $async, $backend->enqueue_async( 'async' ) );
		self::assertSame( $clear, $backend->unschedule( 'clear' ) );
	}

	/**
	 * Queued readiness answers reproduce a backend becoming unavailable between selection and use.
	 *
	 * @return  void
	 */
	public function test_write_rechecks_a_remaining_queued_readiness_answer(): void {
		$backend                    = new RecordingBackend();
		$backend->readiness_results = array( true, false );

		self::assertTrue( $backend->is_ready() );

		$result = $backend->enqueue_async( 'async' );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::BackendNotReady, $result->error->reason );
		self::assertSame( array( 'is_ready', 'enqueue_async', 'is_ready' ), \array_column( $backend->calls, 'verb' ) );
	}

	/**
	 * Write results remain non-discardable on every concrete declaration.
	 *
	 * @return  void
	 */
	public function test_write_verbs_repeat_the_no_discard_attribute_on_every_backend_type(): void {
		$types = array(
			BackendInterface::class,
			ActionSchedulerBackend::class,
			WPCronBackend::class,
			SchedulerFacade::class,
			RecordingBackend::class,
		);
		$verbs = array(
			'schedule_recurring',
			'schedule_single',
			'enqueue_async',
			'unschedule',
			'unschedule_hooks',
		);

		foreach ( $types as $type ) {
			$reflection = new \ReflectionClass( $type );
			foreach ( $verbs as $verb ) {
				self::assertCount( 1, $reflection->getMethod( $verb )->getAttributes( \NoDiscard::class ), \sprintf( '%s::%s() must declare NoDiscard directly.', $type, $verb ) );
			}
		}
	}

	/**
	 * Query, readiness, and lifecycle calls return scripted state and remain visible in order.
	 *
	 * @return  void
	 */
	public function test_reads_readiness_and_lifecycle_are_recorded(): void {
		$backend                 = new RecordingBackend();
		$backend->scheduled      = true;
		$backend->next_scheduled = 1_700_000_000;
		$backend->ready          = false;

		self::assertSame( 0, $backend->scheduled_count( 'count', array( 'pending' ), 'reports' ) );
		self::assertTrue( $backend->is_scheduled( 'query', array( 'a' ), 'reports' ) );
		self::assertSame( 1_700_000_000, $backend->get_next_scheduled( 'next', array( 'b' ), 'imports' ) );
		self::assertFalse( $backend->is_ready() );
		$backend->register_hooks();

		self::assertSame(
			array(
				array(
					'verb' => 'scheduled_count',
					'args' => array(
						'hook'  => 'count',
						'args'  => array( 'pending' ),
						'group' => 'reports',
					),
				),
				array(
					'verb' => 'is_scheduled',
					'args' => array(
						'hook'  => 'query',
						'args'  => array( 'a' ),
						'group' => 'reports',
					),
				),
				array(
					'verb' => 'get_next_scheduled',
					'args' => array(
						'hook'  => 'next',
						'args'  => array( 'b' ),
						'group' => 'imports',
					),
				),
				array(
					'verb' => 'is_ready',
					'args' => array(),
				),
				array(
					'verb' => 'register_hooks',
					'args' => array(),
				),
			),
			$backend->calls
		);
	}
}

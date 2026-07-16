<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Backends;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ordered backend routing through the production engine graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( SchedulerFacade::class )]
final class SchedulerFacadeTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const IDENTITY  = self::OWNER . ':' . self::TASK_NAME;
	private const NOW       = 1_700_000_000;
	private const OWNER     = 'scheduler-tests';
	private const TASK_NAME = 'refresh-index';

	private Consumer $consumer;
	private EngineRig $rig;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress seams before the production graph is built.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		EngineRig::bootstrap();
	}

	/**
	 * Boots preferred and fallback recording boundaries in production order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig      = EngineRig::set_up( self::NOW, 2 );
		$this->consumer = $this->rig->consumer( self::OWNER );
		$this->consumer->tasks()->register( new RecordingTask( self::TASK_NAME ) );
		$this->reset_backend_observations();
	}

	/**
	 * Releases request-local engine state after each scenario.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function tearDown(): void {
		try {
			$this->rig->tear_down();
		} finally {
			parent::tearDown();
		}
	}

	// endregion.

	// region TESTS.

	/**
	 * A ready Action Scheduler candidate accepts work without touching the WP-Cron fallback.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_ready_preferred_backend_accepts_public_task_admission(): void {
		$result = $this->consumer->tasks()->enqueue( self::TASK_NAME, array( 'site_id' => 7 ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( array( 'is_ready', 'enqueue_async' ), $this->verbs( $this->preferred() ) );
		self::assertSame( array(), $this->fallback()->calls );
		$this->preferred()->assert_scheduled( self::IDENTITY );
		$this->fallback()->assert_not_scheduled( self::IDENTITY );
	}

	/**
	 * An unavailable Action Scheduler candidate yields public task admission to the WP-Cron fallback.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unready_preferred_backend_falls_back_for_public_task_admission(): void {
		$this->preferred()->ready = false;

		$result = $this->consumer->tasks()->enqueue( self::TASK_NAME, array( 'site_id' => 7 ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( array( 'is_ready' ), $this->verbs( $this->preferred() ) );
		self::assertSame( array( 'is_ready', 'enqueue_async' ), $this->verbs( $this->fallback() ) );
		$this->preferred()->assert_not_scheduled( self::IDENTITY );
		$this->fallback()->assert_scheduled( self::IDENTITY );
	}

	/**
	 * A preferred backend that becomes unready during its write yields to the ready fallback.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_mid_write_readiness_loss_falls_through_without_losing_the_run(): void {
		$this->preferred()->readiness_results = array( true, false );

		$result = $this->consumer->tasks()->enqueue( self::TASK_NAME, array( 'site_id' => 7 ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( array( 'is_ready', 'enqueue_async', 'is_ready' ), $this->verbs( $this->preferred() ) );
		self::assertSame( array( 'is_ready', 'enqueue_async' ), $this->verbs( $this->fallback() ) );
		$this->preferred()->assert_not_scheduled( self::IDENTITY );
		$this->fallback()->assert_scheduled( self::IDENTITY );
	}

	/**
	 * Schedule removal clears the same owner-qualified chain from every ready backend.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_public_schedule_removal_clears_every_ready_backend(): void {
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), self::TASK_NAME );
		self::assertInstanceOf( Success::class, $this->consumer->schedules()->sync( array( $schedule ) ) );
		$this->reset_backend_observations();

		$result = $this->consumer->schedules()->sync( array() );

		self::assertInstanceOf( Success::class, $result );
		foreach ( $this->rig->backends() as $backend ) {
			$calls = $this->calls( $backend, 'unschedule' );
			self::assertCount( 1, $calls );
			self::assertSame( 'scheduler-tests:nightly', $calls[0]['args']['group'] ?? null );
		}
	}

	/**
	 * The production-published scheduler accepts the exact JSON ceiling and rejects the next byte.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_publication_retains_the_8000_byte_argument_ceiling(): void {
		$scheduler = Component::get_scheduler();
		self::assertInstanceOf( SchedulerFacade::class, $scheduler );

		$accepted = $scheduler->enqueue_async(
			'a8csp_background_tasks/payload_boundary',
			self::args_with_json_length( 8_000 ),
			'scheduler-tests:payload-boundary'
		);

		self::assertInstanceOf( Success::class, $accepted );
		self::assertSame( array( 'is_ready', 'enqueue_async' ), $this->verbs( $this->preferred() ) );
		self::assertSame( array(), $this->fallback()->calls );
		$this->preferred()->assert_scheduled( 'scheduler-tests:payload-boundary' );
		$this->reset_backend_observations();

		$rejected = $scheduler->enqueue_async(
			'a8csp_background_tasks/payload_boundary',
			self::args_with_json_length( 8_001 ),
			'scheduler-tests:payload-boundary'
		);

		self::assertInstanceOf( Failure::class, $rejected );
		self::assertInstanceOf( SchedulingError::class, $rejected->error );
		self::assertSame( SchedulingErrorReason::InvalidPayload, $rejected->error->reason );
		self::assertSame( 8_000, $rejected->error->context['maximum_json_length'] ?? null );
		self::assertSame( array(), $this->preferred()->calls );
		self::assertSame( array(), $this->fallback()->calls );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns the first backend candidate, mirroring Action Scheduler's production position.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RecordingBackend
	 */
	private function preferred(): RecordingBackend {
		return $this->rig->backends()[0];
	}

	/**
	 * Returns the final backend candidate, mirroring WP-Cron's fallback position.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RecordingBackend
	 */
	private function fallback(): RecordingBackend {
		return $this->rig->backends()[1];
	}

	/**
	 * Returns one backend's recorded verb sequence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RecordingBackend $backend Backend to inspect.
	 *
	 * @return  list<string>
	 */
	private function verbs( RecordingBackend $backend ): array {
		return \array_column( $backend->calls, 'verb' );
	}

	/**
	 * Returns one backend's calls for an exact verb.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RecordingBackend $backend Backend to inspect.
	 * @param   string           $verb    Exact verb.
	 *
	 * @return  list<array{verb: string, args: array<string, mixed>}>
	 */
	private function calls( RecordingBackend $backend, string $verb ): array {
		return \array_values( \array_filter( $backend->calls, static fn ( array $call ): bool => $verb === $call['verb'] ) );
	}

	/**
	 * Builds one list whose default JSON encoding has the requested byte length.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $json_length Requested encoded length.
	 *
	 * @return  list<string>
	 */
	private static function args_with_json_length( int $json_length ): array {
		$args    = array( \str_repeat( 'x', $json_length - 4 ) );
		$encoded = \wp_json_encode( $args );
		if ( ! \is_string( $encoded ) || \strlen( $encoded ) !== $json_length ) {
			throw new \LogicException( 'The scheduler payload fixture must produce the requested JSON length.' );
		}

		return $args;
	}

	/**
	 * Clears backend observations without changing accepted deliveries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function reset_backend_observations(): void {
		foreach ( $this->rig->backends() as $backend ) {
			$backend->calls = array();
		}
	}

	// endregion.
}

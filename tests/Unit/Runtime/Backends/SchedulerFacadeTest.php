<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Backends;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\ScopeOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

	private const string IDENTITY = self::SCOPE . ':' . self::JOB_NAME;
	private const int NOW         = 1_700_000_000;
	private const string SCOPE    = 'scheduler-tests';
	private const string JOB_NAME = 'refresh-index';

	private ScopeOperations $client;
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

		$this->rig    = EngineRig::set_up( self::NOW, 2 );
		$this->client = $this->rig->operations( self::SCOPE );
		$this->client->register( ( new RecordingJob( self::JOB_NAME ) )->definition() );
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
	public function test_ready_preferred_backend_accepts_public_job_admission(): void {
		$result = $this->client->dispatch( self::JOB_NAME, array( 'site_id' => 7 ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( array( 'is_ready', 'enqueue_async' ), $this->verbs( $this->preferred() ) );
		self::assertSame( array(), $this->fallback()->calls );
		$this->preferred()->assert_scheduled( self::IDENTITY );
		$this->fallback()->assert_not_scheduled( self::IDENTITY );
	}

	/**
	 * An unavailable Action Scheduler candidate yields public job admission to the WP-Cron fallback.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unready_preferred_backend_falls_back_for_public_job_admission(): void {
		$this->preferred()->ready = false;

		$result = $this->client->dispatch( self::JOB_NAME, array( 'site_id' => 7 ) );

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

		$result = $this->client->dispatch( self::JOB_NAME, array( 'site_id' => 7 ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( array( 'is_ready', 'enqueue_async', 'is_ready' ), $this->verbs( $this->preferred() ) );
		self::assertSame( array( 'is_ready', 'enqueue_async' ), $this->verbs( $this->fallback() ) );
		$this->preferred()->assert_not_scheduled( self::IDENTITY );
		$this->fallback()->assert_scheduled( self::IDENTITY );
	}

	/**
	 * Scheduled counts preserve multiple matching occurrences inside one ready backend.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_scheduled_count_preserves_same_backend_multiplicity(): void {
		$identity  = self::SCOPE . ':same-backend-count';
		$scheduler = new SchedulerFacade( array( $this->preferred() ) );
		self::assertInstanceOf( Success::class, $this->preferred()->schedule_recurring( OccurrenceDelivery::SCHEDULE_HOOK, 300, array( $identity ), self::NOW + 300, $identity ) );
		self::assertInstanceOf( Success::class, $this->preferred()->schedule_recurring( OccurrenceDelivery::SCHEDULE_HOOK, 300, array( $identity ), self::NOW + 600, $identity ) );

		self::assertSame( 2, $scheduler->scheduled_count( OccurrenceDelivery::SCHEDULE_HOOK, array( $identity ), $identity ) );
	}

	/**
	 * Scheduled counts sum matching occurrences across ready backends.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_scheduled_count_sums_ready_backend_occurrences(): void {
		$identity  = self::SCOPE . ':cross-backend-count';
		$scheduler = new SchedulerFacade( $this->rig->backends() );
		self::assertInstanceOf( Success::class, $this->preferred()->schedule_recurring( OccurrenceDelivery::SCHEDULE_HOOK, 300, array( $identity ), self::NOW + 300, $identity ) );
		self::assertInstanceOf( Success::class, $this->fallback()->schedule_recurring( OccurrenceDelivery::SCHEDULE_HOOK, 300, array( $identity ), self::NOW + 300, $identity ) );

		self::assertSame( 2, $scheduler->scheduled_count( OccurrenceDelivery::SCHEDULE_HOOK, array( $identity ), $identity ) );

		$this->preferred()->ready = false;
		self::assertSame( 1, $scheduler->scheduled_count( OccurrenceDelivery::SCHEDULE_HOOK, array( $identity ), $identity ) );
	}

	/**
	 * Bulk scheduled counts preserve zero, one, and surplus totals across ready backends.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_scheduled_counts_sum_multiple_identities_across_ready_backends(): void {
		$identities = array( 'single', 'missing', 'many', 'split' );
		$scheduler  = new SchedulerFacade( $this->rig->backends() );
		self::assertInstanceOf( Success::class, $this->preferred()->schedule_recurring( OccurrenceDelivery::SCHEDULE_HOOK, 300, array( 'single' ), self::NOW + 300, 'single' ) );
		self::assertInstanceOf( Success::class, $this->preferred()->schedule_recurring( OccurrenceDelivery::SCHEDULE_HOOK, 300, array( 'many' ), self::NOW + 300, 'many' ) );
		self::assertInstanceOf( Success::class, $this->preferred()->schedule_recurring( OccurrenceDelivery::SCHEDULE_HOOK, 300, array( 'many' ), self::NOW + 600, 'many' ) );
		self::assertInstanceOf( Success::class, $this->preferred()->schedule_recurring( OccurrenceDelivery::SCHEDULE_HOOK, 300, array( 'split' ), self::NOW + 300, 'split' ) );
		self::assertInstanceOf( Success::class, $this->fallback()->schedule_recurring( OccurrenceDelivery::SCHEDULE_HOOK, 300, array( 'split' ), self::NOW + 300, 'split' ) );
		$this->reset_backend_observations();

		$counts = $scheduler->scheduled_counts( OccurrenceDelivery::SCHEDULE_HOOK, $identities );

		self::assertSame(
			array(
				'single'  => 1,
				'missing' => 0,
				'many'    => 2,
				'split'   => 2,
			),
			$counts
		);
		foreach ( $this->rig->backends() as $backend ) {
			$calls = $this->calls( $backend, 'scheduled_counts' );
			self::assertCount( 1, $calls );
			self::assertSame( $identities, $calls[0]['args']['identities'] ?? null );
			self::assertSame( array(), $this->calls( $backend, 'scheduled_count' ) );
		}
	}

	/**
	 * Schedule removal clears the same scope-qualified chain from every ready backend.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_public_schedule_removal_clears_every_ready_backend(): void {
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), self::JOB_NAME );
		self::assertInstanceOf( Success::class, $this->client->sync( array( $schedule ) ) );
		$this->reset_backend_observations();

		$result = $this->client->sync( array() );

		self::assertInstanceOf( Success::class, $result );
		foreach ( $this->rig->backends() as $backend ) {
			$calls = $this->calls( $backend, 'unschedule' );
			self::assertCount( 1, $calls );
			self::assertSame( 'scheduler-tests:nightly', $calls[0]['args']['group'] ?? null );
		}
	}

	/**
	 * Duplicate ready chains converge onto the preferred backend with the current declaration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_duplicate_ready_chains_converge_to_the_current_preferred_declaration(): void {
		$initial = new Schedule( 'nightly', Recurrence::every( 300 ), self::JOB_NAME, priority: 21 );
		$current = new Schedule( 'nightly', Recurrence::every( 900 ), self::JOB_NAME, priority: 73 );
		self::assertInstanceOf( Success::class, $this->client->sync( array( $initial ) ) );
		$this->preferred()->scheduled = true;
		$this->preferred()->ready     = false;
		self::assertInstanceOf( Success::class, $this->client->sync( array( $current ) ) );
		$this->fallback()->scheduled = true;
		$this->preferred()->ready    = true;
		$registration                = $this->schedule_registration();
		$registry_raw                = $this->raw_schedule_registry();
		$this->reset_backend_observations();

		$result = $this->client->sync( array( $current ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $registry_raw, $this->raw_schedule_registry() );
		$this->preferred()->assert_scheduled( 'scheduler-tests:nightly' );
		$this->fallback()->assert_not_scheduled( 'scheduler-tests:nightly' );
		foreach ( $this->rig->backends() as $backend ) {
			self::assertCount( 1, $this->calls( $backend, 'unschedule' ) );
		}
		$writes = $this->calls( $this->preferred(), 'schedule_recurring' );
		self::assertCount( 1, $writes );
		self::assertSame( 900, $writes[0]['args']['interval'] ?? null );
		self::assertSame( $registration['next_due'], $writes[0]['args']['first_run_timestamp'] ?? null );
		self::assertSame( 0, $writes[0]['args']['priority'] ?? null );
		self::assertSame( array(), $this->calls( $this->fallback(), 'schedule_recurring' ) );
	}

	/**
	 * One fallback chain remains in place after the preferred backend recovers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_single_fallback_chain_is_not_migrated_after_preferred_recovery(): void {
		$schedule                 = new Schedule( 'nightly', Recurrence::every( 300 ), self::JOB_NAME );
		$this->preferred()->ready = false;
		self::assertInstanceOf( Success::class, $this->client->sync( array( $schedule ) ) );
		$this->fallback()->scheduled = true;
		$this->preferred()->ready    = true;
		$registry_raw                = $this->raw_schedule_registry();
		$this->reset_backend_observations();

		$result = $this->client->sync( array( $schedule ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $registry_raw, $this->raw_schedule_registry() );
		$this->preferred()->assert_not_scheduled( 'scheduler-tests:nightly' );
		$this->fallback()->assert_scheduled( 'scheduler-tests:nightly' );
		foreach ( $this->rig->backends() as $backend ) {
			self::assertSame( array(), $this->calls( $backend, 'unschedule' ) );
			self::assertSame( array(), $this->calls( $backend, 'schedule_recurring' ) );
		}
	}

	/**
	 * A dormant preferred backend remains outside convergence reads and writes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dormant_preferred_backend_is_not_consulted_for_convergence(): void {
		$schedule                 = new Schedule( 'nightly', Recurrence::every( 300 ), self::JOB_NAME );
		$this->preferred()->ready = false;
		self::assertInstanceOf( Success::class, $this->client->sync( array( $schedule ) ) );
		$this->fallback()->scheduled = true;
		$this->reset_backend_observations();

		$result = $this->client->sync( array( $schedule ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( array(), $this->calls( $this->preferred(), 'scheduled_counts' ) );
		foreach ( $this->rig->backends() as $backend ) {
			self::assertSame( array(), $this->calls( $backend, 'unschedule' ) );
			self::assertSame( array(), $this->calls( $backend, 'schedule_recurring' ) );
		}
		$this->fallback()->assert_scheduled( 'scheduler-tests:nightly' );
	}

	/**
	 * A failed duplicate clearance retains registration state and skips recreation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_convergence_clear_failure_preserves_registration_without_recreating(): void {
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), self::JOB_NAME );
		self::assertInstanceOf( Success::class, $this->client->sync( array( $schedule ) ) );
		$registration = $this->schedule_registration();
		$next_due     = $registration['next_due'] ?? null;
		self::assertIsInt( $next_due );
		$this->preferred()->scheduled = true;
		self::assertInstanceOf(
			Success::class,
			$this->fallback()->schedule_recurring( OccurrenceDelivery::SCHEDULE_HOOK, 300, array( 'scheduler-tests:nightly' ), $next_due, 'scheduler-tests:nightly' )
		);
		$this->fallback()->scheduled              = true;
		$this->preferred()->results['unschedule'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'The backend could not confirm clearance.' ) );
		$registry_raw                             = $this->raw_schedule_registry();
		$this->reset_backend_observations();

		$result = $this->client->sync( array( $schedule ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertSame( $registry_raw, $this->raw_schedule_registry() );
		foreach ( $this->rig->backends() as $backend ) {
			self::assertSame( array(), $this->calls( $backend, 'schedule_recurring' ) );
		}
		$this->preferred()->assert_scheduled( 'scheduler-tests:nightly' );
		$this->fallback()->assert_not_scheduled( 'scheduler-tests:nightly' );
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

		$accepted = $scheduler->enqueue_async( 'a8csp_bgje/payload_boundary', self::args_with_json_length( 8_000 ), 'scheduler-tests:payload-boundary' );

		self::assertInstanceOf( Success::class, $accepted );
		self::assertSame( array( 'is_ready', 'enqueue_async' ), $this->verbs( $this->preferred() ) );
		self::assertSame( array(), $this->fallback()->calls );
		$this->preferred()->assert_scheduled( 'scheduler-tests:payload-boundary' );
		$this->reset_backend_observations();

		$rejected = $scheduler->enqueue_async( 'a8csp_bgje/payload_boundary', self::args_with_json_length( 8_001 ), 'scheduler-tests:payload-boundary' );

		self::assertInstanceOf( Failure::class, $rejected );
		self::assertInstanceOf( SchedulingError::class, $rejected->error );
		self::assertSame( SchedulingErrorReason::InvalidPayload, $rejected->error->reason );
		self::assertSame( 8_000, $rejected->error->context['maximum_json_length'] ?? null );
		self::assertSame( array(), $this->preferred()->calls );
		self::assertSame( array(), $this->fallback()->calls );
	}

	/**
	 * Backend write throwables become redacted checked failures at every routing position.
	 *
	 * @load-bearing security
	 * @pin-rationale The facade is the boundary that converts backend throwables before orchestration can compensate; exact verb and backend-position coverage cannot be isolated through a higher-level public workflow.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'enqueue_async'|'schedule_single'|'schedule_recurring' $verb             Backend write verb.
	 * @param   'preferred'|'fallback'                                 $backend_position Selected backend position.
	 *
	 * @return  void
	 */
	#[DataProvider( 'throwing_write_scenarios' )]
	public function test_backend_write_throwables_become_redacted_checked_failures( string $verb, string $backend_position ): void {
		$scheduler = new SchedulerFacade( $this->rig->backends() );
		$secret    = 'raw-backend-detail-' . $verb . '-' . $backend_position;
		$throwable = new \RuntimeException( $secret );
		if ( 'fallback' === $backend_position ) {
			$this->preferred()->ready = false;
		}

		$backend = 'preferred' === $backend_position ? $this->preferred() : $this->fallback();
		$backend->before_next(
			$verb,
			static function () use ( $throwable ): void {
				throw $throwable;
			}
		);

		$result = match ( $verb ) {
			'enqueue_async'     => $scheduler->enqueue_async( 'a8csp_bgje/throwable_barrier', array( 'run-17' ), 'scheduler-tests:throwable-barrier' ),
			'schedule_single'   => $scheduler->schedule_single( 'a8csp_bgje/throwable_barrier', self::NOW + 300, array( 'run-17' ), 'scheduler-tests:throwable-barrier' ),
			'schedule_recurring' => $scheduler->schedule_recurring( 'a8csp_bgje/throwable_barrier', 300, array( 'run-17' ), self::NOW + 300, 'scheduler-tests:throwable-barrier' ),
		};

		self::assertTrue( $result->is_failure() );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::ScheduleFailed, $result->error->reason );
		self::assertSame( \sprintf( 'The scheduling backend could not accept the write because %s was thrown; repair the backend and retry.', \get_debug_type( $throwable ) ), $result->error->message );
		self::assertStringNotContainsString( $secret, $result->error->message );
		self::assertSame( array(), $result->error->context );

		if ( 'preferred' === $backend_position ) {
			self::assertSame( array( 'is_ready', $verb ), $this->verbs( $this->preferred() ) );
			self::assertSame( array(), $this->fallback()->calls );
		} else {
			self::assertSame( array( 'is_ready' ), $this->verbs( $this->preferred() ) );
			self::assertSame( array( 'is_ready', $verb ), $this->verbs( $this->fallback() ) );
		}
	}

	// endregion.

	// region DATA PROVIDERS.

	/**
	 * Supplies every scheduling write at both facade routing positions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{verb: 'enqueue_async'|'schedule_single'|'schedule_recurring', backend_position: 'preferred'|'fallback'}>
	 */
	public static function throwing_write_scenarios(): array {
		return array(
			'preferred async enqueue'      => array(
				'verb'             => 'enqueue_async',
				'backend_position' => 'preferred',
			),
			'fallback async enqueue'       => array(
				'verb'             => 'enqueue_async',
				'backend_position' => 'fallback',
			),
			'preferred single schedule'    => array(
				'verb'             => 'schedule_single',
				'backend_position' => 'preferred',
			),
			'fallback single schedule'     => array(
				'verb'             => 'schedule_single',
				'backend_position' => 'fallback',
			),
			'preferred recurring schedule' => array(
				'verb'             => 'schedule_recurring',
				'backend_position' => 'preferred',
			),
			'fallback recurring schedule'  => array(
				'verb'             => 'schedule_recurring',
				'backend_position' => 'fallback',
			),
		);
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
	 * Returns the persisted registration for the scheduler-test declaration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, mixed>
	 */
	private function schedule_registration(): array {
		$snapshot = $this->rig->inspection()->schedules( self::SCOPE );
		self::assertNotNull( $snapshot );
		$registration = \array_find( $snapshot['entries'], static fn ( array $entry ): bool => 'scheduler-tests:nightly' === ( $entry['identity'] ?? null ) );
		self::assertIsArray( $registration );

		return $registration;
	}

	/**
	 * Returns the exact persisted schedule-registry bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function raw_schedule_registry(): string {
		$raw = $this->rig->wpdb()->rows[ ScheduleRegistry::option_name( self::SCOPE ) ] ?? null;
		self::assertIsString( $raw );

		return $raw;
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

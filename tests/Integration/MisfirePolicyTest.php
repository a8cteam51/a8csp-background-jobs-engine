<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\EngineFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\DeliveryScheduler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\ChunkedJobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\JobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunReconciliation;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Inspection;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\CleanupIntents;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceLease;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\RegistrationUpdateOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\AbstractIntegrationTestCase;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingRandomizer;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;

/**
 * Verifies fixed-recurrence misfire policy, hook payloads, counters, and the strict grace boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class MisfirePolicyTest extends AbstractIntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Read-only inspection published by the deterministic graph. */
	private ?Inspection $deterministic_inspection = null;

	/** Registry seam used only to age occurrence fixtures through production CAS. */
	private ?ScheduleRegistry $deterministic_registry = null;

	/** Registered work used by the deterministic graph. */
	private ?JobRegistry $deterministic_job_registry = null;

	/** Fixed interval shared by deterministic recurrence probes. */
	private const int INTERVAL = 300;

	/** Owner isolated to the RunOnce occurrence. */
	private const string RUN_ONCE_OWNER = 'integration-misfire-run-once';

	/** Schedule isolated to the RunOnce occurrence. */
	private const string RUN_ONCE_SCHEDULE = 'late-run-once';

	/** Owner-qualified RunOnce schedule identity. */
	private const string RUN_ONCE_SCHEDULE_IDENTITY = self::RUN_ONCE_OWNER . ':' . self::RUN_ONCE_SCHEDULE;

	/** Job isolated to the RunOnce occurrence. */
	private const string RUN_ONCE_JOB = 'integration-misfire-run-once-job';

	/** Owner-qualified RunOnce target identity. */
	private const string RUN_ONCE_JOB_IDENTITY = self::RUN_ONCE_OWNER . ':' . self::RUN_ONCE_JOB;

	/** Owner isolated to the Skip occurrence. */
	private const string SKIP_OWNER = 'integration-misfire-skip';

	/** Schedule isolated to the Skip occurrence. */
	private const string SKIP_SCHEDULE = 'late-skip';

	/** Owner-qualified Skip schedule identity. */
	private const string SKIP_SCHEDULE_IDENTITY = self::SKIP_OWNER . ':' . self::SKIP_SCHEDULE;

	/** Job isolated to the Skip occurrence. */
	private const string SKIP_JOB = 'integration-misfire-skip-job';

	/** Owner isolated to the grace-boundary occurrences. */
	private const string BOUNDARY_OWNER = 'integration-misfire-boundary';

	/** Schedule exactly at the grace boundary. */
	private const string EXACT_SCHEDULE = 'exact-grace';

	/** Owner-qualified exact-boundary schedule identity. */
	private const string EXACT_SCHEDULE_IDENTITY = self::BOUNDARY_OWNER . ':' . self::EXACT_SCHEDULE;

	/** Job exactly at the grace boundary. */
	private const string EXACT_JOB = 'integration-misfire-exact-job';

	/** Owner-qualified exact-boundary target identity. */
	private const string EXACT_JOB_IDENTITY = self::BOUNDARY_OWNER . ':' . self::EXACT_JOB;

	/** Schedule one second beyond the grace boundary. */
	private const string BEYOND_SCHEDULE = 'beyond-grace';

	/** Owner-qualified beyond-boundary schedule identity. */
	private const string BEYOND_SCHEDULE_IDENTITY = self::BOUNDARY_OWNER . ':' . self::BEYOND_SCHEDULE;

	/** Job one second beyond the grace boundary. */
	private const string BEYOND_JOB = 'integration-misfire-beyond-job';

	/** Owner isolated to misfire-grace filter ordering. */
	private const string FILTER_OWNER = 'integration-misfire-filter-order';

	/** Schedule isolated to misfire-grace filter ordering. */
	private const string FILTER_SCHEDULE = 'filter-order';

	/** Owner-qualified schedule identity isolated to misfire-grace filter ordering. */
	private const string FILTER_SCHEDULE_IDENTITY = self::FILTER_OWNER . ':' . self::FILTER_SCHEDULE;

	/** Job isolated to misfire-grace filter ordering. */
	private const string FILTER_JOB = 'integration-misfire-filter-order-job';

	/** Owner-qualified target identity isolated to misfire-grace filter ordering. */
	private const string FILTER_JOB_IDENTITY = self::FILTER_OWNER . ':' . self::FILTER_JOB;

	// endregion.

	// region TESTS.

	/**
	 * Misfire-grace filters compose generic then schedule-specific before classification.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_misfire_grace_filters_run_generic_then_schedule_specific_before_classification(): void {
		$now    = \time();
		$clock  = new FixedClock( $now );
		$logger = new RecordingLogger();
		$this->expect_option( ScheduleRegistry::option_name( self::FILTER_OWNER ) );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::FILTER_JOB_IDENTITY );
		$engine = $this->build_engine( $clock, $logger );
		$job    = new RecordingJob( self::FILTER_JOB );
		$this->register_deterministic_job( Identity::compose( self::FILTER_OWNER, self::FILTER_JOB ), $job );
		$schedule = new Schedule( self::FILTER_SCHEDULE, Recurrence::every( self::INTERVAL ), self::FILTER_JOB, catch_up: CatchUpPolicy::Skip );
		$this->assert_sync_success( $engine->schedules, self::FILTER_OWNER, array( $schedule ) );

		$due = $now - 2 * \MINUTE_IN_SECONDS;
		$this->set_next_due( self::FILTER_OWNER, self::FILTER_SCHEDULE, $due );
		$observations    = array();
		$generic_filter  = static function ( int $grace, string $owner, string $identity ) use ( &$observations ): int {
			$observations[] = array( 'generic', $grace, $owner, $identity );

			return 0;
		};
		$specific_filter = static function ( int $grace, string $owner, string $identity ) use ( &$observations ): int {
			$observations[] = array( 'specific', $grace, $owner, $identity );

			return self::INTERVAL;
		};
		\add_filter( 'a8csp_bgje/misfire_grace', $generic_filter, 10, 3 );
		\add_filter( 'a8csp_bgje/misfire_grace/' . self::FILTER_SCHEDULE_IDENTITY, $specific_filter, 10, 3 );

		try {
			\do_action( 'a8csp_bgje/internal/schedule_due', self::FILTER_SCHEDULE_IDENTITY );
		} finally {
			\remove_filter( 'a8csp_bgje/misfire_grace', $generic_filter, 10 );
			\remove_filter( 'a8csp_bgje/misfire_grace/' . self::FILTER_SCHEDULE_IDENTITY, $specific_filter, 10 );
		}

		self::assertSame(
			array(
				array( 'generic', self::INTERVAL, self::FILTER_OWNER, self::FILTER_SCHEDULE_IDENTITY ),
				array( 'specific', 0, self::FILTER_OWNER, self::FILTER_SCHEDULE_IDENTITY ),
			),
			$observations,
			'The schedule-specific filter must receive and override the generic filter result'
		);
		self::assertSame( 1, $this->run_next_due_action(), 'The final schedule-specific grace must admit the occurrence that the generic zero grace would skip' );
		self::assertSame( array( array() ), $job->calls, 'The schedule-specific grace result must decide misfire classification' );
		$registration = $this->registration( self::FILTER_OWNER, self::FILTER_SCHEDULE );
		self::assertSame( $now, $registration['last_fired'] );
		self::assertSame( 0, $registration['misfire_skips'] );
	}

	/**
	 * RunOnce dispatches one late occurrence and realigns its next due instant to the recurrence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_once_executes_one_late_occurrence_and_realigns_recurrence(): void {
		$now    = \time();
		$clock  = new FixedClock( $now );
		$logger = new RecordingLogger();
		$this->expect_option( ScheduleRegistry::option_name( self::RUN_ONCE_OWNER ) );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::RUN_ONCE_JOB_IDENTITY );
		$engine = $this->build_engine( $clock, $logger );
		$job    = new RecordingJob( self::RUN_ONCE_JOB );
		$this->register_deterministic_job( Identity::compose( self::RUN_ONCE_OWNER, self::RUN_ONCE_JOB ), $job );
		$schedule = new Schedule( self::RUN_ONCE_SCHEDULE, Recurrence::every( self::INTERVAL ), self::RUN_ONCE_JOB, array( 'policy' => 'run-once' ) );
		$this->assert_sync_success( $engine->schedules, self::RUN_ONCE_OWNER, array( $schedule ) );

		$aged_due = $now - 3 * self::INTERVAL - 1;
		$this->set_next_due( self::RUN_ONCE_OWNER, self::RUN_ONCE_SCHEDULE, $aged_due );
		$dynamic_misfire_skips = array();
		$generic_misfire_skips = array();
		$this->record_misfire_skipped_hooks( self::RUN_ONCE_SCHEDULE_IDENTITY, $dynamic_misfire_skips, $generic_misfire_skips );

		\do_action( 'a8csp_bgje/internal/schedule_due', self::RUN_ONCE_SCHEDULE_IDENTITY );
		self::assertSame( array(), $job->calls, 'RunOnce must enqueue the make-up occurrence instead of invoking the job inline' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute the single RunOnce make-up occurrence' );

		self::assertSame( array( array( 'policy' => 'run-once' ) ), $job->calls, 'RunOnce must execute exactly one make-up occurrence' );
		self::assertSame( array(), $dynamic_misfire_skips, 'RunOnce must not publish the dynamic misfire-skipped hook' );
		self::assertSame( array(), $generic_misfire_skips, 'RunOnce must not publish the generic misfire-skipped hook' );
		$registration = $this->registration( self::RUN_ONCE_OWNER, self::RUN_ONCE_SCHEDULE );
		self::assertSame( $now, $registration['last_fired'] );
		self::assertSame( 0, $registration['misfire_skips'] );
		self::assertSame( 0, $registration['overlap_skips'], 'A misfire outcome must not touch the disjoint overlap-skip counter' );
		self::assertSame( self::realigned_due( $aged_due, $now ), $registration['next_due'] );
	}

	/**
	 * Skip drops one beyond-grace occurrence and publishes both documented hook payloads.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_skip_drops_a_late_occurrence_and_records_the_misfire(): void {
		$now    = \time();
		$clock  = new FixedClock( $now );
		$logger = new RecordingLogger();
		$this->expect_option( ScheduleRegistry::option_name( self::SKIP_OWNER ) );
		$engine = $this->build_engine( $clock, $logger );
		$job    = new RecordingJob( self::SKIP_JOB );
		$this->register_deterministic_job( Identity::compose( self::SKIP_OWNER, self::SKIP_JOB ), $job );
		$schedule = new Schedule( self::SKIP_SCHEDULE, Recurrence::every( self::INTERVAL ), self::SKIP_JOB, array( 'policy' => 'skip' ), CatchUpPolicy::Skip );
		$this->assert_sync_success( $engine->schedules, self::SKIP_OWNER, array( $schedule ) );

		$aged_due = $now - 3 * self::INTERVAL - 1;
		$this->set_next_due( self::SKIP_OWNER, self::SKIP_SCHEDULE, $aged_due );
		$dynamic_misfire_skips = array();
		$generic_misfire_skips = array();
		$this->record_misfire_skipped_hooks( self::SKIP_SCHEDULE_IDENTITY, $dynamic_misfire_skips, $generic_misfire_skips );

		\do_action( 'a8csp_bgje/internal/schedule_due', self::SKIP_SCHEDULE_IDENTITY );

		self::assertSame( 0, $this->run_next_due_action(), 'Skip must not enqueue a target-job action for the dropped occurrence' );
		self::assertSame( array(), $job->calls, 'Skip must not execute a job for the dropped occurrence' );
		self::assertSame( array( array( self::SKIP_OWNER, $aged_due, $now ) ), $dynamic_misfire_skips, 'The dynamic misfire-skipped hook must receive owner, due instant, and fired instant' );
		self::assertSame( array( array( self::SKIP_SCHEDULE_IDENTITY, self::SKIP_OWNER, $aged_due, $now ) ), $generic_misfire_skips, 'The generic misfire-skipped hook must prepend the complete schedule identity to the same payload' );
		$expected_due = self::realigned_due( $aged_due, $now );
		$registration = $this->registration( self::SKIP_OWNER, self::SKIP_SCHEDULE );
		self::assertNull( $registration['last_fired'] );
		self::assertSame( 1, $registration['misfire_skips'] );
		self::assertSame( 0, $registration['overlap_skips'], 'A dropped misfire must count as a misfire, never as an overlap skip' );
		self::assertSame( $expected_due, $registration['next_due'] );
		self::assertCount( 1, $logger->records );
		self::assertSame( 'info', $logger->records[0]['level'] ?? null );
		self::assertSame(
			array(
				'owner'             => self::SKIP_OWNER,
				'schedule_identity' => self::SKIP_OWNER . ':' . self::SKIP_SCHEDULE,
				'next_due'          => $expected_due,
				'fired_at'          => $now,
			),
			$logger->records[0]['context'] ?? null,
			'The Skip misfire log must carry the dropped occurrence and aligned successor as structured context'
		);
	}

	/**
	 * Skip executes exactly-at-grace and drops the occurrence one second beyond grace.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_misfire_grace_boundary_is_strictly_greater_than(): void {
		$now    = \time();
		$clock  = new FixedClock( $now );
		$logger = new RecordingLogger();
		$this->expect_option( ScheduleRegistry::option_name( self::BOUNDARY_OWNER ) );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::EXACT_JOB_IDENTITY );
		$engine     = $this->build_engine( $clock, $logger );
		$exact_job  = new RecordingJob( self::EXACT_JOB );
		$beyond_job = new RecordingJob( self::BEYOND_JOB );
		$this->register_deterministic_job( Identity::compose( self::BOUNDARY_OWNER, self::EXACT_JOB ), $exact_job );
		$this->register_deterministic_job( Identity::compose( self::BOUNDARY_OWNER, self::BEYOND_JOB ), $beyond_job );
		$exact  = new Schedule( self::EXACT_SCHEDULE, Recurrence::every( self::INTERVAL ), self::EXACT_JOB, catch_up: CatchUpPolicy::Skip );
		$beyond = new Schedule( self::BEYOND_SCHEDULE, Recurrence::every( self::INTERVAL ), self::BEYOND_JOB, catch_up: CatchUpPolicy::Skip );
		$this->assert_sync_success( $engine->schedules, self::BOUNDARY_OWNER, array( $exact, $beyond ) );

		$exact_due  = $now - self::INTERVAL;
		$beyond_due = $exact_due - 1;
		$this->set_next_due( self::BOUNDARY_OWNER, self::EXACT_SCHEDULE, $exact_due );
		$this->set_next_due( self::BOUNDARY_OWNER, self::BEYOND_SCHEDULE, $beyond_due );
		$exact_dynamic  = array();
		$exact_generic  = array();
		$beyond_dynamic = array();
		$beyond_generic = array();
		$this->record_misfire_skipped_hooks( self::EXACT_SCHEDULE_IDENTITY, $exact_dynamic, $exact_generic );
		$this->record_misfire_skipped_hooks( self::BEYOND_SCHEDULE_IDENTITY, $beyond_dynamic, $beyond_generic );

		\do_action( 'a8csp_bgje/internal/schedule_due', self::EXACT_SCHEDULE_IDENTITY );
		self::assertSame( 1, $this->run_next_due_action(), 'An occurrence exactly at grace must execute normally' );
		\do_action( 'a8csp_bgje/internal/schedule_due', self::BEYOND_SCHEDULE_IDENTITY );
		self::assertSame( 0, $this->run_next_due_action(), 'An occurrence one second beyond grace must be dropped' );

		self::assertSame( array( array() ), $exact_job->calls, 'Exactly-at-grace must remain a due job occurrence' );
		self::assertSame( array(), $beyond_job->calls, 'One-second-beyond must not execute the target job' );
		self::assertSame( array(), $exact_dynamic, 'Exactly-at-grace must not fire the dynamic misfire-skipped hook' );
		self::assertSame( array(), $exact_generic, 'Exactly-at-grace must not fire the generic misfire-skipped hook' );
		self::assertSame( array( array( self::BOUNDARY_OWNER, $beyond_due, $now ) ), $beyond_dynamic, 'One-second-beyond must fire the dynamic misfire-skipped hook' );
		self::assertSame( array( array( self::BEYOND_SCHEDULE_IDENTITY, self::BOUNDARY_OWNER, $beyond_due, $now ) ), $beyond_generic, 'One-second-beyond must fire the generic misfire-skipped hook' );
		$exact_registration  = $this->registration( self::BOUNDARY_OWNER, self::EXACT_SCHEDULE );
		$beyond_registration = $this->registration( self::BOUNDARY_OWNER, self::BEYOND_SCHEDULE );
		self::assertSame( 0, $exact_registration['misfire_skips'] );
		self::assertSame( 0, $exact_registration['overlap_skips'] );
		self::assertSame( $now, $exact_registration['last_fired'] );
		self::assertSame( $now + self::INTERVAL, $exact_registration['next_due'] );
		self::assertSame( 1, $beyond_registration['misfire_skips'] );
		self::assertSame( 0, $beyond_registration['overlap_skips'] );
		self::assertNull( $beyond_registration['last_fired'] );
		self::assertSame( $now + self::INTERVAL - 1, $beyond_registration['next_due'] );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Registers one job in the deterministic graph.
	 *
	 * @param   Identity      $identity Complete owner-qualified job identity.
	 * @param   RecordingJob $job     Job to register.
	 *
	 * @return  void
	 */
	private function register_deterministic_job( Identity $identity, RecordingJob $job ): void {
		$job_registry = $this->deterministic_job_registry;
		self::assertNotNull( $job_registry );
		$job_registry->register( $identity, $job->definition() );
	}

	/**
	 * Builds a live engine graph with deterministic time and observation-only logger seams.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   FixedClock      $clock  Deterministic current instant.
	 * @param   RecordingLogger $logger Recorded engine log sink.
	 *
	 * @return  EngineFacade
	 */
	private function build_engine( FixedClock $clock, RecordingLogger $logger ): EngineFacade {
		global $wpdb;

		self::assertInstanceOf( \wpdb::class, $wpdb );
		$rows                 = new OptionRows( $wpdb );
		$job_registry         = new JobRegistry();
		$schedule_registry    = new ScheduleRegistry( $rows, $logger );
		$randomizer           = new RecordingRandomizer( 42 );
		$locks                = new OptionRows( $wpdb );
		$guard                = new OverlapGuard( $clock, $logger, $locks );
		$overlap_identity     = new OverlapIdentity();
		$stores               = new StoreFactory( $clock, $rows, $logger );
		$lock_windows         = new LockWindows( $clock, $logger );
		$terminal_effects     = new LifecycleEffects( $guard, $stores, $logger );
		$terminal_transitions = new RunTransitions( $guard, $stores, $clock, $lock_windows, $logger, $terminal_effects );
		$scheduler            = new SchedulerFacade(
			array(
				new ActionSchedulerBackend(),
				new WPCronBackend(),
			)
		);
		$delivery_scheduler   = new DeliveryScheduler( $scheduler, $clock );
		$failure_lifecycle    = new FailureLifecycle( $delivery_scheduler, $clock, $randomizer, $logger, $terminal_transitions, $terminal_effects );
		$job_handler          = new JobKindHandler( $job_registry, $logger, $clock, $lock_windows, $terminal_transitions, $terminal_effects, $failure_lifecycle );
		$chunked_job_handler  = new ChunkedJobKindHandler( $job_registry, $delivery_scheduler, $logger, $clock, $lock_windows, $terminal_transitions, $terminal_effects, $failure_lifecycle );
		$handlers             = array(
			$job_handler->key()         => $job_handler,
			$chunked_job_handler->key() => $chunked_job_handler,
		);
		$action_deliveries    = new ActionDeliveries( $handlers, $stores, $terminal_transitions );
		$dispatcher           = new Dispatcher( $job_registry, $handlers, $scheduler, $delivery_scheduler, $guard, $overlap_identity, $stores, $clock, $randomizer, $logger, $lock_windows, $terminal_transitions );
		$reconciliation       = new RunReconciliation( $guard, $stores, $clock, $logger, $lock_windows, $terminal_transitions, $terminal_effects, $handlers, $delivery_scheduler );
		$occurrence_lease     = new OccurrenceLease( $locks, $clock, $randomizer );
		$cleanup_intents      = new CleanupIntents( $schedule_registry, $scheduler, $rows, $clock, $logger );
		$occurrence_delivery  = new OccurrenceDelivery( $schedule_registry, $dispatcher, $occurrence_lease, $cleanup_intents, $clock, $logger );
		$schedules            = new ScheduleOperations( $schedule_registry, $scheduler, $clock, $occurrence_delivery );
		$inspection           = new Inspection( $schedule_registry, $job_registry, $handlers, $scheduler, $guard, $overlap_identity, $stores, $rows, $lock_windows, $clock );
		$engine               = new EngineFacade( $schedules, $dispatcher );

		$this->deterministic_inspection   = $inspection;
		$this->deterministic_registry     = $schedule_registry;
		$this->deterministic_job_registry = $job_registry;

		\remove_all_actions( 'a8csp_bgje/internal/deliver' );
		\remove_all_actions( 'a8csp_bgje/internal/schedule_due' );
		$scheduler->register_hooks();
		$action_deliveries->register_hooks();
		$occurrence_delivery->register_hooks();

		return $engine;
	}

	/**
	 * Asserts an internal deterministic schedule synchronization succeeds.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ScheduleOperations $schedules Schedule API.
	 * @param   string             $owner     Stable owner.
	 * @param   array<Schedule>    $declared  Complete owner declaration.
	 *
	 * @return  void
	 */
	private function assert_sync_success( ScheduleOperations $schedules, string $owner, array $declared ): void {
		$declarations = array();
		foreach ( $declared as $schedule ) {
			$identity                           = Identity::compose( $owner, $schedule->name );
			$declarations[ (string) $identity ] = array(
				'schedule' => $schedule,
				'job'      => Identity::compose( $owner, $schedule->job ),
			);
		}

		$result = $schedules->sync( $owner, $declarations );
		self::assertInstanceOf( Success::class, $result, 'The deterministic schedule sync must succeed' );
		self::assertTrue( $result->value );
	}

	/**
	 * Replaces one persisted next-due instant for a sanctioned aging simulation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner    Stable owner.
	 * @param   string $name     Stable schedule name.
	 * @param   int    $next_due Aged due instant.
	 *
	 * @return  void
	 */
	private function set_next_due( string $owner, string $name, int $next_due ): void {
		$registry = $this->deterministic_registry;
		if ( null === $registry ) {
			throw new \LogicException( 'Build the deterministic graph before aging a schedule fixture.' );
		}

		$identity = Identity::compose( $owner, $name );
		$read     = $registry->registration( (string) $identity );
		self::assertInstanceOf( Success::class, $read );
		$registration = $read->value;
		self::assertIsArray( $registration );
		self::assertIsString( $registration['fingerprint'] ?? null );
		$last_fired = $registration['last_fired'] ?? null;
		self::assertTrue( null === $last_fired || \is_int( $last_fired ) );
		self::assertIsInt( $registration['misfire_skips'] ?? null );
		self::assertIsInt( $registration['overlap_skips'] ?? null );
		self::assertIsInt( $registration['undeclared_occurrences'] ?? null );
		self::assertIsBool( $registration['undeclared_escalated'] ?? null );
		$updated = StoreFixtureBuilder::schedule_registration_state(
			$registration['fingerprint'],
			$next_due,
			$last_fired,
			$registration['misfire_skips'],
			$registration['overlap_skips'],
			$registration['undeclared_occurrences'],
			$registration['undeclared_escalated']
		);
		self::assertSame( RegistrationUpdateOutcome::Updated, $registry->update_registration( $identity, $registration['fingerprint'], $updated ) );
	}

	/**
	 * Returns one complete persisted schedule registration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Stable owner.
	 * @param   string $name  Stable schedule name.
	 *
	 * @return  array{
	 *     owner: string,
	 *     identity: string,
	 *     recurrence: int|null,
	 *     next_due: int,
	 *     last_fired: int|null,
	 *     misfire_skips: int,
	 *     overlap_skips: int,
	 *     occurrence_visible: bool,
	 *     lock: array{state: 'free'|'invalid'|'not_declared'|'overlap_allowed'|'read_failed'|'resolver_failed'}|array{state: 'held', run_id: string, stale: bool}
	 * }
	 */
	private function registration( string $owner, string $name ): array {
		$inspection = $this->deterministic_inspection;
		if ( null === $inspection ) {
			throw new \LogicException( 'Build the deterministic graph before inspecting a schedule.' );
		}

		$observed = $inspection->schedules( $owner );
		self::assertIsArray( $observed );
		$registration = \array_find( $observed['entries'], static fn ( array $entry ): bool => $owner . ':' . $name === $entry['identity'] );
		self::assertIsArray( $registration );
		self::assertIsInt( $registration['next_due'] ?? null );
		self::assertTrue( null === ( $registration['last_fired'] ?? null ) || \is_int( $registration['last_fired'] ) );
		self::assertIsInt( $registration['misfire_skips'] ?? null );
		self::assertIsInt( $registration['overlap_skips'] ?? null );

		return $registration;
	}

	/**
	 * Records the dynamic and generic misfire-skipped hook payloads for one schedule.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                                $identity Owner-qualified schedule identity.
	 * @param   list<array{string, int, int}>         $dynamic  Dynamic-hook payloads.
	 * @param   list<array{string, string, int, int}> $generic  Generic-hook payloads.
	 *
	 * @return  void
	 */
	private function record_misfire_skipped_hooks( string $identity, array &$dynamic, array &$generic ): void {
		\add_action(
			'a8csp_bgje/misfire_skipped/' . $identity,
			static function ( string $owner, int $due, int $fired_at ) use ( &$dynamic ): void {
				$dynamic[] = array( $owner, $due, $fired_at );
			},
			10,
			3
		);
		\add_action(
			'a8csp_bgje/misfire_skipped',
			static function ( string $schedule, string $owner, int $due, int $fired_at ) use ( $identity, &$generic ): void {
				if ( $schedule === $identity ) {
					$generic[] = array( $schedule, $owner, $due, $fired_at );
				}
			},
			10,
			4
		);
	}

	/**
	 * Returns the first due instant strictly after the deterministic current time.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $next_due Aged due instant.
	 * @param   int $now      Deterministic current time.
	 *
	 * @return  int
	 */
	private static function realigned_due( int $next_due, int $now ): int {
		return $next_due + ( \intdiv( $now - $next_due, self::INTERVAL ) + 1 ) * self::INTERVAL;
	}

	// endregion.
}

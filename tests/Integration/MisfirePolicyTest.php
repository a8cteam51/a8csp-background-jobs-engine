<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Batches;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine;
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
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Tasks;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingRandomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;

/**
 * Verifies fixed-recurrence misfire policy, hook payloads, counters, and the strict grace boundary.
 */
final class MisfirePolicyTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Fixed interval shared by deterministic recurrence probes. */
	private const INTERVAL = 300;

	/** Owner isolated to the RunOnce occurrence. */
	private const RUN_ONCE_OWNER = 'integration-misfire-run-once';

	/** Schedule isolated to the RunOnce occurrence. */
	private const RUN_ONCE_SCHEDULE = 'late-run-once';

	/** Task isolated to the RunOnce occurrence. */
	private const RUN_ONCE_TASK = 'integration-misfire-run-once-task';

	/** Owner isolated to the Skip occurrence. */
	private const SKIP_OWNER = 'integration-misfire-skip';

	/** Schedule isolated to the Skip occurrence. */
	private const SKIP_SCHEDULE = 'late-skip';

	/** Task isolated to the Skip occurrence. */
	private const SKIP_TASK = 'integration-misfire-skip-task';

	/** Owner isolated to the grace-boundary occurrences. */
	private const BOUNDARY_OWNER = 'integration-misfire-boundary';

	/** Schedule exactly at the grace boundary. */
	private const EXACT_SCHEDULE = 'exact-grace';

	/** Task exactly at the grace boundary. */
	private const EXACT_TASK = 'integration-misfire-exact-task';

	/** Schedule one second beyond the grace boundary. */
	private const BEYOND_SCHEDULE = 'beyond-grace';

	/** Task one second beyond the grace boundary. */
	private const BEYOND_TASK = 'integration-misfire-beyond-task';

	// endregion.

	// region TESTS.

	/**
	 * RunOnce dispatches one late occurrence and realigns its next due instant to the recurrence.
	 *
	 * @return  void
	 */
	public function test_run_once_executes_one_late_occurrence_and_realigns_recurrence(): void {
		$now    = \time();
		$clock  = new FixedClock( $now );
		$logger = new RecordingLogger();
		$this->expect_option( 'a8csp_bgte_schedules' );
		$this->expect_option( 'a8csp_bgte_latest_' . self::RUN_ONCE_TASK );
		$engine = $this->build_engine( $clock, $logger );
		$task   = new RecordingTask( self::RUN_ONCE_TASK );
		$engine->tasks()->register( $task );
		$schedule = new Schedule(
			self::RUN_ONCE_SCHEDULE,
			Recurrence::every( self::INTERVAL ),
			self::RUN_ONCE_TASK,
			array( 'policy' => 'run-once' ),
			OverlapPolicy::Skip
		);
		$this->assert_sync_success( $engine->schedules(), self::RUN_ONCE_OWNER, array( $schedule ) );

		$aged_due = $now - 3 * self::INTERVAL - 1;
		$this->set_next_due( self::RUN_ONCE_OWNER, self::RUN_ONCE_SCHEDULE, $aged_due );
		$dynamic_misfires = array();
		$generic_misfires = array();
		$this->record_misfire_hooks( self::RUN_ONCE_SCHEDULE, $dynamic_misfires, $generic_misfires );

		\do_action(
			'a8csp/background_tasks/schedule_due',
			self::RUN_ONCE_OWNER . ':' . self::RUN_ONCE_SCHEDULE
		);
		self::assertSame( array(), $task->calls, 'RunOnce must enqueue the make-up occurrence instead of invoking the task inline' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute the single RunOnce make-up occurrence' );

		self::assertSame(
			array( array( 'policy' => 'run-once' ) ),
			$task->calls,
			'RunOnce must execute exactly one make-up occurrence'
		);
		self::assertSame( array(), $dynamic_misfires, 'RunOnce must not publish the Skip-policy dynamic misfire hook' );
		self::assertSame( array(), $generic_misfires, 'RunOnce must not publish the Skip-policy generic misfire hook' );
		$registration = $this->registration( self::RUN_ONCE_OWNER, self::RUN_ONCE_SCHEDULE );
		self::assertSame( $now, $registration['last_fired'] );
		self::assertSame( 0, $registration['misfires'] );
		self::assertSame( 0, $registration['skips'], 'A misfire outcome must not touch the disjoint overlap-skip counter' );
		self::assertSame( self::realigned_due( $aged_due, $now ), $registration['next_due'] );
	}

	/**
	 * Skip drops one beyond-grace occurrence and publishes both documented hook payloads.
	 *
	 * @return  void
	 */
	public function test_skip_drops_a_late_occurrence_and_records_the_misfire(): void {
		$now    = \time();
		$clock  = new FixedClock( $now );
		$logger = new RecordingLogger();
		$this->expect_option( 'a8csp_bgte_schedules' );
		$engine = $this->build_engine( $clock, $logger );
		$task   = new RecordingTask( self::SKIP_TASK );
		$engine->tasks()->register( $task );
		$schedule = new Schedule(
			self::SKIP_SCHEDULE,
			Recurrence::every( self::INTERVAL ),
			self::SKIP_TASK,
			array( 'policy' => 'skip' ),
			OverlapPolicy::Skip,
			CatchUpPolicy::Skip
		);
		$this->assert_sync_success( $engine->schedules(), self::SKIP_OWNER, array( $schedule ) );

		$aged_due = $now - 3 * self::INTERVAL - 1;
		$this->set_next_due( self::SKIP_OWNER, self::SKIP_SCHEDULE, $aged_due );
		$dynamic_misfires = array();
		$generic_misfires = array();
		$this->record_misfire_hooks( self::SKIP_SCHEDULE, $dynamic_misfires, $generic_misfires );

		\do_action( 'a8csp/background_tasks/schedule_due', self::SKIP_OWNER . ':' . self::SKIP_SCHEDULE );

		self::assertSame( 0, $this->run_next_due_action(), 'Skip must not enqueue a target-task action for the dropped occurrence' );
		self::assertSame( array(), $task->calls, 'Skip must not execute a task for the dropped occurrence' );
		self::assertSame(
			array( array( self::SKIP_OWNER, $aged_due, $now ) ),
			$dynamic_misfires,
			'The dynamic misfire hook must receive owner, due instant, and fired instant'
		);
		self::assertSame(
			array( array( self::SKIP_SCHEDULE, self::SKIP_OWNER, $aged_due, $now ) ),
			$generic_misfires,
			'The generic misfire hook must prepend the schedule name to the same payload'
		);
		$expected_due = self::realigned_due( $aged_due, $now );
		$registration = $this->registration( self::SKIP_OWNER, self::SKIP_SCHEDULE );
		self::assertNull( $registration['last_fired'] );
		self::assertSame( 1, $registration['misfires'] );
		self::assertSame( 0, $registration['skips'], 'A dropped misfire must count as a misfire, never as an overlap skip' );
		self::assertSame( $expected_due, $registration['next_due'] );
		self::assertSame(
			array(
				array(
					'level'   => 'info',
					'message' => 'Misfired schedule occurrence skipped and realigned to its recurrence.',
					'context' => array(
						'owner'    => self::SKIP_OWNER,
						'name'     => self::SKIP_SCHEDULE,
						'next_due' => $expected_due,
						'fired_at' => $now,
					),
				),
			),
			$logger->records,
			'The Skip misfire log must name the dropped occurrence and its aligned successor'
		);
	}

	/**
	 * Skip executes exactly-at-grace and drops the occurrence one second beyond grace.
	 *
	 * @return  void
	 */
	public function test_misfire_grace_boundary_is_strictly_greater_than(): void {
		$now    = \time();
		$clock  = new FixedClock( $now );
		$logger = new RecordingLogger();
		$this->expect_option( 'a8csp_bgte_schedules' );
		$this->expect_option( 'a8csp_bgte_latest_' . self::EXACT_TASK );
		$engine      = $this->build_engine( $clock, $logger );
		$exact_task  = new RecordingTask( self::EXACT_TASK );
		$beyond_task = new RecordingTask( self::BEYOND_TASK );
		$engine->tasks()->register( $exact_task );
		$engine->tasks()->register( $beyond_task );
		$exact  = new Schedule(
			self::EXACT_SCHEDULE,
			Recurrence::every( self::INTERVAL ),
			self::EXACT_TASK,
			catch_up: CatchUpPolicy::Skip
		);
		$beyond = new Schedule(
			self::BEYOND_SCHEDULE,
			Recurrence::every( self::INTERVAL ),
			self::BEYOND_TASK,
			catch_up: CatchUpPolicy::Skip
		);
		$this->assert_sync_success( $engine->schedules(), self::BOUNDARY_OWNER, array( $exact, $beyond ) );

		$exact_due  = $now - self::INTERVAL;
		$beyond_due = $exact_due - 1;
		$this->set_next_due( self::BOUNDARY_OWNER, self::EXACT_SCHEDULE, $exact_due );
		$this->set_next_due( self::BOUNDARY_OWNER, self::BEYOND_SCHEDULE, $beyond_due );
		$exact_dynamic  = array();
		$exact_generic  = array();
		$beyond_dynamic = array();
		$beyond_generic = array();
		$this->record_misfire_hooks( self::EXACT_SCHEDULE, $exact_dynamic, $exact_generic );
		$this->record_misfire_hooks( self::BEYOND_SCHEDULE, $beyond_dynamic, $beyond_generic );

		\do_action( 'a8csp/background_tasks/schedule_due', self::BOUNDARY_OWNER . ':' . self::EXACT_SCHEDULE );
		self::assertSame( 1, $this->run_next_due_action(), 'An occurrence exactly at grace must execute normally' );
		\do_action( 'a8csp/background_tasks/schedule_due', self::BOUNDARY_OWNER . ':' . self::BEYOND_SCHEDULE );
		self::assertSame( 0, $this->run_next_due_action(), 'An occurrence one second beyond grace must be dropped' );

		self::assertSame( array( array() ), $exact_task->calls, 'Exactly-at-grace must remain a due task occurrence' );
		self::assertSame( array(), $beyond_task->calls, 'One-second-beyond must not execute the target task' );
		self::assertSame( array(), $exact_dynamic, 'Exactly-at-grace must not fire the dynamic misfire hook' );
		self::assertSame( array(), $exact_generic, 'Exactly-at-grace must not fire the generic misfire hook' );
		self::assertSame(
			array( array( self::BOUNDARY_OWNER, $beyond_due, $now ) ),
			$beyond_dynamic,
			'One-second-beyond must fire the dynamic misfire hook'
		);
		self::assertSame(
			array( array( self::BEYOND_SCHEDULE, self::BOUNDARY_OWNER, $beyond_due, $now ) ),
			$beyond_generic,
			'One-second-beyond must fire the generic misfire hook'
		);
		$exact_registration  = $this->registration( self::BOUNDARY_OWNER, self::EXACT_SCHEDULE );
		$beyond_registration = $this->registration( self::BOUNDARY_OWNER, self::BEYOND_SCHEDULE );
		self::assertSame( 0, $exact_registration['misfires'] );
		self::assertSame( 0, $exact_registration['skips'] );
		self::assertSame( $now, $exact_registration['last_fired'] );
		self::assertSame( $now + self::INTERVAL, $exact_registration['next_due'] );
		self::assertSame( 1, $beyond_registration['misfires'] );
		self::assertSame( 0, $beyond_registration['skips'] );
		self::assertNull( $beyond_registration['last_fired'] );
		self::assertSame( $now + self::INTERVAL - 1, $beyond_registration['next_due'] );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Builds a live engine graph with deterministic time and observation-only logger seams.
	 *
	 * @param   FixedClock      $clock  Deterministic current instant.
	 * @param   RecordingLogger $logger Recorded engine log sink.
	 *
	 * @return  Engine
	 */
	private function build_engine( FixedClock $clock, RecordingLogger $logger ): Engine {
		global $wpdb;

		self::assertInstanceOf( \wpdb::class, $wpdb );
		$rows         = new OptionRows( $wpdb );
		$tasks        = new TaskRegistry();
		$batches      = new BatchRegistry();
		$scheduler    = $this->scheduler_facade_with_action_scheduler_probe( static fn (): bool => true );
		$locks        = new LockRows( $wpdb );
		$guard        = new OverlapGuard( $clock, $logger, $locks );
		$stores       = new StoreFactory( $clock, $rows );
		$randomizer   = new RecordingRandomizer( 42 );
		$orchestrator = new Orchestrator(
			$tasks,
			$batches,
			$scheduler,
			$guard,
			$stores,
			$logger,
			$clock,
			new LockWindows( $clock ),
			new TerminalTransitions( $guard, $stores, $clock, $logger ),
			$randomizer
		);
		$schedules    = new Schedules(
			new ScheduleRegistry( $rows ),
			$scheduler,
			$clock,
			$orchestrator,
			new OccurrenceLease( $locks, $clock, $randomizer ),
			$logger
		);

		\remove_all_actions( 'a8csp/background_tasks/schedule_due' );
		\remove_all_actions( 'a8csp/background_tasks/run' );
		\add_action( 'a8csp/background_tasks/schedule_due', array( $schedules, 'handle_schedule_due' ), 10, 2 );
		\add_action( 'a8csp/background_tasks/run', array( $orchestrator, 'handle_run_action' ), 10, 4 );

		return new Engine(
			new Tasks( $tasks, $orchestrator ),
			$schedules,
			new Batches( $batches, $orchestrator ),
			$orchestrator
		);
	}

	/**
	 * Asserts a public schedule synchronization succeeds.
	 *
	 * @param   Schedules       $schedules Schedule API.
	 * @param   string          $owner     Stable owner.
	 * @param   array<Schedule> $declared  Complete owner declaration.
	 *
	 * @return  void
	 */
	private function assert_sync_success( Schedules $schedules, string $owner, array $declared ): void {
		$result = $schedules->sync( $owner, $declared );
		self::assertInstanceOf( Success::class, $result, 'Schedule sync must succeed through the public API' );
		self::assertTrue( $result->value );
	}

	/**
	 * Replaces one persisted next-due instant for a sanctioned aging simulation.
	 *
	 * @param   string $owner    Stable owner.
	 * @param   string $name     Stable schedule name.
	 * @param   int    $next_due Aged due instant.
	 *
	 * @return  void
	 */
	private function set_next_due( string $owner, string $name, int $next_due ): void {
		$registry = \get_option( 'a8csp_bgte_schedules', null );
		self::assertIsArray( $registry );
		$owner_registrations = $registry[ $owner ] ?? null;
		self::assertIsArray( $owner_registrations );
		$registration = $owner_registrations[ $name ] ?? null;
		self::assertIsArray( $registration );
		$registration['next_due']     = $next_due;
		$owner_registrations[ $name ] = $registration;
		$registry[ $owner ]           = $owner_registrations;
		self::assertTrue(
			\update_option( 'a8csp_bgte_schedules', $registry, false ),
			'The misfire simulation must persist the manipulated next-due instant'
		);
	}

	/**
	 * Returns one complete persisted schedule registration.
	 *
	 * @param   string $owner Stable owner.
	 * @param   string $name  Stable schedule name.
	 *
	 * @return  array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int}
	 */
	private function registration( string $owner, string $name ): array {
		$registry = \get_option( 'a8csp_bgte_schedules', null );
		self::assertIsArray( $registry );
		$owner_registrations = $registry[ $owner ] ?? null;
		self::assertIsArray( $owner_registrations );
		$registration = $owner_registrations[ $name ] ?? null;
		self::assertIsArray( $registration );
		self::assertIsString( $registration['fingerprint'] ?? null );
		self::assertIsInt( $registration['next_due'] ?? null );
		self::assertTrue( null === ( $registration['last_fired'] ?? null ) || \is_int( $registration['last_fired'] ) );
		self::assertIsInt( $registration['misfires'] ?? null );
		self::assertIsInt( $registration['skips'] ?? null );

		/** @var array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int} $registration */
		return $registration;
	}

	/**
	 * Records the dynamic and generic misfire hook payloads for one schedule.
	 *
	 * @param   string                                $name    Stable schedule name.
	 * @param   list<array{string, int, int}>         $dynamic Dynamic-hook payloads.
	 * @param   list<array{string, string, int, int}> $generic Generic-hook payloads.
	 *
	 * @return  void
	 */
	private function record_misfire_hooks( string $name, array &$dynamic, array &$generic ): void {
		\add_action(
			'a8csp/background_tasks/misfired/' . $name,
			static function ( string $owner, int $due, int $fired_at ) use ( &$dynamic ): void {
				$dynamic[] = array( $owner, $due, $fired_at );
			},
			10,
			3
		);
		\add_action(
			'a8csp/background_tasks/misfired',
			static function ( string $schedule, string $owner, int $due, int $fired_at ) use ( $name, &$generic ): void {
				if ( $schedule === $name ) {
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

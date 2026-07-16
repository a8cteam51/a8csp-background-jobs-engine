<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Occurrences;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\CleanupIntents;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Detects unsafe native object construction while poisoned storage is inspected.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class ScheduleExecutionWakeupProbe {
	// region FIELDS AND CONSTANTS.

	public static int $wakeups = 0;

	// endregion.

	// region MAGIC METHODS.

	/**
	 * Records an unsafe native object construction.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function __wakeup(): void {
		++self::$wakeups;
	}

	// endregion.
}

/**
 * Exercises schedule occurrence policies through the owner-bound production graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( OccurrenceDelivery::class )]
#[UsesClass( ScheduleRegistry::class )]
final class ScheduleExecutionTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const ARGS             = array(
		'site_id' => 7,
		'mode'    => 'full',
	);
	private const INTERVAL         = 300;
	private const NAME             = 'nightly';
	private const NOW              = 1_700_000_000;
	private const OWNER            = 'owner-a';
	private const REGISTRATION_KEY = self::OWNER . ':' . self::NAME;
	private const TASK             = 'refresh-index';
	private const TASK_IDENTITY    = self::OWNER . ':' . self::TASK;

	private Consumer $consumer;
	private StoreFixtureBuilder $fixtures;
	private EngineRig $rig;
	private RecordingTask $task;

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
	 * Boots one declared task against deterministic production boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig      = EngineRig::set_up( self::NOW );
		$this->consumer = $this->rig->consumer( self::OWNER );
		$this->task     = new RecordingTask( self::TASK );
		$this->consumer->tasks()->register( $this->task );
		$this->fixtures = StoreFixtureBuilder::for_identity( self::TASK_IDENTITY );
		$this->reset_observations();
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

	// region BEHAVIOR.

	/**
	 * A Skip schedule delivered inside grace dispatches normally without a misfire hook.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_within_grace_skip_schedule_dispatches_normally(): void {
		$this->sync_schedule( self::schedule( catch_up: CatchUpPolicy::Skip ) );
		$this->rig->clock()->timestamp = self::NOW + 2 * self::INTERVAL - 1;

		$this->rig->run_due();

		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_background_tasks/started/' . self::TASK_IDENTITY ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_background_tasks/misfired' ) );
		$registration = $this->registration();
		self::assertSame( self::NOW + 2 * self::INTERVAL, $registration['next_due'] ?? null );
		self::assertSame( 0, $registration['misfires'] ?? null );
	}

	/**
	 * RunOnce makes up one beyond-grace occurrence and realigns without firing misfire hooks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_beyond_grace_run_once_dispatches_one_make_up_run(): void {
		$this->sync_schedule( self::schedule( catch_up: CatchUpPolicy::RunOnce ) );
		$fired_at                      = self::NOW + self::INTERVAL + 901;
		$this->rig->clock()->timestamp = $fired_at;

		$this->rig->run_due();

		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_background_tasks/started/' . self::TASK_IDENTITY ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_background_tasks/misfired' ) );
		$registration = $this->registration();
		self::assertSame( self::NOW + 5 * self::INTERVAL, $registration['next_due'] ?? null );
		self::assertSame( $fired_at, $registration['last_fired'] ?? null );
		self::assertSame( 0, $registration['misfires'] ?? null );
	}

	/**
	 * Skip drops one beyond-grace occurrence and publishes both documented misfire hooks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_beyond_grace_skip_publishes_misfire_outcomes(): void {
		$this->sync_schedule( self::schedule( catch_up: CatchUpPolicy::Skip ) );
		$fired_at                      = self::NOW + self::INTERVAL + 901;
		$this->rig->clock()->timestamp = $fired_at;

		$this->rig->run_due();

		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_background_tasks/started/' . self::TASK_IDENTITY ) );
		self::assertSame(
			array( array( self::OWNER, self::NOW + self::INTERVAL, $fired_at ) ),
			$this->rig->hooks()->fired( 'a8csp_background_tasks/misfired/' . self::REGISTRATION_KEY )
		);
		self::assertSame(
			array( array( self::REGISTRATION_KEY, self::OWNER, self::NOW + self::INTERVAL, $fired_at ) ),
			$this->rig->hooks()->fired( 'a8csp_background_tasks/misfired' )
		);
		$registration = $this->registration();
		self::assertSame( self::NOW + 5 * self::INTERVAL, $registration['next_due'] ?? null );
		self::assertNull( $registration['last_fired'] ?? null );
		self::assertSame( 1, $registration['misfires'] ?? null );
	}

	/**
	 * An unreadable registry aborts delivery without retaining scheduler, lease, or cleanup state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_occurrence_aborts_when_the_registry_read_fails(): void {
		$this->sync_schedule( self::schedule() );
		$before = $this->rig->wpdb()->rows;
		$this->rig->wpdb()->before_next( 'select', static function (): void {} );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted occurrence registry read failure';
			}
		);

		\do_action( OccurrenceDelivery::SCHEDULE_HOOK, self::REGISTRATION_KEY );

		self::assertSame( array(), $this->rig->backend()->calls );
		self::assertSame( array(), $this->task->calls );
		self::assertSame( 'scripted occurrence registry read failure', $this->rig->wpdb()->last_error );
		self::assertSame( $before, $this->rig->wpdb()->rows );
		self::assertArrayNotHasKey( OccurrenceLease::OPTION_PREFIX . \hash( 'sha256', self::REGISTRATION_KEY ), $this->rig->wpdb()->rows );
		self::assertArrayNotHasKey( CleanupIntents::OPTION_PREFIX . \hash( 'sha256', self::REGISTRATION_KEY ), $this->rig->wpdb()->rows );
	}

	// endregion.

	// region KEEP GENERATION AND SECURITY MICRO-SUITE.

	/**
	 * A concurrent public sync wins over stale accepted occurrence state.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The replacement lands at the occurrence registry CAS boundary, proving a delivery cannot overwrite a newer definition generation after its backend action is accepted.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_occurrence_state_cannot_overwrite_a_concurrently_synchronized_generation(): void {
		$this->sync_schedule( self::schedule( overlap: OverlapPolicy::Allow ) );
		$replacement     = self::schedule( interval: 600, overlap: OverlapPolicy::Allow );
		$replacement_raw = null;
		$this->rig->wpdb()->before_next(
			'update',
			function ( WpdbLockSpy $wpdb ) use ( $replacement, &$replacement_raw ): void {
				self::assertInstanceOf( Success::class, $this->consumer->schedules()->sync( array( $replacement ) ) );
				$replacement_raw = $wpdb->rows[ ScheduleRegistry::OPTION_NAME ] ?? null;
				self::assertIsString( $replacement_raw );
			}
		);
		$this->rig->clock()->timestamp = self::NOW + self::INTERVAL;

		$this->rig->run_due();

		self::assertIsString( $replacement_raw );
		self::assertSame( $replacement_raw, $this->rig->wpdb()->rows[ ScheduleRegistry::OPTION_NAME ] ?? null );
		self::assertSame( 600, $this->registration()['recurrence'] ?? null );
		self::assertContains( 'enqueue_async', \array_column( $this->rig->backend()->calls, 'verb' ) );
		self::assertSame( 'Schedule registration superseded concurrently; delivery state discarded.', $this->rig->logger()->records[0]['message'] ?? null );
	}

	/**
	 * A stale request declaration cannot dispatch a newer persisted registration generation.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Production-built replacement bytes retain a newer fingerprint while the request keeps its original declaration, proving delivery fences the registry generation before task admission.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_stale_declaration_does_not_dispatch_a_replaced_registry_generation(): void {
		$original    = self::schedule();
		$replacement = self::schedule( interval: 600 );
		$this->sync_schedule( $original );
		$fixture = $this->fixtures->schedule_registry(
			array(
				self::owner_fixture( $replacement, self::NOW + 600 ),
			)
		);
		$this->rig->wpdb()->put( $fixture[0], $fixture[1] );
		$this->rig->clock()->timestamp = self::NOW + 600;

		$this->rig->run_due();

		self::assertSame( array(), $this->calls( 'enqueue_async' ) );
		self::assertSame( $fixture[1], $this->rig->wpdb()->rows[ ScheduleRegistry::OPTION_NAME ] ?? null );
		self::assertSame( 'Stale request schedule declaration does not match the persisted registration; leave the occurrence for a current request.', $this->rig->logger()->records[0]['message'] ?? null );
	}

	/**
	 * A production-serialized incumbent lock generation causes a benign Skip outcome.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built lock and latest-pointer rows prove the occurrence observes one coherent incumbent generation instead of a hand-authored approximation of private storage.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_skip_policy_respects_a_fixture_built_lock_generation(): void {
		$this->sync_schedule( self::schedule( overlap: OverlapPolicy::Skip ) );
		$args_hash = $this->fixtures->args_hash( self::ARGS );
		$this->put_fixture( $this->fixtures->lock( $args_hash, 'run-incumbent', self::NOW, self::NOW ) );
		$this->put_fixture(
			$this->fixtures->latest(
				array(
					array(
						'run_id'    => 'run-incumbent',
						'args_hash' => $args_hash,
					),
				)
			)
		);
		$this->rig->clock()->timestamp = self::NOW + self::INTERVAL;

		$this->rig->run_due();

		self::assertSame( array(), $this->calls( 'enqueue_async' ) );
		self::assertSame( 1, $this->registration()['skips'] ?? null );
		self::assertSame( self::NOW + 2 * self::INTERVAL, $this->registration()['next_due'] ?? null );
		self::assertSame( 'Schedule occurrence skipped because the target task lock is held.', $this->rig->logger()->records[0]['message'] ?? null );
	}

	/**
	 * A poisoned lock row is recovered without constructing its serialized class.
	 *
	 * @load-bearing security
	 * @pin-rationale The deliberately corrupt row bypasses production serialization and places an object at the task-lock boundary, proving occurrence admission neither runs wakeup code nor treats poison as an incumbent generation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_poisoned_lock_row_is_tolerated_without_constructing_classes(): void {
		$this->sync_schedule( self::schedule( overlap: OverlapPolicy::Skip ) );
		$args_hash = $this->fixtures->args_hash( self::ARGS );
		$raw       = \maybe_serialize( new ScheduleExecutionWakeupProbe() );
		self::assertIsString( $raw );
		$lock_fixture = $this->fixtures->lock( $args_hash, 'poisoned-row-key', self::NOW, self::NOW );
		$this->rig->wpdb()->put( $lock_fixture[0], $raw );
		ScheduleExecutionWakeupProbe::$wakeups = 0;
		$this->rig->clock()->timestamp         = self::NOW + self::INTERVAL;

		$this->rig->run_due();

		self::assertSame( 0, ScheduleExecutionWakeupProbe::$wakeups );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_background_tasks/started/' . self::TASK_IDENTITY ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns one schedule declaration for the requested policies.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int           $interval Recurrence interval.
	 * @param   OverlapPolicy $overlap  Overlap policy.
	 * @param   CatchUpPolicy $catch_up Catch-up policy.
	 *
	 * @return  Schedule
	 */
	private static function schedule( int $interval = self::INTERVAL, OverlapPolicy $overlap = OverlapPolicy::Skip, CatchUpPolicy $catch_up = CatchUpPolicy::RunOnce ): Schedule {
		return new Schedule( self::NAME, Recurrence::every( $interval ), self::TASK, self::ARGS, $overlap, $catch_up, 23 );
	}

	/**
	 * Synchronizes one declaration and clears setup observations.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Schedule $schedule Schedule declaration.
	 *
	 * @return  void
	 */
	private function sync_schedule( Schedule $schedule ): void {
		self::assertInstanceOf( Success::class, $this->consumer->schedules()->sync( array( $schedule ) ) );
		$this->reset_observations();
	}

	/**
	 * Returns one complete owner fixture request.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Schedule $schedule Schedule declaration.
	 * @param   int      $next_due Next occurrence timestamp.
	 *
	 * @return  array{owner: string, declarations: array<string, array{schedule: Schedule, task: string}>, registrations: array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int}>}
	 */
	private static function owner_fixture( Schedule $schedule, int $next_due ): array {
		return array(
			'owner'         => self::OWNER,
			'declarations'  => array(
				self::REGISTRATION_KEY => array(
					'schedule' => $schedule,
					'task'     => self::TASK_IDENTITY,
				),
			),
			'registrations' => array(
				self::REGISTRATION_KEY => array(
					'fingerprint' => $schedule->fingerprint(),
					'next_due'    => $next_due,
					'last_fired'  => null,
					'misfires'    => 0,
					'skips'       => 0,
				),
			),
		);
	}

	/**
	 * Returns the persisted registration through production inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, mixed>
	 */
	private function registration(): array {
		$snapshot = $this->rig->inspection()->schedules( self::OWNER );
		self::assertNotNull( $snapshot );
		$entry = $snapshot['entries'][0] ?? null;
		self::assertIsArray( $entry );

		return $entry;
	}

	/**
	 * Returns primary-backend calls for one verb.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $verb Backend verb.
	 *
	 * @return  list<array{verb: string, args: array<string, mixed>}>
	 */
	private function calls( string $verb ): array {
		return \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => $verb === $call['verb'] ) );
	}

	/**
	 * Stores one production-built raw fixture in the active graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{string, string} $fixture Option name and raw value.
	 *
	 * @return  void
	 */
	private function put_fixture( array $fixture ): void {
		$this->rig->wpdb()->put( $fixture[0], $fixture[1] );
	}

	/**
	 * Clears behavioral observations without changing accepted deliveries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function reset_observations(): void {
		$this->rig->backend()->calls  = array();
		$this->rig->logger()->records = array();
	}

	// endregion.
}

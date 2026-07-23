<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Occurrences;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Client;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Occurrences\CleanupIntents;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Occurrences\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Occurrences\OccurrenceLease;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Occurrences\OwnerReplacementOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

	private const array ARGS              = array(
		'site_id' => 7,
		'mode'    => 'full',
	);
	private const int ANCHOR              = 50;
	private const string CHUNKED_JOB      = 'refresh-index-chunked';
	private const string CHUNKED_IDENTITY = self::OWNER . ':' . self::CHUNKED_JOB;
	private const int INTERVAL            = 300;
	private const string NAME             = 'nightly';
	private const int NOW                 = 1_700_000_000;
	private const string OWNER            = 'owner-a';
	private const string REGISTRATION_KEY = self::OWNER . ':' . self::NAME;
	private const string JOB              = 'refresh-index';
	private const string JOB_IDENTITY     = self::OWNER . ':' . self::JOB;

	private Client $client;
	private RecordingChunkedJob $chunked_job;
	private StoreFixtureBuilder $chunked_fixtures;
	private StoreFixtureBuilder $fixtures;
	private EngineRig $rig;
	private RecordingJob $job;

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
	 * Boots job execution fixtures against deterministic production boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig              = EngineRig::set_up( self::NOW );
		$this->client           = $this->rig->client( self::OWNER );
		$this->job              = new RecordingJob( self::JOB );
		$this->chunked_job      = new RecordingChunkedJob( self::CHUNKED_JOB );
		$this->fixtures         = StoreFixtureBuilder::for_identity( self::JOB_IDENTITY );
		$this->chunked_fixtures = StoreFixtureBuilder::for_identity( self::CHUNKED_IDENTITY );
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
	 * A Skip schedule delivered inside grace dispatches normally without a misfire-skipped hook.
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

		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_jobs_engine/started/' . self::JOB_IDENTITY ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/misfire_skipped' ) );
		$registration = $this->registration();
		self::assertSame( self::NOW + 2 * self::INTERVAL, $registration['next_due'] ?? null );
		self::assertSame( 0, $registration['misfire_skips'] ?? null );
	}

	/**
	 * RunOnce makes up one beyond-grace occurrence and realigns without firing misfire-skipped hooks.
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

		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_jobs_engine/started/' . self::JOB_IDENTITY ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/misfire_skipped' ) );
		$registration = $this->registration();
		self::assertSame( self::NOW + 5 * self::INTERVAL, $registration['next_due'] ?? null );
		self::assertSame( $fired_at, $registration['last_fired'] ?? null );
		self::assertSame( 0, $registration['misfire_skips'] ?? null );
	}

	/**
	 * Skip drops one beyond-grace occurrence and publishes both documented misfire-skipped hooks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_beyond_grace_skip_publishes_misfire_skipped_outcomes(): void {
		$this->sync_schedule( self::schedule( catch_up: CatchUpPolicy::Skip ) );
		$fired_at                      = self::NOW + self::INTERVAL + 901;
		$this->rig->clock()->timestamp = $fired_at;

		$this->rig->run_due();

		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/started/' . self::JOB_IDENTITY ) );
		self::assertSame(
			array( array( self::OWNER, self::NOW + self::INTERVAL, $fired_at ) ),
			$this->rig->hooks()->fired( 'a8csp_jobs_engine/misfire_skipped/' . self::REGISTRATION_KEY )
		);
		self::assertSame(
			array( array( self::REGISTRATION_KEY, self::OWNER, self::NOW + self::INTERVAL, $fired_at ) ),
			$this->rig->hooks()->fired( 'a8csp_jobs_engine/misfire_skipped' )
		);
		$registration = $this->registration();
		self::assertSame( self::NOW + 5 * self::INTERVAL, $registration['next_due'] ?? null );
		self::assertNull( $registration['last_fired'] ?? null );
		self::assertSame( 1, $registration['misfire_skips'] ?? null );
	}

	/**
	 * Anchored RunOnce and Skip catch-up retain their UTC phase.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $catch_up               Catch-up policy value.
	 * @param   int    $expected_started       Expected started-hook count.
	 * @param   int    $expected_misfire_skips Expected persisted skip count.
	 *
	 * @return  void
	 */
	#[DataProvider( 'anchored_catch_up_policies' )]
	public function test_anchored_catch_up_preserves_the_utc_phase_grid( string $catch_up, int $expected_started, int $expected_misfire_skips ): void {
		$schedule = new Schedule( self::NAME, Recurrence::every_anchored( self::INTERVAL, self::ANCHOR ), self::JOB, self::ARGS, CatchUpPolicy::from( $catch_up ), 23 );
		$this->sync_schedule( $schedule );

		$first_due = $this->registration()['next_due'] ?? null;
		self::assertIsInt( $first_due );
		self::assertSame( self::ANCHOR, $first_due % self::INTERVAL );

		$this->rig->clock()->timestamp = $first_due + 3 * self::INTERVAL + 1;
		$this->rig->run_due();

		$registration = $this->registration();
		$next_due     = $registration['next_due'] ?? null;
		self::assertIsInt( $next_due );
		self::assertSame( $first_due + 4 * self::INTERVAL, $next_due );
		self::assertSame( self::ANCHOR, $next_due % self::INTERVAL );
		self::assertCount( $expected_started, $this->rig->hooks()->fired( 'a8csp_jobs_engine/started/' . self::JOB_IDENTITY ) );
		self::assertSame( $expected_misfire_skips, $registration['misfire_skips'] ?? null );
	}

	/**
	 * An anchored recurring schedule admits and starts its chunked target without losing UTC phase.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_anchored_recurring_chunked_schedule_runs_end_to_end(): void {
		$schedule = new Schedule( self::NAME, Recurrence::every_anchored( self::INTERVAL, self::ANCHOR ), self::CHUNKED_JOB, self::ARGS, CatchUpPolicy::RunOnce, 23 );
		$this->sync_schedule( $schedule );

		$first_due = $this->registration()['next_due'] ?? null;
		self::assertIsInt( $first_due );
		self::assertSame( self::ANCHOR, $first_due % self::INTERVAL );

		$this->rig->clock()->timestamp = $first_due + 1;
		$this->rig->run_due();

		$registration = $this->registration();
		$next_due     = $registration['next_due'] ?? null;
		self::assertIsInt( $next_due );
		self::assertSame( $first_due + self::INTERVAL, $next_due );
		self::assertSame( self::ANCHOR, $next_due % self::INTERVAL );
		$start_calls = $this->calls( 'enqueue_async' );
		self::assertCount( 1, $start_calls );
		self::assertSame( 'a8csp_jobs_engine/deliver', $start_calls[0]['args']['hook'] ?? null );
		$action_args = $start_calls[0]['args']['args'] ?? null;
		self::assertIsArray( $action_args );
		$run_id = $action_args[1] ?? null;
		self::assertIsString( $run_id );
		$live = $this->rig->inspection()->runs( self::CHUNKED_IDENTITY )['live'];
		self::assertCount( 1, $live );
		self::assertSame( $run_id, $live[0]['run_id'] ?? null );
		self::assertSame( 'chunked_job', $live[0]['kind'] ?? null );

		$this->rig->run_due();

		self::assertSame( array( self::ARGS ), $this->chunked_job->generate_calls );
		$started       = $this->rig->hooks()->fired( 'a8csp_jobs_engine/started/' . self::CHUNKED_IDENTITY );
		$public_run_id = $started[0][0] ?? null;
		self::assertInstanceOf( RunId::class, $public_run_id );
		self::assertSame( $run_id, (string) $public_run_id );
		self::assertSame( array( array( $public_run_id, self::ARGS ) ), $started );
	}

	/**
	 * Supplies both anchored catch-up paths.
	 *
	 * Scalar policy values keep provider discovery independent of the guarded production autoloader.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{catch_up: string, expected_started: int, expected_misfire_skips: int}>
	 */
	public static function anchored_catch_up_policies(): array {
		return array(
			'RunOnce make-up' => array(
				'catch_up'               => 'run_once',
				'expected_started'       => 1,
				'expected_misfire_skips' => 0,
			),
			'Skip drop'       => array(
				'catch_up'               => 'skip',
				'expected_started'       => 0,
				'expected_misfire_skips' => 1,
			),
		);
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
		self::assertSame( array(), $this->job->calls );
		self::assertSame( 'scripted occurrence registry read failure', $this->rig->wpdb()->last_error );
		self::assertSame( $before, $this->rig->wpdb()->rows );
		self::assertArrayNotHasKey( OccurrenceLease::OPTION_PREFIX . \hash( 'sha256', self::REGISTRATION_KEY ), $this->rig->wpdb()->rows );
		self::assertArrayNotHasKey( CleanupIntents::OPTION_PREFIX . \hash( 'sha256', self::REGISTRATION_KEY ), $this->rig->wpdb()->rows );
		self::assertCount( 1, $this->rig->logger()->records );
		self::assertSame( 'warning', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->rig->logger()->records[0]['context']['registration_key'] ?? null );
		$read_error = $this->rig->logger()->records[0]['context']['error'] ?? null;
		self::assertIsString( $read_error );
		self::assertStringContainsString( 'read failed', $read_error );
	}

	/**
	 * A confirmed concurrent occurrence lease is benign delivery contention.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_held_occurrence_lease_logs_debug_and_drops_delivery(): void {
		$this->sync_schedule( self::schedule() );
		$raw = \maybe_serialize(
			array(
				'claim_token' => 'incumbent-claim',
				'claimed_at'  => self::NOW,
			)
		);
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( OccurrenceLease::OPTION_PREFIX . \hash( 'sha256', self::REGISTRATION_KEY ), $raw );

		\do_action( OccurrenceDelivery::SCHEDULE_HOOK, self::REGISTRATION_KEY );

		self::assertSame( array(), $this->rig->backend()->calls );
		self::assertCount( 1, $this->rig->logger()->records );
		self::assertSame( 'debug', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->rig->logger()->records[0]['context']['registration_key'] ?? null );
	}

	/**
	 * An unconfirmed occurrence-lease write reports storage degradation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_occurrence_lease_write_failure_logs_warning_and_drops_delivery(): void {
		$this->sync_schedule( self::schedule() );
		$this->rig->wpdb()->script_result( 'insert', false );

		\do_action( OccurrenceDelivery::SCHEDULE_HOOK, self::REGISTRATION_KEY );

		self::assertSame( array(), $this->rig->backend()->calls );
		self::assertCount( 1, $this->rig->logger()->records );
		self::assertSame( 'warning', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->rig->logger()->records[0]['context']['registration_key'] ?? null );
		self::assertSame( 'write', $this->rig->logger()->records[0]['context']['storage_operation'] ?? null );
	}

	/**
	 * An unconfirmed occurrence-lease read reports storage degradation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_occurrence_lease_read_failure_logs_warning_and_drops_delivery(): void {
		$this->sync_schedule( self::schedule() );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted occurrence lease read failure';
			}
		);

		\do_action( OccurrenceDelivery::SCHEDULE_HOOK, self::REGISTRATION_KEY );

		self::assertSame( array(), $this->rig->backend()->calls );
		self::assertCount( 1, $this->rig->logger()->records );
		self::assertSame( 'warning', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->rig->logger()->records[0]['context']['registration_key'] ?? null );
		self::assertSame( 'read', $this->rig->logger()->records[0]['context']['storage_operation'] ?? null );
	}

	/**
	 * An invalid misfire-grace filter falls back and reports the schedule identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_invalid_misfire_grace_filter_logs_warning(): void {
		$this->sync_schedule( self::schedule() );
		self::assertIsArray( $GLOBALS['a8csp_bgje_test_filter_values'] ?? null );
		$GLOBALS['a8csp_bgje_test_filter_values'][ 'a8csp_jobs_engine/misfire_grace/' . self::REGISTRATION_KEY ] = '300';

		$this->rig->clock()->timestamp = self::NOW + self::INTERVAL;

		$this->rig->run_due();

		self::assertNotEmpty( $this->rig->logger()->records );
		self::assertSame( 'warning', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->rig->logger()->records[0]['context']['name'] ?? null );
		self::assertSame( 'string', $this->rig->logger()->records[0]['context']['returned_type'] ?? null );
		self::assertSame( self::INTERVAL, $this->rig->logger()->records[0]['context']['default_grace'] ?? null );
	}

	/**
	 * An inactive registration warns once after three consecutive recurring deliveries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_inactive_registration_warns_once_after_three_consecutive_deliveries(): void {
		$schedule = self::schedule();
		$this->put_fixture( $this->fixtures->schedule_registration( self::owner_fixture( $schedule, self::NOW + self::INTERVAL ) ) );
		$scheduled = $this->rig->backend()->schedule_recurring( OccurrenceDelivery::SCHEDULE_HOOK, self::INTERVAL, array( self::REGISTRATION_KEY ), self::NOW + self::INTERVAL, self::REGISTRATION_KEY );
		self::assertInstanceOf( Success::class, $scheduled );
		$this->reset_observations();

		$this->rig->run_due();
		$this->rig->run_due();

		self::assertCount( 2, $this->inactive_debug_records() );
		self::assertSame( array(), $this->inactive_warning_records() );

		$this->rig->run_due();

		$warnings = $this->inactive_warning_records();
		self::assertCount( 3, $this->inactive_debug_records() );
		self::assertCount( 1, $warnings );
		$records = $this->rig->logger()->records;
		self::assertNotEmpty( $records );
		$warning_record = \array_pop( $records );
		self::assertIsArray( $warning_record );
		$warning_message = $warning_record['message'] ?? null;
		self::assertIsString( $warning_message );
		self::assertStringContainsString( 'reinstate', $warning_message );
		self::assertStringContainsString( 'sync()', $warning_message );
		self::assertStringContainsString( 'wp background-jobs schedules remove ' . self::OWNER, $warning_message );

		$this->rig->run_due();

		self::assertCount( 4, $this->inactive_debug_records() );
		self::assertCount( 1, $this->inactive_warning_records() );
		self::assertSame( array(), $this->calls( 'enqueue_async' ) );

		$redeclared = self::owner_fixture( $schedule, self::NOW + self::INTERVAL );
		$registry   = new ScheduleRegistry( new OptionRows( $this->rig->wpdb() ), $this->rig->logger() );
		self::assertSame( OwnerReplacementOutcome::Persisted, $registry->replace_owner( self::OWNER, $redeclared['declarations'], $redeclared['registrations'], reset_undeclared_episodes: true ) );
		$this->reset_observations();

		$this->rig->run_due();
		$this->rig->run_due();
		self::assertSame( array(), $this->inactive_warning_records() );

		$this->rig->run_due();
		self::assertCount( 1, $this->inactive_warning_records() );
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
		$this->sync_schedule( self::schedule(), new JobOptions( overlap: OverlapPolicy::Allow ) );
		$replacement     = self::schedule( interval: 600 );
		$replacement_raw = null;
		$this->rig->wpdb()->before_next(
			'update',
			function ( WpdbLockSpy $wpdb ) use ( $replacement, &$replacement_raw ): void {
				self::assertInstanceOf( Success::class, $this->client->schedules()->sync( array( $replacement ) ) );
				$replacement_raw = $wpdb->rows[ ScheduleRegistry::option_name( self::OWNER ) ] ?? null;
				self::assertIsString( $replacement_raw );
			}
		);
		$this->rig->clock()->timestamp = self::NOW + self::INTERVAL;

		$this->rig->run_due();

		self::assertIsString( $replacement_raw );
		self::assertSame( $replacement_raw, $this->rig->wpdb()->rows[ ScheduleRegistry::option_name( self::OWNER ) ] ?? null );
		self::assertSame( 600, $this->registration()['recurrence'] ?? null );
		self::assertContains( 'enqueue_async', \array_column( $this->rig->backend()->calls, 'verb' ) );
		self::assertCount( 1, $this->rig->logger()->records );
		self::assertSame( 'debug', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( self::OWNER, $this->rig->logger()->records[0]['context']['owner'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->rig->logger()->records[0]['context']['registration_key'] ?? null );
	}

	/**
	 * A stale request declaration cannot dispatch a newer persisted registration generation.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Production-built replacement bytes retain a newer fingerprint while the request keeps its original declaration, proving delivery fences the registry generation before job admission.
	 * @fixture StoreFixtureBuilder
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
		$fixture = $this->fixtures->schedule_registration( self::owner_fixture( $replacement, self::NOW + 600 ) );
		$this->rig->wpdb()->put( $fixture[0], $fixture[1] );
		$this->rig->clock()->timestamp = self::NOW + 600;

		$this->rig->run_due();

		self::assertSame( array(), $this->calls( 'enqueue_async' ) );
		self::assertSame( $fixture[1], $this->rig->wpdb()->rows[ ScheduleRegistry::option_name( self::OWNER ) ] ?? null );
		self::assertCount( 1, $this->rig->logger()->records );
		self::assertSame( 'debug', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->rig->logger()->records[0]['context']['registration_key'] ?? null );
	}

	/**
	 * A production-serialized incumbent lock generation causes a benign skipped occurrence.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built lock and latest-pointer rows prove the occurrence observes one coherent incumbent generation instead of a hand-authored approximation of private storage.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_reject_policy_respects_a_fixture_built_lock_generation(): void {
		$this->sync_schedule( self::schedule() );
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
		self::assertSame( 1, $this->registration()['overlap_skips'] ?? null );
		self::assertSame( self::NOW + 2 * self::INTERVAL, $this->registration()['next_due'] ?? null );
		self::assertSame( 'info', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->rig->logger()->records[0]['context']['name'] ?? null );
	}

	/**
	 * A chunked target under a fixture-built Reject lock records one benign skipped occurrence.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The public occurrence path must preserve its accepted cadence while translating the chunked target's authoritative held lock into overlap accounting instead of a hard dispatch failure.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_chunked_reject_target_records_a_soft_overlap_skip(): void {
		$this->sync_schedule(
			new Schedule( self::NAME, Recurrence::every( self::INTERVAL ), self::CHUNKED_JOB, self::ARGS, CatchUpPolicy::RunOnce, 23 ),
			new JobOptions( overlap: OverlapPolicy::Reject )
		);
		$args_hash = $this->chunked_fixtures->args_hash( self::ARGS );
		$this->put_fixture( $this->chunked_fixtures->lock( $args_hash, 'run-incumbent', self::NOW, self::NOW ) );
		$this->put_fixture(
			$this->chunked_fixtures->latest(
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
		self::assertSame( array(), $this->chunked_job->generate_calls );
		self::assertSame( 1, $this->registration()['overlap_skips'] ?? null );
		self::assertSame( self::NOW + 2 * self::INTERVAL, $this->registration()['next_due'] ?? null );
		self::assertSame( 'info', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->rig->logger()->records[0]['context']['name'] ?? null );
	}

	/**
	 * A poisoned lock row is recovered without constructing its serialized class.
	 *
	 * @load-bearing security
	 * @pin-rationale The deliberately corrupt row bypasses production serialization and places an object at the job-lock boundary, proving occurrence admission neither runs wakeup code nor treats poison as an incumbent generation.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_poisoned_lock_row_is_tolerated_without_constructing_classes(): void {
		$this->sync_schedule( self::schedule() );
		$args_hash = $this->fixtures->args_hash( self::ARGS );
		$raw       = \maybe_serialize( new ScheduleExecutionWakeupProbe() );
		self::assertIsString( $raw );
		$lock_fixture = $this->fixtures->lock( $args_hash, 'poisoned-row-key', self::NOW, self::NOW );
		$this->rig->wpdb()->put( $lock_fixture[0], $raw );
		ScheduleExecutionWakeupProbe::$wakeups = 0;
		$this->rig->clock()->timestamp         = self::NOW + self::INTERVAL;

		$this->rig->run_due();

		self::assertSame( 0, ScheduleExecutionWakeupProbe::$wakeups );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_jobs_engine/started/' . self::JOB_IDENTITY ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns one schedule declaration for the requested timing policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int           $interval Recurrence interval.
	 * @param   CatchUpPolicy $catch_up Catch-up policy.
	 *
	 * @return  Schedule
	 */
	private static function schedule( int $interval = self::INTERVAL, CatchUpPolicy $catch_up = CatchUpPolicy::RunOnce ): Schedule {
		return new Schedule( self::NAME, Recurrence::every( $interval ), self::JOB, self::ARGS, $catch_up, 23 );
	}

	/**
	 * Synchronizes one declaration and clears setup observations.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Schedule       $schedule Schedule declaration.
	 * @param   JobOptions|null $options  Optional target policy declaration.
	 *
	 * @return  void
	 */
	private function sync_schedule( Schedule $schedule, ?JobOptions $options = null ): void {
		$execution = match ( $schedule->job ) {
			self::JOB => $this->job,
			self::CHUNKED_JOB => $this->chunked_job,
			default => throw new \LogicException( 'Schedule target has no execution fixture.' ),
		};
		$this->client->jobs()->register( $execution->definition( $options ) );

		self::assertInstanceOf( Success::class, $this->client->schedules()->sync( array( $schedule ) ) );
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
	 * @return  array{owner: string, declarations: array<string, array{schedule: Schedule, job: string}>, registrations: array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int, undeclared_occurrences: int, undeclared_escalated: bool}>}
	 */
	private static function owner_fixture( Schedule $schedule, int $next_due ): array {
		return array(
			'owner'         => self::OWNER,
			'declarations'  => array(
				self::REGISTRATION_KEY => array(
					'schedule' => $schedule,
					'job'      => self::JOB_IDENTITY,
				),
			),
			'registrations' => array( self::REGISTRATION_KEY => StoreFixtureBuilder::schedule_registration_state( $schedule->fingerprint(), $next_due ) ),
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
	 * Returns inactive-registration debug records.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{level: mixed, message: string, context: array<array-key, mixed>}>
	 */
	private function inactive_debug_records(): array {
		return \array_values(
			\array_filter(
				$this->rig->logger()->records,
				static fn ( array $record ): bool => 'debug' === $record['level'] && 'Schedule registration is inactive in this request; leave its recurring occurrence unchanged.' === $record['message']
			)
		);
	}

	/**
	 * Returns zombie-schedule warning records.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{level: mixed, message: string, context: array<array-key, mixed>}>
	 */
	private function inactive_warning_records(): array {
		return \array_values(
			\array_filter(
				$this->rig->logger()->records,
				static fn ( array $record ): bool => 'warning' === $record['level'] && \str_contains( $record['message'], 'wp background-jobs schedules remove' )
			)
		);
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

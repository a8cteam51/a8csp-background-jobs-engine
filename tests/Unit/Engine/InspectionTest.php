<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Engine;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Inspection;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\JobType;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises production inspection over live facades and encoded store fixtures.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Inspection::class )]
final class InspectionTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const int NOW = 1_700_000_000;

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
	 * Boots one deterministic production graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig = EngineRig::set_up( self::NOW );
	}

	/**
	 * Releases request-local engine state after each inspection scenario.
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
	 * Schedule inspection joins a live declaration, an orphaned row, scheduler visibility, and lock state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedules_join_live_declarations_with_persisted_orphans_and_locks(): void {
		$client = $this->rig->client( 'owner-a' );
		$client->jobs()->register( new RecordingJob( 'refresh-index' ) );
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', array( 'scope' => 'all' ) );
		self::assertInstanceOf( Success::class, $client->schedules()->sync( array( $schedule ) ) );
		$fixture = StoreFixtureBuilder::for_identity( 'owner-a:refresh-index' );
		$this->put(
			$fixture->schedule_registration(
				array(
					'owner'         => 'owner-a',
					'declarations'  => array(
						'owner-a:nightly' => array(
							'schedule' => $schedule,
							'job'      => 'owner-a:refresh-index',
						),
					),
					'registrations' => array( 'owner-a:nightly' => StoreFixtureBuilder::schedule_registration_state( $schedule->fingerprint(), self::NOW + 300, self::NOW - 60, 1, 2 ) ),
				)
			)
		);
		$this->put(
			$fixture->schedule_registration(
				array(
					'owner'         => 'owner-b',
					'declarations'  => array(),
					'registrations' => array( 'owner-b:orphaned' => StoreFixtureBuilder::schedule_registration_state( 'orphaned', self::NOW + 600, misfire_skips: 4, overlap_skips: 5 ) ),
				)
			)
		);
		unset( $this->rig->wpdb()->rows[ ScheduleRegistry::option_name( 'a8csp-jobs-engine' ) ] );
		$this->put( $fixture->lock( $fixture->args_hash( $schedule->args ), 'run-lock', self::NOW, self::NOW ) );
		$this->rig->backend()->scheduled = true;

		$snapshot = $this->rig->inspection()->schedules();

		self::assertNotNull( $snapshot );
		self::assertSame( array( 'owner-a:nightly', 'owner-b:orphaned' ), \array_column( $snapshot['entries'], 'name' ) );
		self::assertSame( 300, $snapshot['entries'][0]['recurrence'] );
		self::assertSame(
			array(
				'state'  => 'held',
				'run_id' => 'run-lock',
				'stale'  => false,
			),
			$snapshot['entries'][0]['lock']
		);
		self::assertSame( array( 'state' => 'not_declared' ), $snapshot['entries'][1]['lock'] );
		self::assertTrue( $snapshot['entries'][0]['occurrence_visible'] );
		self::assertSame( array( 'owner-b' ), \array_column( $this->rig->inspection()->schedules( 'owner-b' )['entries'] ?? array(), 'owner' ) );
	}

	/**
	 * Schedule lock inspection preserves every non-held honesty state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedule_locks_preserve_every_discriminated_honesty_state(): void {
		$client        = $this->rig->client( 'owner' );
		$schedules     = array(
			'allow'   => new Schedule( 'allow', Recurrence::every( 300 ), 'allow-job', array( 'case' => 'allow' ), OverlapPolicy::Allow ),
			'failed'  => new Schedule( 'failed', Recurrence::every( 300 ), 'failed-job', array( 'case' => 'failed' ) ),
			'free'    => new Schedule( 'free', Recurrence::every( 300 ), 'free-job', array( 'case' => 'free' ) ),
			'invalid' => new Schedule( 'invalid', Recurrence::every( 300 ), 'invalid-job', array( 'case' => 'invalid' ) ),
		);
		$declarations  = array();
		$registrations = array( 'owner:orphaned' => StoreFixtureBuilder::schedule_registration_state( 'orphaned', self::NOW + 300 ) );
		foreach ( $schedules as $name => $schedule ) {
			$client->jobs()->register( new RecordingJob( $schedule->job ) );
			$declarations[ 'owner:' . $name ]  = array(
				'schedule' => $schedule,
				'job'      => 'owner:' . $schedule->job,
			);
			$registrations[ 'owner:' . $name ] = StoreFixtureBuilder::schedule_registration_state( $schedule->fingerprint(), self::NOW + 300 );
		}
		self::assertInstanceOf( Success::class, $client->schedules()->sync( \array_values( $schedules ) ) );
		$fixture = StoreFixtureBuilder::for_identity( 'owner:invalid-job' );
		$this->put(
			$fixture->schedule_registration(
				array(
					'owner'         => 'owner',
					'declarations'  => $declarations,
					'registrations' => $registrations,
				)
			)
		);
		unset( $this->rig->wpdb()->rows[ ScheduleRegistry::option_name( 'a8csp-jobs-engine' ) ] );
		$this->rig->wpdb()->put( 'a8csp_bgje_overlap_lock_owner:invalid-job_' . $fixture->args_hash( array( 'case' => 'invalid' ) ), 'not-a-lock-row' );
		$this->rig->wpdb()->before_next( 'select', static function (): void {} );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted lock read failure';
			}
		);

		$snapshot = $this->rig->inspection()->schedules();
		self::assertNotNull( $snapshot );
		$locks = \array_column( $snapshot['entries'], 'lock', 'name' );

		self::assertSame( array( 'state' => 'overlap_allowed' ), $locks['owner:allow'] );
		self::assertSame( array( 'state' => 'read_failed' ), $locks['owner:failed'] );
		self::assertSame( array( 'state' => 'free' ), $locks['owner:free'] );
		self::assertSame( array( 'state' => 'invalid' ), $locks['owner:invalid'] );
		self::assertSame( array( 'state' => 'not_declared' ), $locks['owner:orphaned'] );
	}

	/**
	 * Schedule read failure remains unavailable while a dormant backend remains explicit.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedule_unknown_states_do_not_collapse_into_empty_or_absent(): void {
		$this->rig->backend()->ready = false;
		$dormant                     = $this->rig->inspection()->schedules();
		self::assertNotNull( $dormant );
		self::assertTrue( $dormant['dormant_candidate'] );

		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'schedule registry read failed';
			}
		);
		self::assertNull( $this->rig->inspection()->schedules() );
	}

	/**
	 * Live inspection exposes waiting, executing, completed, and strict staleness states.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_public_job_lifecycle_is_visible_with_strict_staleness(): void {
		$identity       = 'owner:email-digest';
		$client         = $this->rig->client( 'owner' );
		$job            = new RecordingJob( 'email-digest' );
		$during         = null;
		$job->on_handle = function () use ( $identity, &$during ): void {
			$during = $this->rig->inspection()->runs( $identity )['live'][0] ?? null;
		};
		$client->jobs()->register( $job );
		self::assertInstanceOf( Success::class, $client->jobs()->enqueue( 'email-digest' ) );

		$waiting = $this->rig->inspection()->runs( $identity )['live'][0];
		self::assertFalse( $waiting['executing'] );
		$this->rig->clock()->timestamp = self::NOW + 15 * \MINUTE_IN_SECONDS;
		self::assertFalse( $this->rig->inspection()->runs( $identity )['live'][0]['stale'] );
		$this->rig->clock()->timestamp = self::NOW + 15 * \MINUTE_IN_SECONDS + 1;
		self::assertTrue( $this->rig->inspection()->runs( $identity )['live'][0]['stale'] );

		$this->rig->run_due();

		self::assertIsArray( $during );
		self::assertTrue( $during['executing'] );
		$terminal = $this->rig->inspection()->runs( $identity );
		self::assertSame( array(), $terminal['live'] );
		self::assertSame( 'completed', $terminal['history'][0]['outcome'] ?? null );
	}

	/**
	 * Valid run and history fixtures retain their data while unreadable live rows remain counted.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_runs_merge_valid_live_history_and_failed_store_rows(): void {
		$identity = 'owner:catalog-sync';
		$this->rig->client( 'owner' )->chunked_jobs()->register( new RecordingChunkedJob( 'catalog-sync' ) );
		$fixtures = StoreFixtureBuilder::for_identity( $identity );
		$live_id  = self::run_id( 1 );
		$this->put( $fixtures->run( $live_id, self::state( 'hash-live', array( array( 'page' => 1 ), array( 'page' => 2 ) ), JobType::ChunkedJob ) ) );
		$this->put(
			$fixtures->history(
				array(
					array(
						'run_id'    => $live_id,
						'args_hash' => 'hash-live',
					),
					array(
						'run_id'    => 'run-completed',
						'args_hash' => 'hash-completed',
					),
				),
				array(
					array(
						'run_id'    => 'run-completed',
						'args_hash' => 'hash-completed',
						'status'    => RunStatus::Completed,
					),
					array(
						'run_id'    => 'run-failed',
						'args_hash' => 'hash-failed',
						'status'    => RunStatus::Failed,
					),
				)
			)
		);
		$failure = new RunFailure( identity: $identity, run_id: 'run-failed', attempts: 2, stage: RunFailureStage::Execution, code: ApiErrorCode::ExecutionFailed, summary: 'Retained failure.', failed_chunk: null );
		$this->put( $fixtures->failed( self::NOW - 1, array(), $failure, new EngineError( 'Retained failure.' ) ) );
		$this->put( $fixtures->unreadable_run( self::run_id( 99 ) ) );

		$snapshot = $this->rig->inspection()->runs( $identity );

		self::assertCount( 1, $snapshot['live'] );
		self::assertSame( 'chunked_job', $snapshot['live'][0]['kind'] );
		self::assertSame( 2, $snapshot['live'][0]['queue_depth'] );
		self::assertSame( 1, $snapshot['live_unreadable'] );
		self::assertSame( array( 'run-failed', 'run-completed', $live_id ), \array_column( $snapshot['history'] ?? array(), 'run_id' ) );
		self::assertTrue( $snapshot['history'][0]['failed_store'] ?? false );
	}

	/**
	 * Persisted work kinds survive absent registrations and cross-kind identity reuse.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_persisted_work_kind_controls_live_run_inspection(): void {
		$orphaned_identity    = 'owner:orphaned';
		$job_identity         = 'owner-a:shared';
		$chunked_job_identity = 'owner-b:shared';
		$this->rig->client( 'owner-a' )->jobs()->register( new RecordingJob( 'shared' ) );
		$this->rig->client( 'owner-b' )->chunked_jobs()->register( new RecordingChunkedJob( 'shared' ) );
		$this->put( StoreFixtureBuilder::for_identity( $orphaned_identity )->run( self::run_id( 1 ), self::state( 'orphaned-hash', array( array( 'page' => 1 ), array( 'page' => 2 ) ), JobType::ChunkedJob ) ) );
		$this->put( StoreFixtureBuilder::for_identity( $job_identity )->run( self::run_id( 2 ), self::state( 'job-hash', array( array( 'page' => 1 ) ), JobType::ChunkedJob ) ) );
		$this->put( StoreFixtureBuilder::for_identity( $chunked_job_identity )->run( self::run_id( 3 ), self::state( 'chunked-job-hash', array( array( 'page' => 1 ) ) ) ) );

		$orphaned    = $this->rig->inspection()->runs( $orphaned_identity )['live'][0];
		$job         = $this->rig->inspection()->runs( $job_identity )['live'][0];
		$chunked_job = $this->rig->inspection()->runs( $chunked_job_identity )['live'][0];

		self::assertSame( 'chunked_job', $orphaned['kind'] );
		self::assertSame( 2, $orphaned['queue_depth'] );
		self::assertSame( 'chunked_job', $job['kind'] );
		self::assertSame( 1, $job['queue_depth'] );
		self::assertSame( 'job', $chunked_job['kind'] );
		self::assertNull( $chunked_job['queue_depth'] );
	}

	/**
	 * Recording order, not lexical run-id order, selects the latest completion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_last_completed_run_id_follows_terminal_recording_order(): void {
		$identity = 'owner:recording-order';
		$fixtures = StoreFixtureBuilder::for_identity( $identity );
		$this->put(
			$fixtures->history(
				terminal: array(
					array(
						'run_id'    => self::run_id( 99 ),
						'args_hash' => 'shared',
						'status'    => RunStatus::Completed,
					),
					array(
						'run_id'    => self::run_id( 1 ),
						'args_hash' => 'shared',
						'status'    => RunStatus::Completed,
					),
					array(
						'run_id'    => self::run_id( 100 ),
						'args_hash' => 'shared',
						'status'    => RunStatus::Failed,
					),
				)
			)
		);

		$result = $this->rig->inspection()->last_completed_run_id( $identity );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::run_id( 1 ), $result->value );
	}

	/**
	 * Malformed and prefix-colliding names cannot occupy the valid-row inspection budget.
	 *
	 * @load-bearing fail-closed-ordering
	 * @pin-rationale Twenty lexically leading malformed candidates and one prefix-colliding identity prove validation precedes the row cap, so corrupt or foreign names cannot hide an authoritative valid run.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_enumeration_validates_identity_before_applying_its_cap(): void {
		$requested = 'owner:foo';
		$foreign   = 'owner:foo_bar';
		$this->put( StoreFixtureBuilder::for_identity( $requested )->run( self::run_id( 1 ), self::state( 'requested' ) ) );
		$this->put( StoreFixtureBuilder::for_identity( $foreign )->run( self::run_id( 2 ), self::state( 'foreign' ) ) );
		for ( $sequence = 1; $sequence <= 20; ++$sequence ) {
			$this->put( StoreFixtureBuilder::for_identity( $requested )->unreadable_run( \sprintf( '!%039d', $sequence ) ) );
		}

		$snapshot = $this->rig->inspection()->runs( $requested );

		self::assertSame( array( self::run_id( 1 ) ), \array_column( $snapshot['live'], 'run_id' ) );
		self::assertSame( 1, $snapshot['live_scanned'] );
		self::assertSame( 0, $snapshot['live_uninspected'] );
		self::assertSame( 20, $snapshot['live_unreadable'] );
	}

	/**
	 * Live inspection terminates at its documented cap and reports the exact remainder.
	 *
	 * @load-bearing bounded-retry-liveness
	 * @pin-rationale Twenty-four production-encoded rows prove the paged scan stops after twenty accepted candidates while exposing four uninspected rows instead of retrying without a bound.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_enumeration_is_bounded_with_an_exact_remainder(): void {
		$identity = 'owner:many-runs';
		$fixtures = StoreFixtureBuilder::for_identity( $identity );
		for ( $sequence = 1; $sequence <= 24; ++$sequence ) {
			$this->put( $fixtures->run( self::run_id( $sequence ), self::state( 'hash-' . $sequence ) ) );
		}

		$snapshot = $this->rig->inspection()->runs( $identity );

		self::assertSame( 20, $snapshot['live_scanned'] );
		self::assertSame( 4, $snapshot['live_uninspected'] );
		self::assertSame( 0, $snapshot['live_unreadable'] );
		self::assertCount( 20, $snapshot['live'] );
	}

	/**
	 * Enumeration, row, and history read failures remain discriminated unknown states.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_read_failures_do_not_collapse_into_absence(): void {
		$this->rig->wpdb()->before_next(
			'scan',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'enumeration failed';
			}
		);
		$enumeration = $this->rig->inspection()->runs( 'owner:enumeration' );
		self::assertSame( 'enumeration_failed', $enumeration['live_error'] );
		self::assertSame( array( 0, 0, 0 ), array( $enumeration['live_scanned'], $enumeration['live_uninspected'], $enumeration['live_unreadable'] ) );

		$identity = 'owner:failed-row';
		$fixtures = StoreFixtureBuilder::for_identity( $identity );
		$this->put( $fixtures->unreadable_run( \sprintf( '!%039d', 1 ) ) );
		$this->put( $fixtures->run( self::run_id( 1 ), self::state( 'hash' ) ) );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'row read failed';
			}
		);
		$row = $this->rig->inspection()->runs( $identity );
		self::assertSame( 'read_failed', $row['live_error'] );
		self::assertSame( array( 1, 0, 1 ), array( $row['live_scanned'], $row['live_uninspected'], $row['live_unreadable'] ) );

		$this->rig->wpdb()->before_next( 'scan', static function (): void {} );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'history read failed';
			}
		);
		self::assertNull( $this->rig->inspection()->runs( 'owner:history' )['history'] );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns one canonical fixed-width run identifier.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $sequence Per-timestamp run sequence.
	 *
	 * @return  string
	 */
	private static function run_id( int $sequence ): string {
		return \sprintf( '%020d-%019d', self::NOW, $sequence );
	}

	/**
	 * Returns one production-valid running state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string             $args_hash Persisted arguments hash.
	 * @param   list<array<mixed>> $queue     Persisted pending queue.
	 * @param   JobType            $kind      Persisted work kind.
	 *
	 * @return  RunState
	 */
	private static function state( string $args_hash, array $queue = array( array() ), JobType $kind = JobType::Job ): RunState {
		return new RunState( status: RunStatus::Running, kind: $kind, executing: false, start_args: array(), args_hash: $args_hash, queue: $queue, failed_attempts: 0, action_sequence: 1, created_at: self::NOW, heartbeat_at: self::NOW );
	}

	/**
	 * Persists one production-encoded option fixture.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{string, string} $fixture Encoded option name and value.
	 *
	 * @return  void
	 */
	private function put( array $fixture ): void {
		$this->rig->wpdb()->put( $fixture[0], $fixture[1] );
	}

	// endregion.
}

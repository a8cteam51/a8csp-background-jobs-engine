<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Schedules;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\OwnerOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\UndeclaredOccurrenceOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises declarative schedule convergence through owner-bound public facades.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( ScheduleOperations::class )]
final class ScheduleOperationsTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const int NOW = 1_700_000_000;

	private OwnerOperations $client_a;
	private OwnerOperations $client_b;
	private StoreFixtureBuilder $fixtures;
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
	 * Boots two owners against one deterministic production graph.
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
		$this->client_a = $this->rig->operations( 'owner-a' );
		$this->client_b = $this->rig->operations( 'owner-b' );
		$this->client_a->register( ( new RecordingJob( 'refresh-index' ) )->definition() );
		$this->client_b->register( ( new RecordingJob( 'refresh-index' ) )->definition() );
		$this->fixtures = StoreFixtureBuilder::for_identity( 'owner-a:refresh-index' );
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
	 * Add, no-op, replacement, and removal converge without crossing owner boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_converges_the_complete_owner_set_and_preserves_siblings(): void {
		$owner_a = self::schedule( 'nightly', 300 );
		$owner_b = self::schedule( 'hourly', 3_600 );

		self::assertInstanceOf( Success::class, $this->client_b->sync( array( $owner_b ) ) );
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( $owner_a ) ) );
		self::assertSame( array( 'owner-a:nightly' ), \array_column( $this->owner_entries( 'owner-a' ), 'name' ) );
		self::assertSame( array( 'owner-b:hourly' ), \array_column( $this->owner_entries( 'owner-b' ), 'name' ) );

		$this->rig->backend()->scheduled = true;
		$this->reset_backend_observations();
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( $owner_a ) ) );
		self::assertSame( array(), $this->write_calls() );

		$this->rig->backend()->scheduled = false;
		$this->reset_backend_observations();
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( self::schedule( 'nightly', 600 ) ) ) );
		self::assertSame( array( 'unschedule', 'schedule_recurring' ), \array_column( $this->write_calls(), 'verb' ) );
		self::assertSame( 600, $this->owner_entries( 'owner-a' )[0]['recurrence'] ?? null );

		$this->reset_backend_observations();
		self::assertInstanceOf( Success::class, $this->client_a->sync( array() ) );
		self::assertSame( array( 'owner-a:nightly' ), \array_map( static fn ( array $call ): mixed => $call['args']['group'] ?? null, $this->write_calls() ) );
		self::assertSame( array(), $this->owner_entries( 'owner-a' ) );
		self::assertSame( array( 'owner-b:hourly' ), \array_column( $this->owner_entries( 'owner-b' ), 'name' ) );
	}

	/**
	 * Unchanged declarations share one bulk backend census per synchronization.
	 *
	 * @load-bearing performance
	 * @pin-rationale Schedule sync runs on every init, so per-declaration backend reads multiply an unbounded Action Scheduler query and WP-Cron scan by the declaration count.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_bulk_counts_unchanged_declarations_once(): void {
		$declarations = array(
			self::schedule( 'nightly', 300 ),
			self::schedule( 'hourly', 3_600 ),
		);
		self::assertInstanceOf( Success::class, $this->client_a->sync( $declarations ) );
		$this->reset_backend_observations();

		$result = $this->client_a->sync( $declarations );

		self::assertInstanceOf( Success::class, $result );
		$calls = $this->calls( 'scheduled_counts' );
		self::assertCount( 1, $calls );
		self::assertSame( OccurrenceDelivery::SCHEDULE_HOOK, $calls[0]['args']['hook'] ?? null );
		self::assertSame( array( 'owner-a:nightly', 'owner-a:hourly' ), $calls[0]['args']['identities'] ?? null );
		self::assertSame( array(), $this->calls( 'scheduled_count' ) );
		self::assertSame( array(), $this->write_calls() );
	}

	/**
	 * A persisted registration without its backend chain is recreated at the retained due instant.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_recreates_a_missing_chain_from_persisted_timing(): void {
		$schedule = self::schedule( 'nightly', 300 );
		$fixture  = $this->fixtures->schedule_registration( self::owner_fixture( $schedule, self::NOW - 60, self::NOW - 360 ) );
		$this->rig->wpdb()->put( $fixture[0], $fixture[1] );

		$result = $this->client_a->sync( array( $schedule ) );

		self::assertInstanceOf( Success::class, $result );
		$calls = $this->calls( 'schedule_recurring' );
		self::assertCount( 1, $calls );
		self::assertSame( self::NOW - 60, $calls[0]['args']['first_run_timestamp'] ?? null );
		self::assertSame( 'owner-a:nightly', $calls[0]['args']['group'] ?? null );
		self::assertSame( $fixture[1], $this->rig->wpdb()->rows[ ScheduleRegistry::option_name( 'owner-a' ) ] ?? null );
	}

	/**
	 * Anchored schedules seed at the first strictly future instant on their UTC phase grid.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_seeds_anchored_schedules_on_their_utc_phase_grid(): void {
		$phase    = new Schedule( 'phase', Recurrence::every_anchored( 300, 50 ), 'refresh-index' );
		$boundary = new Schedule( 'boundary', Recurrence::every_anchored( 300, 200 ), 'refresh-index' );
		$zero     = new Schedule( 'zero', Recurrence::every_anchored( 300, 0 ), 'refresh-index' );
		$future   = new Schedule( 'future', Recurrence::every_anchored( 300, 250 ), 'refresh-index' );

		$result = $this->client_a->sync( array( $phase, $boundary, $zero, $future ) );

		self::assertInstanceOf( Success::class, $result );
		$entries = \array_column( $this->owner_entries( 'owner-a' ), null, 'name' );
		self::assertSame( 1_700_000_150, $entries['owner-a:phase']['next_due'] ?? null );
		self::assertSame( self::NOW + 300, $entries['owner-a:boundary']['next_due'] ?? null );
		self::assertSame( 1_700_000_100, $entries['owner-a:zero']['next_due'] ?? null );
		self::assertSame( self::NOW - ( self::NOW % 300 ) + 250, $entries['owner-a:future']['next_due'] ?? null );
	}

	/**
	 * An anchored seed that exceeds positive Unix seconds fails before backend mutation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_rejects_an_overflowing_anchored_seed(): void {
		$this->rig->clock()->timestamp = \PHP_INT_MAX - 5;
		$anchor                        = 1;
		$schedule                      = new Schedule( 'overflow', Recurrence::every_anchored( 10, $anchor ), 'refresh-index' );

		$result = $this->client_a->sync( array( $schedule ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( BoundaryError::class, $result->error );
		self::assertSame( ErrorCode::PayloadRejected, $result->error->code );
		self::assertSame( array(), $this->write_calls() );
	}

	/**
	 * One sync replaces a same-backend surplus and leaves the repaired chain untouched thereafter.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A duplicate recurring chain has no distinct logical schedule entry, so the backend count plus the exact clear-and-recreate write sequence is the only public-fake evidence that convergence repairs the complete-to-repeat race without churning a healthy chain.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_repairs_same_backend_surplus_then_leaves_one_chain_untouched(): void {
		$schedule = self::schedule( 'nightly', 300 );
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( $schedule ) ) );
		$registration = $this->owner_entries( 'owner-a' )[0];
		$next_due     = $registration['next_due'] ?? null;
		self::assertIsInt( $next_due );
		self::assertInstanceOf( Success::class, $this->rig->backend()->schedule_recurring( OccurrenceDelivery::SCHEDULE_HOOK, 300, array( 'owner-a:nightly' ), $next_due + 300, 'owner-a:nightly', priority: $schedule->priority ) );
		$this->reset_backend_observations();

		$repaired = $this->client_a->sync( array( $schedule ) );

		self::assertInstanceOf( Success::class, $repaired );
		self::assertSame( array( 'unschedule', 'schedule_recurring' ), \array_column( $this->write_calls(), 'verb' ) );
		self::assertSame( $next_due, $this->calls( 'schedule_recurring' )[0]['args']['first_run_timestamp'] ?? null );
		self::assertSame( 1, $this->rig->backend()->scheduled_count( OccurrenceDelivery::SCHEDULE_HOOK, array( 'owner-a:nightly' ), 'owner-a:nightly' ) );
		self::assertSame( $registration, $this->owner_entries( 'owner-a' )[0] );

		$this->reset_backend_observations();
		$healthy = $this->client_a->sync( array( $schedule ) );

		self::assertInstanceOf( Success::class, $healthy );
		self::assertSame( array(), $this->write_calls(), 'A healthy single chain must not be unscheduled or recreated' );
	}

	/**
	 * A failed owner-registry write aborts before a recurring delivery can be accepted.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fail-closed owner replacement must precede schedule_recurring so a storage failure cannot manufacture a backend chain without its registry generation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registry_write_failure_stops_before_recurring_delivery(): void {
		$hourly = self::schedule( 'hourly', 3_600 );
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( $hourly ) ) );
		$before  = $this->raw_registry();
		$backend = $this->rig->backend();
		$this->rig->wpdb()->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $backend ): void {
				$backend->calls = array();
				$wpdb->script_result( 'update', false );
			}
		);

		$result = $this->client_a->sync( array( $hourly, self::schedule( 'nightly', 300 ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( BoundaryError::class, $result->error );
		self::assertSame( ErrorCode::StorageFailed, $result->error->code );
		self::assertSame( array(), $backend->calls );
		$backend->assert_not_scheduled( 'owner-a:nightly' );
		self::assertSame( $before, $this->raw_registry() );
	}

	/**
	 * A failed sync preserves an escalation that wins during its intermediate owner write.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale An undeclared-aging CAS can win while sync adds another schedule; the intermediate replacement must retain that fence when later backend convergence fails.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_sync_preserves_a_concurrent_undeclared_escalation_until_retry_resets_it(): void {
		$nightly = self::schedule( 'nightly', 300 );
		$hourly  = self::schedule( 'hourly', 3_600 );
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( $nightly ) ) );
		$registry = new ScheduleRegistry( new OptionRows( $this->rig->wpdb() ), $this->rig->logger() );
		self::assertSame( UndeclaredOccurrenceOutcome::Recorded, $registry->record_undeclared_occurrence( 'owner-a:nightly', 3 ) );
		self::assertSame( UndeclaredOccurrenceOutcome::Recorded, $registry->record_undeclared_occurrence( 'owner-a:nightly', 3 ) );
		$this->rig->wpdb()->before_next(
			'update',
			static function () use ( $registry ): void {
				self::assertSame( UndeclaredOccurrenceOutcome::Escalated, $registry->record_undeclared_occurrence( 'owner-a:nightly', 3 ) );
			}
		);
		$this->rig->backend()->results['schedule_recurring'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Repair scheduling and retry.' ) );

		$failed = $this->client_a->sync( array( $nightly, $hourly ) );

		self::assertInstanceOf( Failure::class, $failed );
		$escalated = $registry->registration( 'owner-a:nightly' );
		self::assertInstanceOf( Success::class, $escalated );
		self::assertIsArray( $escalated->value );
		self::assertSame( 3, $escalated->value['undeclared_occurrences'] ?? null );
		self::assertTrue( $escalated->value['undeclared_escalated'] ?? false );
		self::assertSame( UndeclaredOccurrenceOutcome::AlreadyEscalated, $registry->record_undeclared_occurrence( 'owner-a:nightly', 3 ) );

		unset( $this->rig->backend()->results['schedule_recurring'] );
		$repaired = $this->client_a->sync( array( $nightly, $hourly ) );

		self::assertInstanceOf( Success::class, $repaired );
		$reset = $registry->registration( 'owner-a:nightly' );
		self::assertInstanceOf( Success::class, $reset );
		self::assertIsArray( $reset->value );
		self::assertSame( 0, $reset->value['undeclared_occurrences'] ?? null );
		self::assertFalse( $reset->value['undeclared_escalated'] ?? true );
	}

	/**
	 * An unreadable owner row reports its exact maintenance recovery path.
	 *
	 * @return  void
	 */
	public function test_corrupt_registry_row_reports_reclaim_and_redeclaration_recovery(): void {
		$option_name = ScheduleRegistry::option_name( 'owner-a' );
		$poison      = 'poison-registry-row';
		$this->rig->wpdb()->put( $option_name, $poison );
		$this->rig->backend()->scheduled = true;
		$this->reset_backend_observations();

		$result = $this->client_a->sync( array( self::schedule( 'nightly', 300 ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( BoundaryError::class, $result->error );
		self::assertSame( ErrorCode::StorageFailed, $result->error->code );
		self::assertSame( 'Schedule registry option row "a8csp_bgje_schedule_registrations_owner-a" is unreadable; maintenance reclaims it, then re-declare schedules on the next init.', $result->error->message );
		self::assertSame(
			array(
				'owner'       => 'owner-a',
				'option_name' => $option_name,
			),
			$result->error->context
		);
		self::assertSame( array(), $this->rig->backend()->calls );
		self::assertSame( $poison, $this->rig->wpdb()->rows[ $option_name ] ?? null );
	}

	/**
	 * A read failure during owner replacement keeps the persist-failure recovery contract.
	 *
	 * @return  void
	 */
	public function test_registry_replacement_read_failure_keeps_persist_failure_guidance(): void {
		$this->rig->wpdb()->before_next( 'select', static function (): void {} );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted replacement read failure';
			}
		);

		$result = $this->client_a->sync( array( self::schedule( 'nightly', 300 ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( BoundaryError::class, $result->error );
		self::assertSame( ErrorCode::StorageFailed, $result->error->code );
		self::assertSame( 'Schedule registry state for owner "owner-a" could not be persisted; repair WordPress option writes and retry synchronization.', $result->error->message );
		self::assertSame( array( 'owner' => 'owner-a' ), $result->error->context );
		self::assertSame( array(), $this->write_calls() );
	}

	/**
	 * A failed change-path clearance retains the old registration without persisting the replacement.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_change_path_clearance_retains_the_old_registration(): void {
		$initial = self::schedule( 'nightly', 300 );
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( $initial ) ) );
		$before = $this->raw_registry();
		$this->reset_backend_observations();
		$this->rig->backend()->results['unschedule'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'The backend could not confirm clearance.' ) );

		$result = $this->client_a->sync( array( self::schedule( 'nightly', 600 ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( BoundaryError::class, $result->error );
		self::assertSame( ErrorCode::BackendRejected, $result->error->code );
		self::assertSame( array( 'unschedule' ), \array_column( $this->write_calls(), 'verb' ) );
		self::assertSame( $before, $this->raw_registry() );
		self::assertSame( 300, $this->owner_entries( 'owner-a' )[0]['recurrence'] ?? null );
		$this->rig->backend()->assert_scheduled( 'owner-a:nightly' );
	}

	/**
	 * A failed backend write retains the benign registration so the next sync can recreate the chain.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedule_failure_converges_on_the_next_sync(): void {
		$this->rig->backend()->results['schedule_recurring'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Repair scheduling and retry.' ) );

		$failed = $this->client_a->sync( array( self::schedule( 'nightly', 300 ) ) );

		self::assertInstanceOf( Failure::class, $failed );
		self::assertInstanceOf( BoundaryError::class, $failed->error );
		self::assertSame( ErrorCode::BackendRejected, $failed->error->code );
		self::assertSame( array( 'owner-a:nightly' ), \array_column( $this->owner_entries( 'owner-a' ), 'name' ) );

		unset( $this->rig->backend()->results['schedule_recurring'] );
		$this->reset_backend_observations();
		$retried = $this->client_a->sync( array( self::schedule( 'nightly', 300 ) ) );

		self::assertInstanceOf( Success::class, $retried );
		self::assertCount( 1, $this->calls( 'schedule_recurring' ) );
		$this->rig->backend()->assert_scheduled( 'owner-a:nightly' );
	}

	/**
	 * A failed replacement scheduling write retains the changed registration for later repair.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_replacement_persists_the_changed_registration_for_repair(): void {
		$initial = self::schedule( 'nightly', 300 );
		$changed = self::schedule( 'nightly', 600 );
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( $initial ) ) );
		$initial_raw = $this->raw_registry();
		$this->reset_backend_observations();
		$this->rig->backend()->results['schedule_recurring'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Repair scheduling and retry.' ) );

		$failed = $this->client_a->sync( array( $changed ) );

		self::assertInstanceOf( Failure::class, $failed );
		self::assertInstanceOf( BoundaryError::class, $failed->error );
		self::assertSame( ErrorCode::BackendRejected, $failed->error->code );
		self::assertSame( array( 'unschedule', 'schedule_recurring' ), \array_column( $this->write_calls(), 'verb' ) );
		$failed_entry = $this->owner_entries( 'owner-a' )[0];
		self::assertSame( 600, $failed_entry['recurrence'] ?? null );
		self::assertSame( self::NOW + 600, $failed_entry['next_due'] ?? null );
		self::assertNotSame( $initial_raw, $this->raw_registry() );

		unset( $this->rig->backend()->results['schedule_recurring'] );
		$this->reset_backend_observations();
		$repaired = $this->client_a->sync( array( $initial ) );

		self::assertInstanceOf( Success::class, $repaired );
		self::assertSame( array( 'unschedule', 'schedule_recurring' ), \array_column( $this->write_calls(), 'verb' ) );
		$repaired_entry = $this->owner_entries( 'owner-a' )[0];
		self::assertSame( 300, $repaired_entry['recurrence'] ?? null );
		self::assertSame( self::NOW + 300, $repaired_entry['next_due'] ?? null );
		$this->rig->backend()->assert_scheduled( 'owner-a:nightly' );
	}

	/**
	 * A cleared occurrence with a failed final registry delete converges on the next sync.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_removal_storage_crash_window_converges_on_retry(): void {
		$schedule = self::schedule( 'nightly', 300 );
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( $schedule ) ) );
		$fixture = $this->fixtures->schedule_registration( self::owner_fixture( $schedule, self::NOW + 300 ) );
		$this->rig->wpdb()->put( $fixture[0], $fixture[1] );
		$this->rig->wpdb()->script_result( 'delete', false );
		$this->reset_backend_observations();

		$failed = $this->client_a->sync( array() );

		self::assertInstanceOf( Failure::class, $failed );
		self::assertInstanceOf( BoundaryError::class, $failed->error );
		self::assertSame( ErrorCode::StorageFailed, $failed->error->code );
		self::assertSame( array( 'unschedule' ), \array_column( $this->write_calls(), 'verb' ) );
		self::assertSame( $fixture[1], $this->raw_registry() );
		$this->rig->backend()->assert_not_scheduled( 'owner-a:nightly' );

		$this->reset_backend_observations();
		$retried = $this->client_a->sync( array() );

		self::assertInstanceOf( Success::class, $retried );
		self::assertSame( array( 'unschedule' ), \array_column( $this->write_calls(), 'verb' ) );
		self::assertArrayNotHasKey( ScheduleRegistry::option_name( 'owner-a' ), $this->rig->wpdb()->rows );
	}

	/**
	 * Schedule synchronization compares the selected option generation as exact binary bytes.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Public owner replacement must retain the registry's binary option_value predicate so collation-equivalent generations cannot both win the whole-row CAS.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_updates_the_registry_with_a_binary_option_value_cas(): void {
		$hourly = self::schedule( 'hourly', 3_600 );
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( $hourly ) ) );
		$this->rig->wpdb()->recorded_queries = array();

		self::assertInstanceOf( Success::class, $this->client_a->sync( array( $hourly, self::schedule( 'nightly', 300 ) ) ) );

		$updates = \array_values( \array_filter( $this->rig->wpdb()->recorded_queries, static fn ( string $query ): bool => \str_starts_with( $query, 'UPDATE ' ) ) );
		self::assertNotEmpty( $updates );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $updates[0] );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns one deterministic schedule declaration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name     Schedule name.
	 * @param   int    $interval Recurrence interval.
	 *
	 * @return  Schedule
	 */
	private static function schedule( string $name, int $interval ): Schedule {
		return new Schedule( $name, Recurrence::every( $interval ), 'refresh-index', array( 'schedule' => $name ) );
	}

	/**
	 * Returns one complete owner fixture request.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Schedule $schedule  Schedule declaration.
	 * @param   int      $next_due  Next occurrence timestamp.
	 * @param   int|null $last_fired Last dispatched timestamp.
	 *
	 * @return  array{owner: string, declarations: array<string, array{schedule: Schedule, job: string}>, registrations: array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int, undeclared_occurrences: int, undeclared_escalated: bool}>}
	 */
	private static function owner_fixture( Schedule $schedule, int $next_due, ?int $last_fired = null ): array {
		$identity = 'owner-a:' . $schedule->name;

		return array(
			'owner'         => 'owner-a',
			'declarations'  => array(
				$identity => array(
					'schedule' => $schedule,
					'job'      => 'owner-a:refresh-index',
				),
			),
			'registrations' => array( $identity => StoreFixtureBuilder::schedule_registration_state( $schedule->fingerprint(), $next_due, $last_fired ) ),
		);
	}

	/**
	 * Returns inspected entries belonging to exactly one owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Owner filter.
	 *
	 * @return  list<array<string, mixed>>
	 */
	private function owner_entries( string $owner ): array {
		$snapshot = $this->rig->inspection()->schedules( $owner );
		self::assertNotNull( $snapshot );

		return $snapshot['entries'];
	}

	/**
	 * Returns the exact persisted schedule-registry bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function raw_registry(): string {
		$raw = $this->rig->wpdb()->rows[ ScheduleRegistry::option_name( 'owner-a' ) ] ?? null;
		self::assertIsString( $raw );

		return $raw;
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
	 * Returns backend writes that mutate one schedule chain.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{verb: string, args: array<string, mixed>}>
	 */
	private function write_calls(): array {
		return \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => \in_array( $call['verb'], array( 'schedule_recurring', 'unschedule' ), true ) ) );
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
		$this->rig->backend()->calls = array();
	}

	// endregion.
}

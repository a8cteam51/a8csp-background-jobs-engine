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
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Logging\HookLogger;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OwnerReplacementOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\RegistrationUpdateOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\UndeclaredOccurrenceOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/** Detects unsafe class construction while corrupt registry storage is inspected. */
final class ScheduleRegistryWakeupProbe {
	public static int $wakeups = 0;

	/** Records an unsafe native object construction. */
	public function __wakeup(): void {
		++self::$wakeups;
	}
}

/**
 * Exercises schedule persistence through owner facades and retains whole-row CAS proofs.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( ScheduleRegistry::class )]
#[CoversClass( OwnerReplacementOutcome::class )]
#[UsesClass( HookLogger::class )]
final class ScheduleRegistryTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const int NOW = 1_700_000_000;

	private OwnerOperations $client_a;
	private OwnerOperations $client_b;
	private StoreFixtureBuilder $fixtures;
	private EngineRig $rig;
	private OptionRows $rows;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded production files before the graph is built.
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
	 * Boots two owner-bound facades against one deterministic production graph.
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
		$this->rows     = new OptionRows( $this->rig->wpdb() );
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
	 * Owner syncs preserve sibling slices, expose canonical identities, and remove only their own rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_owner_sync_and_removal_are_visible_through_schedule_inspection(): void {
		$nightly = self::schedule( 'nightly', 300 );
		$hourly  = self::schedule( 'hourly', 3_600 );
		self::assertInstanceOf( Success::class, $this->client_b->sync( array( $hourly ) ) );
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( $nightly ) ) );

		$owner_a = $this->owner_entries( 'owner-a' );
		$owner_b = $this->owner_entries( 'owner-b' );
		self::assertSame( array( 'owner-a:nightly' ), \array_column( $owner_a, 'name' ) );
		self::assertSame( 300, $owner_a[0]['recurrence'] );
		self::assertSame( self::NOW + 300, $owner_a[0]['next_due'] );
		self::assertSame( array( 'owner-b:hourly' ), \array_column( $owner_b, 'name' ) );
		self::assertSame( 3_600, $owner_b[0]['recurrence'] );

		$replacement = self::schedule( 'nightly', 600 );
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( $replacement ) ) );
		$owner_a = $this->owner_entries( 'owner-a' );
		self::assertSame( 600, $owner_a[0]['recurrence'] );
		self::assertSame( self::NOW + 600, $owner_a[0]['next_due'] );

		self::assertInstanceOf( Success::class, $this->client_a->sync( array() ) );
		self::assertSame( array(), $this->owner_entries( 'owner-a' ) );
		self::assertSame( array( 'owner-b:hourly' ), \array_column( $this->owner_entries( 'owner-b' ), 'name' ) );
	}

	/**
	 * Each owner persists an independent registration row without a global registry row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_owner_sync_persists_one_registration_row_per_owner(): void {
		$nightly = self::schedule( 'nightly', 300 );
		$hourly  = self::schedule( 'hourly', 3_600 );
		self::assertInstanceOf( Success::class, $this->client_b->sync( array( $hourly ) ) );
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( $nightly ) ) );

		$owner_a = \maybe_unserialize( $this->rig->wpdb()->rows['a8csp_bgje_schedule_registrations_owner-a'] ?? null );
		$owner_b = \maybe_unserialize( $this->rig->wpdb()->rows['a8csp_bgje_schedule_registrations_owner-b'] ?? null );

		self::assertIsArray( $owner_a );
		self::assertIsArray( $owner_b );
		self::assertSame( array( 'owner-a:nightly' ), \array_keys( $owner_a ) );
		self::assertSame( self::registration( $nightly, self::NOW + 300 ), $owner_a['owner-a:nightly'] );
		self::assertSame( array( 'owner-b:hourly' ), \array_keys( $owner_b ) );
		self::assertSame( self::registration( $hourly, self::NOW + 3_600 ), $owner_b['owner-b:hourly'] );
		self::assertArrayNotHasKey( 'a8csp_bgje_schedule_registrations', $this->rig->wpdb()->rows );
	}

	/**
	 * A delivered occurrence advances complete timing state through the schedules facade graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_occurrence_delivery_advances_registration_effects_behaviorally(): void {
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( self::schedule( 'nightly', 300 ) ) ) );

		$this->rig->run_due();

		$entries = $this->owner_entries( 'owner-a' );
		self::assertCount( 1, $entries );
		self::assertSame( self::NOW + 300, $entries[0]['last_fired'] );
		self::assertSame( self::NOW + 600, $entries[0]['next_due'] );
		self::assertSame( 0, $entries[0]['misfire_skips'] );
		self::assertSame( 0, $entries[0]['overlap_skips'] );
	}

	/**
	 * A successful same-definition redeclaration resets only the inactive episode markers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_successful_redeclaration_resets_inactive_episode_markers_without_rewinding_timing(): void {
		$schedule = self::schedule( 'nightly', 300 );
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( $schedule ) ) );
		$registry     = $this->registry();
		$registration = $registry->registration( 'owner-a:nightly' );
		self::assertInstanceOf( Success::class, $registration );
		self::assertIsArray( $registration->value );
		$marked = StoreFixtureBuilder::schedule_registration_state( $schedule->fingerprint(), self::NOW + 600, self::NOW + 300, 2, 3, 3, true );
		self::assertSame( RegistrationUpdateOutcome::Updated, $registry->update_registration( 'owner-a:nightly', $schedule->fingerprint(), $marked ) );

		$persisted = $registry->registration( 'owner-a:nightly' );
		self::assertInstanceOf( Success::class, $persisted );
		self::assertIsArray( $persisted->value );
		self::assertSame( 3, $persisted->value['undeclared_occurrences'] ?? null );
		self::assertTrue( $persisted->value['undeclared_escalated'] ?? false );

		$this->rig->backend()->calls = array();
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( $schedule ) ) );

		$reset = $registry->registration( 'owner-a:nightly' );
		self::assertInstanceOf( Success::class, $reset );
		self::assertIsArray( $reset->value );
		self::assertSame( self::NOW + 600, $reset->value['next_due'] ?? null );
		self::assertSame( self::NOW + 300, $reset->value['last_fired'] ?? null );
		self::assertSame( 2, $reset->value['misfire_skips'] ?? null );
		self::assertSame( 3, $reset->value['overlap_skips'] ?? null );
		self::assertSame( 0, $reset->value['undeclared_occurrences'] ?? null );
		self::assertFalse( $reset->value['undeclared_escalated'] ?? true );
		self::assertSame( array(), \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => \in_array( $call['verb'], array( 'schedule_recurring', 'unschedule' ), true ) ) ) );

		self::assertSame( UndeclaredOccurrenceOutcome::Recorded, $registry->record_undeclared_occurrence( 'owner-a:nightly', 3 ) );
		self::assertSame( UndeclaredOccurrenceOutcome::Recorded, $registry->record_undeclared_occurrence( 'owner-a:nightly', 3 ) );
		self::assertSame( UndeclaredOccurrenceOutcome::Escalated, $registry->record_undeclared_occurrence( 'owner-a:nightly', 3 ) );
		$this->rig->wpdb()->recorded_queries = array();
		self::assertSame( UndeclaredOccurrenceOutcome::AlreadyEscalated, $registry->record_undeclared_occurrence( 'owner-a:nightly', 3 ) );
		self::assertSame( array(), $this->queries_starting_with( 'UPDATE ' ) );
		$fresh_episode = $registry->registration( 'owner-a:nightly' );
		self::assertInstanceOf( Success::class, $fresh_episode );
		self::assertIsArray( $fresh_episode->value );
		self::assertSame( 3, $fresh_episode->value['undeclared_occurrences'] ?? null );
		self::assertTrue( $fresh_episode->value['undeclared_escalated'] ?? false );
	}

	/**
	 * A declaration reset survives deletion of the selected owner row.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Deletion at the reset CAS boundary forces the absent-row retry path, which must not reinsert the selected inactive episode.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_declaration_reset_survives_row_deletion_during_owner_update(): void {
		$schedule = self::schedule( 'nightly', 300 );
		$owner    = self::owner_fixture( 'owner-a', $schedule, self::NOW + 300 );

		$owner['registrations']['owner-a:nightly'] = StoreFixtureBuilder::schedule_registration_state( $schedule->fingerprint(), self::NOW + 300, undeclared_occurrences: 3, undeclared_escalated: true );
		$this->put_fixture( $this->fixtures->schedule_registration( $owner ) );
		$this->rig->wpdb()->recorded_queries = array();
		$this->rig->wpdb()->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ): void {
				$option_name = ScheduleRegistry::option_name( 'owner-a' );
				unset( $wpdb->rows[ $option_name ], $wpdb->autoload[ $option_name ] );
			}
		);
		$registry = $this->registry();

		self::assertSame( OwnerReplacementOutcome::Persisted, $registry->replace_owner( 'owner-a', $owner['declarations'], $owner['registrations'], reset_undeclared_episodes: true ) );

		$expected = $owner;

		$expected['registrations']['owner-a:nightly'] = StoreFixtureBuilder::schedule_registration_state( $schedule->fingerprint(), self::NOW + 300 );
		self::assertSame( $this->fixtures->schedule_registration( $expected )[1], $this->raw_row() );
		self::assertCount( 2, $this->queries_starting_with( 'SELECT ' ) );
		self::assertCount( 1, $this->queries_starting_with( 'UPDATE ' ) );
		self::assertCount( 1, $this->queries_starting_with( 'INSERT ' ) );
	}

	/**
	 * Numeric canonical components remain string identities through sync and inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_numeric_owner_and_schedule_components_remain_canonical_strings(): void {
		$client = $this->rig->operations( '123' );
		$client->register( ( new RecordingJob( 'refresh-index' ) )->definition() );
		self::assertInstanceOf( Success::class, $client->sync( array( self::schedule( '456', 300 ) ) ) );

		self::assertSame( array( '123:456' ), \array_column( $this->owner_entries( '123' ), 'name' ) );
	}

	/**
	 * Valid neighbors survive malformed rows without constructing serialized classes.
	 *
	 * @load-bearing security
	 * @pin-rationale The deliberately corrupt owner row bypasses production serialization and mixes an object payload with one valid registration, proving hardened inspection does not execute wakeup hooks or discard safe data.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_malformed_registry_rows_are_tolerated_without_constructing_classes(): void {
		$schedule = self::schedule( 'nightly', 300 );
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( $schedule ) ) );
		$raw = \maybe_serialize(
			array(
				'owner-a:nightly' => self::registration( $schedule, self::NOW + 300 ),
				'nightly'         => array( 'fingerprint' => 'unqualified' ),
				'poison'          => new ScheduleRegistryWakeupProbe(),
			)
		);
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( ScheduleRegistry::option_name( 'owner-a' ), $raw );
		ScheduleRegistryWakeupProbe::$wakeups = 0;

		$entries = $this->owner_entries( 'owner-a' );

		self::assertSame( array( 'owner-a:nightly' ), \array_column( $entries, 'name' ) );
		self::assertSame( 0, ScheduleRegistryWakeupProbe::$wakeups );
	}

	/**
	 * An authoritative read failure reaches the public facade as a storage failure without a write.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_reports_authoritative_read_failure_without_changing_registry_bytes(): void {
		self::assertInstanceOf( Success::class, $this->client_a->sync( array( self::schedule( 'nightly', 300 ) ) ) );
		$option_name = ScheduleRegistry::option_name( 'owner-a' );
		$before      = $this->rig->wpdb()->rows[ $option_name ] ?? null;
		self::assertIsString( $before );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted registry read failure';
			}
		);
		$this->rig->wpdb()->recorded_queries = array();
		$this->rig->backend()->calls         = array();

		$result = $this->client_a->sync( array( self::schedule( 'nightly', 300 ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( BoundaryError::class, $result->error );
		self::assertSame( ErrorCode::StorageFailure, $result->error->code );
		self::assertSame( array(), $this->rig->backend()->calls );
		self::assertSame( $before, $this->rig->wpdb()->rows[ $option_name ] ?? null );
		self::assertSame( array(), $this->write_queries() );
	}

	/**
	 * Owner replacement distinguishes an authoritative read failure from write contention.
	 *
	 * @return  void
	 */
	public function test_owner_replacement_reports_read_failure(): void {
		$owner = self::owner_fixture( 'owner-a', self::schedule( 'nightly', 300 ), self::NOW + 300 );

		$this->rig->wpdb()->recorded_queries = array();
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted registry read failure';
			}
		);
		$registry = $this->registry();

		$outcome = $registry->replace_owner( 'owner-a', $owner['declarations'], $owner['registrations'] );

		self::assertSame( OwnerReplacementOutcome::ReadFailed, $outcome );
		self::assertNull( $registry->declaration( 'owner-a:nightly' ) );
		self::assertSame( array(), $this->write_queries() );
	}

	/**
	 * Owner replacement names an undecodable selected row as corruption.
	 *
	 * @return  void
	 */
	public function test_owner_replacement_reports_corrupt_row(): void {
		$option_name = ScheduleRegistry::option_name( 'owner-a' );
		$poison      = 'poison-registry-row';
		$this->rig->wpdb()->put( $option_name, $poison );
		$owner    = self::owner_fixture( 'owner-a', self::schedule( 'nightly', 300 ), self::NOW + 300 );
		$registry = $this->registry();

		$outcome = $registry->replace_owner( 'owner-a', $owner['declarations'], $owner['registrations'] );

		self::assertSame( OwnerReplacementOutcome::Corrupt, $outcome );
		self::assertSame( $poison, $this->rig->wpdb()->rows[ $option_name ] ?? null );
		self::assertNull( $registry->declaration( 'owner-a:nightly' ) );
	}

	/**
	 * Each listing call reports one warning for an undecodable owner row.
	 *
	 * @return  void
	 */
	public function test_corrupt_owner_row_warns_once_per_listing_call(): void {
		$option_name = ScheduleRegistry::option_name( 'owner-a' );
		$this->rig->wpdb()->put( $option_name, 'poison-registry-row' );
		$this->rig->logger()->records = array();

		$registry = $this->registry();

		$owner = $registry->registrations_for( 'owner-a' );

		self::assertInstanceOf( Failure::class, $owner );
		self::assertInstanceOf( SchedulingError::class, $owner->error );
		self::assertSame( $option_name, $owner->error->context['option_name'] ?? null );
		$records = $this->rig->logger()->records;
		self::assertCount( 1, $records );
		self::assertSame( 'warning', $records[0]['level'] ?? null );
		self::assertSame( $option_name, $records[0]['context']['option_name'] ?? null );

		$this->rig->logger()->records = array();

		$all = $registry->all_registrations();

		self::assertInstanceOf( Success::class, $all );
		self::assertIsArray( $all->value );
		self::assertSame( array(), \array_filter( \array_keys( $all->value ), static fn ( int|string $identity ): bool => \is_string( $identity ) && \str_starts_with( $identity, 'owner-a:' ) ) );
		$records = $this->rig->logger()->records;
		self::assertCount( 1, $records );
		self::assertSame( 'warning', $records[0]['level'] ?? null );
		self::assertSame( $option_name, $records[0]['context']['option_name'] ?? null );
	}

	/**
	 * A registration without mandatory inactive-episode markers fails and reports its owner row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registration_without_undeclared_markers_breaks_and_reports_the_owner_row(): void {
		$complete   = $this->fixtures->schedule_registration( self::owner_fixture( 'owner-a', self::schedule( 'nightly', 300 ), self::NOW + 300 ) );
		$incomplete = StoreFixtureBuilder::schedule_registration_without_undeclared_markers( $complete );
		$this->put_fixture( $incomplete );
		$this->rig->logger()->records = array();

		$read = $this->registry()->registrations_for( 'owner-a' );

		self::assertInstanceOf( Failure::class, $read );
		self::assertInstanceOf( SchedulingError::class, $read->error );
		self::assertSame( $incomplete[0], $read->error->context['option_name'] ?? null );
		self::assertSame( $incomplete[1], $this->raw_row() );
		self::assertCount( 1, $this->rig->logger()->records );
		self::assertSame( 'warning', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( $incomplete[0], $this->rig->logger()->records[0]['context']['option_name'] ?? null );
	}

	/**
	 * A listener inspecting the same corrupt row cannot recursively emit its warning.
	 *
	 * @return  void
	 */
	public function test_corrupt_owner_warning_is_guarded_against_reentrant_listeners(): void {
		$option_name = ScheduleRegistry::option_name( 'owner-a' );
		$this->rig->wpdb()->put( $option_name, 'poison-registry-row' );
		$registry       = new ScheduleRegistry( $this->rows, new HookLogger() );
		$listener_calls = 0;
		$nested         = null;
		$callbacks      = $GLOBALS['a8csp_bgje_test_action_callbacks'] ?? null;
		self::assertIsArray( $callbacks );
		$callbacks['a8csp_jobs_engine/log']          = static function () use ( $registry, &$listener_calls, &$nested ): void {
			++$listener_calls;
			if ( 1 === $listener_calls ) {
				$nested = $registry->registrations_for( 'owner-a' );
			}
		};
		$GLOBALS['a8csp_bgje_test_action_callbacks'] = $callbacks;

		$outer = $registry->registrations_for( 'owner-a' );

		self::assertInstanceOf( Failure::class, $outer );
		self::assertInstanceOf( Failure::class, $nested );
		self::assertSame( 1, $listener_calls );
		$fired = $GLOBALS['a8csp_bgje_test_fired_actions'] ?? null;
		self::assertIsArray( $fired );
		self::assertCount(
			1,
			\array_filter(
				$fired,
				static fn ( mixed $action ): bool => \is_array( $action ) && 'a8csp_jobs_engine/log' === ( $action['hook_name'] ?? null )
			)
		);
	}

	// endregion.

	// region KEEP CAS MICRO-SUITE.

	/**
	 * Interleaved inactive-aging writers persist one warning transition.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Two writers crossing the threshold at the same owner-row update boundary must classify exactly one winning CAS as the escalation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_interleaved_undeclared_aging_has_exactly_one_escalation_winner(): void {
		$schedule = self::schedule( 'nightly', 300 );
		$owner    = self::owner_fixture( 'owner-a', $schedule, self::NOW + 300 );

		$owner['registrations']['owner-a:nightly'] = StoreFixtureBuilder::schedule_registration_state( $schedule->fingerprint(), self::NOW + 300, undeclared_occurrences: 2 );
		$this->put_fixture( $this->fixtures->schedule_registration( $owner ) );
		$nested = null;
		$this->rig->wpdb()->before_next(
			'update',
			function () use ( &$nested ): void {
				$nested = $this->registry()->record_undeclared_occurrence( 'owner-a:nightly', 3 );
			}
		);

		$outer = $this->registry()->record_undeclared_occurrence( 'owner-a:nightly', 3 );

		self::assertSame( UndeclaredOccurrenceOutcome::Escalated, $nested );
		self::assertSame( UndeclaredOccurrenceOutcome::AlreadyEscalated, $outer );
		$persisted = $this->registry()->registration( 'owner-a:nightly' );
		self::assertInstanceOf( Success::class, $persisted );
		self::assertIsArray( $persisted->value );
		self::assertSame( 3, $persisted->value['undeclared_occurrences'] ?? null );
		self::assertTrue( $persisted->value['undeclared_escalated'] ?? false );
	}

	/**
	 * An inactive-aging write failure loses only that best-effort increment.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_undeclared_aging_write_failure_leaves_the_selected_registration_unchanged(): void {
		$schedule = self::schedule( 'nightly', 300 );
		$owner    = self::owner_fixture( 'owner-a', $schedule, self::NOW + 300 );

		$owner['registrations']['owner-a:nightly'] = StoreFixtureBuilder::schedule_registration_state( $schedule->fingerprint(), self::NOW + 300, undeclared_occurrences: 2 );

		$fixture = $this->fixtures->schedule_registration( $owner );
		$this->put_fixture( $fixture );
		$this->rig->wpdb()->recorded_queries = array();
		$this->rig->wpdb()->script_result( 'update', false );

		self::assertSame( UndeclaredOccurrenceOutcome::Failed, $this->registry()->record_undeclared_occurrence( 'owner-a:nightly', 3 ) );
		self::assertSame( $fixture[1], $this->raw_row() );
		self::assertCount( 1, $this->queries_starting_with( 'SELECT ' ) );
		self::assertCount( 1, $this->queries_starting_with( 'UPDATE ' ) );
	}

	/**
	 * Owner replacement and final removal compare exact binary option bytes.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built generations and literal SQL predicates prove owner updates cannot match a collation-equivalent but byte-distinct registry row.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_owner_replace_and_final_remove_use_binary_exact_row_comparisons(): void {
		$schedule    = self::schedule( 'nightly', 300 );
		$replacement = self::schedule( 'nightly', 600 );
		$initial     = self::owner_fixture( 'owner-a', $schedule, self::NOW + 300 );
		$owner_a     = self::owner_fixture( 'owner-a', $replacement, self::NOW + 600 );
		$this->put_fixture( $this->fixtures->schedule_registration( $initial ) );
		$this->rig->wpdb()->recorded_queries = array();
		$registry                            = $this->registry();

		self::assertSame( OwnerReplacementOutcome::Persisted, $registry->replace_owner( 'owner-a', $owner_a['declarations'], $owner_a['registrations'] ) );
		self::assertSame( $this->fixtures->schedule_registration( $owner_a )[1], $this->raw_row() );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $this->queries_starting_with( 'UPDATE ' )[0] );

		$this->put_fixture( $this->fixtures->schedule_registration( $owner_a ) );
		$this->rig->wpdb()->recorded_queries = array();
		self::assertSame( OwnerReplacementOutcome::Persisted, $registry->replace_owner( 'owner-a', array(), array() ) );
		self::assertArrayNotHasKey( ScheduleRegistry::option_name( 'owner-a' ), $this->rig->wpdb()->rows );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $this->queries_starting_with( 'DELETE ' )[0] );
	}

	/**
	 * Interleaved owner writers update independent rows without retrying each other.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Production-built owner rows and an update-boundary interleave prove cross-owner writes do not share a CAS generation while both timing advances survive.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_interleaved_owner_writers_update_independent_rows_without_retry(): void {
		$schedule_a    = self::schedule( 'nightly', 300 );
		$schedule_b    = self::schedule( 'hourly', 3_600 );
		$replacement_a = self::schedule( 'nightly', 600 );
		$replacement_b = self::schedule( 'hourly', 7_200 );
		$initial_a     = self::owner_fixture( 'owner-a', $schedule_a, self::NOW + 300 );
		$initial_b     = self::owner_fixture( 'owner-b', $schedule_b, self::NOW + 3_600 );
		$next_a        = self::owner_fixture( 'owner-a', $replacement_a, self::NOW + 600 );
		$next_b        = self::owner_fixture( 'owner-b', $replacement_b, self::NOW + 7_200, self::NOW + 3_600 );
		$this->put_fixture( $this->fixtures->schedule_registration( $initial_a ) );
		$this->put_fixture( $this->fixtures->schedule_registration( $initial_b ) );
		$this->rig->wpdb()->recorded_queries = array();
		$this->rig->wpdb()->before_next(
			'update',
			function () use ( $next_b ): void {
				self::assertSame( OwnerReplacementOutcome::Persisted, $this->registry()->replace_owner( 'owner-b', $next_b['declarations'], $next_b['registrations'] ) );
			}
		);

		self::assertSame( OwnerReplacementOutcome::Persisted, $this->registry()->replace_owner( 'owner-a', $next_a['declarations'], $next_a['registrations'] ) );

		self::assertCount( 2, $this->queries_starting_with( 'UPDATE ' ) );
		self::assertSame( $this->fixtures->schedule_registration( $next_a )[1], $this->raw_row( 'owner-a' ) );
		self::assertSame( $this->fixtures->schedule_registration( $next_b )[1], $this->raw_row( 'owner-b' ) );
	}

	/**
	 * Owner replacement preserves a delivery advance for an unchanged schedule definition.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The sync-vs-delivery rewind race occurs at the owner-row update boundary, where only a staged storage interleave proves the fresh delivery fence survives the replacement retry.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_owner_replacement_preserves_concurrently_advanced_unchanged_registration(): void {
		$nightly     = self::schedule( 'nightly', 300 );
		$hourly      = self::schedule( 'hourly', 3_600 );
		$stored      = self::owner_fixture( 'owner-a', $nightly, self::NOW + 300 );
		$replacement = self::owner_fixture_many( 'owner-a', array( $nightly, $hourly ), array( self::NOW + 300, self::NOW + 3_600 ) );
		$advanced    = $stored['registrations']['owner-a:nightly'];

		$advanced['next_due']      = self::NOW + 600;
		$advanced['last_fired']    = self::NOW + 300;
		$advanced['misfire_skips'] = 2;
		$advanced['overlap_skips'] = 3;
		$this->put_fixture( $this->fixtures->schedule_registration( $stored ) );
		$this->rig->wpdb()->before_next(
			'update',
			function () use ( $advanced ): void {
				self::assertSame( RegistrationUpdateOutcome::Updated, $this->registry()->update_registration( 'owner-a:nightly', $advanced['fingerprint'], $advanced ) );
			}
		);
		$registry = $this->registry();

		self::assertSame( OwnerReplacementOutcome::Persisted, $registry->replace_owner( 'owner-a', $replacement['declarations'], $replacement['registrations'] ) );

		$expected                                     = $replacement;
		$expected['registrations']['owner-a:nightly'] = $advanced;
		self::assertSame( $this->fixtures->schedule_registration( $expected )[1], $this->raw_row() );
		$registrations = $registry->registrations_for( 'owner-a' );
		self::assertInstanceOf( Success::class, $registrations );
		self::assertIsArray( $registrations->value );
		self::assertArrayHasKey( 'owner-a:nightly', $registrations->value );
		$persisted = $registrations->value['owner-a:nightly'];
		self::assertIsArray( $persisted );
		self::assertSame( self::NOW + 600, $persisted['next_due'] ?? null );
		self::assertSame( self::NOW + 300, $persisted['last_fired'] ?? null );
		self::assertSame( 2, $persisted['misfire_skips'] ?? null );
		self::assertSame( 3, $persisted['overlap_skips'] ?? null );
	}

	/**
	 * Owner replacement performs no write when only an unchanged schedule's timing state advanced.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The sync-vs-delivery rewind race cannot be excluded through public state alone; a zero-write assertion proves a stale sync snapshot merges to the freshly advanced delivery fence before equality comparison.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_owner_replacement_merges_advanced_unchanged_registration_without_writing(): void {
		$schedule              = self::schedule( 'nightly', 300 );
		$stale                 = self::owner_fixture( 'owner-a', $schedule, self::NOW + 300 );
		$advanced_registration = self::registration( $schedule, self::NOW + 600, self::NOW + 300 );

		$advanced_registration['misfire_skips'] = 2;
		$advanced_registration['overlap_skips'] = 3;

		$advanced = $stale;

		$advanced['registrations']['owner-a:nightly'] = $advanced_registration;

		$fixture = $this->fixtures->schedule_registration( $advanced );
		$this->put_fixture( $fixture );
		$this->rig->wpdb()->recorded_queries = array();
		$registry                            = $this->registry();

		self::assertSame( OwnerReplacementOutcome::Persisted, $registry->replace_owner( 'owner-a', $stale['declarations'], $stale['registrations'] ) );

		self::assertSame( array(), $this->write_queries() );
		self::assertSame( $fixture[1], $this->raw_row() );
	}

	/**
	 * Owner replacement resets timing state when a schedule definition fingerprint changes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_owner_replacement_keeps_fresh_timing_for_changed_fingerprint(): void {
		$stored      = self::owner_fixture( 'owner-a', self::schedule( 'nightly', 300 ), self::NOW + 600, self::NOW + 300 );
		$replacement = self::owner_fixture( 'owner-a', self::schedule( 'nightly', 600 ), self::NOW + 600 );
		$this->put_fixture( $this->fixtures->schedule_registration( $stored ) );
		$registry = $this->registry();

		self::assertSame( OwnerReplacementOutcome::Persisted, $registry->replace_owner( 'owner-a', $replacement['declarations'], $replacement['registrations'] ) );

		$registrations = $registry->registrations_for( 'owner-a' );
		self::assertInstanceOf( Success::class, $registrations );
		self::assertIsArray( $registrations->value );
		self::assertSame( $replacement['registrations']['owner-a:nightly'], $registrations->value['owner-a:nightly'] ?? null );
	}

	/**
	 * A row deleted during owner replacement is reinserted from the caller's authoritative state.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Deleting the selected owner generation at the exact update boundary proves retry reconstructs only that owner's current declaration.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_concurrent_row_deletion_reinserts_only_the_callers_slice(): void {
		$owner_a = self::owner_fixture( 'owner-a', self::schedule( 'nightly', 300 ), self::NOW + 300 );
		$owner_b = self::owner_fixture( 'owner-b', self::schedule( 'hourly', 3_600 ), self::NOW + 3_600 );
		$next_a  = self::owner_fixture( 'owner-a', self::schedule( 'nightly', 600 ), self::NOW + 600 );
		$this->put_fixture( $this->fixtures->schedule_registration( $owner_a ) );
		$this->put_fixture( $this->fixtures->schedule_registration( $owner_b ) );
		$owner_b_raw = $this->raw_row( 'owner-b' );
		$this->rig->wpdb()->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ): void {
				$option_name = ScheduleRegistry::option_name( 'owner-a' );
				unset( $wpdb->rows[ $option_name ], $wpdb->autoload[ $option_name ] );
			}
		);

		self::assertSame( OwnerReplacementOutcome::Persisted, $this->registry()->replace_owner( 'owner-a', $next_a['declarations'], $next_a['registrations'] ) );

		self::assertSame( $this->fixtures->schedule_registration( $next_a )[1], $this->raw_row() );
		self::assertSame( $owner_b_raw, $this->raw_row( 'owner-b' ) );
	}

	/**
	 * Delivery-state updates retry sibling changes and fence replaced or pruned definitions.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Owner-row CAS must merge a same-owner sibling update, but the same retry must refuse to resurrect a definition whose fingerprint changed or whose row disappeared.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registration_updates_merge_siblings_and_fence_superseded_or_pruned_rows(): void {
		$nightly = self::schedule( 'nightly', 300 );
		$hourly  = self::schedule( 'hourly', 3_600 );
		$owner   = self::owner_fixture_many( 'owner-a', array( $nightly, $hourly ), array( self::NOW + 300, self::NOW + 3_600 ) );
		$this->put_fixture( $this->fixtures->schedule_registration( $owner ) );
		$nightly_next               = $owner['registrations']['owner-a:nightly'];
		$nightly_next['next_due']   = self::NOW + 600;
		$nightly_next['last_fired'] = self::NOW + 300;
		$hourly_next                = $owner['registrations']['owner-a:hourly'];
		$hourly_next['last_fired']  = self::NOW + 111;
		$this->rig->wpdb()->before_next(
			'update',
			function () use ( $hourly_next ): void {
				self::assertSame( RegistrationUpdateOutcome::Updated, $this->registry()->update_registration( 'owner-a:hourly', $hourly_next['fingerprint'], $hourly_next ) );
			}
		);

		self::assertSame( RegistrationUpdateOutcome::Updated, $this->registry()->update_registration( 'owner-a:nightly', $nightly_next['fingerprint'], $nightly_next ) );
		$expected                                     = $owner;
		$expected['registrations']['owner-a:nightly'] = $nightly_next;
		$expected['registrations']['owner-a:hourly']  = $hourly_next;
		self::assertSame( $this->fixtures->schedule_registration( $expected )[1], $this->raw_row() );

		$replacement = self::owner_fixture( 'owner-a', self::schedule( 'nightly', 600 ), self::NOW + 1_200 );
		$this->put_fixture( $this->fixtures->schedule_registration( $owner ) );
		$replacement_fixture = $this->fixtures->schedule_registration( $replacement );
		$this->rig->wpdb()->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $replacement_fixture ): void {
				$wpdb->put( $replacement_fixture[0], $replacement_fixture[1] );
			}
		);
		self::assertSame( RegistrationUpdateOutcome::Superseded, $this->registry()->update_registration( 'owner-a:nightly', $nightly_next['fingerprint'], $nightly_next ) );
		self::assertSame( $replacement_fixture[1], $this->raw_row() );

		$this->put_fixture( $this->fixtures->schedule_registration( $owner ) );
		$this->rig->wpdb()->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ): void {
				$option_name = ScheduleRegistry::option_name( 'owner-a' );
				unset( $wpdb->rows[ $option_name ], $wpdb->autoload[ $option_name ] );
			}
		);
		self::assertSame( RegistrationUpdateOutcome::Pruned, $this->registry()->update_registration( 'owner-a:nightly', $nightly_next['fingerprint'], $nightly_next ) );
		self::assertArrayNotHasKey( ScheduleRegistry::option_name( 'owner-a' ), $this->rig->wpdb()->rows );
	}

	/**
	 * A failed registration update returns before an incumbent-classification read.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A typed write-failed outcome must stop delivery-state persistence without entering the comparison-loss path that reads a competing generation.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registration_update_write_failure_returns_without_a_diagnostic_read(): void {
		$owner = self::owner_fixture( 'owner-a', self::schedule( 'nightly', 300 ), self::NOW + 300 );
		$this->put_fixture( $this->fixtures->schedule_registration( $owner ) );
		$next               = $owner['registrations']['owner-a:nightly'];
		$next['next_due']   = self::NOW + 600;
		$next['last_fired'] = self::NOW + 300;

		$this->rig->wpdb()->recorded_queries = array();
		$this->rig->wpdb()->script_result( 'update', false );

		self::assertSame( RegistrationUpdateOutcome::Failed, $this->registry()->update_registration( 'owner-a:nightly', $next['fingerprint'], $next ) );
		self::assertSame( $this->fixtures->schedule_registration( $owner )[1], $this->raw_row() );
		self::assertCount( 1, $this->queries_starting_with( 'SELECT ' ) );
		self::assertCount( 1, $this->queries_starting_with( 'UPDATE ' ) );
	}

	/**
	 * An unchanged row after a failed exact update reports failure without retaining declarations.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A typed write-failed outcome prevents request-local declarations from claiming an unpersisted owner state without entering the comparison-loss retry path.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_exact_owner_update_leaves_bytes_and_declarations_unchanged(): void {
		$owner_a   = self::owner_fixture( 'owner-a', self::schedule( 'nightly', 300 ), self::NOW + 300 );
		$incumbent = self::owner_fixture( 'owner-a', self::schedule( 'hourly', 3_600 ), self::NOW + 3_600 );
		$fixture   = $this->fixtures->schedule_registration( $incumbent );
		$this->put_fixture( $fixture );
		$this->rig->wpdb()->recorded_queries = array();
		$this->rig->wpdb()->script_result( 'update', false );
		$registry = $this->registry();

		self::assertSame( OwnerReplacementOutcome::CasFailed, $registry->replace_owner( 'owner-a', $owner_a['declarations'], $owner_a['registrations'] ) );

		self::assertSame( $fixture[1], $this->raw_row() );
		self::assertNull( $registry->declaration( 'owner-a:nightly' ) );
		self::assertCount( 1, $this->queries_starting_with( 'SELECT ' ) );
		self::assertCount( 1, $this->queries_starting_with( 'UPDATE ' ) );
	}

	/**
	 * Same-owner replacement retries and wins after an ABA row restoration.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A rival generation at the update boundary and restoration at the retry-read boundary reproduce A-to-B-to-A; only a staged storage interleave proves the comparison loss remains retryable when the selected bytes reappear.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_same_owner_replace_retries_and_wins_after_an_aba_restore(): void {
		$owner_a     = self::owner_fixture( 'owner-a', self::schedule( 'nightly', 300 ), self::NOW + 300 );
		$schedule_b  = self::schedule( 'hourly', 3_600 );
		$initial     = $this->fixtures->schedule_registration( self::owner_fixture( 'owner-a', $schedule_b, self::NOW + 3_600 ) );
		$rival       = $this->fixtures->schedule_registration( self::owner_fixture( 'owner-a', $schedule_b, self::NOW + 3_601 ) );
		$replacement = $this->fixtures->schedule_registration( $owner_a );
		$this->put_fixture( $initial );
		$this->rig->wpdb()->recorded_queries = array();
		$this->rig->wpdb()->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $initial, $rival ): void {
				$wpdb->put( $rival[0], $rival[1] );
				$wpdb->before_next(
					'select',
					static function ( WpdbLockSpy $wpdb ) use ( $initial ): void {
						$wpdb->put( $initial[0], $initial[1] );
					}
				);
			}
		);
		$registry = $this->registry();

		self::assertSame( OwnerReplacementOutcome::Persisted, $registry->replace_owner( 'owner-a', $owner_a['declarations'], $owner_a['registrations'] ) );
		self::assertCount( 2, $this->queries_starting_with( 'SELECT ' ) );
		self::assertCount( 2, $this->queries_starting_with( 'UPDATE ' ) );
		self::assertSame( $replacement[1], $this->raw_row() );
	}

	/**
	 * Same-owner replacement stops after five consecutive comparison losses.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The exact five-attempt bound (UPDATE_ATTEMPTS=5) is the liveness contract; an unbounded loop under permanent contention would hang schedule synchronization.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_same_owner_replace_stops_after_five_consecutive_cas_losses(): void {
		$owner_a    = self::owner_fixture( 'owner-a', self::schedule( 'nightly', 300 ), self::NOW + 300 );
		$schedule_b = self::schedule( 'hourly', 3_600 );
		$fixture    = $this->fixtures->schedule_registration( self::owner_fixture( 'owner-a', $schedule_b, self::NOW + 3_600 ) );
		$this->put_fixture( $fixture );
		$this->rig->wpdb()->recorded_queries = array();

		$last_rival = $fixture;
		for ( $attempt = 1; $attempt <= 5; ++$attempt ) {
			$rival      = $this->fixtures->schedule_registration( self::owner_fixture( 'owner-a', $schedule_b, self::NOW + 3_600 + $attempt ) );
			$last_rival = $rival;
			$this->rig->wpdb()->before_next(
				'update',
				static function ( WpdbLockSpy $wpdb ) use ( $rival ): void {
					$wpdb->put( $rival[0], $rival[1] );
				}
			);
		}
		$registry = $this->registry();

		self::assertSame( OwnerReplacementOutcome::CasFailed, $registry->replace_owner( 'owner-a', $owner_a['declarations'], $owner_a['registrations'] ) );
		self::assertCount( 5, $this->queries_starting_with( 'SELECT ' ) );
		self::assertCount( 5, $this->queries_starting_with( 'UPDATE ' ) );
		self::assertSame( array(), $this->queries_starting_with( 'INSERT ' ) );
		self::assertSame( array(), $this->queries_starting_with( 'DELETE ' ) );
		self::assertSame( $last_rival[1], $this->raw_row() );
		self::assertNull( $registry->declaration( 'owner-a:nightly' ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns a schedule with one deterministic target and interval.
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
	 * Returns one complete owner fixture request.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $owner       Owner identifier.
	 * @param   Schedule $schedule    Schedule declaration.
	 * @param   int      $next_due    Next occurrence timestamp.
	 * @param   int|null $last_fired  Last occurrence timestamp.
	 *
	 * @return  array{owner: string, declarations: array<string, array{schedule: Schedule, job: string}>, registrations: array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int, undeclared_occurrences: int, undeclared_escalated: bool}>}
	 */
	private static function owner_fixture( string $owner, Schedule $schedule, int $next_due, ?int $last_fired = null ): array {
		return self::owner_fixture_many( $owner, array( $schedule ), array( $next_due ), array( $last_fired ) );
	}

	/**
	 * Returns one owner fixture request containing several schedules.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<Schedule> $schedules
	 * @phpstan-param list<int> $next_due
	 * @phpstan-param list<int|null> $last_fired
	 *
	 * @param   string $owner       Owner identifier.
	 * @param   array  $schedules   Schedule declarations.
	 * @param   array  $next_due    Next occurrence timestamps.
	 * @param   array  $last_fired  Last occurrence timestamps.
	 *
	 * @return  array{owner: string, declarations: array<string, array{schedule: Schedule, job: string}>, registrations: array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int, undeclared_occurrences: int, undeclared_escalated: bool}>}
	 */
	private static function owner_fixture_many( string $owner, array $schedules, array $next_due, array $last_fired = array() ): array {
		$declarations  = array();
		$registrations = array();
		foreach ( $schedules as $index => $schedule ) {
			$identity                   = $owner . ':' . $schedule->name;
			$declarations[ $identity ]  = array(
				'schedule' => $schedule,
				'job'      => $owner . ':' . $schedule->job,
			);
			$registrations[ $identity ] = self::registration( $schedule, $next_due[ $index ], $last_fired[ $index ] ?? null );
		}

		return array(
			'owner'         => $owner,
			'declarations'  => $declarations,
			'registrations' => $registrations,
		);
	}

	/**
	 * Returns complete persisted timing state for one schedule.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Schedule $schedule   Schedule declaration.
	 * @param   int      $next_due   Next occurrence timestamp.
	 * @param   int|null $last_fired Last occurrence timestamp.
	 *
	 * @return  array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int, undeclared_occurrences: int, undeclared_escalated: bool}
	 */
	private static function registration( Schedule $schedule, int $next_due, ?int $last_fired = null ): array {
		return StoreFixtureBuilder::schedule_registration_state( $schedule->fingerprint(), $next_due, $last_fired );
	}

	/**
	 * Returns a registry bound to the active authoritative rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  ScheduleRegistry
	 */
	private function registry(): ScheduleRegistry {
		return new ScheduleRegistry( $this->rows, $this->rig->logger() );
	}

	/**
	 * Stores one production-built raw fixture in the active database.
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
	 * Returns the registry's authoritative raw bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Owner identifier.
	 *
	 * @return  string
	 */
	private function raw_row( string $owner = 'owner-a' ): string {
		$raw = $this->rig->wpdb()->rows[ ScheduleRegistry::option_name( $owner ) ] ?? null;
		self::assertIsString( $raw );

		return $raw;
	}

	/**
	 * Returns authoritative write statements.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<string>
	 */
	private function write_queries(): array {
		return \array_values( \array_filter( $this->rig->wpdb()->recorded_queries, static fn ( string $query ): bool => \str_starts_with( $query, 'INSERT ' ) || \str_starts_with( $query, 'UPDATE ' ) || \str_starts_with( $query, 'DELETE ' ) ) );
	}

	/**
	 * Returns recorded statements carrying one literal prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $prefix Statement prefix.
	 *
	 * @return  list<string>
	 */
	private function queries_starting_with( string $prefix ): array {
		return \array_values( \array_filter( $this->rig->wpdb()->recorded_queries, static fn ( string $query ): bool => \str_starts_with( $query, $prefix ) ) );
	}

	// endregion.
}

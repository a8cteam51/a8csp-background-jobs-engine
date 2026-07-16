<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;

/**
 * Verifies declarative sync mutates only one owner's engine registration identities.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class DeclarativeSyncTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Internal occurrence hook owned by the engine. */
	private const SCHEDULE_HOOK = 'a8csp_background_tasks/schedule_due';

	/** Foreign WP-Cron hook outside the engine namespace. */
	private const FOREIGN_CRON_HOOK = 'third_party/integration/declarative_sync/foreign_cron';

	/** Foreign WP-Cron arguments whose exact identity must survive sync. */
	private const FOREIGN_CRON_ARGS = array( 'declarative-sync-foreign-cron' );

	/** Foreign Action Scheduler hook outside the engine namespace. */
	private const FOREIGN_ACTION_HOOK = 'third_party/integration/declarative_sync/foreign_action';

	/** Foreign Action Scheduler arguments whose exact identity must survive sync. */
	private const FOREIGN_ACTION_ARGS = array( 'declarative-sync-foreign-action' );

	/** Foreign Action Scheduler group outside every engine registration identity. */
	private const FOREIGN_ACTION_GROUP = 'a8csp-bgte-integration-declarative-sync-foreign';

	/** Owner isolated to orphan pruning. */
	private const ORPHAN_OWNER = 'integration-declarative-orphan';

	/** Owner isolated to fingerprint replacement. */
	private const FINGERPRINT_OWNER = 'integration-decl-fingerprint';

	/** Owner isolated to identical redeclaration. */
	private const NOOP_OWNER = 'integration-declarative-noop';

	/** First owner isolated to owner-scoped pruning. */
	private const SCOPED_OWNER_A = 'integration-declarative-owner-a';

	/** Second owner isolated to owner-scoped pruning. */
	private const SCOPED_OWNER_B = 'integration-declarative-owner-b';

	/** Attempted cron-option writes after the foreign fixture is seeded. */
	private int $cron_option_writes = 0;

	/** Foreign WP-Cron boundary used for behavior reads. */
	private ?WPCronBackend $foreign_cron = null;

	/** Foreign Action Scheduler boundary used for behavior reads. */
	private ?ActionSchedulerBackend $foreign_action_scheduler = null;

	/** Original foreign WP-Cron timestamp. */
	private int $foreign_cron_at = 0;

	/** Original foreign Action Scheduler timestamp. */
	private int $foreign_action_at = 0;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Seeds foreign recurring work before each sync contract runs.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->foreign_cron             = new WPCronBackend();
		$this->foreign_action_scheduler = new ActionSchedulerBackend( static fn (): bool => true );
		$this->foreign_cron->register_hooks();
		$this->foreign_cron_at   = \time() + 2 * \HOUR_IN_SECONDS;
		$this->foreign_action_at = $this->foreign_cron_at + \MINUTE_IN_SECONDS;

		$cron_scheduled = $this->foreign_cron->schedule_recurring( self::FOREIGN_CRON_HOOK, \HOUR_IN_SECONDS, self::FOREIGN_CRON_ARGS, $this->foreign_cron_at );
		self::assertInstanceOf( Success::class, $cron_scheduled );
		$action_scheduled = $this->foreign_action_scheduler->schedule_recurring( self::FOREIGN_ACTION_HOOK, 17 * \MINUTE_IN_SECONDS, self::FOREIGN_ACTION_ARGS, $this->foreign_action_at, self::FOREIGN_ACTION_GROUP, 73 );
		self::assertInstanceOf( Success::class, $action_scheduled );

		$this->cron_option_writes = 0;
		\add_filter(
			'pre_update_option_cron',
			function ( mixed $value ): mixed {
				++$this->cron_option_writes;

				return $value;
			}
		);
	}

	/**
	 * Proves sync leaves both foreign scheduler identities unchanged before parent cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function tearDown(): void {
		try {
			self::assertSame( 0, $this->cron_option_writes, 'Declarative sync must never write the cron option, not even rewriting identical state' );
			if ( null !== $this->foreign_cron ) {
				self::assertSame( $this->foreign_cron_at, $this->foreign_cron->get_next_scheduled( self::FOREIGN_CRON_HOOK, self::FOREIGN_CRON_ARGS ), 'Declarative sync must preserve the foreign WP-Cron occurrence' );
			}
			if ( null !== $this->foreign_action_scheduler ) {
				self::assertSame( $this->foreign_action_at, $this->foreign_action_scheduler->get_next_scheduled( self::FOREIGN_ACTION_HOOK, self::FOREIGN_ACTION_ARGS, self::FOREIGN_ACTION_GROUP ), 'Declarative sync must preserve the foreign Action Scheduler occurrence' );
			}
		} finally {
			parent::tearDown();
		}
	}

	// endregion.

	// region TESTS.

	/**
	 * Removing one declaration prunes only its registration and occurrence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_owner_sync_prunes_an_orphan_registration_and_occurrence(): void {
		$this->expect_option( ScheduleRegistry::option_name( self::ORPHAN_OWNER ) );
		$schedule_a = new Schedule( 'orphan-a', Recurrence::every( 300 ), 'integration-declarative-orphan-task-a' );
		$schedule_b = new Schedule( 'orphan-b', Recurrence::every( 600 ), 'integration-declarative-orphan-task-b' );
		$this->assert_sync_succeeds( self::ORPHAN_OWNER, array( $schedule_a, $schedule_b ) );
		$before = $this->schedule_entries( self::ORPHAN_OWNER );
		self::assertCount( 2, $before );
		$retained = $this->schedule_entry( $before, self::ORPHAN_OWNER . ':orphan-a' );

		$this->assert_sync_succeeds( self::ORPHAN_OWNER, array( $schedule_a ) );

		$after = $this->schedule_entries( self::ORPHAN_OWNER );
		self::assertCount( 1, $after );
		self::assertSame( $retained, $this->schedule_entry( $after, self::ORPHAN_OWNER . ':orphan-a' ) );
		self::assertNull( $this->schedule_entry( $after, self::ORPHAN_OWNER . ':orphan-b' ) );
		self::assertFalse( ( new ActionSchedulerBackend( static fn (): bool => true ) )->is_scheduled( self::SCHEDULE_HOOK, array( self::ORPHAN_OWNER . ':orphan-b' ), self::ORPHAN_OWNER . ':orphan-b' ), 'Pruning must cancel the backend occurrence, not merely drop the registration' );
	}

	/**
	 * A changed definition replaces the observable occurrence contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_fingerprint_change_reschedules_the_occurrence(): void {
		$this->expect_option( ScheduleRegistry::option_name( self::FINGERPRINT_OWNER ) );
		$original    = new Schedule( 'fingerprint', Recurrence::every( 300 ), 'integration-declarative-fingerprint-task', array( 'mode' => 'original' ), OverlapPolicy::Skip, CatchUpPolicy::RunOnce, 21 );
		$replacement = new Schedule( 'fingerprint', Recurrence::every( 900 ), 'integration-declarative-fingerprint-task', array( 'mode' => 'replacement' ), OverlapPolicy::Replace, CatchUpPolicy::Skip, 22 );
		$this->assert_sync_succeeds( self::FINGERPRINT_OWNER, array( $original ) );
		$before = $this->schedule_entry( $this->schedule_entries( self::FINGERPRINT_OWNER ), self::FINGERPRINT_OWNER . ':fingerprint' );
		self::assertIsArray( $before );
		self::assertSame( 300, $before['recurrence'] );
		self::assertTrue( $before['occurrence_visible'] );

		$this->assert_sync_succeeds( self::FINGERPRINT_OWNER, array( $replacement ) );

		$after = $this->schedule_entry( $this->schedule_entries( self::FINGERPRINT_OWNER ), self::FINGERPRINT_OWNER . ':fingerprint' );
		self::assertIsArray( $after );
		self::assertSame( 900, $after['recurrence'] );
		self::assertTrue( $after['occurrence_visible'] );
		self::assertNotSame( $before['next_due'], $after['next_due'], 'The changed recurrence must publish a replacement due time' );
		self::assertSame( $after['next_due'], ( new ActionSchedulerBackend( static fn (): bool => true ) )->get_next_scheduled( self::SCHEDULE_HOOK, array( self::FINGERPRINT_OWNER . ':fingerprint' ), self::FINGERPRINT_OWNER . ':fingerprint' ), 'The earliest backend occurrence must carry the replacement due time; a surviving superseded original would surface here first' );
	}

	/**
	 * An identical redeclaration performs no registry or scheduler write.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A remove-and-recreate sequence preserves public schedule reads while opening an absence window to concurrent runners; no public seam can prove the original Action Scheduler row was never replaced.
	 *
	 * @return  void
	 */
	public function test_identical_redeclaration_is_an_exact_noop(): void {
		$registry_option = ScheduleRegistry::option_name( self::NOOP_OWNER );
		$this->expect_option( $registry_option );
		$declaration = new Schedule( 'noop', Recurrence::every( 420 ), 'integration-declarative-noop-task', array( 'scope' => 'stable' ), OverlapPolicy::Allow, CatchUpPolicy::RunOnce, 42 );
		$this->assert_sync_succeeds( self::NOOP_OWNER, array( $declaration ) );
		$registration_key  = self::NOOP_OWNER . ':noop';
		$action_id         = $this->sole_pending_schedule_action_id( $registration_key );
		$action_snapshot   = $this->action_snapshot( $action_id );
		$registry_snapshot = \get_option( $registry_option, array() );
		self::assertIsArray( $registry_snapshot );
		$inspection_snapshot = $this->schedule_entries( self::NOOP_OWNER );

		$identical = new Schedule( 'noop', Recurrence::every( 420 ), 'integration-declarative-noop-task', array( 'scope' => 'stable' ), OverlapPolicy::Allow, CatchUpPolicy::RunOnce, 42 );
		$this->assert_sync_succeeds( self::NOOP_OWNER, array( $identical ) );

		self::assertSame( $inspection_snapshot, $this->schedule_entries( self::NOOP_OWNER ) );
		self::assertSame( array( $action_id ), $this->pending_schedule_action_ids( $registration_key ) );
		self::assertSame( $action_snapshot, $this->action_snapshot( $action_id ) );
		self::assertSame( $registry_snapshot, \get_option( $registry_option, array() ) );
	}

	/**
	 * Empty sync for one owner leaves another owner's registration and occurrence untouched.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_orphan_detection_is_scoped_to_the_synced_owner(): void {
		$this->expect_option( ScheduleRegistry::option_name( self::SCOPED_OWNER_B ) );
		$schedule_a = new Schedule( 'scoped-a', Recurrence::every( 360 ), 'integration-declarative-scoped-task-a' );
		$schedule_b = new Schedule( 'scoped-b', Recurrence::every( 720 ), 'integration-declarative-scoped-task-b', array( 'owner' => 'b' ), OverlapPolicy::Skip, CatchUpPolicy::Skip, 64 );
		$this->assert_sync_succeeds( self::SCOPED_OWNER_A, array( $schedule_a ) );
		$this->assert_sync_succeeds( self::SCOPED_OWNER_B, array( $schedule_b ) );
		$owner_b_snapshot = $this->schedule_entries( self::SCOPED_OWNER_B );

		$this->assert_sync_succeeds( self::SCOPED_OWNER_A, array() );

		self::assertSame( array(), $this->schedule_entries( self::SCOPED_OWNER_A ) );
		self::assertSame( $owner_b_snapshot, $this->schedule_entries( self::SCOPED_OWNER_B ) );
		self::assertFalse( ( new ActionSchedulerBackend( static fn (): bool => true ) )->is_scheduled( self::SCHEDULE_HOOK, array( self::SCOPED_OWNER_A . ':scoped-a' ), self::SCOPED_OWNER_A . ':scoped-a' ), 'Empty owner sync must cancel its own backend occurrence' );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Synchronizes one owner through the public consumer facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner     Owner being synchronized.
	 * @param   array  $schedules Complete declaration set.
	 *
	 * @return  void
	 *
	 * @phpstan-param list<Schedule> $schedules
	 */
	private function assert_sync_succeeds( string $owner, array $schedules ): void {
		$result = \a8csp_bgte( $owner )->schedules()->sync( $schedules );
		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
	}

	/**
	 * Returns observable schedule entries for one owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Owner to inspect.
	 *
	 * @return  list<array<string, mixed>>
	 */
	private function schedule_entries( string $owner ): array {
		$inspection = $this->inspection()->schedules( $owner );
		self::assertIsArray( $inspection );

		return $inspection['entries'];
	}

	/**
	 * Returns one observable schedule entry by identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<array<string, mixed>> $entries  Observable schedule entries.
	 * @param   string                     $identity Owner-qualified schedule identity.
	 *
	 * @return  array<string, mixed>|null
	 */
	private function schedule_entry( array $entries, string $identity ): ?array {
		return \array_find( $entries, static fn ( array $entry ): bool => ( $entry['name'] ?? null ) === $identity );
	}

	/**
	 * Returns pending engine occurrences matching one registry identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key Complete owner-qualified schedule identity.
	 *
	 * @return  list<string>
	 */
	private function pending_schedule_action_ids( string $registration_key ): array {
		$action_ids = $this->action_scheduler_store()->query_actions(
			array(
				'hook'     => self::SCHEDULE_HOOK,
				'args'     => array( $registration_key ),
				'group'    => $registration_key,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
		self::assertIsArray( $action_ids );

		return \array_values( \array_filter( $action_ids, '\\is_string' ) );
	}

	/**
	 * Returns the sole pending engine occurrence for one registry identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key Complete owner-qualified schedule identity.
	 *
	 * @return  string
	 */
	private function sole_pending_schedule_action_id( string $registration_key ): string {
		$action_ids = $this->pending_schedule_action_ids( $registration_key );
		self::assertCount( 1, $action_ids );

		return $action_ids[0];
	}

	/**
	 * Returns the Action Scheduler fields that must remain stable across the zero-write window.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $action_id Stored action identifier.
	 *
	 * @return  array{status: string, hook: string, args: array<array-key, mixed>, group: string, priority: int, scheduled_at: int, first_scheduled_at: int, recurrence: int|string, matching_ids: list<string>}
	 */
	private function action_snapshot( string $action_id ): array {
		$store  = $this->action_scheduler_store();
		$action = $store->fetch_action( $action_id );
		self::assertInstanceOf( \ActionScheduler_Action::class, $action );
		$hook = $action->get_hook();
		self::assertIsString( $hook );
		$args = $action->get_args();
		self::assertIsArray( $args );
		$schedule = $action->get_schedule();
		self::assertInstanceOf( \ActionScheduler_Abstract_RecurringSchedule::class, $schedule );
		$first_scheduled_at = $schedule->get_first_date();
		self::assertInstanceOf( \DateTime::class, $first_scheduled_at );

		// Status-blind so a same-identity duplicate in any other status breaks the canary.
		$matching_ids = $store->query_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $action->get_group(),
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
		self::assertIsArray( $matching_ids );
		$typed_matching_ids = array();
		foreach ( $matching_ids as $matching_id ) {
			self::assertIsString( $matching_id );
			$typed_matching_ids[] = $matching_id;
		}

		return array(
			'status'             => $store->get_status( $action_id ),
			'hook'               => $hook,
			'args'               => $args,
			'group'              => $action->get_group(),
			'priority'           => $action->get_priority(),
			'scheduled_at'       => $store->get_date( $action_id )->getTimestamp(),
			'first_scheduled_at' => $first_scheduled_at->getTimestamp(),
			'recurrence'         => $schedule->get_recurrence(),
			'matching_ids'       => $typed_matching_ids,
		);
	}

	// endregion.
}

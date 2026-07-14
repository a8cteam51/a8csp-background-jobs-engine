<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;

/**
 * Verifies declarative sync mutates only one owner's engine registration identities.
 */
final class DeclarativeSyncTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Persisted schedule registry option. */
	private const REGISTRY_OPTION = 'a8csp_bgte_schedules';

	/** Internal occurrence hook owned by the engine. */
	private const SCHEDULE_HOOK = 'a8csp/background_tasks/schedule_due';

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
	private const FINGERPRINT_OWNER = 'integration-declarative-fingerprint';

	/** Owner isolated to identical redeclaration. */
	private const NOOP_OWNER = 'integration-declarative-noop';

	/** First owner isolated to owner-scoped pruning. */
	private const SCOPED_OWNER_A = 'integration-declarative-owner-a';

	/** Second owner isolated to owner-scoped pruning. */
	private const SCOPED_OWNER_B = 'integration-declarative-owner-b';

	/**
	 * Exact cron option captured after the foreign occurrence is seeded.
	 *
	 * @var array<array-key, mixed>|null
	 */
	private ?array $foreign_cron_snapshot = null;

	/** Foreign Action Scheduler row identifier. */
	private ?string $foreign_action_id = null;

	/**
	 * Public Action Scheduler state captured for the foreign occurrence.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $foreign_action_snapshot = null;

	/** Attempted cron-option writes after the foreign fixture is seeded. */
	private int $cron_option_writes = 0;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Seeds foreign recurring work before each sync contract runs.
	 *
	 * @return  void
	 */
	protected function setUp(): void {
		parent::setUp();

		$foreign_cron_at = \time() + 2 * \HOUR_IN_SECONDS;
		$cron_scheduled  = \wp_schedule_event(
			$foreign_cron_at,
			'hourly',
			self::FOREIGN_CRON_HOOK,
			self::FOREIGN_CRON_ARGS,
			true
		);
		self::assertTrue(
			true === $cron_scheduled,
			'The foreign-safety fixture must persist its built-in WP-Cron occurrence'
		);

		$foreign_action_id = \as_schedule_recurring_action(
			$foreign_cron_at + \MINUTE_IN_SECONDS,
			17 * \MINUTE_IN_SECONDS,
			self::FOREIGN_ACTION_HOOK,
			self::FOREIGN_ACTION_ARGS,
			self::FOREIGN_ACTION_GROUP,
			false,
			73
		);
		self::assertGreaterThan(
			0,
			$foreign_action_id,
			'The foreign-safety fixture must persist its raw Action Scheduler occurrence'
		);
		$this->foreign_action_id = (string) $foreign_action_id;

		$cron = \get_option( 'cron', array() );
		self::assertIsArray( $cron );
		$this->foreign_cron_snapshot   = $cron;
		$this->foreign_action_snapshot = $this->action_snapshot( $this->foreign_action_id );

		// Terminal cron equality alone would accept an unschedule-and-re-add absence window.
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
	 * @return  void
	 */
	protected function tearDown(): void {
		try {
			self::assertSame(
				0,
				$this->cron_option_writes,
				'Declarative sync must never write the cron option, not even rewriting identical state'
			);
			if ( null !== $this->foreign_cron_snapshot ) {
				self::assertSame(
					$this->foreign_cron_snapshot,
					\get_option( 'cron', array() ),
					'Declarative sync must leave the complete foreign WP-Cron option unchanged'
				);
			}
		} finally {
			try {
				if ( null !== $this->foreign_action_id && null !== $this->foreign_action_snapshot ) {
					self::assertSame(
						$this->foreign_action_snapshot,
						$this->action_snapshot( $this->foreign_action_id ),
						'Declarative sync must leave every public field of the foreign Action Scheduler occurrence unchanged'
					);
				}
			} finally {
				parent::tearDown();
			}
		}
	}

	// endregion.

	// region TESTS.

	/**
	 * Removing one declaration prunes only its registry row and backend occurrence.
	 *
	 * @return  void
	 */
	public function test_owner_sync_prunes_an_orphan_registration_and_occurrence(): void {
		$this->expect_option( self::REGISTRY_OPTION );

		$schedule_a = new Schedule(
			'orphan-a',
			Recurrence::every( 300 ),
			'integration-declarative-orphan-task-a'
		);
		$schedule_b = new Schedule(
			'orphan-b',
			Recurrence::every( 600 ),
			'integration-declarative-orphan-task-b'
		);
		$this->assert_sync_succeeds( self::ORPHAN_OWNER, array( $schedule_a, $schedule_b ) );

		$registration_key_a = self::ORPHAN_OWNER . ':orphan-a';
		$registration_key_b = self::ORPHAN_OWNER . ':orphan-b';
		$retained_action_id = $this->sole_pending_schedule_action_id( $registration_key_a );
		$retained_snapshot  = $this->action_snapshot( $retained_action_id );
		$orphan_action_id   = $this->sole_pending_schedule_action_id( $registration_key_b );
		self::assertSame(
			\ActionScheduler_Store::STATUS_PENDING,
			$this->action_scheduler_store()->get_status( $orphan_action_id ),
			'The orphan candidate must begin as a pending occurrence'
		);

		$this->assert_sync_succeeds( self::ORPHAN_OWNER, array( $schedule_a ) );

		self::assertSame(
			array( $retained_action_id ),
			$this->pending_schedule_action_ids( $registration_key_a ),
			'Orphan pruning must retain the declared sibling backend occurrence'
		);
		self::assertSame(
			$retained_snapshot,
			$this->action_snapshot( $retained_action_id ),
			'Orphan pruning must leave every public field of the declared sibling occurrence untouched'
		);
		self::assertSame(
			array(),
			$this->pending_schedule_action_ids( $registration_key_b ),
			'Orphan pruning must remove the missing declaration from the pending backend store'
		);
		self::assertSame(
			\ActionScheduler_Store::STATUS_CANCELED,
			$this->action_scheduler_store()->get_status( $orphan_action_id ),
			'Orphan pruning must cancel the exact missing backend occurrence'
		);

		$owner_rows = $this->owner_registry_rows( self::ORPHAN_OWNER );
		self::assertSame(
			array( 'orphan-a' ),
			\array_keys( $owner_rows ),
			'Orphan pruning must retain only declarations present in the owner replacement set'
		);
		self::assertArrayNotHasKey(
			'orphan-b',
			$owner_rows,
			'Orphan pruning must delete the missing declaration registry row'
		);
	}

	/**
	 * A changed definition cancels the old occurrence and persists a replacement fingerprint.
	 *
	 * @return  void
	 */
	public function test_fingerprint_change_reschedules_the_occurrence(): void {
		$this->expect_option( self::REGISTRY_OPTION );

		$original    = new Schedule(
			'fingerprint',
			Recurrence::every( 300 ),
			'integration-declarative-fingerprint-task',
			array( 'mode' => 'original' ),
			OverlapPolicy::Skip,
			CatchUpPolicy::RunOnce,
			21
		);
		$replacement = new Schedule(
			'fingerprint',
			Recurrence::every( 900 ),
			'integration-declarative-fingerprint-task',
			array( 'mode' => 'replacement' ),
			OverlapPolicy::Replace,
			CatchUpPolicy::Skip,
			22
		);
		$this->assert_sync_succeeds( self::FINGERPRINT_OWNER, array( $original ) );

		$registration_key   = self::FINGERPRINT_OWNER . ':fingerprint';
		$original_action_id = $this->sole_pending_schedule_action_id( $registration_key );
		$original_row       = $this->registration_row( self::FINGERPRINT_OWNER, 'fingerprint' );
		self::assertSame( $original->fingerprint(), $original_row['fingerprint'] ?? null );

		$this->assert_sync_succeeds( self::FINGERPRINT_OWNER, array( $replacement ) );

		self::assertSame(
			\ActionScheduler_Store::STATUS_CANCELED,
			$this->action_scheduler_store()->get_status( $original_action_id ),
			'Fingerprint replacement must cancel the exact superseded occurrence'
		);
		$replacement_action_id = $this->sole_pending_schedule_action_id( $registration_key );
		self::assertNotSame(
			$original_action_id,
			$replacement_action_id,
			'Fingerprint replacement must persist a distinct backend occurrence'
		);

		$replacement_row = $this->registration_row( self::FINGERPRINT_OWNER, 'fingerprint' );
		self::assertSame(
			$replacement->fingerprint(),
			$replacement_row['fingerprint'] ?? null,
			'Fingerprint replacement must persist the complete changed definition identity'
		);
		self::assertNotSame(
			$original_row['fingerprint'] ?? null,
			$replacement_row['fingerprint'] ?? null,
			'Changed recurrence, arguments, and policies must produce a new persisted fingerprint'
		);
		self::assertNotSame(
			$original_row['next_due'] ?? null,
			$replacement_row['next_due'] ?? null,
			'Fingerprint replacement must schedule from the changed recurrence'
		);
		$replacement_snapshot = $this->action_snapshot( $replacement_action_id );
		self::assertSame(
			$replacement_row['next_due'] ?? null,
			$replacement_snapshot['store_scheduled_at'],
			'The replacement backend occurrence must use the persisted next-due timestamp'
		);
		self::assertSame(
			900,
			$replacement_snapshot['recurrence'],
			'The replacement backend occurrence must recur on the changed recurrence'
		);
		self::assertSame(
			22,
			$replacement_snapshot['priority'],
			'The replacement backend occurrence must carry the changed advisory priority'
		);
	}

	/**
	 * An identical redeclaration leaves the registry and backend occurrence exactly stable.
	 *
	 * @return  void
	 */
	public function test_identical_redeclaration_is_an_exact_noop(): void {
		$this->expect_option( self::REGISTRY_OPTION );

		$declaration = new Schedule(
			'noop',
			Recurrence::every( 420 ),
			'integration-declarative-noop-task',
			array( 'scope' => 'stable' ),
			OverlapPolicy::Allow,
			CatchUpPolicy::RunOnce,
			42
		);
		$this->assert_sync_succeeds( self::NOOP_OWNER, array( $declaration ) );

		$registration_key  = self::NOOP_OWNER . ':noop';
		$action_id         = $this->sole_pending_schedule_action_id( $registration_key );
		$action_snapshot   = $this->action_snapshot( $action_id );
		$registry_snapshot = \get_option( self::REGISTRY_OPTION, array() );
		self::assertIsArray( $registry_snapshot );
		$next_due = $this->registration_row( self::NOOP_OWNER, 'noop' )['next_due'] ?? null;

		$identical = new Schedule(
			'noop',
			Recurrence::every( 420 ),
			'integration-declarative-noop-task',
			array( 'scope' => 'stable' ),
			OverlapPolicy::Allow,
			CatchUpPolicy::RunOnce,
			42
		);
		$this->assert_sync_succeeds( self::NOOP_OWNER, array( $identical ) );

		self::assertSame(
			array( $action_id ),
			$this->pending_schedule_action_ids( $registration_key ),
			'Identical redeclaration must retain the exact pending action ID'
		);
		self::assertSame(
			$action_snapshot,
			$this->action_snapshot( $action_id ),
			'Identical redeclaration must leave every public backend occurrence field untouched'
		);
		self::assertSame(
			$next_due,
			$this->registration_row( self::NOOP_OWNER, 'noop' )['next_due'] ?? null,
			'Identical redeclaration must retain the exact next-due timestamp'
		);
		self::assertSame(
			$registry_snapshot,
			\get_option( self::REGISTRY_OPTION, array() ),
			'Identical redeclaration must leave the complete registry option untouched'
		);
	}

	/**
	 * Empty sync for one owner leaves another owner's registration and occurrence untouched.
	 *
	 * @return  void
	 */
	public function test_orphan_detection_is_scoped_to_the_synced_owner(): void {
		$this->expect_option( self::REGISTRY_OPTION );

		$schedule_a = new Schedule(
			'scoped-a',
			Recurrence::every( 360 ),
			'integration-declarative-scoped-task-a'
		);
		$schedule_b = new Schedule(
			'scoped-b',
			Recurrence::every( 720 ),
			'integration-declarative-scoped-task-b',
			array( 'owner' => 'b' ),
			OverlapPolicy::Skip,
			CatchUpPolicy::Skip,
			64
		);
		$this->assert_sync_succeeds( self::SCOPED_OWNER_A, array( $schedule_a ) );
		$this->assert_sync_succeeds( self::SCOPED_OWNER_B, array( $schedule_b ) );

		$registration_key_a = self::SCOPED_OWNER_A . ':scoped-a';
		$registration_key_b = self::SCOPED_OWNER_B . ':scoped-b';
		$action_id_a        = $this->sole_pending_schedule_action_id( $registration_key_a );
		$action_id_b        = $this->sole_pending_schedule_action_id( $registration_key_b );
		$owner_b_snapshot   = $this->owner_registry_rows( self::SCOPED_OWNER_B );
		$action_b_snapshot  = $this->action_snapshot( $action_id_b );

		$this->assert_sync_succeeds( self::SCOPED_OWNER_A, array() );

		$registry = $this->registry();
		self::assertArrayNotHasKey(
			self::SCOPED_OWNER_A,
			$registry,
			'Empty owner sync must prune only the synchronized owner registry branch'
		);
		self::assertSame(
			$owner_b_snapshot,
			$registry[ self::SCOPED_OWNER_B ] ?? null,
			'One owner sync must leave the other owner registry branch exactly unchanged'
		);
		self::assertSame(
			array( $action_id_b ),
			$this->pending_schedule_action_ids( $registration_key_b ),
			'One owner sync must retain the other owner pending action ID'
		);
		self::assertSame(
			$action_b_snapshot,
			$this->action_snapshot( $action_id_b ),
			'One owner sync must leave every public field of the other owner occurrence untouched'
		);
		self::assertSame(
			array(),
			$this->pending_schedule_action_ids( $registration_key_a ),
			'Empty owner sync must remove its own pending occurrence'
		);
		self::assertSame(
			\ActionScheduler_Store::STATUS_CANCELED,
			$this->action_scheduler_store()->get_status( $action_id_a ),
			'Empty owner sync must cancel its own exact occurrence'
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Synchronizes one owner through the live public engine and checks its Result contract.
	 *
	 * @phpstan-param list<Schedule> $schedules
	 *
	 * @param   string $owner     Owner being synchronized.
	 * @param   array  $schedules Complete declaration set.
	 *
	 * @return  void
	 */
	private function assert_sync_succeeds( string $owner, array $schedules ): void {
		$engine = \a8csp_bgte_engine();
		self::assertNotNull( $engine, 'The live plugin must publish its engine before declarative sync runs' );

		$result = $engine->schedules()->sync( $owner, $schedules );
		self::assertInstanceOf( Success::class, $result, 'Declarative sync must return a checked success Result' );
		self::assertTrue( true === $result->value, 'Declarative sync success must carry true' );
	}

	/**
	 * Returns the complete persisted registry.
	 *
	 * @return  array<array-key, mixed>
	 */
	private function registry(): array {
		$registry = \get_option( self::REGISTRY_OPTION, array() );
		self::assertIsArray( $registry );

		return $registry;
	}

	/**
	 * Returns one owner's complete registry branch.
	 *
	 * @param   string $owner Owner to read.
	 *
	 * @return  array<array-key, mixed>
	 */
	private function owner_registry_rows( string $owner ): array {
		$owner_rows = $this->registry()[ $owner ] ?? null;
		self::assertIsArray( $owner_rows, 'Declarative sync must persist the synchronized owner registry branch' );

		return $owner_rows;
	}

	/**
	 * Returns one persisted registration row.
	 *
	 * @param   string $owner Owner to read.
	 * @param   string $name  Schedule to read.
	 *
	 * @return  array<array-key, mixed>
	 */
	private function registration_row( string $owner, string $name ): array {
		$row = $this->owner_registry_rows( $owner )[ $name ] ?? null;
		self::assertIsArray( $row, 'Declarative sync must persist the requested registration row' );

		return $row;
	}

	/**
	 * Returns pending engine occurrences matching one registry-key identity.
	 *
	 * @param   string $registration_key Complete `{owner}:{name}` identity.
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
		$typed_action_ids = array();
		foreach ( $action_ids as $action_id ) {
			self::assertIsString( $action_id );
			$typed_action_ids[] = $action_id;
		}

		return $typed_action_ids;
	}

	/**
	 * Returns the sole pending engine occurrence for one registry-key identity.
	 *
	 * @param   string $registration_key Complete `{owner}:{name}` identity.
	 *
	 * @return  string
	 */
	private function sole_pending_schedule_action_id( string $registration_key ): string {
		$action_ids = $this->pending_schedule_action_ids( $registration_key );
		self::assertCount(
			1,
			$action_ids,
			'The synchronized registration must own exactly one pending backend occurrence'
		);
		$action_id = $action_ids[0] ?? null;
		self::assertIsString( $action_id );

		return $action_id;
	}

	/**
	 * Returns every public Action Scheduler field that identifies a recurring occurrence.
	 *
	 * @param   string $action_id Stored action identifier.
	 *
	 * @return  array{id: string, status: string, hook: string, args: array<array-key, mixed>, group: string, priority: int, schedule_class: class-string, scheduled_at: int, first_scheduled_at: int, recurrence: int|string, store_scheduled_at: int, matching_ids: list<string>}
	 */
	private function action_snapshot( string $action_id ): array {
		$store  = $this->action_scheduler_store();
		$action = $store->fetch_action( $action_id );
		self::assertInstanceOf( \ActionScheduler_Action::class, $action );

		$hook = $action->get_hook();
		self::assertIsString( $hook );
		$args = $action->get_args();
		self::assertIsArray( $args );
		$group = $action->get_group();
		self::assertIsString( $group );
		$priority = $action->get_priority();
		self::assertIsInt( $priority );
		$status = $store->get_status( $action_id );
		self::assertIsString( $status );

		$schedule = $action->get_schedule();
		self::assertInstanceOf( \ActionScheduler_Abstract_RecurringSchedule::class, $schedule );
		$scheduled_at = $schedule->get_date();
		self::assertInstanceOf( \DateTime::class, $scheduled_at );
		$first_scheduled_at = $schedule->get_first_date();
		self::assertInstanceOf( \DateTime::class, $first_scheduled_at );
		$recurrence         = $schedule->get_recurrence();
		$store_scheduled_at = $store->get_date( $action_id );
		self::assertInstanceOf( \DateTime::class, $store_scheduled_at );

		// Status-blind so a same-identity duplicate in any other status breaks the canary.
		$matching_ids = $store->query_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $group,
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
			'id'                 => $action_id,
			'status'             => $status,
			'hook'               => $hook,
			'args'               => $args,
			'group'              => $group,
			'priority'           => $priority,
			'schedule_class'     => $schedule::class,
			'scheduled_at'       => $scheduled_at->getTimestamp(),
			'first_scheduled_at' => $first_scheduled_at->getTimestamp(),
			'recurrence'         => $recurrence,
			'store_scheduled_at' => $store_scheduled_at->getTimestamp(),
			'matching_ids'       => $typed_matching_ids,
		);
	}

	// endregion.
}

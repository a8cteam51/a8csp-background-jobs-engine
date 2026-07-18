<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Maintenance;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkIdentity;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Maintenance\MaintenanceSchedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Maintenance\MaintenanceTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\Schedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RowDeleteOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\EngineRig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the engine-owned maintenance recurrence survives registry recovery.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( MaintenanceSchedule::class )]
#[UsesClass( MaintenanceTask::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
#[UsesClass( ScheduleRegistry::class )]
#[UsesClass( Schedules::class )]
final class MaintenanceScheduleTest extends TestCase {
	private const int NOW = 1_700_000_000;

	private EngineRig $rig;

	/** Loads the guarded WordPress seams before the production graph is built. */
	#[\Override]
	public static function setUpBeforeClass(): void {
		EngineRig::bootstrap();
	}

	/** Builds one production graph with its maintenance schedule already declared. */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig = EngineRig::set_up( self::NOW );
	}

	/** Releases request-local engine state. */
	#[\Override]
	protected function tearDown(): void {
		try {
			$this->rig->tear_down();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Corruption cannot cancel the maintenance chain, and the next sync recreates a reclaimed row.
	 *
	 * @return  void
	 */
	public function test_corrupt_registry_preserves_the_chain_until_reclaim_and_next_sync(): void {
		$engine = Component::get_engine();
		self::assertNotNull( $engine );
		$maintenance = new MaintenanceSchedule( $engine->schedules, $this->rig->logger() );
		$option_name = ScheduleRegistry::option_name( WorkIdentity::ENGINE_OWNER );
		$poison      = 'poison-maintenance-registry-row';
		$this->rig->wpdb()->put( $option_name, $poison );
		$this->rig->backend()->scheduled = true;
		$this->rig->backend()->calls     = array();
		$this->rig->logger()->records    = array();

		$maintenance->sync_maintenance_schedule();

		self::assertSame( array(), $this->rig->backend()->calls );
		self::assertSame( $poison, $this->rig->wpdb()->rows[ $option_name ] ?? null );
		self::assertSame(
			array(
				'Schedule registry option row is unreadable; maintenance reclaims it, then re-declare schedules on the next init.',
				'Engine maintenance schedule could not be synchronized: {error}',
			),
			\array_column( $this->rig->logger()->records, 'message' )
		);

		$rows = new OptionRows( $this->rig->wpdb() );
		self::assertSame( RowDeleteOutcome::Deleted, $rows->delete_if_value_matches( $option_name, $poison ) );
		$this->rig->backend()->calls  = array();
		$this->rig->logger()->records = array();

		$maintenance->sync_maintenance_schedule();

		$raw = $this->rig->wpdb()->rows[ $option_name ] ?? null;
		self::assertIsString( $raw );
		$registrations = RawOptionDecoder::decode( $raw );
		self::assertIsArray( $registrations );
		self::assertArrayHasKey( WorkIdentity::compose( WorkIdentity::ENGINE_OWNER, MaintenanceTask::NAME, true ), $registrations );
		self::assertSame( array( 'unschedule', 'schedule_recurring' ), \array_values( \array_filter( \array_column( $this->rig->backend()->calls, 'verb' ), static fn ( string $verb ): bool => \in_array( $verb, array( 'unschedule', 'schedule_recurring' ), true ) ) ) );
		self::assertSame( array(), $this->rig->logger()->records );
	}
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Maintenance;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Maintenance\MaintenanceJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Maintenance\MaintenanceSchedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RowDeleteOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
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
#[UsesClass( MaintenanceJob::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
#[UsesClass( ScheduleRegistry::class )]
#[UsesClass( ScheduleOperations::class )]
final class MaintenanceScheduleTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const int NOW = 1_700_000_000;

	private EngineRig $rig;

	// endregion.

	// region LIFECYCLE.

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

	// endregion.

	// region TESTS.

	/**
	 * Corruption cannot cancel the maintenance chain, and the next sync recreates a reclaimed row.
	 *
	 * @return  void
	 */
	public function test_corrupt_registry_preserves_the_chain_until_reclaim_and_next_sync(): void {
		$engine = Component::get_engine();
		self::assertNotNull( $engine );
		$maintenance = new MaintenanceSchedule( $engine->schedules, $this->rig->logger() );
		$option_name = ScheduleRegistry::option_name( Identity::ENGINE_SCOPE );
		$poison      = 'poison-maintenance-registry-row';
		$this->rig->wpdb()->put( $option_name, $poison );
		$this->rig->backend()->scheduled = true;
		$this->rig->backend()->calls     = array();
		$this->rig->logger()->records    = array();

		$maintenance->sync_maintenance_schedule();

		self::assertSame( array(), $this->rig->backend()->calls );
		self::assertSame( $poison, $this->rig->wpdb()->rows[ $option_name ] ?? null );
		$records = $this->rig->logger()->records;
		self::assertCount( 2, $records );
		self::assertSame( array( 'warning', 'error' ), \array_column( $records, 'level' ) );
		self::assertSame( $option_name, $records[0]['context']['option_name'] ?? null );
		self::assertArrayHasKey( 'error', $records[1]['context'] );

		$rows = new OptionRows( $this->rig->wpdb() );
		self::assertSame( RowDeleteOutcome::Deleted, $rows->delete_if_value_matches( $option_name, $poison ) );
		$this->rig->backend()->calls  = array();
		$this->rig->logger()->records = array();

		$maintenance->sync_maintenance_schedule();

		$raw = $this->rig->wpdb()->rows[ $option_name ] ?? null;
		self::assertIsString( $raw );
		$registrations = RawOptionDecoder::decode( $raw );
		self::assertIsArray( $registrations );
		self::assertArrayHasKey( (string) Identity::compose( Identity::ENGINE_SCOPE, MaintenanceJob::NAME, true ), $registrations );
		self::assertSame( array( 'unschedule', 'schedule_recurring' ), \array_values( \array_filter( \array_column( $this->rig->backend()->calls, 'verb' ), static fn ( string $verb ): bool => \in_array( $verb, array( 'unschedule', 'schedule_recurring' ), true ) ) ) );
		self::assertSame( array(), $this->rig->logger()->records );
	}

	/**
	 * Engine-owned per-request synchronization stays silent while a backend candidate is dormant.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dormant_backend_does_not_warn_during_engine_maintenance_sync(): void {
		$this->rig->tear_down();
		$this->rig = EngineRig::set_up( self::NOW, 2 );
		$engine    = Component::get_engine();
		self::assertNotNull( $engine );
		$maintenance                  = new MaintenanceSchedule( $engine->schedules, $this->rig->logger() );
		$backends                     = $this->rig->backends();
		$backends[0]->ready           = false;
		$this->rig->logger()->records = array();

		$maintenance->sync_maintenance_schedule();

		self::assertSame( array(), $this->rig->logger()->records );
	}

	// endregion.
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\CLI;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\CLI\Commands\ResetCommand;
use A8C\SpecialProjects\BackgroundTasksEngine\CLI\Output\ResetOutput;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\CliHarness;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the registered development-reset command against real engine state.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( ResetCommand::class )]
#[CoversClass( ResetOutput::class )]
final class ResetCommandTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string MAINTENANCE_CURSOR_OPTION = 'a8csp_bgte_maintenance_sweep';
	private const string UNRELATED_OPTION          = 'consumer_plugin_state';

	private EngineRig $rig;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads the engine and WP-CLI boundary fakes before command registration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		EngineRig::bootstrap();
		require_once \dirname( __DIR__, 2 ) . '/Support/WpCliRuntimeStub.php';
	}

	/**
	 * Boots one production graph and captures the real reset registration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig = EngineRig::set_up();
		CliHarness::set_up();
	}

	/**
	 * Releases request-local engine state after each reset scenario.
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
	 * An acknowledged reset removes production-created rows and pending engine actions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registered_reset_purges_real_engine_state_and_reports_counts(): void {
		$this->seed_engine_state();
		$this->rig->wpdb()->put( self::MAINTENANCE_CURSOR_OPTION, 'run:a8csp-bgte:maintenance' );
		$this->rig->wpdb()->put( self::UNRELATED_OPTION, 'keep' );
		$this->rig->backend()->pending_actions[ ActionDeliveries::RUN_TASK_HOOK ]  = 2;
		$this->rig->backend()->pending_actions[ ActionDeliveries::RUN_CHUNK_HOOK ] = 3;
		$owned_before = $this->engine_option_names();
		self::assertNotEmpty( $owned_before );

		$result = CliHarness::run( 'reset', array(), array( 'yes' => true ) );

		self::assertSame( 0, $result->exit_code );
		self::assertSame( '', $result->stderr );
		self::assertStringContainsString( 'Option rows deleted: ' . \count( $owned_before ), $result->stdout );
		self::assertStringContainsString( 'Pending backend actions unscheduled: 5', $result->stdout );
		self::assertStringContainsString( 'Success: Background tasks development state reset.', $result->stdout );
		self::assertSame( array(), $this->engine_option_names() );
		self::assertSame( array(), $this->rig->backend()->pending_actions );
		self::assertSame( 'keep', $this->rig->wpdb()->rows[ self::UNRELATED_OPTION ] ?? null );
	}

	/**
	 * Declining the interactive gate preserves every row and pending backend action byte-for-byte.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_declined_confirmation_prevents_every_mutation(): void {
		$result = CliHarness::run_interactive( 'reset-declined', "n\n" );
		$probe  = \json_decode( $result->probe, true, 512, \JSON_THROW_ON_ERROR );

		self::assertSame( 0, $result->exit_code );
		self::assertSame( 'This development reset permanently deletes every engine option row and pending backend action. In-flight work cannot be recovered. Continue? [y/n] ', $result->stdout );
		self::assertSame( '', $result->stderr );
		self::assertIsArray( $probe );
		$before = $probe['before'] ?? null;
		$after  = $probe['after'] ?? null;
		self::assertIsArray( $before );
		self::assertIsArray( $after );
		$rows = $before['wpdb'] ?? null;
		self::assertIsArray( $rows );
		self::assertNotEmpty( $rows );
		self::assertSame( $before, $after );
	}

	/**
	 * A dormant backend aborts before any authoritative row can be deleted.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registered_reset_preserves_rows_when_backend_clearance_is_unknown(): void {
		$this->seed_engine_state();
		$this->rig->backend()->ready = false;
		$before                      = $this->rig->wpdb()->rows;

		$result = CliHarness::run( 'reset', array(), array( 'yes' => true ) );

		self::assertSame( 1, $result->exit_code );
		self::assertStringContainsString( 'backend', \strtolower( $result->stderr ) );
		self::assertSame( $before, $this->rig->wpdb()->rows );
	}

	/**
	 * A row changed after discovery aborts the reset with the concurrency-specific correction.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A public rival schedule sync replaces the selected registry generation at the exact delete boundary; the registered command is the only public seam exposing the reset correction.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registered_reset_reports_a_row_changed_during_reset(): void {
		$this->seed_engine_state();
		unset( $this->rig->wpdb()->rows[ ScheduleRegistry::option_name( 'a8csp-bgte' ) ] );
		$option_name = ScheduleRegistry::option_name( 'reset-tests' );
		$before      = $this->rig->wpdb()->rows;
		$this->rig->wpdb()->before_next(
			'delete',
			function (): void {
				$consumer = $this->rig->consumer( 'reset-tests' );
				self::assertInstanceOf( Success::class, $consumer->schedules()->sync( array( new Schedule( 'nightly', Recurrence::every( 600 ), 'refresh' ) ) ) );
			}
		);

		$result = CliHarness::run( 'reset', array(), array( 'yes' => true ) );

		self::assertSame( 1, $result->exit_code );
		self::assertSame( 'Error: Engine option row "' . $option_name . '" changed during reset after 0 deletions; stop background writes and retry.' . "\n", $result->stderr );
		self::assertNotSame( $before[ $option_name ] ?? null, $this->rig->wpdb()->rows[ $option_name ] ?? null );
	}

	/**
	 * A database delete failure aborts the reset with the storage-specific correction.
	 *
	 * @load-bearing security
	 * @pin-rationale A scripted authoritative delete failure proves the destructive command stops at the database boundary; no public operation can force wpdb to reject one exact DELETE.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registered_reset_reports_a_database_delete_failure(): void {
		$this->seed_engine_state();
		$before = $this->rig->wpdb()->rows;
		$this->rig->wpdb()->script_result( 'delete', false );

		$result = CliHarness::run( 'reset', array(), array( 'yes' => true ) );

		self::assertSame( 1, $result->exit_code );
		self::assertSame( "Error: The database delete for engine option rows failed after 0 deletions; repair the database error and retry the reset.\n", $result->stderr );
		self::assertSame( $before, $this->rig->wpdb()->rows );
	}

	/**
	 * Invalid command shapes fail before destructive work begins.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registered_reset_rejects_undocumented_arguments(): void {
		$this->seed_engine_state();
		$before = $this->rig->wpdb()->rows;

		$result = CliHarness::run( 'reset', array( 'extra' ), array( 'yes' => true ) );

		self::assertSame( 1, $result->exit_code );
		self::assertSame( "Error: Reset accepts only --yes; use wp background-tasks reset [--yes].\n", $result->stderr );
		self::assertSame( $before, $this->rig->wpdb()->rows );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Creates schedule and run rows through the public production graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function seed_engine_state(): void {
		$consumer = $this->rig->consumer( 'reset-tests' );
		$consumer->tasks()->register( new RecordingTask( 'refresh' ) );
		self::assertInstanceOf( Success::class, $consumer->tasks()->enqueue( 'refresh', array( 'site_id' => 7 ) ) );
		self::assertInstanceOf( Success::class, $consumer->schedules()->sync( array( new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh' ) ) ) );
	}

	/**
	 * Returns every engine-owned option name across both storage seams.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<string>
	 */
	private function engine_option_names(): array {
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? array();
		self::assertIsArray( $options );
		$names = \array_values( \array_filter( \array_unique( \array_merge( \array_keys( $this->rig->wpdb()->rows ), \array_keys( $options ) ) ), static fn ( string $name ): bool => \str_starts_with( $name, 'a8csp_bgte_' ) ) );
		\sort( $names, \SORT_STRING );

		return $names;
	}

	// endregion.
}

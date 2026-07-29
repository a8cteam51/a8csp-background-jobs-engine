<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Locks;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockRepair;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockRepairPlan;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins explicit malformed-lock recovery to exhaustive preflight and independent exact-row fences.
 *
 * @load-bearing concurrency
 * @pin-rationale Repair must supersede every observed matching run before deleting only the exact malformed lock generation.
 * @fixture StoreFixtureBuilder
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( LockRepair::class )]
#[CoversClass( LockRepairPlan::class )]
#[UsesClass( LifecycleEffects::class )]
#[UsesClass( LockWindows::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( RunTransitions::class )]
#[UsesClass( RunStore::class )]
#[UsesClass( StoreFactory::class )]
#[UsesClass( OptionRows::class )]
final class LockRepairTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string ARGS_HASH     = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const string IDENTITY      = 'repair-tests:reports';
	private const int NOW              = 1_700_000_000;
	private const string OTHER_HASH    = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	private const string RUN_ID        = '00000000001700000000-0000000000000000042';
	private const string SECOND_RUN_ID = '00000000001700000000-0000000000000000043';
	private const string THIRD_RUN_ID  = '00000000001700000000-0000000000000000044';
	private const string FOURTH_RUN_ID = '00000000001700000000-0000000000000000045';

	private StoreFixtureBuilder $fixtures;
	private Identity $identity;
	private EngineRig $rig;
	private LockRepair $repair;
	private OptionRows $rows;
	private StoreFactory $stores;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded production files before collaborators are built.
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
	 * Builds one deterministic repair graph.
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
		$this->identity = Identity::compose( 'repair-tests', 'reports' );
		$this->rows     = new OptionRows( $this->rig->wpdb() );
		$guard          = new OverlapGuard( $this->rig->clock(), $this->rig->logger(), $this->rows, new LockWindows( $this->rig->clock(), $this->rig->logger() ) );
		$this->stores   = new StoreFactory( $this->rig->clock(), $this->rows, $this->rig->logger() );
		$lock_windows   = new LockWindows( $this->rig->clock(), $this->rig->logger() );
		$effects        = new LifecycleEffects( $guard, $this->stores, $this->rig->logger() );
		$transitions    = new RunTransitions( $guard, $this->stores, $this->rig->clock(), $lock_windows, $this->rig->logger(), $effects );
		$this->repair   = new LockRepair( $this->rows, $guard, $this->stores, $lock_windows, $transitions );
		$this->fixtures = StoreFixtureBuilder::for_identity( self::IDENTITY );
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
	 * Repair supersedes a matching Running row before deleting the exact malformed lock.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_repair_supersedes_every_matching_running_run_before_lock_delete(): void {
		$lock_name = $this->put_malformed_lock( self::IDENTITY, self::ARGS_HASH, 'malformed-lock' );
		$this->put_running_run( self::IDENTITY, self::RUN_ID, self::ARGS_HASH );
		$this->put_running_run( self::IDENTITY, self::SECOND_RUN_ID, self::ARGS_HASH );
		$other_lane = $this->put_running_run( self::IDENTITY, self::THIRD_RUN_ID, self::OTHER_HASH );
		$terminal   = $this->fixtures->run( self::FOURTH_RUN_ID, $this->running_state( self::ARGS_HASH )->with_status( RunStatus::Superseded )->with_pending( null ) );
		$this->put_fixture( $terminal );
		$plan = $this->ready_plan( $this->identity );
		self::assertSame( 2, $plan->running_run_count );
		$this->rig->wpdb()->recorded_queries = array();

		$repaired = $this->repair->repair( $plan );

		self::assertInstanceOf( Success::class, $repaired );
		self::assertSame( 2, $repaired->value );
		self::assertArrayNotHasKey( $lock_name, $this->rig->wpdb()->rows );
		$write_queries = \array_values(
			\array_filter(
				$this->rig->wpdb()->recorded_queries,
				static fn ( string $query ): bool => \str_starts_with( $query, 'UPDATE ' ) || \str_starts_with( $query, 'DELETE ' )
			)
		);
		self::assertCount( 3, $write_queries );
		self::assertStringStartsWith( 'UPDATE ', $write_queries[0] );
		self::assertStringStartsWith( 'UPDATE ', $write_queries[1] );
		self::assertStringStartsWith( 'DELETE ', $write_queries[2] );
		$inspected = $this->stores->run_store( $this->identity )->inspect( self::RUN_ID );
		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		self::assertInstanceOf( RunState::class, $inspected->value['state'] );
		self::assertSame( RunStatus::Superseded, $inspected->value['state']->status );
		self::assertNull( $inspected->value['state']->pending );
		self::assertSame( array(), $inspected->value['state']->effects );
		$second = $this->stores->run_store( $this->identity )->inspect( self::SECOND_RUN_ID );
		self::assertInstanceOf( Success::class, $second );
		self::assertIsArray( $second->value );
		$second_state = $second->value['state'] ?? null;
		self::assertInstanceOf( RunState::class, $second_state );
		self::assertSame( RunStatus::Superseded, $second_state->status );
		self::assertSame( $other_lane[1], $this->rig->wpdb()->rows[ $other_lane[0] ] ?? null );
		self::assertSame( $terminal[1], $this->rig->wpdb()->rows[ $terminal[0] ] ?? null );
	}

	/**
	 * An identity without a malformed lane yields an idempotent no-op preparation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_prepare_reports_nothing_to_repair_without_mutation(): void {
		$owned = $this->fixtures->lock( self::ARGS_HASH, self::RUN_ID, self::NOW, self::NOW );
		$this->put_fixture( $owned );
		$this->rig->wpdb()->recorded_queries = array();

		$prepared = $this->repair->prepare( $this->identity, null );

		self::assertInstanceOf( Success::class, $prepared );
		self::assertNull( $prepared->value );
		self::assertSame( $owned[1], $this->rig->wpdb()->rows[ $owned[0] ] ?? null );
		$this->assert_no_writes();
	}

	/**
	 * Ambiguous discovery lists every malformed lane and explicit selection repairs only one.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_multiple_malformed_lanes_require_an_explicit_argument_hash(): void {
		$first  = $this->put_malformed_lock( self::IDENTITY, self::ARGS_HASH, 'malformed-first' );
		$second = $this->put_malformed_lock( self::IDENTITY, self::OTHER_HASH, 'malformed-second' );

		$this->rig->wpdb()->recorded_queries = array();

		$ambiguous = $this->repair->prepare( $this->identity, null );

		self::assertInstanceOf( Success::class, $ambiguous );
		self::assertIsArray( $ambiguous->value );
		self::assertSame( array( self::ARGS_HASH, self::OTHER_HASH ), \array_column( $ambiguous->value, 'args_hash' ) );
		$this->assert_no_writes();

		$plan     = $this->ready_plan( $this->identity, self::OTHER_HASH );
		$repaired = $this->repair->repair( $plan );

		self::assertInstanceOf( Success::class, $repaired );
		self::assertSame( 0, $repaired->value );
		self::assertSame( 'malformed-first', $this->rig->wpdb()->rows[ $first ] ?? null );
		self::assertArrayNotHasKey( $second, $this->rig->wpdb()->rows );
	}

	/**
	 * Corrupt candidate decoding refuses every repair mutation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_prepare_refuses_an_indeterminate_candidate_without_mutation(): void {
		$lock_name = $this->put_malformed_lock( self::IDENTITY, self::ARGS_HASH, 'malformed-lock' );
		$run_name  = RunIdentity::option_name( $this->identity, self::RUN_ID );
		$this->rig->wpdb()->put( $run_name, 'corrupt-run-row' );
		$this->rig->wpdb()->recorded_queries = array();

		$prepared = $this->repair->prepare( $this->identity, null );

		self::assertInstanceOf( Failure::class, $prepared );
		self::assertInstanceOf( EngineError::class, $prepared->error );
		self::assertStringContainsString( 'unreadable', $prepared->error->message );
		self::assertSame( 'malformed-lock', $this->rig->wpdb()->rows[ $lock_name ] ?? null );
		self::assertSame( 'corrupt-run-row', $this->rig->wpdb()->rows[ $run_name ] ?? null );
		$this->assert_no_writes();
	}

	/**
	 * A failed active-run enumeration refuses every repair mutation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_prepare_refuses_a_failed_candidate_enumeration_without_mutation(): void {
		$lock_name = $this->put_malformed_lock( self::IDENTITY, self::ARGS_HASH, 'malformed-lock' );

		$this->rig->wpdb()->recorded_queries = array();
		$this->rig->wpdb()->before_next( 'scan', static function (): void {} );
		$this->rig->wpdb()->before_next(
			'scan',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'run enumeration failed';
			}
		);

		$prepared = $this->repair->prepare( $this->identity, null );

		self::assertInstanceOf( Failure::class, $prepared );
		self::assertSame( 'malformed-lock', $this->rig->wpdb()->rows[ $lock_name ] ?? null );
		$this->assert_no_writes();
	}

	/**
	 * A lost run supersession fence aborts without deleting the malformed lock.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_repair_aborts_without_deleting_the_lock_after_a_lost_run_cas(): void {
		$lock_name = $this->put_malformed_lock( self::IDENTITY, self::ARGS_HASH, 'malformed-lock' );
		$this->put_running_run( self::IDENTITY, self::RUN_ID, self::ARGS_HASH );
		$plan   = $this->ready_plan( $this->identity );
		$winner = $this->fixtures->run( self::RUN_ID, $this->running_state( self::ARGS_HASH )->with_heartbeat_at( self::NOW + 1 ) );
		$this->rig->wpdb()->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $winner ): void {
				$wpdb->put( $winner[0], $winner[1] );
			}
		);

		$repaired = $this->repair->repair( $plan );

		self::assertInstanceOf( Failure::class, $repaired );
		self::assertInstanceOf( EngineError::class, $repaired->error );
		self::assertStringContainsString( 'changed', $repaired->error->message );
		self::assertSame( 0, $repaired->error->context['runs_superseded'] ?? null );
		self::assertSame( 'malformed-lock', $this->rig->wpdb()->rows[ $lock_name ] ?? null );
		self::assertSame( $winner[1], $this->rig->wpdb()->rows[ $winner[0] ] ?? null );
		self::assertSame( array(), $this->queries_starting_with( 'DELETE ' ) );
	}

	/**
	 * A changed lock generation survives the exact delete loss after run supersession.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_repair_aborts_when_the_lock_changes_before_exact_delete(): void {
		$lock_name = $this->put_malformed_lock( self::IDENTITY, self::ARGS_HASH, 'malformed-lock' );
		$this->put_running_run( self::IDENTITY, self::RUN_ID, self::ARGS_HASH );
		$plan   = $this->ready_plan( $this->identity );
		$winner = $this->fixtures->lock( self::ARGS_HASH, self::SECOND_RUN_ID, self::NOW, self::NOW );
		$this->rig->wpdb()->before_next(
			'delete',
			static function ( WpdbLockSpy $wpdb ) use ( $winner ): void {
				$wpdb->put( $winner[0], $winner[1] );
			}
		);

		$repaired = $this->repair->repair( $plan );

		self::assertInstanceOf( Failure::class, $repaired );
		self::assertInstanceOf( EngineError::class, $repaired->error );
		self::assertStringContainsString( 'changed', $repaired->error->message );
		self::assertSame( 1, $repaired->error->context['runs_superseded'] ?? null );
		self::assertSame( $winner[1], $this->rig->wpdb()->rows[ $lock_name ] ?? null );
		$run = $this->stores->run_store( $this->identity )->inspect( self::RUN_ID );
		self::assertInstanceOf( Success::class, $run );
		self::assertIsArray( $run->value );
		$state = $run->value['state'] ?? null;
		self::assertInstanceOf( RunState::class, $state );
		self::assertSame( RunStatus::Superseded, $state->status );
	}

	/**
	 * The engine maintenance identity can be repaired without Dispatcher admission.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_repair_handles_the_engine_maintenance_lane_directly(): void {
		$identity = Identity::compose( Identity::ENGINE_SCOPE, 'maintenance', true );
		$lock     = $this->put_malformed_lock( (string) $identity, self::ARGS_HASH, 'malformed-maintenance-lock' );
		$this->put_running_run( (string) $identity, self::RUN_ID, self::ARGS_HASH );

		$plan     = $this->ready_plan( $identity );
		$repaired = $this->repair->repair( $plan );

		self::assertInstanceOf( Success::class, $repaired );
		self::assertSame( 1, $repaired->value );
		self::assertArrayNotHasKey( $lock, $this->rig->wpdb()->rows );
		$run       = new RunStore( (string) $identity, $this->rig->clock(), $this->rows );
		$inspected = $run->inspect( self::RUN_ID );
		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		$state = $inspected->value['state'] ?? null;
		self::assertInstanceOf( RunState::class, $state );
		self::assertSame( RunStatus::Superseded, $state->status );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns one successful ready repair plan.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity    $identity  Complete work identity.
	 * @param   string|null $args_hash Optional selected lane.
	 *
	 * @return  LockRepairPlan
	 */
	private function ready_plan( Identity $identity, ?string $args_hash = null ): LockRepairPlan {
		$prepared = $this->repair->prepare( $identity, $args_hash );
		self::assertInstanceOf( Success::class, $prepared );
		self::assertInstanceOf( LockRepairPlan::class, $prepared->value );

		return $prepared->value;
	}

	/**
	 * Stores one malformed overlap-lock value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity  Complete work identity.
	 * @param   string $args_hash Lane argument hash.
	 * @param   string $raw       Malformed raw value.
	 *
	 * @return  string
	 */
	private function put_malformed_lock( string $identity, string $args_hash, string $raw ): string {
		$option_name = OverlapGuard::OPTION_PREFIX . $identity . '_' . $args_hash;
		$this->rig->wpdb()->put( $option_name, $raw );

		return $option_name;
	}

	/**
	 * Stores one production-built Running row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity  Complete work identity.
	 * @param   string $run_id    Run identifier.
	 * @param   string $args_hash Lane argument hash.
	 *
	 * @return  array{string, string}
	 */
	private function put_running_run( string $identity, string $run_id, string $args_hash ): array {
		$fixture = StoreFixtureBuilder::for_identity( $identity )->run( $run_id, $this->running_state( $args_hash ) );
		$this->put_fixture( $fixture );

		return $fixture;
	}

	/**
	 * Returns one complete Running state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $args_hash Lane argument hash.
	 *
	 * @return  RunState
	 */
	private function running_state( string $args_hash ): RunState {
		return new RunState( status: RunStatus::Running, kind: 'job', executing: false, start_args: array(), args_hash: $args_hash, kind_state: array(), failed_attempts: 0, action_sequence: 1, created_at: self::NOW, heartbeat_at: self::NOW, pending: PendingAction::async( 'run', 10 ) );
	}

	/**
	 * Stores one complete option fixture.
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
	 * Asserts the current authoritative query ledger contains no write.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function assert_no_writes(): void {
		foreach ( $this->rig->wpdb()->recorded_queries as $query ) {
			self::assertFalse( \str_starts_with( $query, 'INSERT ' ) );
			self::assertFalse( \str_starts_with( $query, 'UPDATE ' ) );
			self::assertFalse( \str_starts_with( $query, 'DELETE ' ) );
		}
	}

	/**
	 * Returns queries beginning with one SQL verb.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $prefix SQL prefix.
	 *
	 * @return  list<string>
	 */
	private function queries_starting_with( string $prefix ): array {
		return \array_values( \array_filter( $this->rig->wpdb()->recorded_queries, static fn ( string $query ): bool => \str_starts_with( $query, $prefix ) ) );
	}

	// endregion.
}

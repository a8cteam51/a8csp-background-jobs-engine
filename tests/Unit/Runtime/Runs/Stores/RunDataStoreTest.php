<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs\Stores;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunDataStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\ScopeOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingCompletionJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises per-run consumer data through the production graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RunDataStore::class )]
final class RunDataStoreTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string IDENTITY = self::SCOPE . ':' . self::NAME;
	private const string NAME     = 'catalog-sync';
	private const int NOW         = 1_700_000_000;
	private const string SCOPE    = 'data-tests';

	private ScopeOperations $client;
	private RecordingJob $job;
	private EngineRig $rig;

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
	 * Boots one deterministic production graph with a registered job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig    = EngineRig::set_up( self::NOW );
		$this->client = $this->rig->operations( self::SCOPE );
		$this->job    = new RecordingJob( self::NAME );
		$this->client->register( $this->job->definition( new JobOptions( retry: new RetryPolicy( max_attempts: 1 ) ) ) );
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
	 * A stored value is returned to a later read of the same run and key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_data_round_trips_within_one_run(): void {
		$run = $this->dispatch();

		self::assertTrue( $this->client->set_run_data( self::NAME, (string) $run->id, 'seen', array( 'hosts' => array( 'a', 'b' ) ) ) );

		self::assertSame( array( 'hosts' => array( 'a', 'b' ) ), $this->client->get_run_data( self::NAME, (string) $run->id, 'seen' ) );
	}

	/**
	 * An unset key reads as null while a key holding an empty array reads as that array.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_an_absent_key_is_distinguishable_from_an_empty_one(): void {
		$run = $this->dispatch();

		self::assertNull( $this->client->get_run_data( self::NAME, (string) $run->id, 'seen' ) );

		self::assertTrue( $this->client->set_run_data( self::NAME, (string) $run->id, 'seen', array() ) );

		self::assertSame( array(), $this->client->get_run_data( self::NAME, (string) $run->id, 'seen' ) );
	}

	/**
	 * Keys are independent, and rewriting one replaces only that key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_keys_are_independent_within_one_run(): void {
		$run = $this->dispatch();

		self::assertTrue( $this->client->set_run_data( self::NAME, (string) $run->id, 'repos', array( 1 ) ) );
		self::assertTrue( $this->client->set_run_data( self::NAME, (string) $run->id, 'stats', array( 'total' => 1 ) ) );
		self::assertTrue( $this->client->set_run_data( self::NAME, (string) $run->id, 'repos', array( 1, 2 ) ) );

		self::assertSame( array( 1, 2 ), $this->client->get_run_data( self::NAME, (string) $run->id, 'repos' ) );
		self::assertSame( array( 'total' => 1 ), $this->client->get_run_data( self::NAME, (string) $run->id, 'stats' ) );
	}

	/**
	 * Two runs of the same work keep separate data.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_data_is_scoped_to_one_run(): void {
		$first = $this->dispatch();
		self::assertTrue( $this->client->set_run_data( self::NAME, (string) $first->id, 'seen', array( 'first' ) ) );
		$this->rig->run_due();

		++$this->rig->clock()->timestamp;
		$this->rig->randomizer()->value = 4_242;
		$second                         = $this->dispatch();

		self::assertNull( $this->client->get_run_data( self::NAME, (string) $second->id, 'seen' ) );
	}

	/**
	 * A completed run's data is dropped with its run row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_data_is_dropped_when_the_run_completes(): void {
		$run = $this->dispatch();
		self::assertTrue( $this->client->set_run_data( self::NAME, (string) $run->id, 'seen', array( 'a' ) ) );
		self::assertArrayHasKey( $this->data_option_name( (string) $run->id ), $this->rig->wpdb()->rows );

		$this->rig->run_due();

		$this->rig->assert_completed();
		self::assertArrayNotHasKey( $this->data_option_name( (string) $run->id ), $this->rig->wpdb()->rows );
	}

	/**
	 * A failed run's data is dropped too, so cleanup does not depend on succeeding.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_data_is_dropped_when_the_run_fails(): void {
		$run                  = $this->dispatch();
		$this->job->throwable = new \RuntimeException( 'the work failed' );
		self::assertTrue( $this->client->set_run_data( self::NAME, (string) $run->id, 'seen', array( 'a' ) ) );

		$this->rig->run_due();

		$this->rig->assert_failed( ErrorCode::ExecutionFailed );
		self::assertArrayNotHasKey( $this->data_option_name( (string) $run->id ), $this->rig->wpdb()->rows );
	}

	/**
	 * A cancelled run's data is dropped with its run row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_data_is_dropped_when_the_run_is_cancelled(): void {
		$run = $this->dispatch();
		self::assertTrue( $this->client->set_run_data( self::NAME, (string) $run->id, 'seen', array( 'a' ) ) );

		self::assertInstanceOf( Run::class, $this->client->cancel( self::NAME, (string) $run->id ) );

		self::assertArrayNotHasKey( $this->data_option_name( (string) $run->id ), $this->rig->wpdb()->rows );
	}

	/**
	 * A key outside the data grammar is refused before any storage is touched.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $key Invalid data key.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_key_provider' )]
	public function test_an_invalid_key_is_refused( string $key ): void {
		$run = $this->dispatch();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( \sprintf( 'Background-work "%1$s" data key "%2$s" is invalid; use 1 to 64 bytes matching [a-z0-9_-]+.', self::NAME, $key ) );

		(void) $this->client->set_run_data( self::NAME, (string) $run->id, $key, array() );
	}

	/**
	 * A value that would carry the complete row past its ceiling is refused, leaving the row intact.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_a_value_above_the_row_ceiling_is_refused(): void {
		$run = $this->dispatch();
		self::assertTrue( $this->client->set_run_data( self::NAME, (string) $run->id, 'kept', array( 'a' ) ) );

		$result = $this->client->set_run_data( self::NAME, (string) $run->id, 'oversized', array( \str_repeat( 'a', RunDataStore::MAX_ROW_BYTES ) ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ErrorCode::PayloadRejected->value, $result->get_error_code() );
		self::assertSame( \sprintf( 'Background-work "catalog-sync" data key "oversized" does not fit; the run\'s complete data row is limited to %d serialized bytes.', RunDataStore::MAX_ROW_BYTES ), $result->get_error_message() );
		self::assertSame( array( 'a' ), $this->client->get_run_data( self::NAME, (string) $run->id, 'kept' ) );
		self::assertNull( $this->client->get_run_data( self::NAME, (string) $run->id, 'oversized' ) );
	}

	/**
	 * A value that is not portable is refused rather than persisted as an object graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_a_non_portable_value_is_refused(): void {
		$run = $this->dispatch();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work "catalog-sync" data key "seen" value must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.' );

		(void) $this->client->set_run_data( self::NAME, (string) $run->id, 'seen', array( 'handle' => new \stdClass() ) );
	}

	/**
	 * A lost compare-and-swap is retried, and the value still lands.
	 *
	 * The store is built around a bounded retry budget; without a test that loses a swap, the whole
	 * loop is indistinguishable from a single attempt.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_a_lost_compare_and_swap_is_retried(): void {
		$run = $this->dispatch();
		self::assertTrue( $this->client->set_run_data( self::NAME, (string) $run->id, 'seen', array( 'first' ) ) );

		// Zero affected rows is a lost swap, not a storage failure.
		$this->rig->wpdb()->script_result( 'update', 0 );

		self::assertTrue( $this->client->set_run_data( self::NAME, (string) $run->id, 'seen', array( 'second' ) ) );
		self::assertSame( array( 'second' ), $this->client->get_run_data( self::NAME, (string) $run->id, 'seen' ) );
	}

	/**
	 * A swap that never wins exhausts the budget and reports a storage failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_an_exhausted_compare_and_swap_budget_reports_storage_failure(): void {
		$run = $this->dispatch();
		self::assertTrue( $this->client->set_run_data( self::NAME, (string) $run->id, 'seen', array( 'first' ) ) );

		foreach ( \range( 1, 5 ) as $ignored ) {
			$this->rig->wpdb()->script_result( 'update', 0 );
		}

		$result = $this->client->set_run_data( self::NAME, (string) $run->id, 'seen', array( 'second' ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ErrorCode::StorageFailed->value, $result->get_error_code() );
		self::assertSame( array( 'first' ), $this->client->get_run_data( self::NAME, (string) $run->id, 'seen' ), 'A write that never won must leave the stored value alone.' );
	}

	/**
	 * A failed authoritative read is reported rather than answered as an absent key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_an_unreadable_data_row_is_reported(): void {
		$run = $this->dispatch();
		self::assertTrue( $this->client->set_run_data( self::NAME, (string) $run->id, 'seen', array( 'a' ) ) );

		$this->rig->wpdb()->fail_next_read_at( 'query_filtered' );
		$result = $this->client->get_run_data( self::NAME, (string) $run->id, 'seen' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ErrorCode::StorageFailed->value, $result->get_error_code() );
	}

	/**
	 * The maintenance sweep collects data whose run no longer exists.
	 *
	 * The engine drops data where it drops the run row, so a row that outlives its run got there
	 * by a path that did not — a delete that lost its race, or a write replayed after the run
	 * finished. Without the sweep those rows accumulate with nothing naming them.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_sweep_collects_data_whose_run_is_gone(): void {
		$orphaned = self::run_id( 7 );
		$live     = self::run_id( 8 );
		$this->seed_data( $orphaned );
		$this->seed_data( $live );
		$this->seed_running_run( $live );

		$this->rig->run_maintenance();

		self::assertArrayNotHasKey( $this->data_option_name( $orphaned ), $this->rig->wpdb()->rows );
		self::assertArrayHasKey(
			$this->data_option_name( $live ),
			$this->rig->wpdb()->rows,
			'Run data belonging to a run that still exists must survive the sweep.'
		);
	}

	/**
	 * Run data is collected after a corrupt run row is deleted without firing a terminal hook.
	 *
	 * This is the path a consumer keeping its own per-run rows cannot cover: nothing downstream ever
	 * names the run again, so a hook-driven cleanup would strand the rows permanently. The sweep
	 * reaches it because the run row is what it asks about, not the hook.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_data_is_collected_after_a_corrupt_run_row_is_deleted(): void {
		$run_id = self::run_id( 9 );
		$this->seed_data( $run_id );
		$this->rig->wpdb()->put(
			RunStore::OPTION_PREFIX . self::IDENTITY . '_' . $run_id,
			StoreFixtureBuilder::corrupt_row( array( 'status' => 'not-a-run-state' ) )
		);

		$this->rig->run_maintenance();

		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_bgje/completed/' . self::IDENTITY ) );
		self::assertArrayNotHasKey( $this->data_option_name( $run_id ), $this->rig->wpdb()->rows );
	}

	/**
	 * Run data survives a terminal transition whose hooks effect is still owed a replay.
	 *
	 * A throwing listener leaves the hooks effect unmarked, and maintenance replays it. Dropping
	 * data before every effect is marked would delete the values that replay reads, which is why
	 * the drop sits behind the same gate as the run-row delete rather than beside the hook.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_data_survives_while_a_hook_replay_is_still_owed(): void {
		$run       = $this->dispatch();
		$data_name = $this->data_option_name( (string) $run->id );
		self::assertTrue( $this->client->set_run_data( self::NAME, (string) $run->id, 'seen', array( 'a' ) ) );

		$listener   = new \RuntimeException( 'Completed listener exploded.' );
		$throwables = $GLOBALS['a8csp_bgje_test_action_throwables'] ?? null;
		self::assertIsArray( $throwables );
		$throwables[ 'a8csp_bgje/completed/' . self::IDENTITY ] = $listener;
		$GLOBALS['a8csp_bgje_test_action_throwables']           = $throwables;

		$caught = null;
		try {
			$this->rig->run_due();
		} catch ( \RuntimeException $throwable ) {
			$caught = $throwable;
		}

		self::assertSame( $listener, $caught );
		$state = $this->rig->wpdb()->rows[ RunStore::OPTION_PREFIX . self::IDENTITY . '_' . $run->id ] ?? null;
		self::assertIsString( $state, 'The terminal row is retained for the replay.' );
		self::assertArrayHasKey( $data_name, $this->rig->wpdb()->rows );
		self::assertSame( array( 'a' ), $this->client->get_run_data( self::NAME, (string) $run->id, 'seen' ) );
	}

	/**
	 * A completion role reads the run's data, which the engine drops only after the hooks fire.
	 *
	 * This is the pairing the two features exist for: a Chunked Job accumulates across chunks and
	 * publishes the result once the queue drains. It works because the drop is gated behind every
	 * terminal effect, so the listener still sees the row it is there to read.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_a_completion_role_reads_the_run_data_before_it_is_dropped(): void {
		$rig      = $this->rig;
		$client   = $this->client;
		$observed = array();
		$job      = new RecordingCompletionJob( 'accumulating' );

		$job->on_completed_observer = static function ( RunId $run_id ) use ( $client, &$observed ): void {
			$observed[] = $client->get_run_data( 'accumulating', (string) $run_id, 'seen' );
		};
		$client->register( $job->definition() );
		$rig->activate_registered_hooks();

		$run = $client->dispatch( 'accumulating' );
		self::assertInstanceOf( Run::class, $run );
		self::assertTrue( $client->set_run_data( 'accumulating', (string) $run->id, 'seen', array( 'a', 'b' ) ) );

		$rig->run_due();

		self::assertSame( array( array( array( 'a', 'b' ) ) ), array( $observed ), 'The completion role must observe the run data.' );
		self::assertNull( $client->get_run_data( 'accumulating', (string) $run->id, 'seen' ), 'The data must be gone once the run is finished.' );
	}

	// endregion.

	// region PROVIDERS.

	/**
	 * Returns data keys outside the accepted grammar and byte ceiling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{string}>
	 */
	public static function invalid_key_provider(): array {
		return array(
			'empty'      => array( '' ),
			'uppercase'  => array( 'Seen' ),
			'dot'        => array( 'seen.count' ),
			'slash'      => array( 'seen/count' ),
			'too long'   => array( \str_repeat( 'a', RunDataStore::MAX_KEY_BYTES + 1 ) ),
			'whitespace' => array( 'seen count' ),
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns one canonical run identifier for a fixture index.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $index Stable fixture index.
	 *
	 * @return  string
	 */
	private static function run_id( int $index ): string {
		return \sprintf( '%020d-%019d', self::NOW, $index );
	}

	/**
	 * Stores one data row directly, without running the work it would belong to.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @return  void
	 */
	private function seed_data( string $run_id ): void {
		self::assertTrue( $this->client->set_run_data( self::NAME, $run_id, 'seen', array( 'a' ) ) );
		self::assertArrayHasKey( $this->data_option_name( $run_id ), $this->rig->wpdb()->rows );
	}

	/**
	 * Stores one fresh running run row through the production store fixture.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @return  void
	 */
	private function seed_running_run( string $run_id ): void {
		[ $option_name, $raw ] = StoreFixtureBuilder::for_identity( self::IDENTITY )->run(
			$run_id,
			new RunState( status: RunStatus::Running, kind: 'job', executing: false, start_args: array(), args_hash: \str_repeat( '0', 64 ), kind_state: array(), failed_attempts: 0, action_sequence: 1, created_at: self::NOW, heartbeat_at: self::NOW, pending: PendingAction::async( 'run', 10 ) )
		);
		$this->rig->wpdb()->put( $option_name, $raw );
	}

	/**
	 * Dispatches one run of the registered job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Run
	 */
	private function dispatch(): Run {
		$run = $this->client->dispatch( self::NAME );
		self::assertInstanceOf( Run::class, $run );

		return $run;
	}

	/**
	 * Returns the data option name for one run of the registered job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @return  string
	 */
	private function data_option_name( string $run_id ): string {
		return RunDataStore::option_name( Identity::compose( self::SCOPE, self::NAME ), $run_id );
	}

	// endregion.
}

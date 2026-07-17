<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Client;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Detects unsafe class construction while corrupt run storage is inspected. */
final class RunStoreWakeupProbe {
	public static int $wakeups = 0;

	/** Records an unsafe native object construction. */
	public function __wakeup(): void {
		++self::$wakeups;
	}
}

/**
 * Exercises live run state through lifecycle inspection and retains exact-row CAS proofs.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RunStore::class )]
final class RunStoreTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array ARGS      = array(
		'scope'   => 'all',
		'site_id' => 7,
	);
	private const string IDENTITY = self::OWNER . ':' . self::NAME;
	private const string NAME     = 'reports';
	private const int NOW         = 1_700_000_000;
	private const string OWNER    = 'runs-tests';
	private const string RUN_ID   = '00000000001700000000-0000000000000000042';

	private Client $client;
	private StoreFixtureBuilder $fixtures;
	private EngineRig $rig;
	private OptionRows $rows;
	private RecordingTask $task;

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
	 * Boots one registered task against deterministic interface fakes.
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
		$this->client = $this->rig->client( self::OWNER );
		$this->task   = new RecordingTask( self::NAME );
		$this->client->tasks()->register( $this->task );
		$this->fixtures = StoreFixtureBuilder::for_identity( self::IDENTITY );
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
	 * Enqueue, callback admission, and completion expose the real live-state lifecycle.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_live_state_is_visible_while_running_and_disappears_after_completion(): void {
		$during_callback       = null;
		$this->task->on_handle = function () use ( &$during_callback ): void {
			$during_callback = $this->single_live_run();
		};
		$result                = $this->client->tasks()->enqueue( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );

		$queued = $this->single_live_run();
		self::assertSame( self::RUN_ID, $queued['run_id'] );
		self::assertFalse( $queued['executing'] );
		self::assertSame( 0, $queued['attempts'] );
		self::assertSame( self::NOW, $queued['heartbeat_at'] );

		$this->rig->run_due();

		self::assertIsArray( $during_callback );
		self::assertTrue( $during_callback['executing'] );
		self::assertGreaterThanOrEqual( self::NOW, $during_callback['heartbeat_at'] );
		$snapshot = $this->rig->inspection()->runs( self::IDENTITY );
		self::assertSame( array(), $snapshot['live'] );
		self::assertSame( 'completed', $snapshot['history'][0]['outcome'] ?? null );
		self::assertSame( self::RUN_ID, $snapshot['history'][0]['run_id'] ?? null );
	}

	/**
	 * A retry transition exposes the consumed attempt and clears callback ownership between deliveries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_transition_is_visible_through_live_run_inspection(): void {
		$this->task->retry_policy       = new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 30 );
		$this->task->throwable          = new \RuntimeException( 'Transient failure.' );
		$this->rig->randomizer()->value = 7;
		$result                         = $this->client->tasks()->enqueue( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );

		$this->rig->run_due();

		$retrying = $this->single_live_run();
		self::assertSame( 1, $retrying['attempts'] );
		self::assertFalse( $retrying['executing'] );
		self::assertGreaterThanOrEqual( self::NOW, $retrying['heartbeat_at'] );
		$this->rig->run_due();
		self::assertSame( array(), $this->rig->inspection()->runs( self::IDENTITY )['live'] );
		$this->rig->assert_failed( ApiErrorCode::ExecutionFailed );
	}

	/**
	 * Missing, corrupt, and legacy live rows are skipped instead of becoming fatal inspection data.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_malformed_live_rows_are_tolerated_by_inspection(): void {
		self::assertSame( array(), $this->rig->inspection()->runs( self::IDENTITY )['live'] );
		$this->rig->wpdb()->put( $this->run_option_name(), 'legacy-corrupt-run-row' );

		$snapshot = $this->rig->inspection()->runs( self::IDENTITY );

		self::assertSame( array(), $snapshot['live'] );
		self::assertNull( $snapshot['live_error'] );
		self::assertSame( 1, $snapshot['live_scanned'] );
	}

	// endregion.

	// region KEEP CAS MICRO-SUITE.

	/**
	 * Exact terminal replacement and deletion accept only the observed raw generation.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built running and terminal rows prove both transitions compare binary option bytes and reject stale replays after the generation changes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_exact_raw_transition_and_delete_are_generation_conditioned(): void {
		$running  = $this->state();
		$terminal = $running->with_status( RunStatus::Completed );
		$before   = $this->fixtures->run( self::RUN_ID, $running );
		$after    = $this->fixtures->run( self::RUN_ID, $terminal );
		$this->put_fixture( $before );
		$this->rig->wpdb()->recorded_queries = array();
		$store                               = $this->store();

		self::assertSame( $after[1], $store->replace_if_raw_matches( self::RUN_ID, $before[1], $terminal ) );
		self::assertSame( $after[1], $this->raw_row() );
		self::assertNull( $store->replace_if_raw_matches( self::RUN_ID, $before[1], $terminal ) );
		self::assertTrue( $store->delete_exact( self::RUN_ID, $after[1] ) );
		self::assertFalse( $store->delete_exact( self::RUN_ID, $after[1] ) );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $this->queries_starting_with( 'UPDATE ' )[0] );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $this->queries_starting_with( 'DELETE ' )[0] );
	}

	/**
	 * A stale state writer cannot overwrite an interleaved newer generation.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The rival bytes are produced by RunStore serialization and installed at the exact update boundary, so a null result proves lost-CAS fencing rather than a scripted pass-through.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_lost_state_cas_preserves_the_fixture_built_rival_generation(): void {
		$running = $this->state();
		$rival   = $running->with_action_seq( 2 );
		$caller  = $running->with_action_seq( 3 );
		$before  = $this->fixtures->run( self::RUN_ID, $running );
		$winner  = $this->fixtures->run( self::RUN_ID, $rival );
		$this->put_fixture( $before );
		$this->rig->wpdb()->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $winner ): void {
				$wpdb->put( $winner[0], $winner[1] );
			}
		);

		self::assertNull( $this->store()->replace_if_state_matches( self::RUN_ID, $running, $caller ) );
		self::assertSame( $winner[1], $this->raw_row() );
	}

	/**
	 * Different interleaved terminal effects converge in append order on one exact snapshot.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The rival append executes real production logic at the outer CAS boundary, proving retry merges monotonic effect progress instead of replacing it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_interleaved_terminal_effects_converge_on_one_exact_snapshot(): void {
		$terminal = $this->state()->with_status( RunStatus::Completed );
		$fixture  = $this->fixtures->run( self::RUN_ID, $terminal );
		$expected = $this->fixtures->run( self::RUN_ID, $terminal->with_effects( array( 'callbacks', 'hooks' ) ) );
		$this->put_fixture( $fixture );
		$store = $this->store();
		$this->rig->wpdb()->before_next(
			'update',
			static function () use ( $fixture, $store, $terminal ): void {
				self::assertNotNull( $store->append_terminal_effect( self::RUN_ID, $terminal, $fixture[1], 'callbacks' ) );
			}
		);

		$appended = $store->append_terminal_effect( self::RUN_ID, $terminal, $fixture[1], 'hooks' );

		self::assertNotNull( $appended );
		self::assertSame( array( 'callbacks', 'hooks' ), $appended['state']->effects );
		self::assertSame( $expected[1], $appended['raw'] );
		self::assertSame( $expected[1], $this->raw_row() );
	}

	/**
	 * Terminal-effect persistence stops after five consecutive comparison losses.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The exact five-attempt bound (TERMINAL_EFFECT_ATTEMPTS=5) is the liveness contract; an unbounded loop under permanent contention would hang delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_terminal_effect_append_stops_after_five_consecutive_cas_losses(): void {
		$terminal = $this->state()->with_status( RunStatus::Completed );
		$fixture  = $this->fixtures->run( self::RUN_ID, $terminal );
		$this->put_fixture( $fixture );
		$this->rig->wpdb()->recorded_queries = array();

		$last_rival = $fixture;
		for ( $action_seq = 2; $action_seq <= 6; ++$action_seq ) {
			$rival      = $this->fixtures->run( self::RUN_ID, $terminal->with_action_seq( $action_seq ) );
			$last_rival = $rival;
			$this->rig->wpdb()->before_next(
				'update',
				static function ( WpdbLockSpy $wpdb ) use ( $rival ): void {
					$wpdb->put( $rival[0], $rival[1] );
				}
			);
		}
		$store = $this->store();

		self::assertNull( $store->append_terminal_effect( self::RUN_ID, $terminal, $fixture[1], 'hooks' ) );
		self::assertCount( 5, $this->queries_starting_with( 'UPDATE ' ) );
		self::assertSame( $last_rival[1], $this->raw_row() );
		$persisted = $store->inspect( self::RUN_ID );
		self::assertInstanceOf( Success::class, $persisted );
		self::assertIsArray( $persisted->value );
		self::assertInstanceOf( RunState::class, $persisted->value['state'] );
		self::assertSame( array(), $persisted->value['state']->effects );
	}

	/**
	 * Terminal-effect keys must be non-empty.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_terminal_effect_append_rejects_an_empty_effect_key(): void {
		$terminal = $this->state()->with_status( RunStatus::Completed );
		$fixture  = $this->fixtures->run( self::RUN_ID, $terminal );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'A terminal effect key cannot be empty.' );

		(void) $this->store()->append_terminal_effect( self::RUN_ID, $terminal, $fixture[1], '' );
	}

	/**
	 * Corrupt object-bearing rows remain raw evidence without constructing their classes.
	 *
	 * @load-bearing security
	 * @pin-rationale The deliberately corrupt serialized row bypasses the fixture builder so hardened inspection can prove it rejects nested objects before PHP wakeup hooks run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_object_bearing_rows_are_skipped_without_constructing_classes(): void {
		$raw = \maybe_serialize(
			array(
				'status'          => 'running',
				'executing'       => false,
				'start_args'      => array(),
				'args_hash'       => 'hash-a',
				'queue'           => array( array( 'payload' => new RunStoreWakeupProbe() ) ),
				'failed_attempts' => 0,
				'action_seq'      => 1,
				'created_at'      => self::NOW,
				'heartbeat_at'    => self::NOW,
			)
		);
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( $this->run_option_name(), $raw );
		RunStoreWakeupProbe::$wakeups = 0;

		self::assertSame( array(), $this->rig->inspection()->runs( self::IDENTITY )['live'] );
		self::assertSame( 0, RunStoreWakeupProbe::$wakeups );
		$inspected = $this->store()->inspect( self::RUN_ID );
		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		self::assertSame( $raw, $inspected->value['raw'] ?? null );
		self::assertNull( $inspected->value['state'] ?? null );
		self::assertSame( 0, RunStoreWakeupProbe::$wakeups );
	}

	/**
	 * Pending descriptors accept only the canonical stage/mode/fire-time pairings and field set.
	 *
	 * @load-bearing security
	 * @pin-rationale Inline corrupt rows cover the invalid pairings that production serialization cannot emit, preventing legacy or injected shapes from becoming executable pending actions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_noncanonical_pending_descriptors_never_become_live_runs(): void {
		$invalid = array(
			array(
				'stage'    => 'run',
				'mode'     => 'later',
				'fire_at'  => 2,
				'priority' => 10,
			),
			array(
				'stage'    => 'run',
				'mode'     => 'async',
				'fire_at'  => 2,
				'priority' => 10,
			),
			array(
				'stage'    => 'run',
				'mode'     => 'single',
				'fire_at'  => null,
				'priority' => 10,
			),
			array(
				'stage'    => 'run',
				'mode'     => 'async',
				'fire_at'  => null,
				'priority' => '10',
			),
			array(
				'stage'    => 'run',
				'mode'     => 'async',
				'fire_at'  => null,
				'priority' => 10,
				'extra'    => true,
			),
			array(
				'stage'    => 'start',
				'mode'     => 'single',
				'fire_at'  => 2,
				'priority' => 10,
			),
			array(
				'stage'    => 'cleanup',
				'mode'     => 'single',
				'fire_at'  => 2,
				'priority' => 10,
			),
		);

		foreach ( $invalid as $pending ) {
			$this->put_corrupt_state( array( 'pending' => $pending ) );
			self::assertSame( array(), $this->rig->inspection()->runs( self::IDENTITY )['live'] );
		}
	}

	/**
	 * Optional terminal error and effect metadata accept only their canonical nested shapes.
	 *
	 * @load-bearing security
	 * @pin-rationale Inline malformed metadata cannot be produced by StoreFixtureBuilder and proves persisted data cannot smuggle ambiguous errors or replayable duplicate effect keys into terminal recovery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_noncanonical_terminal_metadata_never_hydrates(): void {
		$invalid = array(
			array( 'error' => null ),
			array( 'error' => array( 'message' => 'Failure.' ) ),
			array(
				'error' => array(
					'class'   => false,
					'message' => 'Failure.',
				),
			),
			array(
				'error' => array(
					'class'   => null,
					'message' => false,
				),
			),
			array(
				'error' => array(
					'class'   => null,
					'message' => 'Failure.',
					'extra'   => true,
				),
			),
			array(
				'error' => array(
					'class'   => null,
					'message' => 'Failure.',
					'stage'   => 'unknown',
					'code'    => ApiErrorCode::ExecutionFailed->value,
				),
			),
			array( 'effects' => array() ),
			array( 'effects' => array( 'key' => 'hooks' ) ),
			array( 'effects' => array( 1 ) ),
			array( 'effects' => array( '' ) ),
			array( 'effects' => array( 'hooks', 'hooks' ) ),
			array(
				'status'  => 'running',
				'effects' => array( 'hooks' ),
			),
			array(
				'status' => 'completed',
				'error'  => array(
					'class'   => null,
					'message' => 'Failure.',
				),
			),
		);

		foreach ( $invalid as $metadata ) {
			$this->put_corrupt_state( $metadata );
			$inspected = $this->store()->inspect( self::RUN_ID );
			self::assertInstanceOf( Success::class, $inspected );
			self::assertIsArray( $inspected->value );
			self::assertNull( $inspected->value['state'] ?? null );
		}
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns the only inspected live run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array{run_id: string, kind: string, status: string, executing: bool, attempts: int, queue_depth: int|null, heartbeat_at: int, stale: bool}
	 */
	private function single_live_run(): array {
		$live = $this->rig->inspection()->runs( self::IDENTITY )['live'];
		self::assertCount( 1, $live );

		return $live[0];
	}

	/**
	 * Returns the shared valid fixture state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RunState
	 */
	private function state(): RunState {
		return new RunState( status: RunStatus::Running, executing: false, start_args: self::ARGS, args_hash: $this->fixtures->args_hash( self::ARGS ), queue: array(), failed_attempts: 0, action_seq: 1, created_at: self::NOW, heartbeat_at: self::NOW, pending: PendingAction::async( 'run', 10 ) );
	}

	/**
	 * Returns a run store bound to the active authoritative rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RunStore
	 */
	private function store(): RunStore {
		return new RunStore( self::IDENTITY, $this->rig->clock(), $this->rows );
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
	 * Stores one deliberately corrupt terminal state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $metadata Invalid fields under test.
	 *
	 * @return  void
	 */
	private function put_corrupt_state( array $metadata ): void {
		$value = array(
			'status'          => 'failed',
			'executing'       => false,
			'start_args'      => array(),
			'args_hash'       => 'hash-a',
			'queue'           => array(),
			'failed_attempts' => 0,
			'action_seq'      => 1,
			'created_at'      => self::NOW,
			'heartbeat_at'    => self::NOW,
			...$metadata,
		);
		$raw   = \maybe_serialize( $value );
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( $this->run_option_name(), $raw );
	}

	/**
	 * Returns the active run's authoritative raw bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function raw_row(): string {
		$raw = $this->rig->wpdb()->rows[ $this->run_option_name() ] ?? null;
		self::assertIsString( $raw );

		return $raw;
	}

	/**
	 * Returns the deterministic active-run option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function run_option_name(): string {
		return RunIdentity::option_name( self::IDENTITY, self::RUN_ID );
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

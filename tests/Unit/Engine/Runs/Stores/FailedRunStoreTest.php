<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Detects unsafe class construction while corrupt failed-run storage is inspected. */
final class FailedRunStorePoison {
	public static int $wakeups = 0;

	/** Records an unsafe native object construction. */
	public function __wakeup(): void {
		++self::$wakeups;
	}
}

/**
 * Exercises failed-run retention through the run facade and retains exact-row CAS proofs.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( FailedRunStore::class )]
final class FailedRunStoreTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const IDENTITY = self::OWNER . ':' . self::NAME;
	private const NAME     = 'reports';
	private const NOW      = 1_700_000_000;
	private const OWNER    = 'runs-tests';

	private Consumer $consumer;
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
	 * Boots one registered failing task against deterministic interface fakes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig                = EngineRig::set_up( self::NOW );
		$this->consumer           = $this->rig->consumer( self::OWNER );
		$this->task               = new RecordingTask( self::NAME );
		$this->task->retry_policy = new RetryPolicy( max_attempts: 1 );
		$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->consumer->tasks()->register( $this->task );
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
	 * The twenty-first retained failure logs the confirmed oldest-run eviction.
	 *
	 * @return  void
	 */
	public function test_retention_eviction_logs_the_evicted_run_and_identity_after_persistence(): void {
		$run_ids = array();
		foreach ( \range( 0, 19 ) as $index ) {
			$run_ids[] = $this->fail_task( array( 'index' => $index ), $index + 1 );
		}
		$this->rig->logger()->records = array();

		$this->fail_task( array( 'index' => 20 ), 21 );

		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Failed-run retention for "{identity}" evicted oldest run IDs beyond the 20-entry limit: {evicted_run_ids}.',
					'context' => array(
						'identity'        => self::IDENTITY,
						'evicted_run_ids' => $run_ids[0],
					),
				),
			),
			$this->rig->logger()->records
		);
	}

	/**
	 * A failed-run persist below the retention limit emits no eviction warning.
	 *
	 * @return  void
	 */
	public function test_non_evicting_failed_run_persist_logs_nothing(): void {
		$this->fail_task( array( 'index' => 0 ), 1 );

		self::assertSame( array(), $this->rig->logger()->records );
	}

	/**
	 * Failed-run retention keeps the newest twenty and retry consumes exactly one retained failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_retention_and_retry_consumption_are_observable_through_the_run_facade(): void {
		$run_ids = array();
		foreach ( \range( 0, 20 ) as $index ) {
			$run_ids[] = $this->fail_task( array( 'index' => $index ), $index + 1 );
		}

		$history = $this->rig->inspection()->runs( self::IDENTITY )['history'];
		self::assertNotNull( $history );
		$retained = \array_column( $history, 'failed_store', 'run_id' );
		self::assertFalse( $retained[ $run_ids[0] ] );
		self::assertTrue( $retained[ $run_ids[1] ] );
		self::assertCount( 20, \array_filter( $retained ) );

		$evicted = $this->consumer->runs()->retry_failed( self::NAME, $run_ids[0] );
		self::assertInstanceOf( Failure::class, $evicted );
		self::assertInstanceOf( ApiError::class, $evicted->error );
		self::assertSame( ApiErrorCode::RunNotRetained, $evicted->error->code );

		$this->task->throwable          = null;
		$this->rig->randomizer()->value = 99;
		$retried                        = $this->consumer->runs()->retry_failed( self::NAME, $run_ids[1] );
		self::assertInstanceOf( Success::class, $retried );
		$consumed = $this->consumer->runs()->retry_failed( self::NAME, $run_ids[1] );
		self::assertInstanceOf( Failure::class, $consumed );
		self::assertInstanceOf( ApiError::class, $consumed->error );
		self::assertSame( ApiErrorCode::RunNotRetained, $consumed->error->code );
		$this->rig->run_due();
		self::assertSame( array( 'index' => 1 ), $this->task->calls[21] ?? null );
	}

	/**
	 * Retried work receives the original portable arguments and can complete as a fresh run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_replays_original_arguments_and_removes_failed_retention(): void {
		$args                           = array(
			'scope'   => 'all',
			'site_id' => 7,
		);
		$failed                         = $this->fail_task( $args, 7 );
		$this->task->throwable          = null;
		$this->rig->randomizer()->value = 8;

		$retried = $this->consumer->runs()->retry_failed( self::NAME, $failed );
		self::assertInstanceOf( Success::class, $retried );
		self::assertNotSame( $failed, $retried->value );
		$this->rig->run_due();

		self::assertSame( array( $args, $args ), $this->task->calls );
		$history = $this->rig->inspection()->runs( self::IDENTITY )['history'];
		self::assertNotNull( $history );
		$failed_entry = \array_find( $history, static fn ( array $entry ): bool => $failed === $entry['run_id'] );
		self::assertNotNull( $failed_entry );
		self::assertFalse( $failed_entry['failed_store'] );
		self::assertSame( 'completed', $history[0]['outcome'] ?? null );
	}

	/**
	 * Corrupt and legacy failed rows inspect as unretained data and never fatal.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_malformed_failed_rows_are_tolerated_by_inspection_and_retry(): void {
		$this->rig->wpdb()->put( FailedRunStore::OPTION_PREFIX . self::IDENTITY, 'legacy-corrupt-failed-row' );

		$snapshot = $this->rig->inspection()->runs( self::IDENTITY );
		self::assertSame( array(), $snapshot['history'] );
		$result = $this->consumer->runs()->retry_failed( self::NAME, 'legacy-run' );
		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( ApiError::class, $result->error );
		self::assertSame( ApiErrorCode::RunNotRetained, $result->error->code );
	}

	/**
	 * Unknown terminalization stages inspect as unretained data and never fatal.
	 *
	 * @load-bearing security
	 * @pin-rationale A canonical fixture cannot contain an unknown stage, so corrupting only that scalar proves read validation fails closed through the public inspection and retry seams.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unknown_failure_stages_are_tolerated_as_unretained_data(): void {
		$fixture = $this->fixtures->failed_runs( array( self::fixture_entry( 'run-unknown-stage', self::NOW ) ) );
		$entries = \maybe_unserialize( $fixture[1] );
		self::assertIsArray( $entries );
		self::assertIsArray( $entries[0] ?? null );
		self::assertIsArray( $entries[0]['error'] ?? null );
		$entries[0]['error']['stage'] = 'unknown';
		$raw                          = \maybe_serialize( $entries );
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( $fixture[0], $raw );

		self::assertSame( array(), $this->rig->inspection()->runs( self::IDENTITY )['history'] );
		$result = $this->consumer->runs()->retry_failed( self::NAME, 'run-unknown-stage' );
		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( ApiError::class, $result->error );
		self::assertSame( ApiErrorCode::RunNotRetained, $result->error->code );
	}

	// endregion.

	// region KEEP CAS MICRO-SUITE.

	/**
	 * Same-identifier writers converge on the first payload that wins the exact update.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built generations prove replayed writers cannot replace the retained retry arguments chosen by the first successful CAS.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_interleaved_same_identifier_writers_converge_on_the_first_payload(): void {
		$initial = array( self::fixture_entry( 'run-existing', 100 ) );
		$rival   = self::fixture_entry( 'run-shared', 200, array( 'writer' => 'rival' ) );
		$caller  = self::fixture_entry( 'run-shared', 300, array( 'writer' => 'caller' ) );
		$this->put_fixture( $this->fixtures->failed_runs( $initial ) );
		$this->rig->wpdb()->before_next(
			'update',
			function () use ( $rival ): void {
				self::assertTrue( $this->record_entry( $rival ) );
			}
		);

		self::assertTrue( $this->record_entry( $caller ) );

		self::assertSame( $this->fixtures->failed_runs( array( ...$initial, $rival ) )[1], $this->raw_row() );
	}

	/**
	 * A lost exact update retries from fresh rival bytes without normalizing them away.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Embedded null bytes in a production-built rival generation make exact preservation distinguishable from decode-and-recreate shortcuts.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_lost_cas_preserves_fixture_built_rival_bytes(): void {
		$initial    = array( self::fixture_entry( 'run-existing', 100 ) );
		$rival      = self::fixture_entry( 'run-rival', 200, array( 'token' => "rival-\0bytes" ), "Rival \0 failure." );
		$caller     = self::fixture_entry( 'run-caller', 300 );
		$concurrent = array( ...$initial, $rival );
		$this->put_fixture( $this->fixtures->failed_runs( $initial ) );
		$rival_fixture = $this->fixtures->failed_runs( $concurrent );
		$this->rig->wpdb()->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $rival_fixture ): void {
				$wpdb->put( $rival_fixture[0], $rival_fixture[1] );
			}
		);
		$this->rig->wpdb()->recorded_queries = array();

		self::assertTrue( $this->record_entry( $caller ) );

		self::assertSame( $this->fixtures->failed_runs( array( ...$concurrent, $caller ) )[1], $this->raw_row() );
		self::assertCount( 2, $this->queries_starting_with( 'UPDATE ' ) );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $this->queries_starting_with( 'UPDATE ' )[0] );
	}

	/**
	 * Read failures and unchanged failed updates cannot alter retained retry data.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A no-write result is required when authority is unavailable, while rereading unchanged bytes distinguishes persistence failure from a lost comparison.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_record_and_remove_authority_failures_leave_exact_bytes_untouched(): void {
		$entry   = self::fixture_entry( 'run-existing', 100 );
		$fixture = $this->fixtures->failed_runs( array( $entry ) );
		$this->put_fixture( $fixture );
		$this->rig->wpdb()->recorded_queries = array();
		$this->fail_next_read();

		self::assertFalse( $this->record_entry( self::fixture_entry( 'run-new', 200 ) ) );
		self::assertSame( $fixture[1], $this->raw_row() );
		self::assertSame( array(), $this->write_queries() );

		$this->rig->wpdb()->recorded_queries = array();
		$this->fail_next_read();
		self::assertFalse( $this->store()->remove( 'run-existing' ) );
		self::assertSame( $fixture[1], $this->raw_row() );
		self::assertSame( array(), $this->write_queries() );

		$this->rig->wpdb()->recorded_queries = array();
		$this->rig->wpdb()->script_result( 'update', false );
		self::assertFalse( $this->store()->remove( 'run-existing' ) );
		self::assertSame( $fixture[1], $this->raw_row() );
		self::assertCount( 1, $this->queries_starting_with( 'UPDATE ' ) );
	}

	/**
	 * Purge retries a changed generation and stops after three consecutive comparison losses.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Exact-delete retry bounds prevent an unbounded maintenance loop while preserving each interleaved append until one complete generation is deleted.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_purge_retries_changed_generations_and_honors_its_retry_bound(): void {
		$initial = self::fixture_entry( 'run-a', 100 );
		$this->put_fixture( $this->fixtures->failed_runs( array( $initial ) ) );
		$this->rig->wpdb()->before_next(
			'delete',
			function (): void {
				self::assertTrue( $this->record_entry( self::fixture_entry( 'run-b', 200 ) ) );
			}
		);
		self::assertSame( 2, $this->store()->purge() );
		self::assertArrayNotHasKey( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $this->rig->wpdb()->rows );

		$this->put_fixture( $this->fixtures->failed_runs( array( $initial ) ) );
		foreach ( array( 'b', 'c', 'd' ) as $index => $suffix ) {
			$this->rig->wpdb()->before_next(
				'delete',
				function () use ( $index, $suffix ): void {
					self::assertTrue( $this->record_entry( self::fixture_entry( 'run-' . $suffix, 200 + $index ) ) );
				}
			);
		}

		self::assertNull( $this->store()->purge() );
		$remaining = $this->store()->all();
		self::assertInstanceOf( Success::class, $remaining );
		self::assertIsArray( $remaining->value );
		self::assertCount( 4, $remaining->value );
	}

	/**
	 * Purge stops without a comparison-loss retry when the database delete fails.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A failed exact DELETE must not enter the generation-reread loop used only for competing writers; the public store result cannot otherwise distinguish those storage outcomes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_purge_stops_after_a_database_delete_failure(): void {
		$fixture = $this->fixtures->failed_runs( array( self::fixture_entry( 'run-a', 100 ) ) );
		$this->put_fixture( $fixture );
		$this->rig->wpdb()->recorded_queries = array();
		$this->rig->wpdb()->script_result( 'delete', false );

		self::assertNull( $this->store()->purge() );
		self::assertSame( $fixture[1], $this->raw_row() );
		self::assertCount( 1, $this->queries_starting_with( 'SELECT ' ) );
		self::assertCount( 1, $this->queries_starting_with( 'DELETE ' ) );
	}

	/**
	 * Serialized objects contain no retryable entries and cannot run wakeup code.
	 *
	 * @load-bearing security
	 * @pin-rationale The corrupt fixture deliberately bypasses production serialization to prove raw decoding rejects object construction before failed-run inspection or purge.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_serialized_objects_are_empty_without_constructing_their_class(): void {
		$raw = \maybe_serialize( new FailedRunStorePoison() );
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( FailedRunStore::OPTION_PREFIX . self::IDENTITY, $raw );
		FailedRunStorePoison::$wakeups = 0;

		$result = $this->store()->all();
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( array(), $result->value );
		self::assertSame( 0, FailedRunStorePoison::$wakeups );
		self::assertSame( 0, $this->store()->purge() );
		self::assertSame( 0, FailedRunStorePoison::$wakeups );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Executes one real terminal task failure and returns its run identifier.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $args       Task arguments.
	 * @param   int                     $randomness Deterministic run-id entropy.
	 *
	 * @return  string
	 */
	private function fail_task( array $args, int $randomness ): string {
		$this->rig->randomizer()->value = $randomness;
		$result                         = $this->consumer->tasks()->enqueue( self::NAME, $args );
		self::assertInstanceOf( Success::class, $result );
		self::assertIsString( $result->value );
		$this->rig->run_due();
		$this->rig->assert_failed( ApiErrorCode::ExecutionFailed );

		return $result->value;
	}

	/**
	 * Returns one fixture request consumed by StoreFixtureBuilder.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id     Run identifier.
	 * @param   int                     $failed_at  Failure timestamp.
	 * @param   array<array-key, mixed> $start_args Original run arguments.
	 * @param   string                  $summary    Failure summary.
	 *
	 * @return  array{failed_at: int, start_args: array<array-key, mixed>, failure: RunFailure, error: EngineError}
	 */
	private static function fixture_entry( string $run_id, int $failed_at, array $start_args = array(), string $summary = 'Failure.' ): array {
		$failure = new RunFailure( identity: self::IDENTITY, run_id: $run_id, attempts: 1, stage: RunFailureStage::Execution, code: ApiErrorCode::ExecutionFailed, summary: $summary, failed_chunk: null );

		return array(
			'failed_at'  => $failed_at,
			'start_args' => $start_args,
			'failure'    => $failure,
			'error'      => new EngineError( $summary ),
		);
	}

	/**
	 * Records one fixture request through the active production store.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{failed_at: int, start_args: array<array-key, mixed>, failure: RunFailure, error: EngineError} $entry Failed-run request.
	 *
	 * @return  bool
	 */
	private function record_entry( array $entry ): bool {
		return $this->store()->record( $entry['failure']->run_id, $entry['failed_at'], $entry['start_args'], $entry['failure']->attempts, $entry['error'], $entry['failure'] );
	}

	/**
	 * Returns a failed-run store bound to the active authoritative rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  FailedRunStore
	 */
	private function store(): FailedRunStore {
		return new FailedRunStore( self::IDENTITY, $this->rows, $this->rig->logger() );
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
	 * Returns the active store's authoritative raw bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function raw_row(): string {
		$raw = $this->rig->wpdb()->rows[ FailedRunStore::OPTION_PREFIX . self::IDENTITY ] ?? null;
		self::assertIsString( $raw );

		return $raw;
	}

	/**
	 * Makes the next authoritative read fail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function fail_next_read(): void {
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted failed-run read failure';
			}
		);
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

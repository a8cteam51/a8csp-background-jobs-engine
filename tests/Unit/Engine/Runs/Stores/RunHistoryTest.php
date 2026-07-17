<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\ExistingRunPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Detects unsafe class construction while corrupt history storage is inspected. */
final class RunHistoryWakeupProbe {
	public static int $wakeups = 0;

	/** Records an unsafe native object construction. */
	public function __wakeup(): void {
		++self::$wakeups;
	}
}

/**
 * Exercises retained lifecycle history through inspection and keeps its exact-row CAS proofs.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RunHistory::class )]
final class RunHistoryTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string IDENTITY = self::OWNER . ':' . self::NAME;
	private const string NAME     = 'reports';
	private const int NOW         = 1_700_000_000;
	private const string OWNER    = 'runs-tests';

	private RecordingBatch $batch;
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
	 * Boots registered task and batch contracts against deterministic interfaces.
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
		$this->consumer = $this->rig->consumer( self::OWNER );
		$this->task     = new RecordingTask( self::NAME );
		$this->batch    = new RecordingBatch( self::NAME . '-batch' );
		$this->consumer->tasks()->register( $this->task );
		$this->consumer->batches()->register( $this->batch );
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
	 * Every terminal lifecycle outcome appears through read-only run inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $status Terminal status under test.
	 *
	 * @return  void
	 */
	#[DataProvider( 'terminal_statuses' )]
	public function test_every_terminal_status_is_exposed_through_inspection( string $status ): void {
		[ $identity, $run_id ] = $this->produce_terminal_outcome( $status );

		$history = $this->rig->inspection()->runs( $identity )['history'];
		self::assertNotNull( $history );
		$entry = \array_find( $history, static fn ( array $candidate ): bool => $run_id === $candidate['run_id'] );
		self::assertNotNull( $entry );
		self::assertSame( $status, $entry['outcome'] );
		self::assertSame( 'failed' === $status, $entry['failed_store'] );
	}

	/**
	 * Supplies every terminal status retained by production history.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{status: string}>
	 */
	public static function terminal_statuses(): array {
		return array(
			'completed'  => array( 'status' => 'completed' ),
			'failed'     => array( 'status' => 'failed' ),
			'cancelled'  => array( 'status' => 'cancelled' ),
			'superseded' => array( 'status' => 'superseded' ),
		);
	}

	/**
	 * Default and filtered global history windows retain only their newest visible outcomes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_history_retention_is_thirty_by_default_and_honors_a_positive_filter(): void {
		$run_ids = $this->complete_tasks( 31 );
		$history = $this->history();
		self::assertCount( 30, $history );
		self::assertNotContains( $run_ids[0], \array_column( $history, 'run_id' ) );
		self::assertSame( $run_ids[30], $history[0]['run_id'] );

		$this->set_history_size( 2 );
		$filtered = $this->complete_tasks( 3, 100 );
		$history  = $this->history();
		self::assertCount( 2, $history );
		self::assertSame( array( $filtered[2], $filtered[1] ), \array_column( $history, 'run_id' ) );
	}

	/**
	 * An invalid history-size filter result reports the affected identity and applied default.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_invalid_history_size_filter_result_logs_a_warning(): void {
		$this->set_history_size( '30' );

		self::assertTrue( $this->store()->record_started( 'run-invalid-filter', 'hash-a' ) );
		self::assertCount( 1, $this->rig->logger()->records );
		self::assertSame( 'warning', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->rig->logger()->records[0]['context']['name'] ?? null );
		self::assertSame( 'string', $this->rig->logger()->records[0]['context']['returned_type'] ?? null );
		self::assertSame( 30, $this->rig->logger()->records[0]['context']['default_size'] ?? null );
	}

	/**
	 * Distinct argument-history buckets retain a twenty-identity LRU footprint.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_argument_history_footprint_is_a_twenty_identity_lru(): void {
		$entries = self::started_entries( 0, 19 );
		$this->put_fixture( $this->fixtures->history( $entries ) );
		$history = $this->store();
		self::assertTrue( $history->record_started( 'run-refreshed', self::hash( 0 ) ) );
		self::assertTrue( $history->record_started( 'run-20', self::hash( 20 ) ) );

		$decoded = RawOptionDecoder::decode( $this->raw_row() );
		self::assertIsArray( $decoded );
		$by_hash = $decoded['by_hash'] ?? null;
		self::assertIsArray( $by_hash );
		self::assertCount( 20, $by_hash );
		self::assertArrayHasKey( self::hash( 0 ), $by_hash );
		self::assertArrayNotHasKey( self::hash( 1 ), $by_hash );
		self::assertArrayHasKey( self::hash( 20 ), $by_hash );
	}

	/**
	 * Inspection keeps valid rows, skips malformed rows, and never constructs serialized classes.
	 *
	 * @load-bearing security
	 * @pin-rationale The deliberately corrupt row mixes valid lifecycle data with an object payload, proving inspection uses the hardened raw decoder without losing safe neighbors.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_malformed_history_is_tolerated_without_constructing_classes(): void {
		$raw = \maybe_serialize(
			array(
				'started'  => array( 'started-safe', 42, new RunHistoryWakeupProbe() ),
				'terminal' => array(
					array(
						'run_id' => 'failed-safe',
						'status' => 'failed',
					),
					array(
						'run_id' => 'running-invalid',
						'status' => 'running',
					),
					new RunHistoryWakeupProbe(),
				),
				'by_hash'  => array( 'legacy' => 'not-a-buffer' ),
			)
		);
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( RunHistory::OPTION_PREFIX . self::IDENTITY, $raw );
		RunHistoryWakeupProbe::$wakeups = 0;

		$history = $this->history();

		self::assertSame( array( 'failed-safe', 'started-safe' ), \array_column( $history, 'run_id' ) );
		self::assertSame( array( 'failed', 'started' ), \array_column( $history, 'outcome' ) );
		self::assertSame( 0, RunHistoryWakeupProbe::$wakeups );
	}

	// endregion.

	// region KEEP CAS MICRO-SUITE.

	/**
	 * Interleaved appends retain both writers while capping exact history bytes.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built before/after rows prove the caller retries the rival generation and caps both the global and per-argument buffers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_interleaved_appends_preserve_both_writers_in_exact_capped_bytes(): void {
		$initial  = self::same_hash_entries( 0, 29 );
		$expected = array(
			...$initial,
			array(
				'run_id'    => 'run-rival',
				'args_hash' => 'hash-a',
			),
			array(
				'run_id'    => 'run-caller',
				'args_hash' => 'hash-a',
			),
		);
		$this->put_fixture( $this->fixtures->history( $initial ) );
		$this->rig->wpdb()->before_next(
			'update',
			function (): void {
				self::assertTrue( $this->store()->record_started( 'run-rival', 'hash-a' ) );
			}
		);

		self::assertTrue( $this->store()->record_started( 'run-caller', 'hash-a' ) );

		self::assertSame( $this->fixtures->history( $expected )[1], $this->raw_row() );
	}

	/**
	 * A lost exact update retries from fresh rival bytes without dropping its append.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Production serialization supplies every generation, including a rival identifier with null bytes that exposes normalization or stale-overwrite shortcuts.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_lost_cas_preserves_fixture_built_rival_bytes(): void {
		$initial  = array(
			array(
				'run_id'    => 'run-existing',
				'args_hash' => 'hash-a',
			),
		);
		$rival    = array(
			...$initial,
			array(
				'run_id'    => "run-rival-\0bytes",
				'args_hash' => 'hash-rival',
			),
		);
		$expected = array(
			...$rival,
			array(
				'run_id'    => 'run-caller',
				'args_hash' => 'hash-a',
			),
		);
		$this->put_fixture( $this->fixtures->history( $initial ) );
		$rival_fixture = $this->fixtures->history( $rival );
		$this->rig->wpdb()->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $rival_fixture ): void {
				$wpdb->put( $rival_fixture[0], $rival_fixture[1] );
			}
		);
		$this->rig->wpdb()->recorded_queries = array();

		self::assertTrue( $this->store()->record_started( 'run-caller', 'hash-a' ) );

		self::assertSame( $this->fixtures->history( $expected )[1], $this->raw_row() );
		self::assertCount( 2, $this->queries_starting_with( 'UPDATE ' ) );
	}

	/**
	 * Failed authority reads and unchanged failed writes never mutate history.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The no-write branches preserve the last confirmed generation when storage cannot establish whether a competing writer exists.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_authority_failures_leave_exact_history_bytes_untouched(): void {
		$fixture = $this->fixtures->history(
			array(
				array(
					'run_id'    => 'run-existing',
					'args_hash' => 'hash-a',
				),
			)
		);
		$this->put_fixture( $fixture );
		$this->rig->wpdb()->recorded_queries = array();
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted history read failure';
			}
		);

		self::assertFalse( $this->store()->record_started( 'run-new', 'hash-a' ) );
		self::assertSame( $fixture[1], $this->raw_row() );
		self::assertSame( array(), $this->queries_starting_with( 'UPDATE ' ) );

		$this->rig->wpdb()->recorded_queries = array();
		$this->rig->wpdb()->script_result( 'update', false );
		self::assertFalse( $this->store()->record_started( 'run-new', 'hash-a' ) );
		self::assertSame( $fixture[1], $this->raw_row() );
		self::assertCount( 1, $this->queries_starting_with( 'UPDATE ' ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Produces one terminal outcome through public task or batch operations.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $status Terminal status.
	 *
	 * @return  array{string, string} Work identity and terminal run identifier.
	 */
	private function produce_terminal_outcome( string $status ): array {
		$this->rig->randomizer()->value = 7;
		if ( 'superseded' === $status ) {
			$name     = self::NAME . '-batch';
			$identity = self::OWNER . ':' . $name;
			$first    = $this->consumer->batches()->start( $name, array( 'scope' => 'all' ), ExistingRunPolicy::Replace );
			self::assertInstanceOf( Success::class, $first );
			self::assertIsString( $first->value );
			$this->rig->randomizer()->value = 8;
			$second                         = $this->consumer->batches()->start( $name, array( 'scope' => 'all' ), ExistingRunPolicy::Replace );
			self::assertInstanceOf( Success::class, $second );
			$this->rig->run_due();

			return array( $identity, $first->value );
		}

		if ( 'failed' === $status ) {
			$this->task->retry_policy = new RetryPolicy( max_attempts: 1 );
			$this->task->throwable    = new \RuntimeException( 'Database unavailable.' );
		}
		$result = $this->consumer->tasks()->enqueue( self::NAME, array( 'scope' => $status ) );
		self::assertInstanceOf( Success::class, $result );
		self::assertIsString( $result->value );
		if ( 'cancelled' === $status ) {
			$cancelled = $this->consumer->runs()->cancel( self::NAME, $result->value );
			self::assertInstanceOf( Success::class, $cancelled );
		} else {
			$this->rig->run_due();
		}

		return array( self::IDENTITY, $result->value );
	}

	/**
	 * Completes deterministic task runs and returns their identifiers in start order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $count  Number of runs.
	 * @param   int $offset Run-id entropy offset.
	 *
	 * @return  list<string>
	 */
	private function complete_tasks( int $count, int $offset = 0 ): array {
		$run_ids = array();
		foreach ( \range( 1, $count ) as $index ) {
			$this->rig->randomizer()->value = $offset + $index;
			$result                         = $this->consumer->tasks()->enqueue( self::NAME, array( 'index' => $offset + $index ) );
			self::assertInstanceOf( Success::class, $result );
			self::assertIsString( $result->value );
			$run_ids[] = $result->value;
			$this->rig->run_due();
		}

		return $run_ids;
	}

	/**
	 * Returns visible history for the primary identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{run_id: string, outcome: string, failed_store: bool}>
	 */
	private function history(): array {
		$history = $this->rig->inspection()->runs( self::IDENTITY )['history'];
		self::assertNotNull( $history );

		return $history;
	}

	/**
	 * Sets the legitimate history-retention filter seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $size Scripted history size.
	 *
	 * @return  void
	 */
	private function set_history_size( mixed $size ): void {
		$filters = $GLOBALS['a8csp_bgte_test_filter_values'] ?? null;
		self::assertIsArray( $filters );
		$filters['a8csp_background_tasks/history_size'] = $size;
		$GLOBALS['a8csp_bgte_test_filter_values']       = $filters;
	}

	/**
	 * Returns a history store bound to the active authoritative rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RunHistory
	 */
	private function store(): RunHistory {
		return new RunHistory( self::IDENTITY, $this->rows, $this->rig->logger() );
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
	 * Returns the active history's authoritative raw bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function raw_row(): string {
		$raw = $this->rig->wpdb()->rows[ RunHistory::OPTION_PREFIX . self::IDENTITY ] ?? null;
		self::assertIsString( $raw );

		return $raw;
	}

	/**
	 * Returns started fixture entries for one inclusive index range.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $first First fixture index.
	 * @param   int $last  Last fixture index.
	 *
	 * @return  list<array{run_id: string, args_hash: string}>
	 */
	private static function started_entries( int $first, int $last ): array {
		return \array_map(
			static fn ( int $index ): array => array(
				'run_id'    => 'run-' . $index,
				'args_hash' => self::hash( $index ),
			),
			\range( $first, $last )
		);
	}

	/**
	 * Returns started fixture entries sharing one argument identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $first First fixture index.
	 * @param   int $last  Last fixture index.
	 *
	 * @return  list<array{run_id: string, args_hash: string}>
	 */
	private static function same_hash_entries( int $first, int $last ): array {
		return \array_map(
			static fn ( int $index ): array => array(
				'run_id'    => 'run-' . $index,
				'args_hash' => 'hash-a',
			),
			\range( $first, $last )
		);
	}

	/**
	 * Returns one deterministic argument identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $index Fixture index.
	 *
	 * @return  string
	 */
	private static function hash( int $index ): string {
		return 'hash-' . \str_pad( (string) $index, 2, '0', \STR_PAD_LEFT );
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

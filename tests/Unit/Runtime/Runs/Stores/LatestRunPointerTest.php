<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs\Stores;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\LatestRunPointer;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises bounded latest-run discovery and retains its exact-row concurrency proofs.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( LatestRunPointer::class )]
final class LatestRunPointerTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string IDENTITY = 'runs-tests:reports';

	private StoreFixtureBuilder $fixtures;
	private EngineRig $rig;
	private OptionRows $rows;

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
	 * Boots one production store graph over deterministic option rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig      = EngineRig::set_up();
		$this->rows     = new OptionRows( $this->rig->wpdb() );
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
	 * Empty, global, per-argument, and repair discovery remain observable through the store contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_discovery_and_repair_preserve_global_and_per_argument_meaning(): void {
		$pointer = $this->pointer();

		self::assertNull( $pointer->get_latest_for_hash( 'hash-a' ) );
		self::assertTrue( $pointer->record( 'run-a', 'hash-a' ) );
		self::assertTrue( $pointer->record( 'run-b', 'hash-b' ) );
		self::assertSame( 'run-a', $pointer->get_latest_for_hash( 'hash-a' ) );
		self::assertTrue( $pointer->record( 'run-a-repaired', 'hash-a' ) );
		self::assertSame( 'run-a-repaired', $pointer->get_latest_for_hash( 'hash-a' ) );
		self::assertSame( 'run-b', $pointer->get_latest_for_hash( 'hash-b' ) );
		self::assertTrue( $pointer->record( 'run-b-repaired', 'hash-b' ) );
		self::assertSame( 'run-b-repaired', $pointer->get_latest_for_hash( 'hash-b' ) );
	}

	/**
	 * Twenty argument identities survive, and refreshing one changes the next eviction victim.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_argument_pointer_retention_is_a_twenty_identity_lru(): void {
		$pointer = $this->pointer();
		foreach ( \range( 0, 19 ) as $index ) {
			self::assertTrue( $pointer->record( self::run_id( $index ), self::hash( $index ) ) );
		}

		self::assertTrue( $pointer->record( 'run-refreshed', self::hash( 0 ) ) );
		self::assertTrue( $pointer->record( self::run_id( 20 ), self::hash( 20 ) ) );

		self::assertSame( 'run-refreshed', $pointer->get_latest_for_hash( self::hash( 0 ) ) );
		self::assertNull( $pointer->get_latest_for_hash( self::hash( 1 ) ) );
		self::assertSame( self::run_id( 2 ), $pointer->get_latest_for_hash( self::hash( 2 ) ) );
		self::assertSame( self::run_id( 20 ), $pointer->get_latest_for_hash( self::hash( 20 ) ) );
	}

	/**
	 * Malformed storage reads as absent and a later real write restores discoverability.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_malformed_storage_is_tolerated_and_normalized_by_recording(): void {
		$this->rig->wpdb()->put( LatestRunPointer::OPTION_PREFIX . self::IDENTITY, 'not-a-pointer' );
		$pointer = $this->pointer();

		self::assertNull( $pointer->get_latest_for_hash( 'hash-a' ) );
		self::assertTrue( $pointer->record( 'run-a', 'hash-a' ) );
		self::assertSame( 'run-a', $pointer->get_latest_for_hash( 'hash-a' ) );
	}

	// endregion.

	// region KEEP CAS MICRO-SUITE.

	/**
	 * Interleaved appends retain both writers while capping the exact authoritative bytes.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built before/after rows prove the caller retries the rival generation instead of overwriting it or exceeding the bounded LRU.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_interleaved_appends_preserve_both_writers_in_exact_capped_bytes(): void {
		$initial  = self::entries( 0, 18 );
		$expected = array(
			...$initial,
			array(
				'run_id'    => 'run-rival',
				'args_hash' => 'hash-rival',
			),
			array(
				'run_id'    => 'run-caller',
				'args_hash' => 'hash-caller',
			),
		);
		$this->put_fixture( $this->fixtures->latest( $initial ) );
		$this->rig->wpdb()->before_next(
			'update',
			function (): void {
				self::assertTrue( $this->pointer()->record( 'run-rival', 'hash-rival' ) );
			}
		);

		self::assertTrue( $this->pointer()->record( 'run-caller', 'hash-caller' ) );

		self::assertSame( $this->fixtures->latest( $expected )[1], $this->raw_row() );
	}

	/**
	 * A lost exact update retries from the rival bytes and preserves its identity.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Every valid precondition and expected generation comes from production serialization, so exact equality proves a genuine lost-CAS retry.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_lost_cas_retries_from_fixture_built_rival_bytes(): void {
		$initial  = array(
			array(
				'run_id'    => 'run-a',
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
				'args_hash' => 'hash-caller',
			),
		);
		$this->put_fixture( $this->fixtures->latest( $initial ) );
		$rival_fixture = $this->fixtures->latest( $rival );
		$this->rig->wpdb()->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $rival_fixture ): void {
				$wpdb->put( $rival_fixture[0], $rival_fixture[1] );
			}
		);
		$this->rig->wpdb()->recorded_queries = array();

		self::assertTrue( $this->pointer()->record( 'run-caller', 'hash-caller' ) );

		self::assertSame( $this->fixtures->latest( $expected )[1], $this->raw_row() );
		self::assertCount( 2, $this->queries_starting_with( 'SELECT ' ) );
		self::assertCount( 2, $this->queries_starting_with( 'UPDATE ' ) );
	}

	/**
	 * Failed reads and unchanged failed writes never manufacture a successful pointer update.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale No-write outcomes are the safety boundary when the authoritative generation cannot be read or an exact update fails without a competing winner.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_authority_failures_return_false_without_changing_bytes(): void {
		$fixture = $this->fixtures->latest(
			array(
				array(
					'run_id'    => 'run-a',
					'args_hash' => 'hash-a',
				),
			)
		);
		$this->put_fixture( $fixture );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted pointer read failure';
			}
		);

		self::assertFalse( $this->pointer()->record( 'run-b', 'hash-b' ) );
		self::assertSame( $fixture[1], $this->raw_row() );
		self::assertSame( array(), $this->queries_starting_with( 'UPDATE ' ) );

		$this->rig->wpdb()->recorded_queries = array();
		$this->rig->wpdb()->script_result( 'update', false );
		self::assertFalse( $this->pointer()->record( 'run-b', 'hash-b' ) );
		self::assertSame( $fixture[1], $this->raw_row() );
		self::assertCount( 1, $this->queries_starting_with( 'SELECT ' ) );
		self::assertCount( 1, $this->queries_starting_with( 'UPDATE ' ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns a pointer bound to the active authoritative rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  LatestRunPointer
	 */
	private function pointer(): LatestRunPointer {
		return new LatestRunPointer( self::IDENTITY, $this->rows );
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
	 * Returns the active pointer's authoritative raw bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function raw_row(): string {
		$raw = $this->rig->wpdb()->rows[ LatestRunPointer::OPTION_PREFIX . self::IDENTITY ] ?? null;
		self::assertIsString( $raw );

		return $raw;
	}

	/**
	 * Returns fixture entries for one inclusive index range.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $first First fixture index.
	 * @param   int $last  Last fixture index.
	 *
	 * @return  list<array{run_id: string, args_hash: string}>
	 */
	private static function entries( int $first, int $last ): array {
		return \array_map(
			static fn ( int $index ): array => array(
				'run_id'    => self::run_id( $index ),
				'args_hash' => self::hash( $index ),
			),
			\range( $first, $last )
		);
	}

	/**
	 * Returns one deterministic run identifier.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $index Fixture index.
	 *
	 * @return  string
	 */
	private static function run_id( int $index ): string {
		return 'run-' . \str_pad( (string) $index, 2, '0', \STR_PAD_LEFT );
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

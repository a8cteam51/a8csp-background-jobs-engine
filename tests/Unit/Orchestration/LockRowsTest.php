<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Orchestration;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LockRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the direct SQL, raw-value CAS, cache, and site-binding lock-row seam.
 *
 */
#[CoversClass( LockRows::class )]
final class LockRowsTest extends TestCase {
	private const KEY = 'a8csp_bgte_lock_email-digest_args-123';

	/** Loads the guarded WordPress functions used by the lock-row seam. */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__ ) . '/wp-lock-stubs.php';
	}

	/** Resets the current site and object-cache ledgers. */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_blog_id']     = 1;
		$GLOBALS['a8csp_bgte_test_cache']       = array();
		$GLOBALS['a8csp_bgte_test_cache_calls'] = array();
	}

	/**
	 * INSERT IGNORE acquires only an absent non-autoloaded row and carries Core's lock marker.
	 *
	 * @return  void
	 */
	public function test_insert_models_exclusive_core_lock_statement(): void {
		$wpdb = new WpdbLockSpy();
		$rows = new LockRows( $wpdb );
		$row  = self::row( 'run-owner', 100, 100 );

		self::assertTrue( $rows->insert( self::KEY, $row ) );
		self::assertFalse( $rows->insert( self::KEY, self::row( 'run-rival', 200, 200 ) ) );

		self::assertSame( self::raw( $row ), $wpdb->rows[ self::KEY ] );
		self::assertSame( 'off', $wpdb->autoload[ self::KEY ] );
		self::assertCount( 2, $wpdb->recorded_queries );
		self::assertStringStartsWith( 'INSERT IGNORE INTO `wp_options`', $wpdb->recorded_queries[0] );
		self::assertStringContainsString( '`autoload`) VALUES (', $wpdb->recorded_queries[0] );
		self::assertStringEndsWith( "'off') /* LOCK */", $wpdb->recorded_queries[0] );
	}

	/**
	 * Direct selection preserves an existing empty raw value instead of treating it as absence.
	 *
	 * @return  void
	 */
	public function test_select_distinguishes_an_empty_malformed_row_from_absence(): void {
		$wpdb = new WpdbLockSpy();
		$wpdb->put( self::KEY, '' );
		$rows = new LockRows( $wpdb );
		self::prime_stale_caches();

		self::assertSame( '', $rows->select( self::KEY ) );
		unset( $wpdb->rows[ self::KEY ], $wpdb->autoload[ self::KEY ] );
		self::assertNull( $rows->select( self::KEY ) );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_cache_calls'] );
	}

	/** Prepared values containing placeholder text remain byte-for-byte data. */
	public function test_prepared_values_cannot_be_reparsed_as_placeholders(): void {
		$wpdb = new WpdbLockSpy();
		$rows = new LockRows( $wpdb );
		$key  = self::KEY . '-%s';
		$row  = self::row( 'run-%s-owner', 100, 100 );

		self::assertTrue( $rows->insert( $key, $row ) );
		self::assertSame( self::raw( $row ), $wpdb->rows[ $key ] );
	}

	/**
	 * Replace writes only while both the option name and exact raw value still match.
	 *
	 * @return  void
	 */
	public function test_replace_is_an_exact_raw_value_compare_and_swap(): void {
		$wpdb    = new WpdbLockSpy();
		$old_row = self::row( 'run-owner', 100, 100 );
		$new_row = self::row( 'run-owner', 100, 200 );
		$old_raw = self::raw( $old_row );
		$wpdb->put( self::KEY, $old_raw );
		$rows = new LockRows( $wpdb );

		self::assertFalse( $rows->replace( self::KEY, self::raw( self::row( 'run-loser', 50, 50 ) ), $new_row ) );
		self::assertSame( $old_raw, $wpdb->rows[ self::KEY ] );
		self::assertTrue( $rows->replace( self::KEY, $old_raw, $new_row ) );
		self::assertSame( self::raw( $new_row ), $wpdb->rows[ self::KEY ] );
		self::assertStringContainsString( 'WHERE `option_name` = ', $wpdb->recorded_queries[0] );
		self::assertStringContainsString( 'AND BINARY `option_value` = BINARY ', $wpdb->recorded_queries[0] );
	}

	/**
	 * An unchanged MySQL update succeeds only after an authoritative read confirms the expected row.
	 *
	 * @return  void
	 */
	public function test_replace_confirms_an_identical_value_after_zero_affected_rows(): void {
		$wpdb = new WpdbLockSpy();
		$row  = self::row( 'run-owner', 100, 100 );
		$raw  = self::raw( $row );
		$wpdb->put( self::KEY, $raw );
		$rows = new LockRows( $wpdb );

		self::assertTrue( $rows->replace( self::KEY, $raw, $row ) );
		self::assertSame( 0, $wpdb->rows_affected );
		self::assertCount( 2, $wpdb->recorded_queries );
		self::assertStringStartsWith( 'UPDATE ', $wpdb->recorded_queries[0] );
		self::assertStringStartsWith( 'SELECT ', $wpdb->recorded_queries[1] );
	}

	/**
	 * A zero-row identical update is not success when a rival replaces the selected row first.
	 *
	 * @return  void
	 */
	public function test_replace_rejects_a_lost_identical_value_compare_and_swap(): void {
		$wpdb       = new WpdbLockSpy();
		$row        = self::row( 'run-owner', 100, 100 );
		$raw        = self::raw( $row );
		$winner_raw = self::raw( self::row( 'run-winner', 200, 200 ) );
		$wpdb->put( self::KEY, $raw );
		$wpdb->before_next(
			'update',
			static function ( WpdbLockSpy $database ) use ( $winner_raw ): void {
				$database->put( self::KEY, $winner_raw );
			}
		);
		$rows = new LockRows( $wpdb );

		self::assertFalse( $rows->replace( self::KEY, $raw, $row ) );
		self::assertSame( $winner_raw, $wpdb->rows[ self::KEY ] );
	}

	/** Collation-equivalent but byte-different raw values cannot satisfy either CAS predicate. */
	public function test_compare_and_swap_uses_binary_raw_value_equality(): void {
		$wpdb         = new WpdbLockSpy();
		$expected_raw = self::raw( self::row( 'run-owner', 100, 100 ) );
		$winner_raw   = self::raw( self::row( 'RUN-OWNER', 100, 100 ) );
		$wpdb->put( self::KEY, $winner_raw );
		$rows = new LockRows( $wpdb );

		self::assertFalse( $rows->replace( self::KEY, $expected_raw, self::row( 'run-owner', 100, 200 ) ) );
		self::assertFalse( $rows->delete( self::KEY, $expected_raw ) );
		self::assertSame( $winner_raw, $wpdb->rows[ self::KEY ] );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $wpdb->recorded_queries[0] );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $wpdb->recorded_queries[1] );
	}

	/** A database error is not the zero-row identical-update case and does not trigger confirmation. */
	public function test_replace_does_not_confirm_an_identical_value_after_a_database_error(): void {
		$wpdb = new WpdbLockSpy();
		$row  = self::row( 'run-owner', 100, 100 );
		$raw  = self::raw( $row );
		$wpdb->put( self::KEY, $raw );
		$wpdb->script_result( 'update', false );
		$rows = new LockRows( $wpdb );
		self::prime_stale_caches();

		self::assertFalse( $rows->replace( self::KEY, $raw, $row ) );
		self::assertCount( 1, $wpdb->recorded_queries );
		self::assertStringStartsWith( 'UPDATE ', $wpdb->recorded_queries[0] );
		self::assert_cache_purge();
	}

	/**
	 * Delete removes only the exact raw row selected by the caller.
	 *
	 * @return  void
	 */
	public function test_delete_is_an_exact_raw_value_compare_and_swap(): void {
		$wpdb = new WpdbLockSpy();
		$raw  = self::raw( self::row( 'run-owner', 100, 100 ) );
		$wpdb->put( self::KEY, $raw );
		$rows = new LockRows( $wpdb );

		self::assertFalse( $rows->delete( self::KEY, self::raw( self::row( 'run-loser', 50, 50 ) ) ) );
		self::assertSame( $raw, $wpdb->rows[ self::KEY ] );
		self::assertTrue( $rows->delete( self::KEY, $raw ) );
		self::assertArrayNotHasKey( self::KEY, $wpdb->rows );
		self::assertStringContainsString( 'WHERE `option_name` = ', $wpdb->recorded_queries[0] );
		self::assertStringContainsString( 'AND BINARY `option_value` = BINARY ', $wpdb->recorded_queries[0] );
	}

	/**
	 * Every direct write drops the per-key cache and removes the key from notoptions.
	 *
	 * @return  void
	 */
	public function test_every_mutation_purges_options_and_notoptions_caches(): void {
		$wpdb = new WpdbLockSpy();
		$rows = new LockRows( $wpdb );
		$row  = self::row( 'run-owner', 100, 100 );

		self::prime_stale_caches();
		$rows->insert( self::KEY, $row );
		self::assert_cache_purge();

		self::prime_stale_caches();
		$raw = $wpdb->rows[ self::KEY ];
		$rows->replace( self::KEY, $raw, self::row( 'run-owner', 100, 200 ) );
		self::assert_cache_purge();

		self::prime_stale_caches();
		$rows->delete( self::KEY, $wpdb->rows[ self::KEY ] );
		self::assert_cache_purge();
	}

	/** Lost inserts and CAS attempts still invalidate both stale cache representations. */
	public function test_failed_mutations_still_purge_options_and_notoptions_caches(): void {
		$wpdb = new WpdbLockSpy();
		$rows = new LockRows( $wpdb );
		$row  = self::row( 'run-owner', 100, 100 );
		$raw  = self::raw( $row );
		$wpdb->put( self::KEY, $raw );

		self::prime_stale_caches();
		self::assertFalse( $rows->insert( self::KEY, self::row( 'run-rival', 200, 200 ) ) );
		self::assert_cache_purge();

		self::prime_stale_caches();
		self::assertFalse( $rows->replace( self::KEY, 'stale-raw', self::row( 'run-owner', 100, 200 ) ) );
		self::assert_cache_purge();

		self::prime_stale_caches();
		self::assertFalse( $rows->delete( self::KEY, 'stale-raw' ) );
		self::assert_cache_purge();
	}

	/**
	 * Site-bound rows reject every operation after an ambient blog switch.
	 *
	 * @param   callable(LockRows): mixed $operation Operation under test.
	 *
	 * @return  void
	 */
	#[DataProvider( 'site_bound_operation_provider' )]
	public function test_every_operation_rejects_switch_to_blog_misuse( callable $operation ): void {
		$wpdb = new WpdbLockSpy();
		$rows = new LockRows( $wpdb );

		$GLOBALS['a8csp_bgte_test_blog_id'] = 2;

		try {
			$operation( $rows );
		} catch ( \LogicException $exception ) {
			self::assertStringContainsString( 'switch_to_blog', $exception->getMessage() );
			self::assertSame( array(), $wpdb->recorded_queries );
			self::assertSame( array(), $GLOBALS['a8csp_bgte_test_cache_calls'] );
			return;
		}

		self::fail( 'Every lock-row operation must reject use after switch_to_blog().' );
	}

	/**
	 * Returns one call for each public lock-row operation.
	 *
	 * @return  iterable<string, array{callable(LockRows): mixed}>
	 */
	public static function site_bound_operation_provider(): iterable {
		$row = self::row( 'run-owner', 100, 100 );
		$raw = 'expected-raw';

		yield 'insert' => array( static fn ( LockRows $rows ): bool => $rows->insert( self::KEY, $row ) );
		yield 'select' => array( static fn ( LockRows $rows ): ?string => $rows->select( self::KEY ) );
		yield 'replace' => array( static fn ( LockRows $rows ): bool => $rows->replace( self::KEY, $raw, $row ) );
		yield 'delete' => array( static fn ( LockRows $rows ): bool => $rows->delete( self::KEY, $raw ) );
	}

	/**
	 * Returns an exact persisted lock shape.
	 *
	 * @param   string $run_id       Run identifier.
	 * @param   int    $claimed_at   Claim timestamp.
	 * @param   int    $heartbeat_at Heartbeat timestamp.
	 *
	 * @return  array{run_id: string, claimed_at: int, heartbeat_at: int}
	 */
	private static function row( string $run_id, int $claimed_at, int $heartbeat_at ): array {
		return array(
			'run_id'       => $run_id,
			'claimed_at'   => $claimed_at,
			'heartbeat_at' => $heartbeat_at,
		);
	}

	/**
	 * Returns a value's WordPress-shaped raw representation.
	 *
	 * @param   mixed $value Value to serialize.
	 *
	 * @return  string
	 */
	private static function raw( mixed $value ): string {
		$raw = \maybe_serialize( $value );
		self::assertIsString( $raw );

		return $raw;
	}

	/** Primes both stale option-cache representations and clears their call ledger. */
	private static function prime_stale_caches(): void {
		$GLOBALS['a8csp_bgte_test_cache']       = array(
			'options' => array(
				self::KEY    => 'stale',
				'notoptions' => array(
					self::KEY => true,
					'other'   => true,
				),
			),
		);
		$GLOBALS['a8csp_bgte_test_cache_calls'] = array();
	}

	/** Asserts Core-shaped per-key and notoptions invalidation. */
	private static function assert_cache_purge(): void {
		/** @var list<array{function: string, args: list<mixed>}> $calls */
		$calls = $GLOBALS['a8csp_bgte_test_cache_calls'];
		self::assertSame(
			array( 'wp_cache_delete', 'wp_cache_get', 'wp_cache_set' ),
			\array_column( $calls, 'function' )
		);
		self::assertSame( array( self::KEY, 'options' ), $calls[0]['args'] );
		self::assertSame( array( 'notoptions', 'options', false ), $calls[1]['args'] );
		self::assertSame( array( 'notoptions', array( 'other' => true ), 'options', 0 ), $calls[2]['args'] );

		/** @var array<string, array<int|string, mixed>> $cache */
		$cache = $GLOBALS['a8csp_bgte_test_cache'];
		self::assertArrayNotHasKey( self::KEY, $cache['options'] );
		self::assertSame( array( 'other' => true ), $cache['options']['notoptions'] );
	}
}

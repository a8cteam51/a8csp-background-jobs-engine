<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\LatestRunPointer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins latest-run discovery pointers and hash-identity LRU retention.
 *
 */
#[CoversClass( LatestRunPointer::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
final class LatestRunPointerTest extends TestCase {
	private const OWNER = 'runs-tests';

	private OptionRows $rows;
	private WpdbLockSpy $wpdb;

	/**
	 * Loads guarded WordPress option and authoritative-row functions before the pointer is autoloaded.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 3 ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__, 3 ) . '/wp-lock-stubs.php';
	}

	/**
	 * Resets request-local option and authoritative-row state.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_options']         = array();
		$GLOBALS['a8csp_bgte_test_option_calls']    = array();
		$GLOBALS['a8csp_bgte_test_option_autoload'] = array();
		$GLOBALS['a8csp_bgte_test_blog_id']         = 1;
		$GLOBALS['a8csp_bgte_test_cache']           = array();
		$GLOBALS['a8csp_bgte_test_cache_calls']     = array();
		$this->wpdb                                 = new WpdbLockSpy();
		$this->rows                                 = new OptionRows( $this->wpdb );
	}

	/**
	 * Returns one owner-qualified test work identity.
	 *
	 * @param   string $name Owner-local work name.
	 *
	 * @return  string
	 */
	private static function identity( string $name ): string {
		return self::OWNER . ':' . $name;
	}

	/**
	 * Empty pointer reads report no latest run.
	 *
	 * @return  void
	 */
	public function test_reads_return_null_without_a_pointer(): void {
		$pointer = new LatestRunPointer( self::identity( 'reports' ), $this->rows );

		self::assertNull( $pointer->get_latest() );
		self::assertNull( $pointer->get_latest_for_hash( 'hash-a' ) );
		self::assertSame( array(), $this->write_queries() );
		self::assertSame( array(), $this->option_calls() );
	}

	/**
	 * Recording overwrites the all pointer while retaining per-hash identities.
	 *
	 * @return  void
	 */
	public function test_record_overwrites_all_and_pins_the_literal_option_key(): void {
		$pointer = new LatestRunPointer( self::identity( 'reports' ), $this->rows );

		self::assertTrue( $pointer->record( 'run-a', 'hash-a' ) );
		self::assertTrue( $pointer->record( 'run-b', 'hash-b' ) );

		self::assertSame( 'run-b', $pointer->get_latest() );
		self::assertSame( 'run-a', $pointer->get_latest_for_hash( 'hash-a' ) );
		self::assertSame( 'run-b', $pointer->get_latest_for_hash( 'hash-b' ) );
		$expected = array(
			'all'     => 'run-b',
			'by_hash' => array(
				'hash-a' => 'run-a',
				'hash-b' => 'run-b',
			),
		);
		$this->assert_pointer_row( 'a8csp_bgte_latest_runs-tests:reports', $expected );
		self::assertSame( array(), $this->option_calls() );
	}

	/**
	 * The twenty-first distinct hash evicts the oldest identity.
	 *
	 * @return  void
	 */
	public function test_distinct_hashes_evict_the_oldest_past_twenty(): void {
		$pointer = new LatestRunPointer( self::identity( 'exports' ), $this->rows );

		for ( $index = 0; $index <= 20; ++$index ) {
			$suffix = \str_pad( (string) $index, 2, '0', STR_PAD_LEFT );
			self::assertTrue( $pointer->record( 'run-' . $suffix, 'hash-' . $suffix ) );
		}

		self::assertNull( $pointer->get_latest_for_hash( 'hash-00' ) );
		self::assertSame( 'run-01', $pointer->get_latest_for_hash( 'hash-01' ) );
		self::assertSame( 'run-20', $pointer->get_latest_for_hash( 'hash-20' ) );
		self::assertSame( 'run-20', $pointer->get_latest() );
		self::assertSame( \array_map( static fn ( int $index ): string => 'hash-' . \str_pad( (string) $index, 2, '0', STR_PAD_LEFT ), \range( 1, 20 ) ), \array_keys( $this->by_hash_option( 'a8csp_bgte_latest_runs-tests:exports' ) ) );

		self::assertSame( 'off', $this->wpdb->autoload['a8csp_bgte_latest_runs-tests:exports'] ?? null );
		self::assertSame( array(), $this->option_calls() );
	}

	/**
	 * Repair initializes an absent global, preserves an unrelated one, and advances a displaced one.
	 *
	 * @return  void
	 */
	public function test_hash_repair_changes_the_global_pointer_when_absent_or_naming_the_displaced_run(): void {
		$empty_pointer = new LatestRunPointer( self::identity( 'empty-repairs' ), $this->rows );
		self::assertTrue( $empty_pointer->repair_for_hash( 'run-first-owner', 'hash-first' ) );

		self::assertSame( 'run-first-owner', $empty_pointer->get_latest() );
		self::assertSame( 'run-first-owner', $empty_pointer->get_latest_for_hash( 'hash-first' ) );

		$pointer = new LatestRunPointer( self::identity( 'repairs' ), $this->rows );
		self::assertTrue( $pointer->record( 'run-old-a', 'hash-a' ) );
		self::assertTrue( $pointer->record( 'run-newest-b', 'hash-b' ) );

		self::assertTrue( $pointer->repair_for_hash( 'run-owner-a', 'hash-a' ) );

		self::assertSame( 'run-newest-b', $pointer->get_latest() );
		self::assertSame( 'run-owner-a', $pointer->get_latest_for_hash( 'hash-a' ) );
		self::assertSame( 'run-newest-b', $pointer->get_latest_for_hash( 'hash-b' ) );

		self::assertTrue( $pointer->repair_for_hash( 'run-owner-b', 'hash-b' ) );

		self::assertSame( 'run-owner-b', $pointer->get_latest() );
		self::assertSame( 'run-owner-b', $pointer->get_latest_for_hash( 'hash-b' ) );
		$expected = array(
			'all'     => 'run-owner-b',
			'by_hash' => array(
				'hash-a' => 'run-owner-a',
				'hash-b' => 'run-owner-b',
			),
		);
		$this->assert_pointer_row( 'a8csp_bgte_latest_runs-tests:repairs', $expected );
		self::assertSame( array(), $this->option_calls() );
	}

	/** Two interleaved appends retain both writers while enforcing the twenty-identity cap. */
	public function test_interleaved_record_appends_preserve_both_hashes_in_exact_capped_bytes(): void {
		$key     = 'a8csp_bgte_latest_runs-tests:interleaved';
		$by_hash = array();
		for ( $index = 0; $index < 19; ++$index ) {
			$suffix                       = \str_pad( (string) $index, 2, '0', STR_PAD_LEFT );
			$by_hash[ 'hash-' . $suffix ] = 'run-' . $suffix;
		}
		$this->put_pointer(
			$key,
			array(
				'all'     => 'run-18',
				'by_hash' => $by_hash,
			)
		);
		$caller = new LatestRunPointer( self::identity( 'interleaved' ), $this->rows );
		$rival  = new LatestRunPointer( self::identity( 'interleaved' ), $this->rows );
		$this->wpdb->before_next(
			'update',
			static function () use ( $rival ): void {
				self::assertTrue( $rival->record( 'run-rival', 'hash-rival' ) );
			}
		);

		self::assertTrue( $caller->record( 'run-caller', 'hash-caller' ) );

		unset( $by_hash['hash-00'] );
		$by_hash['hash-rival']  = 'run-rival';
		$by_hash['hash-caller'] = 'run-caller';
		$this->assert_pointer_row(
			$key,
			array(
				'all'     => 'run-caller',
				'by_hash' => $by_hash,
			)
		);
		self::assertCount( 20, $by_hash );
		self::assertCount( 3, $this->queries_starting_with( 'UPDATE ' ) );
		self::assertSame( array(), $this->option_calls() );
	}

	/** A lost exact-row update retries from fresh bytes and retains the rival identity. */
	public function test_record_retries_a_lost_cas_and_preserves_the_rival_identity(): void {
		$key    = 'a8csp_bgte_latest_runs-tests:latest-cas';
		$stored = array(
			'all'     => 'run-a',
			'by_hash' => array( 'hash-a' => 'run-a' ),
		);
		$rival  = array(
			'all'     => 'run-rival',
			'by_hash' => array(
				'hash-a'     => 'run-a',
				'hash-rival' => 'run-rival',
			),
		);
		$this->put_pointer( $key, $stored );
		$rival_raw = self::raw( $rival );
		$this->wpdb->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $key, $rival_raw ): void {
				$wpdb->put( $key, $rival_raw );
			}
		);
		$pointer = new LatestRunPointer( self::identity( 'latest-cas' ), $this->rows );

		self::assertTrue( $pointer->record( 'run-caller', 'hash-caller' ) );

		$this->assert_pointer_row(
			$key,
			array(
				'all'     => 'run-caller',
				'by_hash' => array(
					'hash-a'      => 'run-a',
					'hash-rival'  => 'run-rival',
					'hash-caller' => 'run-caller',
				),
			)
		);
		self::assertCount( 2, $this->queries_starting_with( 'UPDATE ' ) );
		self::assertSame( array(), $this->option_calls() );
	}

	/** A failed authoritative read rejects recording without issuing a write. */
	public function test_record_returns_false_without_writing_when_the_read_fails(): void {
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted latest-pointer read failure';
			}
		);
		$pointer = new LatestRunPointer( self::identity( 'read-failure' ), $this->rows );

		self::assertFalse( $pointer->record( 'run-a', 'hash-a' ) );

		self::assertArrayNotHasKey( 'a8csp_bgte_latest_runs-tests:read-failure', $this->wpdb->rows );
		self::assertSame( array(), $this->write_queries() );
		self::assertSame( array(), $this->option_calls() );
	}

	/** An unchanged row after a genuine update failure is not reported as a successful CAS. */
	public function test_record_returns_false_when_a_failed_update_leaves_the_expected_row_unchanged(): void {
		$key    = 'a8csp_bgte_latest_runs-tests:write-failure';
		$stored = array(
			'all'     => 'run-a',
			'by_hash' => array( 'hash-a' => 'run-a' ),
		);
		$this->put_pointer( $key, $stored );
		$this->wpdb->script_result( 'update', false );
		$pointer = new LatestRunPointer( self::identity( 'write-failure' ), $this->rows );

		self::assertFalse( $pointer->record( 'run-b', 'hash-b' ) );

		$this->assert_pointer_row( $key, $stored );
		self::assertCount( 1, $this->queries_starting_with( 'UPDATE ' ) );
		self::assertSame( array(), $this->option_calls() );
	}

	/** A malformed existing row is replaced from its exact bytes with normalized pointer state. */
	public function test_record_normalizes_a_malformed_authoritative_row(): void {
		$key = 'a8csp_bgte_latest_runs-tests:malformed';
		$this->wpdb->put( $key, 'not-a-serialized-pointer' );
		$pointer = new LatestRunPointer( self::identity( 'malformed' ), $this->rows );

		self::assertTrue( $pointer->record( 'run-a', 'hash-a' ) );

		$this->assert_pointer_row(
			$key,
			array(
				'all'     => 'run-a',
				'by_hash' => array( 'hash-a' => 'run-a' ),
			)
		);
		self::assertSame( array(), $this->option_calls() );
	}

	/** Recording the already-current tail is confirmed without issuing a write. */
	public function test_same_record_is_a_successful_no_op_without_a_write(): void {
		$key    = 'a8csp_bgte_latest_runs-tests:no-op';
		$stored = array(
			'all'     => 'run-a',
			'by_hash' => array( 'hash-a' => 'run-a' ),
		);
		$this->put_pointer( $key, $stored );
		$pointer = new LatestRunPointer( self::identity( 'no-op' ), $this->rows );

		self::assertTrue( $pointer->record( 'run-a', 'hash-a' ) );

		$this->assert_pointer_row( $key, $stored );
		self::assertSame( array(), $this->write_queries() );
		self::assertSame( array(), $this->option_calls() );
	}

	/** Failed authoritative reads return no pointer and never fall back to a stale option-cache value. */
	public function test_reads_return_null_without_writing_when_authoritative_reads_fail(): void {
		$key    = 'a8csp_bgte_latest_runs-tests:failed-reads';
		$stored = array(
			'all'     => 'run-authoritative',
			'by_hash' => array( 'hash-a' => 'run-authoritative' ),
		);
		$this->put_pointer( $key, $stored );
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );
		$options[ $key ] = array(
			'all'     => 'run-stale-cache',
			'by_hash' => array( 'hash-a' => 'run-stale-cache' ),
		);

		$GLOBALS['a8csp_bgte_test_options'] = $options;

		$pointer = new LatestRunPointer( self::identity( 'failed-reads' ), $this->rows );
		for ( $read = 0; $read < 2; ++$read ) {
			$this->wpdb->before_next(
				'select',
				static function ( WpdbLockSpy $wpdb ): void {
					$wpdb->last_error = 'scripted latest-pointer read failure';
				}
			);
		}

		self::assertNull( $pointer->get_latest() );
		self::assertNull( $pointer->get_latest_for_hash( 'hash-a' ) );

		self::assertSame( self::raw( $stored ), $this->wpdb->rows[ $key ] ?? null );
		self::assertSame( array(), $this->write_queries() );
		self::assertSame( array(), $this->option_calls() );
	}

	/**
	 * Re-recording an existing hash moves it behind every older identity.
	 *
	 * @return  void
	 */
	public function test_existing_hash_refresh_changes_the_next_eviction_victim(): void {
		$pointer = new LatestRunPointer( self::identity( 'imports' ), $this->rows );

		for ( $index = 0; $index < 20; ++$index ) {
			$suffix = \str_pad( (string) $index, 2, '0', STR_PAD_LEFT );
			self::assertTrue( $pointer->record( 'run-' . $suffix, 'hash-' . $suffix ) );
		}

		self::assertTrue( $pointer->record( 'run-refreshed', 'hash-00' ) );
		self::assertTrue( $pointer->record( 'run-20', 'hash-20' ) );

		self::assertSame( 'run-refreshed', $pointer->get_latest_for_hash( 'hash-00' ) );
		self::assertNull( $pointer->get_latest_for_hash( 'hash-01' ) );
		self::assertSame( 'run-02', $pointer->get_latest_for_hash( 'hash-02' ) );
		self::assertSame( 'run-20', $pointer->get_latest_for_hash( 'hash-20' ) );
		self::assertSame( array_merge( \array_map( static fn ( int $index ): string => 'hash-' . \str_pad( (string) $index, 2, '0', STR_PAD_LEFT ), \range( 2, 19 ) ), array( 'hash-00', 'hash-20' ) ), \array_keys( $this->by_hash_option( 'a8csp_bgte_latest_runs-tests:imports' ) ) );
		self::assertSame( 'off', $this->wpdb->autoload['a8csp_bgte_latest_runs-tests:imports'] ?? null );
		self::assertSame( array(), $this->option_calls() );
	}

	/**
	 * Returns one decoded authoritative pointer row.
	 *
	 * @param   string $option_name Option name.
	 *
	 * @return  mixed
	 */
	private function option( string $option_name ): mixed {
		$raw = $this->wpdb->rows[ $option_name ] ?? null;
		self::assertIsString( $raw );

		return RawOptionDecoder::decode( $raw );
	}

	/**
	 * Returns one stored per-hash pointer map.
	 *
	 * @param   string $option_name Option name.
	 *
	 * @return  array<array-key, mixed>
	 */
	private function by_hash_option( string $option_name ): array {
		$option = $this->option( $option_name );
		self::assertIsArray( $option );
		$by_hash = $option['by_hash'] ?? null;
		self::assertIsArray( $by_hash );

		return $by_hash;
	}

	/**
	 * Stores one pointer as an exact authoritative raw-row precondition.
	 *
	 * @param   string                  $option_name Option name.
	 * @param   array<array-key, mixed> $pointer     Pointer option value.
	 *
	 * @return  void
	 */
	private function put_pointer( string $option_name, array $pointer ): void {
		$this->wpdb->put( $option_name, self::raw( $pointer ) );
	}

	/**
	 * Asserts one exact serialized pointer row and its non-autoloaded setting.
	 *
	 * @param   string                  $option_name Option name.
	 * @param   array<array-key, mixed> $expected    Expected pointer value.
	 *
	 * @return  void
	 */
	private function assert_pointer_row( string $option_name, array $expected ): void {
		$raw = $this->wpdb->rows[ $option_name ] ?? null;
		self::assertIsString( $raw );
		self::assertSame( self::raw( $expected ), $raw );
		self::assertSame( $expected, RawOptionDecoder::decode( $raw ) );
		self::assertSame( 'off', $this->wpdb->autoload[ $option_name ] ?? null );
	}

	/**
	 * Returns a value's exact WordPress option representation.
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

	/**
	 * Returns recorded SQL statements beginning with one operation prefix.
	 *
	 * @param   string $prefix SQL operation prefix.
	 *
	 * @return  list<string>
	 */
	private function queries_starting_with( string $prefix ): array {
		return \array_values( \array_filter( $this->wpdb->recorded_queries, static fn ( string $query ): bool => \str_starts_with( $query, $prefix ) ) );
	}

	/**
	 * Returns every recorded authoritative SQL write.
	 *
	 * @return  list<string>
	 */
	private function write_queries(): array {
		return \array_values( \array_filter( $this->wpdb->recorded_queries, static fn ( string $query ): bool => \str_starts_with( $query, 'INSERT ' ) || \str_starts_with( $query, 'UPDATE ' ) || \str_starts_with( $query, 'DELETE ' ) ) );
	}

	/**
	 * Returns every recorded option-function call.
	 *
	 * @return  list<array{function: string, args: list<mixed>}>
	 */
	private function option_calls(): array {
		/** @var list<array{function: string, args: list<mixed>}> $calls */
		$calls = $GLOBALS['a8csp_bgte_test_option_calls'];

		return $calls;
	}
}

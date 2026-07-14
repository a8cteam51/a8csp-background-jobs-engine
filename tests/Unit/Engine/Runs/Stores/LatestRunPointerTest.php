<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\LatestRunPointer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins latest-run discovery pointers and hash-identity LRU retention.
 *
 */
#[CoversClass( LatestRunPointer::class )]
final class LatestRunPointerTest extends TestCase {

	/**
	 * Loads guarded WordPress option functions before the pointer is autoloaded.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 3 ) . '/wp-options-stubs.php';
	}

	/**
	 * Resets request-local option state.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_options']         = array();
		$GLOBALS['a8csp_bgte_test_option_calls']    = array();
		$GLOBALS['a8csp_bgte_test_option_autoload'] = array();
	}

	/**
	 * Empty pointer reads report no latest run.
	 *
	 * @return  void
	 */
	public function test_reads_return_null_without_a_pointer(): void {
		$pointer = new LatestRunPointer( 'reports' );

		self::assertNull( $pointer->get_latest() );
		self::assertNull( $pointer->get_latest_for_hash( 'hash-a' ) );
	}

	/**
	 * Recording overwrites the all pointer while retaining per-hash identities.
	 *
	 * @return  void
	 */
	public function test_record_overwrites_all_and_pins_the_literal_option_key(): void {
		$pointer = new LatestRunPointer( 'reports' );

		$pointer->record( 'run-a', 'hash-a' );
		$pointer->record( 'run-b', 'hash-b' );

		self::assertSame( 'run-b', $pointer->get_latest() );
		self::assertSame( 'run-a', $pointer->get_latest_for_hash( 'hash-a' ) );
		self::assertSame( 'run-b', $pointer->get_latest_for_hash( 'hash-b' ) );
		self::assertSame(
			array(
				'all'     => 'run-b',
				'by_hash' => array(
					'hash-a' => 'run-a',
					'hash-b' => 'run-b',
				),
			),
			$this->option( 'a8csp_bgte_latest_reports' )
		);
		self::assertSame( false, $this->autoload_flag( 'a8csp_bgte_latest_reports' ) );
	}

	/**
	 * The twenty-first distinct hash evicts the oldest identity.
	 *
	 * @return  void
	 */
	public function test_distinct_hashes_evict_the_oldest_past_twenty(): void {
		$pointer = new LatestRunPointer( 'exports' );

		for ( $index = 0; $index <= 20; ++$index ) {
			$suffix = \str_pad( (string) $index, 2, '0', STR_PAD_LEFT );
			$pointer->record( 'run-' . $suffix, 'hash-' . $suffix );
		}

		self::assertNull( $pointer->get_latest_for_hash( 'hash-00' ) );
		self::assertSame( 'run-01', $pointer->get_latest_for_hash( 'hash-01' ) );
		self::assertSame( 'run-20', $pointer->get_latest_for_hash( 'hash-20' ) );
		self::assertSame( 'run-20', $pointer->get_latest() );
		self::assertSame(
			\array_map(
				static fn ( int $index ): string => 'hash-' . \str_pad( (string) $index, 2, '0', STR_PAD_LEFT ),
				\range( 1, 20 )
			),
			\array_keys( $this->by_hash_option( 'a8csp_bgte_latest_exports' ) )
		);

		$this->assert_all_option_writes_disable_autoload();
	}

	/**
	 * Repair initializes an absent global, preserves an unrelated one, and advances a displaced one.
	 *
	 * @return  void
	 */
	public function test_hash_repair_changes_the_global_pointer_when_absent_or_naming_the_displaced_run(): void {
		$empty_pointer = new LatestRunPointer( 'empty-repairs' );
		$empty_pointer->repair_for_hash( 'run-first-owner', 'hash-first' );

		self::assertSame( 'run-first-owner', $empty_pointer->get_latest() );
		self::assertSame( 'run-first-owner', $empty_pointer->get_latest_for_hash( 'hash-first' ) );

		$pointer = new LatestRunPointer( 'repairs' );
		$pointer->record( 'run-old-a', 'hash-a' );
		$pointer->record( 'run-newest-b', 'hash-b' );

		$pointer->repair_for_hash( 'run-owner-a', 'hash-a' );

		self::assertSame( 'run-newest-b', $pointer->get_latest() );
		self::assertSame( 'run-owner-a', $pointer->get_latest_for_hash( 'hash-a' ) );
		self::assertSame( 'run-newest-b', $pointer->get_latest_for_hash( 'hash-b' ) );

		$pointer->repair_for_hash( 'run-owner-b', 'hash-b' );

		self::assertSame( 'run-owner-b', $pointer->get_latest() );
		self::assertSame( 'run-owner-b', $pointer->get_latest_for_hash( 'hash-b' ) );
		self::assertSame(
			array(
				'all'     => 'run-owner-b',
				'by_hash' => array(
					'hash-a' => 'run-owner-a',
					'hash-b' => 'run-owner-b',
				),
			),
			$this->option( 'a8csp_bgte_latest_repairs' )
		);
	}

	/**
	 * Re-recording an existing hash moves it behind every older identity.
	 *
	 * @return  void
	 */
	public function test_existing_hash_refresh_changes_the_next_eviction_victim(): void {
		$pointer = new LatestRunPointer( 'imports' );

		for ( $index = 0; $index < 20; ++$index ) {
			$suffix = \str_pad( (string) $index, 2, '0', STR_PAD_LEFT );
			$pointer->record( 'run-' . $suffix, 'hash-' . $suffix );
		}

		$pointer->record( 'run-refreshed', 'hash-00' );
		$pointer->record( 'run-20', 'hash-20' );

		self::assertSame( 'run-refreshed', $pointer->get_latest_for_hash( 'hash-00' ) );
		self::assertNull( $pointer->get_latest_for_hash( 'hash-01' ) );
		self::assertSame( 'run-02', $pointer->get_latest_for_hash( 'hash-02' ) );
		self::assertSame( 'run-20', $pointer->get_latest_for_hash( 'hash-20' ) );
		self::assertSame(
			array_merge(
				\array_map(
					static fn ( int $index ): string => 'hash-' . \str_pad( (string) $index, 2, '0', STR_PAD_LEFT ),
					\range( 2, 19 )
				),
				array( 'hash-00', 'hash-20' )
			),
			\array_keys( $this->by_hash_option( 'a8csp_bgte_latest_imports' ) )
		);
	}

	/**
	 * Returns one stored option value.
	 *
	 * @param   string $option_name Option name.
	 *
	 * @return  mixed
	 */
	private function option( string $option_name ): mixed {
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );

		return $options[ $option_name ] ?? null;
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
	 * Returns the recorded autoload flag for one option.
	 *
	 * @param   string $option_name Option name.
	 *
	 * @return  mixed
	 */
	private function autoload_flag( string $option_name ): mixed {
		$autoload_flags = $GLOBALS['a8csp_bgte_test_option_autoload'] ?? null;
		self::assertIsArray( $autoload_flags );

		return $autoload_flags[ $option_name ] ?? null;
	}

	/**
	 * Asserts that every option write explicitly disables autoload.
	 *
	 * @return  void
	 */
	private function assert_all_option_writes_disable_autoload(): void {
		foreach ( $this->option_calls() as $call ) {
			if ( 'add_option' === $call['function'] ) {
				self::assertSame( false, $call['args'][3] );
			} elseif ( 'update_option' === $call['function'] ) {
				self::assertSame( false, $call['args'][2] );
			}
		}
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

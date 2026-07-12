<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Orchestration\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\FailedRunStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins failed-run retry data, ordering, and bounded retention.
 *
 */
#[CoversClass( FailedRunStore::class )]
#[UsesClass( EngineError::class )]
final class FailedRunStoreTest extends TestCase {

	/**
	 * Loads guarded WordPress option functions before the store is autoloaded.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 2 ) . '/wp-options-stubs.php';
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
	 * Empty storage returns a newest-last list with no entries.
	 *
	 * @return  void
	 */
	public function test_all_returns_an_empty_list_without_failures(): void {
		self::assertSame( array(), ( new FailedRunStore( 'reports' ) )->all() );
	}

	/**
	 * Record and remove preserve the exact manual-retry schema under the literal key.
	 *
	 * @return  void
	 */
	public function test_record_all_and_remove_round_trip_exact_entries(): void {
		$store = new FailedRunStore( 'reports' );

		$store->record(
			'run-a',
			1_700_000_001,
			array( 'site_id' => 7 ),
			3,
			new EngineError( 'Database unavailable.', \RuntimeException::class )
		);
		$store->record(
			'run-b',
			1_700_000_002,
			array( 'site_id' => 8 ),
			1,
			new EngineError( 'Task returned an invalid result.' )
		);

		$expected = array(
			array(
				'run_id'     => 'run-a',
				'failed_at'  => 1_700_000_001,
				'start_args' => array( 'site_id' => 7 ),
				'attempts'   => 3,
				'error'      => array(
					'class'   => \RuntimeException::class,
					'message' => 'Database unavailable.',
				),
			),
			array(
				'run_id'     => 'run-b',
				'failed_at'  => 1_700_000_002,
				'start_args' => array( 'site_id' => 8 ),
				'attempts'   => 1,
				'error'      => array(
					'class'   => null,
					'message' => 'Task returned an invalid result.',
				),
			),
		);

		self::assertSame( $expected, $store->all() );
		self::assertSame( $expected, $this->option( 'a8csp_bgte_failed_reports' ) );
		self::assertSame( false, $this->autoload_flag( 'a8csp_bgte_failed_reports' ) );

		$store->remove( 'run-a' );

		self::assertSame( array( $expected[1] ), $store->all() );
		$this->assert_all_option_writes_disable_autoload();
	}

	/**
	 * The twenty-first failure evicts the oldest entry and retains newest-last order.
	 *
	 * @return  void
	 */
	public function test_ring_buffer_evicts_the_oldest_entry_past_twenty(): void {
		$store = new FailedRunStore( 'exports' );

		for ( $index = 0; $index <= 20; ++$index ) {
			$suffix = \str_pad( (string) $index, 2, '0', STR_PAD_LEFT );
			$store->record(
				'run-' . $suffix,
				1_700_000_000 + $index,
				array( 'index' => $index ),
				$index + 1,
				new EngineError( 'Failure ' . $suffix )
			);
		}

		$entries = $store->all();

		self::assertCount( 20, $entries );
		self::assertSame(
			\array_map(
				static fn ( int $index ): string => 'run-' . \str_pad( (string) $index, 2, '0', STR_PAD_LEFT ),
				\range( 1, 20 )
			),
			\array_column( $entries, 'run_id' )
		);
		self::assertSame( 'Failure 01', $entries[0]['error']['message'] );
		self::assertSame( 'Failure 20', $entries[19]['error']['message'] );
	}

	/**
	 * Removing an absent run leaves both the option and write ledger untouched.
	 *
	 * @return  void
	 */
	public function test_remove_of_absent_run_is_a_no_op(): void {
		$store = new FailedRunStore( 'imports' );
		$store->record( 'run-a', 100, array(), 1, new EngineError( 'Failure.' ) );
		$before_option = $this->option( 'a8csp_bgte_failed_imports' );
		$before_calls  = $this->option_calls();

		$store->remove( 'missing' );

		self::assertSame( $before_option, $this->option( 'a8csp_bgte_failed_imports' ) );
		self::assertSame( $before_calls, $this->option_calls() );
	}

	/**
	 * Removing from an oversized persisted buffer applies the write-time cap.
	 *
	 * @return  void
	 */
	public function test_remove_caps_a_preexisting_oversized_buffer(): void {
		$entries = array();
		for ( $index = 0; $index < 22; ++$index ) {
			$suffix    = \str_pad( (string) $index, 2, '0', STR_PAD_LEFT );
			$entries[] = array(
				'run_id'     => 'run-' . $suffix,
				'failed_at'  => 1_700_000_000 + $index,
				'start_args' => array( 'index' => $index ),
				'attempts'   => 1,
				'error'      => array(
					'class'   => null,
					'message' => 'Failure ' . $suffix,
				),
			);
		}
		$GLOBALS['a8csp_bgte_test_options'] = array( 'a8csp_bgte_failed_oversized' => $entries );

		$store = new FailedRunStore( 'oversized' );

		$store->remove( 'run-00' );

		self::assertSame(
			\array_map(
				static fn ( int $index ): string => 'run-' . \str_pad( (string) $index, 2, '0', STR_PAD_LEFT ),
				\range( 2, 21 )
			),
			\array_column( $store->all(), 'run_id' )
		);
		self::assertSame( false, $this->autoload_flag( 'a8csp_bgte_failed_oversized' ) );
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

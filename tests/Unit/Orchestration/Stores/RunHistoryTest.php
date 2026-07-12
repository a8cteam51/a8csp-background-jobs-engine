<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Orchestration\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\RunHistory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins global and per-hash run-history buffers.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RunHistory::class )]
final class RunHistoryTest extends TestCase {

	/**
	 * Loads guarded WordPress option and filter functions before the history is autoloaded.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 2 ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/wp-hook-stubs.php';
	}

	/**
	 * Resets request-local option and filter state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_options']         = array();
		$GLOBALS['a8csp_bgte_test_option_calls']    = array();
		$GLOBALS['a8csp_bgte_test_option_autoload'] = array();
		$GLOBALS['a8csp_bgte_test_filter_values']   = array();
	}

	/**
	 * Empty history readers return newest-last lists with no entries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_readers_return_empty_lists_without_history(): void {
		$history = new RunHistory( 'reports' );

		self::assertSame( array(), $history->get_started() );
		self::assertSame( array(), $history->get_completed() );
		self::assertSame( array(), $history->get_started_for_hash( 'hash-a' ) );
		self::assertSame( array(), $history->get_completed_for_hash( 'hash-a' ) );
	}

	/**
	 * Started and completed writes persist the mirrored by-hash schema under the literal key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_records_started_and_completed_with_the_literal_option_key(): void {
		$history = new RunHistory( 'reports' );

		$history->record_started( 'run-a', 'hash-a' );
		$history->record_completed( 'run-a', 'hash-a' );

		self::assertSame( array( 'run-a' ), $history->get_started() );
		self::assertSame( array( 'run-a' ), $history->get_completed() );
		self::assertSame( array( 'run-a' ), $history->get_started_for_hash( 'hash-a' ) );
		self::assertSame( array( 'run-a' ), $history->get_completed_for_hash( 'hash-a' ) );
		self::assertSame(
			array(
				'started'   => array( 'run-a' ),
				'completed' => array( 'run-a' ),
				'by_hash'   => array(
					'hash-a' => array(
						'started'   => array( 'run-a' ),
						'completed' => array( 'run-a' ),
					),
				),
			),
			$this->option( 'a8csp_bgte_history_reports' )
		);
		self::assertSame( false, $this->autoload_flag( 'a8csp_bgte_history_reports' ) );
	}

	/**
	 * Global and per-hash buffers retain the newest thirty entries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_ring_buffers_evict_the_oldest_entry_past_thirty(): void {
		$history = new RunHistory( 'exports' );

		for ( $index = 0; $index <= 30; ++$index ) {
			$suffix = \str_pad( (string) $index, 2, '0', STR_PAD_LEFT );
			$history->record_started( 'started-' . $suffix, 'hash-a' );
			$history->record_completed( 'completed-' . $suffix, 'hash-a' );
		}

		$expected_started   = \array_map(
			static fn ( int $index ): string => 'started-' . \str_pad( (string) $index, 2, '0', STR_PAD_LEFT ),
			\range( 1, 30 )
		);
		$expected_completed = \array_map(
			static fn ( int $index ): string => 'completed-' . \str_pad( (string) $index, 2, '0', STR_PAD_LEFT ),
			\range( 1, 30 )
		);

		self::assertSame( $expected_started, $history->get_started() );
		self::assertSame( $expected_completed, $history->get_completed() );
		self::assertSame( $expected_started, $history->get_started_for_hash( 'hash-a' ) );
		self::assertSame( $expected_completed, $history->get_completed_for_hash( 'hash-a' ) );

		$this->assert_all_option_writes_disable_autoload();
	}

	/**
	 * A smaller filtered cap truncates existing buffers on the write that observes it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_history_size_filter_is_applied_at_write(): void {
		$history = new RunHistory( 'imports' );

		for ( $index = 0; $index < 3; ++$index ) {
			$history->record_started( 'started-a' . $index, 'hash-a' );
			$history->record_started( 'started-b' . $index, 'hash-b' );
			$history->record_completed( 'completed-a' . $index, 'hash-a' );
			$history->record_completed( 'completed-b' . $index, 'hash-b' );
		}

		$GLOBALS['a8csp_bgte_test_filter_values'] = array( 'a8csp/background_tasks/history_size' => 2 );
		$history->record_started( 'started-a3', 'hash-a' );

		$option = $this->option( 'a8csp_bgte_history_imports' );
		self::assertSame(
			array(
				'started'   => array( 'started-b2', 'started-a3' ),
				'completed' => array( 'completed-a2', 'completed-b2' ),
				// by_hash keys are ordered by recording recency (the LRU eviction order);
				// the final write re-inserted hash-a at the tail.
				'by_hash'   => array(
					'hash-b' => array(
						'started'   => array( 'started-b1', 'started-b2' ),
						'completed' => array( 'completed-b1', 'completed-b2' ),
					),
					'hash-a' => array(
						'started'   => array( 'started-a2', 'started-a3' ),
						'completed' => array( 'completed-a1', 'completed-a2' ),
					),
				),
			),
			$option
		);
		self::assertSame( array( 'started-b2', 'started-a3' ), $history->get_started() );
		self::assertSame( array( 'completed-a2', 'completed-b2' ), $history->get_completed() );
		self::assertSame( array( 'started-a2', 'started-a3' ), $history->get_started_for_hash( 'hash-a' ) );
		self::assertSame( array( 'started-b1', 'started-b2' ), $history->get_started_for_hash( 'hash-b' ) );
		self::assertSame( array( 'completed-a1', 'completed-a2' ), $history->get_completed_for_hash( 'hash-a' ) );
		self::assertSame( array( 'completed-b1', 'completed-b2' ), $history->get_completed_for_hash( 'hash-b' ) );
	}

	/**
	 * Per-hash buffers retain only runs recorded for their own argument identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_by_hash_buffers_are_isolated_and_newest_last(): void {
		$history = new RunHistory( 'isolation' );

		$history->record_started( 'started-a1', 'hash-a' );
		$history->record_started( 'started-b1', 'hash-b' );
		$history->record_started( 'started-a2', 'hash-a' );
		$history->record_completed( 'completed-b1', 'hash-b' );
		$history->record_completed( 'completed-a1', 'hash-a' );
		$history->record_completed( 'completed-b2', 'hash-b' );

		self::assertSame( array( 'started-a1', 'started-b1', 'started-a2' ), $history->get_started() );
		self::assertSame( array( 'started-a1', 'started-a2' ), $history->get_started_for_hash( 'hash-a' ) );
		self::assertSame( array( 'started-b1' ), $history->get_started_for_hash( 'hash-b' ) );
		self::assertSame( array( 'completed-b1', 'completed-a1', 'completed-b2' ), $history->get_completed() );
		self::assertSame( array( 'completed-a1' ), $history->get_completed_for_hash( 'hash-a' ) );
		self::assertSame( array( 'completed-b1', 'completed-b2' ), $history->get_completed_for_hash( 'hash-b' ) );
	}

	/**
	 * Distinct argument identities are LRU-evicted past twenty buckets, and re-recording an
	 * existing identity refreshes its recency.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_hash_buckets_evict_the_least_recently_recorded_identity_past_twenty(): void {
		$history = new RunHistory( 'sync' );

		foreach ( \range( 1, 20 ) as $index ) {
			$history->record_started( "run-{$index}", "hash-{$index}" );
		}

		// Re-record the oldest identity so eviction targets hash-2, not hash-1.
		$history->record_started( 'run-1b', 'hash-1' );
		$history->record_started( 'run-21', 'hash-21' );

		self::assertSame( array(), $history->get_started_for_hash( 'hash-2' ) );
		self::assertSame( array( 'run-1', 'run-1b' ), $history->get_started_for_hash( 'hash-1' ) );
		self::assertSame( array( 'run-21' ), $history->get_started_for_hash( 'hash-21' ) );
		self::assertSame( array( 'run-3' ), $history->get_started_for_hash( 'hash-3' ) );
	}

	/**
	 * Returns one stored option value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{function: string, args: list<mixed>}>
	 */
	private function option_calls(): array {
		/** @var list<array{function: string, args: list<mixed>}> $calls */
		$calls = $GLOBALS['a8csp_bgte_test_option_calls'];

		return $calls;
	}
}

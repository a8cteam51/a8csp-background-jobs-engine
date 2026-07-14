<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/** Detects whether history decoding constructs a serialized class. */
final class RunHistoryWakeupProbe {
	public static bool $woke = false;

	/** Records an unsafe object construction during unserialization. */
	public function __wakeup(): void {
		self::$woke = true;
	}
}

/**
 * Pins global and per-hash run-history buffers.
 *
 */
#[CoversClass( RunHistory::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
final class RunHistoryTest extends TestCase {
	private OptionRows $rows;
	private WpdbLockSpy $wpdb;

	/**
	 * Loads guarded WordPress option and filter functions before the history is autoloaded.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 3 ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__, 3 ) . '/wp-hook-stubs.php';
		require_once \dirname( __DIR__, 3 ) . '/wp-lock-stubs.php';
	}

	/**
	 * Resets request-local option and filter state.
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
		$GLOBALS['a8csp_bgte_test_blog_id']         = 1;
		$GLOBALS['a8csp_bgte_test_cache']           = array();
		$GLOBALS['a8csp_bgte_test_cache_calls']     = array();
		$this->wpdb                                 = new WpdbLockSpy();
		$this->rows                                 = new OptionRows( $this->wpdb );
	}

	/**
	 * Started and terminal writes persist the mirrored by-hash schema under the literal key.
	 *
	 * @return  void
	 */
	public function test_records_started_and_terminal_with_the_literal_option_key(): void {
		$history = new RunHistory( 'reports' );

		$history->record_started( 'run-a', 'hash-a' );
		$history->record_terminal( 'run-a', 'hash-a', RunStatus::Completed );

		self::assertSame(
			array(
				'started'   => array( 'run-a' ),
				'completed' => array(
					array(
						'run_id' => 'run-a',
						'status' => 'completed',
					),
				),
				'by_hash'   => array(
					'hash-a' => array(
						'started'   => array( 'run-a' ),
						'completed' => array(
							array(
								'run_id' => 'run-a',
								'status' => 'completed',
							),
						),
					),
				),
			),
			$this->option( 'a8csp_bgte_history_reports' )
		);
		self::assertSame( false, $this->autoload_flag( 'a8csp_bgte_history_reports' ) );
	}

	/**
	 * Repeated lifecycle writes do not duplicate run IDs in mirrored buffers.
	 *
	 * @return  void
	 */
	public function test_repeated_writes_are_idempotent_in_global_and_per_hash_buffers(): void {
		$history = new RunHistory( 'reports' );

		$history->record_started( 'run-a', 'hash-a' );
		$history->record_started( 'run-a', 'hash-a' );
		$history->record_terminal( 'run-a', 'hash-a', RunStatus::Completed );
		$history->record_terminal( 'run-a', 'hash-a', RunStatus::Completed );

		self::assertSame(
			array(
				'started'   => array( 'run-a' ),
				'completed' => array(
					array(
						'run_id' => 'run-a',
						'status' => 'completed',
					),
				),
				'by_hash'   => array(
					'hash-a' => array(
						'started'   => array( 'run-a' ),
						'completed' => array(
							array(
								'run_id' => 'run-a',
								'status' => 'completed',
							),
						),
					),
				),
			),
			$this->option( 'a8csp_bgte_history_reports' )
		);
	}

	/**
	 * Global and per-hash buffers retain the newest thirty entries.
	 *
	 * @return  void
	 */
	public function test_ring_buffers_evict_the_oldest_entry_past_thirty(): void {
		$history = new RunHistory( 'exports' );

		for ( $index = 0; $index <= 30; ++$index ) {
			$suffix = \str_pad( (string) $index, 2, '0', STR_PAD_LEFT );
			$history->record_started( 'started-' . $suffix, 'hash-a' );
			$history->record_terminal( 'completed-' . $suffix, 'hash-a', RunStatus::Completed );
		}

		$expected_started   = \array_map(
			static fn ( int $index ): string => 'started-' . \str_pad( (string) $index, 2, '0', STR_PAD_LEFT ),
			\range( 1, 30 )
		);
		$expected_completed = \array_map(
			static fn ( int $index ): array => array(
				'run_id' => 'completed-' . \str_pad( (string) $index, 2, '0', STR_PAD_LEFT ),
				'status' => 'completed',
			),
			\range( 1, 30 )
		);

		self::assertSame(
			array(
				'started'   => $expected_started,
				'completed' => $expected_completed,
				'by_hash'   => array(
					'hash-a' => array(
						'started'   => $expected_started,
						'completed' => $expected_completed,
					),
				),
			),
			$this->option( 'a8csp_bgte_history_exports' )
		);

		$this->assert_all_option_writes_disable_autoload();
	}

	/**
	 * A smaller filtered cap truncates existing buffers on the write that observes it.
	 *
	 * @return  void
	 */
	public function test_history_size_filter_is_applied_at_write(): void {
		$history = new RunHistory( 'imports' );

		for ( $index = 0; $index < 3; ++$index ) {
			$history->record_started( 'started-a' . $index, 'hash-a' );
			$history->record_started( 'started-b' . $index, 'hash-b' );
			$history->record_terminal( 'completed-a' . $index, 'hash-a', RunStatus::Completed );
			$history->record_terminal( 'completed-b' . $index, 'hash-b', RunStatus::Completed );
		}

		$GLOBALS['a8csp_bgte_test_filter_values'] = array( 'a8csp_background_tasks/history_size' => 2 );
		$history->record_started( 'started-a3', 'hash-a' );

		$option = $this->option( 'a8csp_bgte_history_imports' );
		self::assertSame(
			array(
				'started'   => array( 'started-b2', 'started-a3' ),
				'completed' => array(
					array(
						'run_id' => 'completed-a2',
						'status' => 'completed',
					),
					array(
						'run_id' => 'completed-b2',
						'status' => 'completed',
					),
				),
				// by_hash keys are ordered by recording recency (the LRU eviction order);
				// the final write re-inserted hash-a at the tail.
				'by_hash'   => array(
					'hash-b' => array(
						'started'   => array( 'started-b1', 'started-b2' ),
						'completed' => array(
							array(
								'run_id' => 'completed-b1',
								'status' => 'completed',
							),
							array(
								'run_id' => 'completed-b2',
								'status' => 'completed',
							),
						),
					),
					'hash-a' => array(
						'started'   => array( 'started-a2', 'started-a3' ),
						'completed' => array(
							array(
								'run_id' => 'completed-a1',
								'status' => 'completed',
							),
							array(
								'run_id' => 'completed-a2',
								'status' => 'completed',
							),
						),
					),
				),
			),
			$option
		);
	}

	/**
	 * Per-hash buffers retain only runs recorded for their own argument identity.
	 *
	 * @return  void
	 */
	public function test_by_hash_buffers_are_isolated_and_newest_last(): void {
		$history = new RunHistory( 'isolation' );

		$history->record_started( 'started-a1', 'hash-a' );
		$history->record_started( 'started-b1', 'hash-b' );
		$history->record_started( 'started-a2', 'hash-a' );
		$history->record_terminal( 'completed-b1', 'hash-b', RunStatus::Failed );
		$history->record_terminal( 'completed-a1', 'hash-a', RunStatus::Superseded );
		$history->record_terminal( 'completed-b2', 'hash-b', RunStatus::Cancelled );

		self::assertSame(
			array(
				'started'   => array( 'started-a1', 'started-b1', 'started-a2' ),
				'completed' => array(
					array(
						'run_id' => 'completed-b1',
						'status' => 'failed',
					),
					array(
						'run_id' => 'completed-a1',
						'status' => 'superseded',
					),
					array(
						'run_id' => 'completed-b2',
						'status' => 'cancelled',
					),
				),
				'by_hash'   => array(
					'hash-a' => array(
						'started'   => array( 'started-a1', 'started-a2' ),
						'completed' => array(
							array(
								'run_id' => 'completed-a1',
								'status' => 'superseded',
							),
						),
					),
					'hash-b' => array(
						'started'   => array( 'started-b1' ),
						'completed' => array(
							array(
								'run_id' => 'completed-b1',
								'status' => 'failed',
							),
							array(
								'run_id' => 'completed-b2',
								'status' => 'cancelled',
							),
						),
					),
				),
			),
			$this->option( 'a8csp_bgte_history_isolation' )
		);
	}

	/**
	 * Every terminal status persists its backed value in both terminal buffers.
	 *
	 * @param   string $status Terminal status under test.
	 *
	 * @return  void
	 */
	#[DataProvider( 'terminal_statuses' )]
	public function test_record_terminal_persists_every_terminal_status( string $status ): void {
		$name    = 'status-' . $status;
		$history = new RunHistory( $name );

		$history->record_terminal( 'run-a', 'hash-a', RunStatus::from( $status ) );

		$entry = array(
			'run_id' => 'run-a',
			'status' => $status,
		);
		self::assertSame(
			array(
				'started'   => array(),
				'completed' => array( $entry ),
				'by_hash'   => array(
					'hash-a' => array(
						'started'   => array(),
						'completed' => array( $entry ),
					),
				),
			),
			$this->option( 'a8csp_bgte_history_' . $name )
		);
	}

	/**
	 * Supplies every terminal status.
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
	 * A running status cannot enter the terminal history.
	 *
	 * @return  void
	 */
	public function test_record_terminal_rejects_a_running_status(): void {
		$history = new RunHistory( 'running' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Run history records only terminal outcomes.' );

		$history->record_terminal( 'run-a', 'hash-a', RunStatus::Running );
	}

	/**
	 * Read-only exposures reuse the validated global decoders and perform no option writes.
	 *
	 * @return  void
	 */
	public function test_read_exposures_skip_malformed_rows_without_writing(): void {
		$GLOBALS['a8csp_bgte_test_options'] = array(
			'a8csp_bgte_history_inspection' => array(
				'started'   => array( 'started-a', 42, 'started-b', false ),
				'completed' => array(
					array(
						'run_id' => 'failed-a',
						'status' => 'failed',
					),
					array(
						'run_id' => 'running-a',
						'status' => 'running',
					),
					array(
						'run_id' => 42,
						'status' => 'completed',
					),
					array( 'run_id' => 'missing-status' ),
				),
				'by_hash'   => array(),
			),
		);

		$history = new RunHistory( 'inspection', $this->rows );

		self::assertSame( array( 'started-a', 'started-b' ), $history->started_entries() );
		self::assertSame(
			array(
				array(
					'run_id' => 'failed-a',
					'status' => 'failed',
				),
			),
			$history->terminal_entries()
		);
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
	}

	/**
	 * Public history inspection reports unavailability when its authoritative row cannot be read.
	 *
	 * @return  void
	 */
	public function test_read_exposures_report_an_authoritative_read_failure(): void {
		$history = new RunHistory( 'inspection', $this->rows );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted started-history read failure';
			}
		);

		self::assertNull( $history->started_entries() );

		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted terminal-history read failure';
			}
		);

		self::assertNull( $history->terminal_entries() );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
	}

	/**
	 * Started-entry inspection decodes the raw row without constructing nested serialized classes.
	 *
	 * @return  void
	 */
	public function test_started_entries_reads_raw_rows_without_constructing_serialized_classes(): void {
		RunHistoryWakeupProbe::$woke = false;
		$raw                         = \maybe_serialize(
			array(
				'started'   => array( 'started-safe', new RunHistoryWakeupProbe() ),
				'completed' => array(),
				'by_hash'   => array(),
			)
		);
		self::assertIsString( $raw );
		$this->wpdb->put( 'a8csp_bgte_history_started-raw', $raw );

		$history = new RunHistory( 'started-raw', $this->rows );

		self::assertSame( array( 'started-safe' ), $history->started_entries() );
		self::assertFalse( RunHistoryWakeupProbe::$woke );
	}

	/**
	 * Terminal-entry inspection decodes the raw row without constructing nested serialized classes.
	 *
	 * @return  void
	 */
	public function test_terminal_entries_reads_raw_rows_without_constructing_serialized_classes(): void {
		RunHistoryWakeupProbe::$woke = false;
		$raw                         = \maybe_serialize(
			array(
				'started'   => array(),
				'completed' => array(
					array(
						'run_id' => 'terminal-safe',
						'status' => 'failed',
					),
					new RunHistoryWakeupProbe(),
				),
				'by_hash'   => array(),
			)
		);
		self::assertIsString( $raw );
		$this->wpdb->put( 'a8csp_bgte_history_terminal-raw', $raw );

		$history = new RunHistory( 'terminal-raw', $this->rows );

		self::assertSame(
			array(
				array(
					'run_id' => 'terminal-safe',
					'status' => 'failed',
				),
			),
			$history->terminal_entries()
		);
		self::assertFalse( RunHistoryWakeupProbe::$woke );
	}

	/**
	 * Persisted terminal rows accept only complete terminal shapes and terminal backed values.
	 *
	 * @return  void
	 */
	public function test_malformed_and_non_terminal_persisted_rows_are_skipped(): void {
		$terminal_entries = array(
			array(
				'run_id' => 'completed-run',
				'status' => 'completed',
			),
			array(
				'run_id' => 'failed-run',
				'status' => 'failed',
			),
			array(
				'run_id' => 'cancelled-run',
				'status' => 'cancelled',
			),
			array(
				'run_id' => 'superseded-run',
				'status' => 'superseded',
			),
		);

		$persisted_entries = array(
			...$terminal_entries,
			array(
				'run_id' => 'running-run',
				'status' => 'running',
			),
			array(
				'run_id' => 'foreign-run',
				'status' => 'foreign',
			),
			array( 'run_id' => 'missing-status' ),
			array(
				'run_id' => 42,
				'status' => 'completed',
			),
			'legacy-run',
		);

		$GLOBALS['a8csp_bgte_test_options'] = array(
			'a8csp_bgte_history_decode' => array(
				'started'   => array( 'existing-run', 42 ),
				'completed' => $persisted_entries,
				'by_hash'   => array(
					'hash-a' => array(
						'started'   => array( 'existing-run', false ),
						'completed' => $persisted_entries,
					),
					'broken' => 'not-a-buffer',
				),
			),
		);

		$history = new RunHistory( 'decode' );

		$history->record_started( 'new-run', 'hash-a' );

		self::assertSame(
			array(
				'started'   => array( 'existing-run', 'new-run' ),
				'completed' => $terminal_entries,
				'by_hash'   => array(
					'hash-a' => array(
						'started'   => array( 'existing-run', 'new-run' ),
						'completed' => $terminal_entries,
					),
				),
			),
			$this->option( 'a8csp_bgte_history_decode' )
		);
	}

	/**
	 * Distinct argument identities are LRU-evicted past twenty buckets, and re-recording an
	 * existing identity refreshes its recency.
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

		$expected_by_hash = array();
		foreach ( \range( 3, 20 ) as $index ) {
			$expected_by_hash[ "hash-{$index}" ] = array(
				'started'   => array( "run-{$index}" ),
				'completed' => array(),
			);
		}
		$expected_by_hash['hash-1']  = array(
			'started'   => array( 'run-1', 'run-1b' ),
			'completed' => array(),
		);
		$expected_by_hash['hash-21'] = array(
			'started'   => array( 'run-21' ),
			'completed' => array(),
		);

		self::assertSame(
			array(
				'started'   => array(
					...\array_map(
						static fn ( int $index ): string => "run-{$index}",
						\range( 1, 20 )
					),
					'run-1b',
					'run-21',
				),
				'completed' => array(),
				'by_hash'   => $expected_by_hash,
			),
			$this->option( 'a8csp_bgte_history_sync' )
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

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\RunStatus;
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
	private const OWNER = 'runs-tests';

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
	 * Started and terminal writes persist the mirrored by-hash schema under the literal key.
	 *
	 * @return  void
	 */
	public function test_records_started_and_terminal_with_the_literal_option_key(): void {
		$history = new RunHistory( self::identity( 'reports' ), $this->rows );

		self::assertTrue( $history->record_started( 'run-a', 'hash-a' ) );
		self::assertTrue( $history->record_terminal( 'run-a', 'hash-a', RunStatus::Completed ) );

		self::assertSame(
			array(
				'started'  => array( 'run-a' ),
				'terminal' => array(
					array(
						'run_id' => 'run-a',
						'status' => 'completed',
					),
				),
				'by_hash'  => array(
					'hash-a' => array(
						'started'  => array( 'run-a' ),
						'terminal' => array(
							array(
								'run_id' => 'run-a',
								'status' => 'completed',
							),
						),
					),
				),
			),
			$this->option( 'a8csp_bgte_history_runs-tests:reports' )
		);
		self::assertSame( 'off', $this->autoload_flag( 'a8csp_bgte_history_runs-tests:reports' ) );
	}

	/**
	 * Repeated lifecycle writes do not duplicate run IDs in mirrored buffers.
	 *
	 * @return  void
	 */
	public function test_repeated_writes_are_idempotent_in_global_and_per_hash_buffers(): void {
		$history = new RunHistory( self::identity( 'reports' ), $this->rows );

		self::assertTrue( $history->record_started( 'run-a', 'hash-a' ) );
		self::assertTrue( $history->record_started( 'run-a', 'hash-a' ) );
		self::assertTrue( $history->record_terminal( 'run-a', 'hash-a', RunStatus::Completed ) );
		self::assertTrue( $history->record_terminal( 'run-a', 'hash-a', RunStatus::Completed ) );

		self::assertSame(
			array(
				'started'  => array( 'run-a' ),
				'terminal' => array(
					array(
						'run_id' => 'run-a',
						'status' => 'completed',
					),
				),
				'by_hash'  => array(
					'hash-a' => array(
						'started'  => array( 'run-a' ),
						'terminal' => array(
							array(
								'run_id' => 'run-a',
								'status' => 'completed',
							),
						),
					),
				),
			),
			$this->option( 'a8csp_bgte_history_runs-tests:reports' )
		);
	}

	/**
	 * Interleaved started writes retain both winners while capping the exact persisted buffers.
	 *
	 * @return  void
	 */
	public function test_interleaved_started_writes_preserve_both_appends_and_the_history_cap(): void {
		$key              = 'a8csp_bgte_history_runs-tests:interleaved';
		$started          = \array_map(
			static fn ( int $index ): string => 'run-' . \str_pad( (string) $index, 2, '0', STR_PAD_LEFT ),
			\range( 1, 30 )
		);
		$stored           = array(
			'started'  => $started,
			'terminal' => array(),
			'by_hash'  => array(
				'hash-a' => array(
					'started'  => $started,
					'terminal' => array(),
				),
			),
		);
		$stored_raw       = \maybe_serialize( $stored );
		$rival_history    = new RunHistory( self::identity( 'interleaved' ), $this->rows );
		$rival_recorded   = null;
		$expected_started = array(
			...\array_slice( $started, 2 ),
			'run-rival',
			'run-outer',
		);
		$expected         = array(
			'started'  => $expected_started,
			'terminal' => array(),
			'by_hash'  => array(
				'hash-a' => array(
					'started'  => $expected_started,
					'terminal' => array(),
				),
			),
		);
		$expected_raw     = \maybe_serialize( $expected );
		self::assertIsString( $stored_raw );
		self::assertIsString( $expected_raw );
		$this->wpdb->put( $key, $stored_raw );
		$this->wpdb->before_next(
			'update',
			static function () use ( $rival_history, &$rival_recorded ): void {
				$rival_recorded = $rival_history->record_started( 'run-rival', 'hash-a' );
			}
		);

		$recorded = ( new RunHistory( self::identity( 'interleaved' ), $this->rows ) )->record_started( 'run-outer', 'hash-a' );

		self::assertTrue( $rival_recorded );
		self::assertTrue( $recorded );
		self::assertSame( $expected_raw, $this->wpdb->rows[ $key ] ?? null );
		$decoded = RawOptionDecoder::decode( $this->wpdb->rows[ $key ] );
		self::assertIsArray( $decoded );
		$started = $decoded['started'] ?? null;
		self::assertIsArray( $started );
		self::assertCount( 30, $started );
		$by_hash = $decoded['by_hash'] ?? null;
		self::assertIsArray( $by_hash );
		$hash_history = $by_hash['hash-a'] ?? null;
		self::assertIsArray( $hash_history );
		$hash_started = $hash_history['started'] ?? null;
		self::assertIsArray( $hash_started );
		self::assertCount( 30, $hash_started );
	}

	/**
	 * A lost exact update retries from fresh bytes and retains the rival append.
	 *
	 * @return  void
	 */
	public function test_started_write_retries_a_lost_cas_and_preserves_the_rival_write(): void {
		$key          = 'a8csp_bgte_history_runs-tests:lost-cas';
		$stored       = array(
			'started'  => array( 'run-existing' ),
			'terminal' => array(),
			'by_hash'  => array(
				'hash-a' => array(
					'started'  => array( 'run-existing' ),
					'terminal' => array(),
				),
			),
		);
		$rival        = array(
			'started'  => array( 'run-existing', 'run-rival' ),
			'terminal' => array(),
			'by_hash'  => array(
				'hash-a'     => array(
					'started'  => array( 'run-existing' ),
					'terminal' => array(),
				),
				'hash-rival' => array(
					'started'  => array( 'run-rival' ),
					'terminal' => array(),
				),
			),
		);
		$expected     = array(
			'started'  => array( 'run-existing', 'run-rival', 'run-outer' ),
			'terminal' => array(),
			'by_hash'  => array(
				'hash-rival' => array(
					'started'  => array( 'run-rival' ),
					'terminal' => array(),
				),
				'hash-a'     => array(
					'started'  => array( 'run-existing', 'run-outer' ),
					'terminal' => array(),
				),
			),
		);
		$stored_raw   = \maybe_serialize( $stored );
		$rival_raw    = \maybe_serialize( $rival );
		$expected_raw = \maybe_serialize( $expected );
		self::assertIsString( $stored_raw );
		self::assertIsString( $rival_raw );
		self::assertIsString( $expected_raw );
		$this->wpdb->put( $key, $stored_raw );
		$this->wpdb->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $key, $rival_raw ): void {
				$wpdb->put( $key, $rival_raw );
			}
		);

		$recorded = ( new RunHistory( self::identity( 'lost-cas' ), $this->rows ) )->record_started( 'run-outer', 'hash-a' );

		self::assertTrue( $recorded );
		self::assertSame( $expected_raw, $this->wpdb->rows[ $key ] ?? null );
		self::assertCount( 2, $this->queries_starting_with( 'UPDATE ' ) );
	}

	/**
	 * A failed authoritative read aborts without attempting any option-row write.
	 *
	 * @return  void
	 */
	public function test_started_write_returns_false_without_writing_after_an_authoritative_read_failure(): void {
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted run-history read failure';
			}
		);

		$recorded = ( new RunHistory( self::identity( 'read-failure' ), $this->rows ) )->record_started( 'run-a', 'hash-a' );

		self::assertFalse( $recorded );
		self::assertSame( array(), $this->write_queries() );
	}

	/**
	 * A failed exact update whose raw precondition remains current is a persistence failure.
	 *
	 * @return  void
	 */
	public function test_started_write_returns_false_when_a_failed_update_leaves_the_raw_row_unchanged(): void {
		$key        = 'a8csp_bgte_history_runs-tests:update-failure';
		$stored     = array(
			'started'  => array( 'run-existing' ),
			'terminal' => array(),
			'by_hash'  => array(
				'hash-a' => array(
					'started'  => array( 'run-existing' ),
					'terminal' => array(),
				),
			),
		);
		$stored_raw = \maybe_serialize( $stored );
		self::assertIsString( $stored_raw );
		$this->wpdb->put( $key, $stored_raw );
		$this->wpdb->script_result( 'update', false );

		$recorded = ( new RunHistory( self::identity( 'update-failure' ), $this->rows ) )->record_started( 'run-new', 'hash-a' );

		self::assertFalse( $recorded );
		self::assertSame( $stored_raw, $this->wpdb->rows[ $key ] ?? null );
		self::assertCount( 1, $this->queries_starting_with( 'UPDATE ' ) );
	}

	/**
	 * A duplicate started identifier is confirmed without rewriting the exact row.
	 *
	 * @return  void
	 */
	public function test_duplicate_started_identifier_returns_true_without_writing(): void {
		$key        = 'a8csp_bgte_history_runs-tests:duplicate';
		$stored     = array(
			'started'  => array( 'run-a' ),
			'terminal' => array(),
			'by_hash'  => array(
				'hash-a' => array(
					'started'  => array( 'run-a' ),
					'terminal' => array(),
				),
			),
		);
		$stored_raw = \maybe_serialize( $stored );
		self::assertIsString( $stored_raw );
		$this->wpdb->put( $key, $stored_raw );

		$recorded = ( new RunHistory( self::identity( 'duplicate' ), $this->rows ) )->record_started( 'run-a', 'hash-a' );

		self::assertTrue( $recorded );
		self::assertSame( $stored_raw, $this->wpdb->rows[ $key ] ?? null );
		self::assertSame( array(), $this->write_queries() );
	}

	/**
	 * Global and per-hash buffers retain the newest thirty entries.
	 *
	 * @return  void
	 */
	public function test_ring_buffers_evict_the_oldest_entry_past_thirty(): void {
		$history = new RunHistory( self::identity( 'exports' ), $this->rows );

		for ( $index = 0; $index <= 30; ++$index ) {
			$suffix = \str_pad( (string) $index, 2, '0', STR_PAD_LEFT );
			self::assertTrue( $history->record_started( 'started-' . $suffix, 'hash-a' ) );
			self::assertTrue( $history->record_terminal( 'completed-' . $suffix, 'hash-a', RunStatus::Completed ) );
		}

		$expected_started  = \array_map(
			static fn ( int $index ): string => 'started-' . \str_pad( (string) $index, 2, '0', STR_PAD_LEFT ),
			\range( 1, 30 )
		);
		$expected_terminal = \array_map(
			static fn ( int $index ): array => array(
				'run_id' => 'completed-' . \str_pad( (string) $index, 2, '0', STR_PAD_LEFT ),
				'status' => 'completed',
			),
			\range( 1, 30 )
		);

		self::assertSame(
			array(
				'started'  => $expected_started,
				'terminal' => $expected_terminal,
				'by_hash'  => array(
					'hash-a' => array(
						'started'  => $expected_started,
						'terminal' => $expected_terminal,
					),
				),
			),
			$this->option( 'a8csp_bgte_history_runs-tests:exports' )
		);

		$this->assert_all_option_writes_disable_autoload();
	}

	/**
	 * A smaller filtered cap truncates existing buffers on the write that observes it.
	 *
	 * @return  void
	 */
	public function test_history_size_filter_is_applied_at_write(): void {
		$history = new RunHistory( self::identity( 'imports' ), $this->rows );

		for ( $index = 0; $index < 3; ++$index ) {
			self::assertTrue( $history->record_started( 'started-a' . $index, 'hash-a' ) );
			self::assertTrue( $history->record_started( 'started-b' . $index, 'hash-b' ) );
			self::assertTrue( $history->record_terminal( 'completed-a' . $index, 'hash-a', RunStatus::Completed ) );
			self::assertTrue( $history->record_terminal( 'completed-b' . $index, 'hash-b', RunStatus::Completed ) );
		}

		$GLOBALS['a8csp_bgte_test_filter_values'] = array( 'a8csp_background_tasks/history_size' => 2 );
		self::assertTrue( $history->record_started( 'started-a3', 'hash-a' ) );

		$option = $this->option( 'a8csp_bgte_history_runs-tests:imports' );
		self::assertSame(
			array(
				'started'  => array( 'started-b2', 'started-a3' ),
				'terminal' => array(
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
				'by_hash'  => array(
					'hash-b' => array(
						'started'  => array( 'started-b1', 'started-b2' ),
						'terminal' => array(
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
						'started'  => array( 'started-a2', 'started-a3' ),
						'terminal' => array(
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
		$history = new RunHistory( self::identity( 'isolation' ), $this->rows );

		self::assertTrue( $history->record_started( 'started-a1', 'hash-a' ) );
		self::assertTrue( $history->record_started( 'started-b1', 'hash-b' ) );
		self::assertTrue( $history->record_started( 'started-a2', 'hash-a' ) );
		self::assertTrue( $history->record_terminal( 'completed-b1', 'hash-b', RunStatus::Failed ) );
		self::assertTrue( $history->record_terminal( 'completed-a1', 'hash-a', RunStatus::Superseded ) );
		self::assertTrue( $history->record_terminal( 'completed-b2', 'hash-b', RunStatus::Cancelled ) );

		self::assertSame(
			array(
				'started'  => array( 'started-a1', 'started-b1', 'started-a2' ),
				'terminal' => array(
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
				'by_hash'  => array(
					'hash-a' => array(
						'started'  => array( 'started-a1', 'started-a2' ),
						'terminal' => array(
							array(
								'run_id' => 'completed-a1',
								'status' => 'superseded',
							),
						),
					),
					'hash-b' => array(
						'started'  => array( 'started-b1' ),
						'terminal' => array(
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
			$this->option( 'a8csp_bgte_history_runs-tests:isolation' )
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
		$name    = self::identity( 'status-' . $status );
		$history = new RunHistory( $name, $this->rows );

		self::assertTrue( $history->record_terminal( 'run-a', 'hash-a', RunStatus::from( $status ) ) );

		$entry = array(
			'run_id' => 'run-a',
			'status' => $status,
		);
		self::assertSame(
			array(
				'started'  => array(),
				'terminal' => array( $entry ),
				'by_hash'  => array(
					'hash-a' => array(
						'started'  => array(),
						'terminal' => array( $entry ),
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
		$history = new RunHistory( self::identity( 'running' ), $this->rows );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Run history records only terminal outcomes.' );

		(void) $history->record_terminal( 'run-a', 'hash-a', RunStatus::Running );
	}

	/**
	 * Read-only exposures reuse the validated global decoders and perform no option writes.
	 *
	 * @return  void
	 */
	public function test_read_exposures_skip_malformed_rows_without_writing(): void {
		$this->put_option(
			'a8csp_bgte_history_runs-tests:inspection',
			array(
				'started'  => array( 'started-a', 42, 'started-b', false ),
				'terminal' => array(
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
				'by_hash'  => array(),
			)
		);

		$history = new RunHistory( self::identity( 'inspection' ), $this->rows );

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
		$history = new RunHistory( self::identity( 'inspection' ), $this->rows );
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
				'started'  => array( 'started-safe', new RunHistoryWakeupProbe() ),
				'terminal' => array(),
				'by_hash'  => array(),
			)
		);
		self::assertIsString( $raw );
		$this->wpdb->put( 'a8csp_bgte_history_runs-tests:started-raw', $raw );

		$history = new RunHistory( self::identity( 'started-raw' ), $this->rows );

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
				'started'  => array(),
				'terminal' => array(
					array(
						'run_id' => 'terminal-safe',
						'status' => 'failed',
					),
					new RunHistoryWakeupProbe(),
				),
				'by_hash'  => array(),
			)
		);
		self::assertIsString( $raw );
		$this->wpdb->put( 'a8csp_bgte_history_runs-tests:terminal-raw', $raw );

		$history = new RunHistory( self::identity( 'terminal-raw' ), $this->rows );

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

		$this->put_option(
			'a8csp_bgte_history_runs-tests:decode',
			array(
				'started'  => array( 'existing-run', 42 ),
				'terminal' => $persisted_entries,
				'by_hash'  => array(
					'hash-a' => array(
						'started'  => array( 'existing-run', false ),
						'terminal' => $persisted_entries,
					),
					'broken' => 'not-a-buffer',
				),
			)
		);

		$history = new RunHistory( self::identity( 'decode' ), $this->rows );

		self::assertTrue( $history->record_started( 'new-run', 'hash-a' ) );

		self::assertSame(
			array(
				'started'  => array( 'existing-run', 'new-run' ),
				'terminal' => $terminal_entries,
				'by_hash'  => array(
					'hash-a' => array(
						'started'  => array( 'existing-run', 'new-run' ),
						'terminal' => $terminal_entries,
					),
				),
			),
			$this->option( 'a8csp_bgte_history_runs-tests:decode' )
		);
	}

	/**
	 * Distinct argument identities are LRU-evicted past twenty buckets, and re-recording an
	 * existing identity refreshes its recency.
	 *
	 * @return  void
	 */
	public function test_hash_buckets_evict_the_least_recently_recorded_identity_past_twenty(): void {
		$history = new RunHistory( self::identity( 'sync' ), $this->rows );

		foreach ( \range( 1, 20 ) as $index ) {
			self::assertTrue( $history->record_started( "run-{$index}", "hash-{$index}" ) );
		}

		// Re-record the oldest identity so eviction targets hash-2, not hash-1.
		self::assertTrue( $history->record_started( 'run-1b', 'hash-1' ) );
		self::assertTrue( $history->record_started( 'run-21', 'hash-21' ) );

		$expected_by_hash = array();
		foreach ( \range( 3, 20 ) as $index ) {
			$expected_by_hash[ "hash-{$index}" ] = array(
				'started'  => array( "run-{$index}" ),
				'terminal' => array(),
			);
		}
		$expected_by_hash['hash-1']  = array(
			'started'  => array( 'run-1', 'run-1b' ),
			'terminal' => array(),
		);
		$expected_by_hash['hash-21'] = array(
			'started'  => array( 'run-21' ),
			'terminal' => array(),
		);

		self::assertSame(
			array(
				'started'  => array(
					...\array_map(
						static fn ( int $index ): string => "run-{$index}",
						\range( 1, 20 )
					),
					'run-1b',
					'run-21',
				),
				'terminal' => array(),
				'by_hash'  => $expected_by_hash,
			),
			$this->option( 'a8csp_bgte_history_runs-tests:sync' )
		);
	}

	/**
	 * Seeds one authoritative raw option row with autoload disabled.
	 *
	 * @param   string $option_name Option name.
	 * @param   mixed  $value       Decoded option value.
	 *
	 * @return  void
	 */
	private function put_option( string $option_name, mixed $value ): void {
		$raw = \maybe_serialize( $value );
		self::assertIsString( $raw );

		$this->wpdb->put( $option_name, $raw, 'off' );
	}

	/**
	 * Returns one decoded authoritative raw option value.
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
	 * Returns the recorded autoload flag for one option.
	 *
	 * @param   string $option_name Option name.
	 *
	 * @return  string|null
	 */
	private function autoload_flag( string $option_name ): ?string {
		return $this->wpdb->autoload[ $option_name ] ?? null;
	}

	/**
	 * Asserts that every option write explicitly disables autoload.
	 *
	 * @return  void
	 */
	private function assert_all_option_writes_disable_autoload(): void {
		self::assertNotSame( array(), $this->wpdb->autoload );

		foreach ( $this->wpdb->autoload as $autoload ) {
			self::assertSame( 'off', $autoload );
		}
	}

	/**
	 * Returns recorded INSERT, UPDATE, and DELETE statements.
	 *
	 * @return  list<string>
	 */
	private function write_queries(): array {
		return \array_values(
			\array_filter(
				$this->wpdb->recorded_queries,
				static fn ( string $query ): bool => \str_starts_with( $query, 'INSERT ' )
					|| \str_starts_with( $query, 'UPDATE ' )
					|| \str_starts_with( $query, 'DELETE ' )
			)
		);
	}

	/**
	 * Returns recorded statements carrying one literal prefix.
	 *
	 * @param   string $prefix Query prefix.
	 *
	 * @return  list<string>
	 */
	private function queries_starting_with( string $prefix ): array {
		return \array_values(
			\array_filter(
				$this->wpdb->recorded_queries,
				static fn ( string $query ): bool => \str_starts_with( $query, $prefix )
			)
		);
	}
}

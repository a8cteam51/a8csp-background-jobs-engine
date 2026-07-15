<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins failed-run retry data, ordering, and bounded retention.
 *
 */
#[CoversClass( FailedRunStore::class )]
#[UsesClass( EngineError::class )]
#[UsesClass( RunFailure::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
final class FailedRunStoreTest extends TestCase {
	private OptionRows $rows;
	private WpdbLockSpy $wpdb;

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

		require_once \dirname( __DIR__, 3 ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__, 3 ) . '/wp-lock-stubs.php';
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
		$GLOBALS['a8csp_bgte_test_blog_id']         = 1;
		$GLOBALS['a8csp_bgte_test_cache']           = array();
		$GLOBALS['a8csp_bgte_test_cache_calls']     = array();
		$this->wpdb                                 = new WpdbLockSpy();
		$this->rows                                 = new OptionRows( $this->wpdb );
	}

	/**
	 * Empty storage returns a newest-last list with no entries.
	 *
	 * @return  void
	 */
	public function test_all_returns_an_empty_list_without_failures(): void {
		$result = ( new FailedRunStore( 'reports', $this->rows ) )->all();
		if ( $result->is_failure() ) {
			self::fail( $result->error->message );
		}

		self::assertSame( array(), $result->value );
	}

	/** A failed authoritative read is returned as a failed store outcome. */
	public function test_all_returns_an_explicit_failure_when_the_read_fails(): void {
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted failed-run read failure';
			}
		);

		$result = ( new FailedRunStore( 'reports', $this->rows ) )->all();

		self::assertTrue( $result->is_failure() );
		self::assertInstanceOf( EngineError::class, $result->error );
	}

	/**
	 * Raw failed-run reads reject serialized classes without invoking their wakeup hooks.
	 *
	 * @return  void
	 */
	public function test_all_does_not_construct_serialized_classes(): void {
		FailedRunStorePoison::$wakeups = 0;
		$expected                      = array(
			array(
				'run_id'     => 'run-valid',
				'failed_at'  => 1_700_000_001,
				'start_args' => array( 'site_id' => 7 ),
				'attempts'   => 2,
				'error'      => array(
					'class'   => null,
					'message' => 'Expected failure.',
					'stage'   => 'execution',
					'code'    => ApiErrorCode::ExecutionFailed->value,
				),
			),
		);
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The fixture must model hostile raw option bytes.
		$this->wpdb->put( 'a8csp_bgte_failed_poisoned', \serialize( array( $expected[0], new FailedRunStorePoison() ) ) );

		$result = ( new FailedRunStore( 'poisoned', $this->rows ) )->all();
		if ( $result->is_failure() ) {
			self::fail( $result->error->message );
		}

		self::assertSame( $expected, $result->value );
		self::assertSame( 0, FailedRunStorePoison::$wakeups );
	}

	/**
	 * Record and remove preserve the exact manual-retry schema under the literal key.
	 *
	 * @return  void
	 */
	public function test_record_all_and_remove_round_trip_exact_entries(): void {
		$store = new FailedRunStore( 'reports', $this->rows );

		self::assertTrue(
			$store->record(
				'run-a',
				1_700_000_001,
				array( 'site_id' => 7 ),
				3,
				new EngineError( 'Database unavailable.', \RuntimeException::class ),
				self::failure( 'run-a', 3, 'Database unavailable.', 'reports', array( 'page' => 7 ) )
			)
		);
		self::assertTrue(
			$store->record(
				'run-b',
				1_700_000_002,
				array( 'site_id' => 8 ),
				1,
				new EngineError( 'Task returned an invalid result.' ),
				self::failure( 'run-b', 1, 'Task returned an invalid result.', 'reports' )
			)
		);

		$expected = array(
			array(
				'run_id'     => 'run-a',
				'failed_at'  => 1_700_000_001,
				'start_args' => array( 'site_id' => 7 ),
				'attempts'   => 3,
				'error'      => array(
					'class'        => \RuntimeException::class,
					'message'      => 'Database unavailable.',
					'stage'        => 'execution',
					'code'         => ApiErrorCode::ExecutionFailed->value,
					'failed_chunk' => array( 'page' => 7 ),
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
					'stage'   => 'execution',
					'code'    => ApiErrorCode::ExecutionFailed->value,
				),
			),
		);

		$all = $store->all();
		if ( $all->is_failure() ) {
			self::fail( $all->error->message );
		}

		self::assertSame( $expected, $all->value );
		$this->assert_authoritative_row( 'a8csp_bgte_failed_reports', $expected );

		self::assertTrue( $store->remove( 'run-a' ) );

		$remaining = $store->all();
		if ( $remaining->is_failure() ) {
			self::fail( $remaining->error->message );
		}

		self::assertSame( array( $expected[1] ), $remaining->value );
		$this->assert_authoritative_row( 'a8csp_bgte_failed_reports', array( $expected[1] ) );

		self::assertTrue( $store->remove( 'run-b' ) );
		$raw = $this->wpdb->rows['a8csp_bgte_failed_reports'] ?? null;
		self::assertIsString( $raw );
		self::assertSame( self::raw( array() ), $raw );
		$this->assert_authoritative_row( 'a8csp_bgte_failed_reports', array() );
	}

	/** Repeating a failed run keeps the first persisted payload and issues no write. */
	public function test_record_is_first_write_wins_for_an_existing_run_id(): void {
		$key   = 'a8csp_bgte_failed_first-write-wins';
		$store = new FailedRunStore( 'first-write-wins', $this->rows );
		$first = self::entry( 'run-a', 100, array( 'source' => 'first' ), 1, 'First failure.' );

		self::assertTrue(
			$store->record( 'run-a', 100, array( 'source' => 'first' ), 1, new EngineError( 'First failure.' ), self::failure( 'run-a', 1, 'First failure.', 'first-write-wins' ) )
		);
		$first_raw = $this->wpdb->rows[ $key ] ?? null;
		self::assertIsString( $first_raw );
		$this->wpdb->recorded_queries = array();

		$recorded = $store->record(
			'run-a',
			200,
			array( 'source' => 'second' ),
			2,
			new EngineError( 'Second failure.', \RuntimeException::class ),
			self::failure( 'run-a', 2, 'Second failure.', 'first-write-wins' )
		);

		self::assertTrue( $recorded );
		self::assertSame( $first_raw, $this->wpdb->rows[ $key ] ?? null );
		self::assertSame( array( $first ), RawOptionDecoder::decode( $first_raw ) );
		self::assertSame( array(), $this->write_queries() );
	}

	/** A rival insert for the same run ID wins without a duplicate or overwrite. */
	public function test_interleaved_same_run_id_insert_converges_on_the_first_payload(): void {
		$key            = 'a8csp_bgte_failed_same-run-race';
		$store          = new FailedRunStore( 'same-run-race', $this->rows );
		$rival_recorded = null;
		$rival          = self::entry( 'run-a', 100, array( 'source' => 'rival' ), 1, 'Rival failure.' );
		$this->wpdb->before_next(
			'insert',
			static function () use ( $store, &$rival_recorded ): void {
				$rival_recorded = $store->record(
					'run-a',
					100,
					array( 'source' => 'rival' ),
					1,
					new EngineError( 'Rival failure.' ),
					self::failure( 'run-a', 1, 'Rival failure.', 'same-run-race' )
				);
			}
		);

		$recorded = $store->record(
			'run-a',
			200,
			array( 'source' => 'requested' ),
			2,
			new EngineError( 'Requested failure.' ),
			self::failure( 'run-a', 2, 'Requested failure.', 'same-run-race' )
		);

		$raw = $this->wpdb->rows[ $key ] ?? null;
		self::assertTrue( $rival_recorded );
		self::assertTrue( $recorded );
		self::assertIsString( $raw );
		self::assertSame( self::raw( array( $rival ) ), $raw );
		self::assertSame( array( $rival ), RawOptionDecoder::decode( $raw ) );
		self::assertSame( array(), $this->write_queries( 'UPDATE ' ) );
	}

	/**
	 * The twenty-first failure evicts the oldest entry and retains newest-last order.
	 *
	 * @return  void
	 */
	public function test_ring_buffer_evicts_the_oldest_entry_past_twenty(): void {
		$store = new FailedRunStore( 'exports', $this->rows );

		for ( $index = 0; $index <= 20; ++$index ) {
			$suffix = \str_pad( (string) $index, 2, '0', STR_PAD_LEFT );
			self::assertTrue(
				$store->record(
					'run-' . $suffix,
					1_700_000_000 + $index,
					array( 'index' => $index ),
					$index + 1,
					new EngineError( 'Failure ' . $suffix ),
					self::failure( 'run-' . $suffix, $index + 1, 'Failure ' . $suffix, 'exports' )
				)
			);
		}

		$result = $store->all();
		if ( $result->is_failure() ) {
			self::fail( $result->error->message );
		}

		$entries = $result->value;

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
		$this->assert_authoritative_row( 'a8csp_bgte_failed_exports', $entries );
	}

	/** Two interleaved appends preserve both entries and retain only the newest twenty. */
	public function test_interleaved_records_preserve_both_appends_and_enforce_the_entry_limit(): void {
		$entries = array();
		for ( $index = 0; $index < 19; ++$index ) {
			$entries[] = self::entry( 'run-' . \str_pad( (string) $index, 2, '0', STR_PAD_LEFT ), 100 + $index );
		}
		$key = 'a8csp_bgte_failed_interleaved';
		$this->wpdb->put( $key, self::raw( $entries ) );
		$store = new FailedRunStore( 'interleaved', $this->rows );
		$this->wpdb->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $store ): void {
				self::assertTrue( $store->record( 'run-rival', 200, array( 'source' => 'rival' ), 2, new EngineError( 'Rival failure.' ), self::failure( 'run-rival', 2, 'Rival failure.', 'interleaved' ) ) );
			}
		);

		$recorded = $store->record( 'run-requested', 300, array( 'source' => 'requested' ), 3, new EngineError( 'Requested failure.' ), self::failure( 'run-requested', 3, 'Requested failure.', 'interleaved' ) );

		$expected   = $entries;
		$expected[] = self::entry( 'run-rival', 200, array( 'source' => 'rival' ), 2, 'Rival failure.' );
		$expected[] = self::entry( 'run-requested', 300, array( 'source' => 'requested' ), 3, 'Requested failure.' );
		$expected   = \array_slice( $expected, -20 );
		$raw        = $this->wpdb->rows[ $key ] ?? null;
		self::assertTrue( $recorded );
		self::assertIsString( $raw );
		self::assertSame( self::raw( $expected ), $raw );
		self::assertSame( $expected, RawOptionDecoder::decode( $raw ) );
		self::assertCount( 20, $expected );
		self::assertSame( array( 'run-01', 'run-rival', 'run-requested' ), array( $expected[0]['run_id'], $expected[18]['run_id'], $expected[19]['run_id'] ) );
		self::assertSame( 'off', $this->wpdb->autoload[ $key ] ?? null );
		self::assertSame( array(), $this->option_calls() );
	}

	/** A lost exact update retries from fresh bytes without normalizing away its rival. */
	public function test_record_retries_a_lost_exact_update_and_preserves_rival_bytes(): void {
		$key         = 'a8csp_bgte_failed_record-cas-retry';
		$stored      = array( self::entry( 'run-existing', 100 ) );
		$rival_entry = self::entry( 'run-rival', 200, array( 'token' => "rival-\0bytes" ), 2, "Rival \0 failure." );
		$concurrent  = array( $stored[0], $rival_entry );
		$this->wpdb->put( $key, self::raw( $stored ) );
		$this->wpdb->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $concurrent, $key ): void {
				$wpdb->put( $key, self::raw( $concurrent ) );
			}
		);
		$store = new FailedRunStore( 'record-cas-retry', $this->rows );

		$recorded = $store->record( 'run-requested', 300, array(), 3, new EngineError( 'Requested failure.' ), self::failure( 'run-requested', 3, 'Requested failure.', 'record-cas-retry' ) );

		$expected   = $concurrent;
		$expected[] = self::entry( 'run-requested', 300, array(), 3, 'Requested failure.' );
		$raw        = $this->wpdb->rows[ $key ] ?? null;
		self::assertTrue( $recorded );
		self::assertIsString( $raw );
		self::assertSame( self::raw( $expected ), $raw );
		self::assertStringContainsString( self::raw( $rival_entry ), $raw );
		self::assertSame( $expected, RawOptionDecoder::decode( $raw ) );
		self::assertCount( 2, $this->write_queries( 'UPDATE ' ) );
		self::assertSame( 'off', $this->wpdb->autoload[ $key ] ?? null );
		self::assertSame( array(), $this->option_calls() );
	}

	/**
	 * Removing an absent run leaves both the option and write ledger untouched.
	 *
	 * @return  void
	 */
	public function test_remove_of_absent_run_is_a_no_op(): void {
		$key   = 'a8csp_bgte_failed_imports';
		$raw   = self::raw( array( self::entry( 'run-a', 100 ) ) );
		$store = new FailedRunStore( 'imports', $this->rows );
		$this->wpdb->put( $key, $raw );

		$removed = $store->remove( 'missing' );

		self::assertTrue( $removed );
		self::assertSame( $raw, $this->wpdb->rows[ $key ] ?? null );
		self::assertSame( array(), $this->write_queries() );
		self::assertSame( 'off', $this->wpdb->autoload[ $key ] ?? null );
		self::assertSame( array(), $this->option_calls() );
	}

	/** An absent failed-run row already satisfies removal without issuing a write. */
	public function test_remove_of_absent_row_returns_true_without_writing(): void {
		self::assertTrue( ( new FailedRunStore( 'absent', $this->rows ) )->remove( 'run-a' ) );
		self::assertSame( array(), $this->write_queries() );
	}

	/** Recording aborts without replacing retained entries when their authoritative read fails. */
	public function test_record_does_not_write_when_the_store_read_fails(): void {
		$key           = 'a8csp_bgte_failed_imports';
		$persisted_raw = self::raw( array( self::entry( 'run-existing', 100, array(), 1, 'Existing failure.' ) ) );
		$this->wpdb->put( $key, $persisted_raw );
		$read_failures = 0;
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ) use ( &$read_failures ): void {
				++$read_failures;
				$wpdb->last_error = 'scripted record read failure';
			}
		);

		$recorded = ( new FailedRunStore( 'imports', $this->rows ) )->record(
			'run-new',
			200,
			array(),
			1,
			new EngineError( 'New failure.' ),
			self::failure( 'run-new', 1, 'New failure.', 'imports' )
		);

		self::assertFalse( $recorded );
		self::assertSame( 1, $read_failures );
		self::assertSame( $persisted_raw, $this->wpdb->rows[ $key ] ?? null );
		self::assertSame( array(), $this->write_queries() );
		self::assertSame( array(), $this->option_calls() );
	}

	/** A failed exact record update with unchanged selected bytes reports failure. */
	public function test_record_returns_false_when_a_failed_exact_update_leaves_raw_unchanged(): void {
		$key = 'a8csp_bgte_failed_record-write-failure';
		$raw = self::raw( array( self::entry( 'run-existing', 100 ) ) );
		$this->wpdb->put( $key, $raw );
		$this->wpdb->script_result( 'update', false );

		$recorded = ( new FailedRunStore( 'record-write-failure', $this->rows ) )
			->record( 'run-new', 200, array(), 2, new EngineError( 'New failure.' ), self::failure( 'run-new', 2, 'New failure.', 'record-write-failure' ) );

		self::assertFalse( $recorded );
		self::assertSame( $raw, $this->wpdb->rows[ $key ] ?? null );
		$this->assert_failed_update_was_verified();
	}

	/** Removal aborts without replacing retained entries when their authoritative read fails. */
	public function test_remove_does_not_write_when_the_store_read_fails(): void {
		$key           = 'a8csp_bgte_failed_imports';
		$persisted_raw = self::raw( array( self::entry( 'run-existing', 100, array(), 1, 'Existing failure.' ) ) );
		$this->wpdb->put( $key, $persisted_raw );
		$read_failures = 0;
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ) use ( &$read_failures ): void {
				++$read_failures;
				$wpdb->last_error = 'scripted remove read failure';
			}
		);

		$removed = ( new FailedRunStore( 'imports', $this->rows ) )->remove( 'run-existing' );

		self::assertFalse( $removed );
		self::assertSame( 1, $read_failures );
		self::assertSame( $persisted_raw, $this->wpdb->rows[ $key ] ?? null );
		self::assertSame( array(), $this->write_queries() );
		self::assertSame( array(), $this->option_calls() );
	}

	/** A failed exact removal with unchanged selected bytes reports failure. */
	public function test_remove_returns_false_when_a_failed_exact_update_leaves_raw_unchanged(): void {
		$key = 'a8csp_bgte_failed_remove-write-failure';
		$raw = self::raw( array( self::entry( 'run-existing', 100 ) ) );
		$this->wpdb->put( $key, $raw );
		$this->wpdb->script_result( 'update', false );

		$removed = ( new FailedRunStore( 'remove-write-failure', $this->rows ) )->remove( 'run-existing' );

		self::assertFalse( $removed );
		self::assertSame( $raw, $this->wpdb->rows[ $key ] ?? null );
		$this->assert_failed_update_was_verified();
	}

	/**
	 * Purging deletes the complete option and reports the retained-entry count.
	 *
	 * @return  void
	 */
	public function test_purge_deletes_the_store_and_returns_its_entry_count(): void {
		$store = new FailedRunStore( 'reports', $this->rows );
		self::assertTrue( $store->record( 'run-a', 100, array(), 1, new EngineError( 'First failure.' ), self::failure( 'run-a', 1, 'First failure.', 'reports' ) ) );
		self::assertTrue( $store->record( 'run-b', 200, array(), 2, new EngineError( 'Second failure.' ), self::failure( 'run-b', 2, 'Second failure.', 'reports' ) ) );
		$this->assert_authoritative_row(
			'a8csp_bgte_failed_reports',
			array(
				self::entry( 'run-a', 100, array(), 1, 'First failure.' ),
				self::entry( 'run-b', 200, array(), 2, 'Second failure.' ),
			)
		);

		self::assertSame( 2, $store->purge() );
		self::assertArrayNotHasKey( 'a8csp_bgte_failed_reports', $this->wpdb->rows );
		self::assertSame( array(), $this->option_calls() );
		self::assertSame( 0, $store->purge() );
	}

	/**
	 * An authoritative read failure remains distinct from an absent store.
	 *
	 * @return  void
	 */
	public function test_purge_returns_failure_when_the_authoritative_read_fails(): void {
		$store = new FailedRunStore( 'read-failure', $this->rows );
		self::assertTrue( $store->record( 'run-a', 100, array(), 1, new EngineError( 'Failure.' ), self::failure( 'run-a', 1, 'Failure.', 'read-failure' ) ) );
		$raw = $this->wpdb->rows['a8csp_bgte_failed_read-failure'] ?? null;
		self::assertIsString( $raw );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted read failure';
			}
		);

		self::assertNull( $store->purge() );
		self::assertSame( $raw, $this->wpdb->rows['a8csp_bgte_failed_read-failure'] ?? null );
		self::assertSame( array( self::entry( 'run-a', 100 ) ), RawOptionDecoder::decode( $raw ) );
		self::assertSame( 'off', $this->wpdb->autoload['a8csp_bgte_failed_read-failure'] ?? null );
		self::assertSame( array(), $this->option_calls() );
	}

	/**
	 * A failed exact delete leaves the selected store intact and reports failure.
	 *
	 * @return  void
	 */
	public function test_purge_returns_failure_when_the_exact_delete_fails(): void {
		$store = new FailedRunStore( 'delete-failure', $this->rows );
		self::assertTrue( $store->record( 'run-a', 100, array(), 1, new EngineError( 'Failure.' ), self::failure( 'run-a', 1, 'Failure.', 'delete-failure' ) ) );
		$raw = $this->wpdb->rows['a8csp_bgte_failed_delete-failure'] ?? null;
		self::assertIsString( $raw );
		$this->wpdb->before_next(
			'delete',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted delete failure';
			}
		);
		$this->wpdb->script_result( 'delete', false );

		self::assertNull( $store->purge() );
		self::assertSame( $raw, $this->wpdb->rows['a8csp_bgte_failed_delete-failure'] ?? null );
		self::assertSame( array( self::entry( 'run-a', 100 ) ), RawOptionDecoder::decode( $raw ) );
		self::assertSame( 'off', $this->wpdb->autoload['a8csp_bgte_failed_delete-failure'] ?? null );
		self::assertSame( array(), $this->option_calls() );
	}

	/**
	 * A concurrent append loses the first CAS and the retry deletes the newer exact row.
	 *
	 * @return  void
	 */
	public function test_purge_retries_a_cas_loss_and_reports_the_deleted_snapshot_count(): void {
		$store = new FailedRunStore( 'cas-retry', $this->rows );
		self::assertTrue( $store->record( 'run-a', 100, array(), 1, new EngineError( 'First failure.' ), self::failure( 'run-a', 1, 'First failure.', 'cas-retry' ) ) );
		$this->wpdb->before_next(
			'delete',
			static function ( WpdbLockSpy $wpdb ) use ( $store ): void {
				self::assertTrue( $store->record( 'run-b', 200, array(), 2, new EngineError( 'Second failure.' ), self::failure( 'run-b', 2, 'Second failure.', 'cas-retry' ) ) );
			}
		);

		self::assertSame( 2, $store->purge() );
		self::assertArrayNotHasKey( 'a8csp_bgte_failed_cas-retry', $this->wpdb->rows );
		self::assertSame( array(), $this->option_calls() );
	}

	/**
	 * Three consecutive concurrent appends exhaust the bounded exact-delete attempts.
	 *
	 * @return  void
	 */
	public function test_purge_reports_failure_after_three_cas_losses(): void {
		$store = new FailedRunStore( 'cas-exhaustion', $this->rows );
		self::assertTrue( $store->record( 'run-a', 100, array(), 1, new EngineError( 'Failure A.' ), self::failure( 'run-a', 1, 'Failure A.', 'cas-exhaustion' ) ) );

		foreach ( array( 'b', 'c', 'd' ) as $index => $suffix ) {
			$this->wpdb->before_next(
				'delete',
				static function ( WpdbLockSpy $wpdb ) use ( $store, $index, $suffix ): void {
					self::assertTrue(
						$store->record(
							'run-' . $suffix,
							200 + $index,
							array(),
							2 + $index,
							new EngineError( 'Failure ' . \strtoupper( $suffix ) . '.' ),
							self::failure( 'run-' . $suffix, 2 + $index, 'Failure ' . \strtoupper( $suffix ) . '.', 'cas-exhaustion' )
						)
					);
				}
			);
		}

		self::assertNull( $store->purge() );
		$result = $store->all();
		if ( $result->is_failure() ) {
			self::fail( $result->error->message );
		}

		self::assertCount( 4, $result->value );
		$this->assert_authoritative_row( 'a8csp_bgte_failed_cas-exhaustion', $result->value );
	}

	/**
	 * A wholly malformed row has the same zero valid entries as all() and is still deleted.
	 *
	 * @return  void
	 */
	public function test_purge_deletes_malformed_raw_storage_with_a_zero_count(): void {
		$this->wpdb->put( 'a8csp_bgte_failed_malformed', 'not a failed-run list' );

		$store = new FailedRunStore( 'malformed', $this->rows );

		$result = $store->all();
		if ( $result->is_failure() ) {
			self::fail( $result->error->message );
		}

		self::assertSame( array(), $result->value );
		self::assertSame( 0, $store->purge() );
		self::assertArrayNotHasKey( 'a8csp_bgte_failed_malformed', $this->wpdb->rows );
		self::assertSame( array(), $this->option_calls() );
	}

	/**
	 * A serialized object payload contains no valid failed-run entries and is still purged.
	 *
	 * @return  void
	 */
	public function test_purge_counts_a_serialized_object_payload_as_zero_valid_entries(): void {
		$raw = \maybe_serialize( new \stdClass() );
		self::assertIsString( $raw );
		$this->wpdb->put( 'a8csp_bgte_failed_object', $raw );

		$store = new FailedRunStore( 'object', $this->rows );

		self::assertSame( 0, $store->purge() );
		self::assertArrayNotHasKey( 'a8csp_bgte_failed_object', $this->wpdb->rows );
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
					'stage'   => 'execution',
					'code'    => ApiErrorCode::ExecutionFailed->value,
				),
			);
		}
		$key = 'a8csp_bgte_failed_oversized';
		$this->wpdb->put( $key, self::raw( $entries ) );

		$store = new FailedRunStore( 'oversized', $this->rows );

		self::assertTrue( $store->remove( 'run-00' ) );
		$result = $store->all();
		if ( $result->is_failure() ) {
			self::fail( $result->error->message );
		}

		$expected_run_ids =
			\array_map(
				static fn ( int $index ): string => 'run-' . \str_pad( (string) $index, 2, '0', STR_PAD_LEFT ),
				\range( 2, 21 )
			);
		self::assertSame( $expected_run_ids, \array_column( $result->value, 'run_id' ) );
		$this->assert_authoritative_row( $key, $result->value );
	}

	/**
	 * Returns one complete failed-run fixture.
	 *
	 * @param   string                  $run_id     Run identifier.
	 * @param   int                     $failed_at  Failure timestamp.
	 * @param   array<array-key, mixed> $start_args Start arguments.
	 * @param   int                     $attempts   Consumed attempts.
	 * @param   string                  $message    Failure message.
	 *
	 * @return  array{
	 *     run_id: string,
	 *     failed_at: int,
	 *     start_args: array<array-key, mixed>,
	 *     attempts: int,
	 *     error: array{class: null, message: string, stage: string, code: string}
	 * }
	 */
	private static function entry( string $run_id, int $failed_at, array $start_args = array(), int $attempts = 1, string $message = 'Failure.' ): array {
		return array(
			'run_id'     => $run_id,
			'failed_at'  => $failed_at,
			'start_args' => $start_args,
			'attempts'   => $attempts,
			'error'      => array(
				'class'   => null,
				'message' => $message,
				'stage'   => 'execution',
				'code'    => ApiErrorCode::ExecutionFailed->value,
			),
		);
	}

	/**
	 * Returns consumer failure metadata matching one retained entry.
	 *
	 * @param   string                       $run_id       Run identifier.
	 * @param   int                          $attempts     Consumed attempts.
	 * @param   string                       $summary      Failure summary.
	 * @param   string                       $name         Stable work identity.
	 * @param   array<array-key, mixed>|null $failed_chunk Failed batch chunk, or null.
	 *
	 * @return  RunFailure
	 */
	private static function failure( string $run_id, int $attempts, string $summary, string $name, ?array $failed_chunk = null ): RunFailure {
		return new RunFailure(
			name: $name,
			run_id: $run_id,
			attempts: $attempts,
			stage: 'execution',
			code: ApiErrorCode::ExecutionFailed,
			summary: $summary,
			failed_chunk: $failed_chunk,
		);
	}

	/**
	 * Serializes one exact failed-run row fixture.
	 *
	 * @param   array<array-key, mixed> $value Persisted value.
	 *
	 * @return  string
	 */
	private static function raw( array $value ): string {
		$raw = \maybe_serialize( $value );
		self::assertIsString( $raw );

		return $raw;
	}

	/** Asserts that a failed exact update was followed by an unchanged-row verification read. */
	private function assert_failed_update_was_verified(): void {
		self::assertCount( 3, $this->wpdb->recorded_queries );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[0] );
		self::assertStringStartsWith( 'UPDATE ', $this->wpdb->recorded_queries[1] );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[2] );
	}

	/**
	 * Returns authoritative write queries, optionally restricted to one statement prefix.
	 *
	 * @param   string|null $prefix Required query prefix, or null for every write.
	 *
	 * @return  list<string>
	 */
	private function write_queries( ?string $prefix = null ): array {
		return \array_values(
			\array_filter(
				$this->wpdb->recorded_queries,
				static fn ( string $query ): bool => null === $prefix
					? \str_starts_with( $query, 'INSERT ' ) || \str_starts_with( $query, 'UPDATE ' ) || \str_starts_with( $query, 'DELETE ' )
					: \str_starts_with( $query, $prefix )
			)
		);
	}

	/**
	 * Asserts one failed-run row through the authoritative raw-storage seam.
	 *
	 * @param   string                  $key      Option name.
	 * @param   array<array-key, mixed> $expected Expected decoded row.
	 *
	 * @return  void
	 */
	private function assert_authoritative_row( string $key, array $expected ): void {
		$raw = $this->wpdb->rows[ $key ] ?? null;
		self::assertIsString( $raw );
		self::assertSame( $expected, RawOptionDecoder::decode( $raw ) );
		self::assertSame( 'off', $this->wpdb->autoload[ $key ] ?? null );
		self::assertSame( array(), $this->option_calls() );
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

/** Serialized poison probe for hardened failed-run option reads. */
final class FailedRunStorePoison {
	public static int $wakeups = 0;

	/** Records unsafe native object construction. */
	public function __wakeup(): void {
		++self::$wakeups;
	}
}

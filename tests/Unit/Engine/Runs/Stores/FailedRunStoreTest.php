<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
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

		$all = $store->all();
		if ( $all->is_failure() ) {
			self::fail( $all->error->message );
		}

		self::assertSame( $expected, $all->value );
		self::assertSame( $expected, $this->option( 'a8csp_bgte_failed_reports' ) );
		self::assertSame( false, $this->autoload_flag( 'a8csp_bgte_failed_reports' ) );

		$store->remove( 'run-a' );

		$remaining = $store->all();
		if ( $remaining->is_failure() ) {
			self::fail( $remaining->error->message );
		}

		self::assertSame( array( $expected[1] ), $remaining->value );
		$this->assert_all_option_writes_disable_autoload();
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
			$store->record(
				'run-' . $suffix,
				1_700_000_000 + $index,
				array( 'index' => $index ),
				$index + 1,
				new EngineError( 'Failure ' . $suffix )
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
	}

	/**
	 * Removing an absent run leaves both the option and write ledger untouched.
	 *
	 * @return  void
	 */
	public function test_remove_of_absent_run_is_a_no_op(): void {
		$store = new FailedRunStore( 'imports', $this->rows );
		$store->record( 'run-a', 100, array(), 1, new EngineError( 'Failure.' ) );
		$before_option = $this->option( 'a8csp_bgte_failed_imports' );
		$before_calls  = $this->option_calls();

		$store->remove( 'missing' );

		self::assertSame( $before_option, $this->option( 'a8csp_bgte_failed_imports' ) );
		self::assertSame( $before_calls, $this->option_calls() );
	}

	/** Recording aborts without replacing retained entries when their authoritative read fails. */
	public function test_record_does_not_write_when_the_store_read_fails(): void {
		$persisted                          = array(
			array(
				'run_id'     => 'run-existing',
				'failed_at'  => 100,
				'start_args' => array(),
				'attempts'   => 1,
				'error'      => array(
					'class'   => null,
					'message' => 'Existing failure.',
				),
			),
		);
		$GLOBALS['a8csp_bgte_test_options'] = array( 'a8csp_bgte_failed_imports' => $persisted );
		$read_failures                      = 0;
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ) use ( &$read_failures ): void {
				++$read_failures;
				$wpdb->last_error = 'scripted record read failure';
			}
		);

		( new FailedRunStore( 'imports', $this->rows ) )->record(
			'run-new',
			200,
			array(),
			1,
			new EngineError( 'New failure.' )
		);

		self::assertSame( 1, $read_failures );
		self::assertSame( $persisted, $this->option( 'a8csp_bgte_failed_imports' ) );
		self::assertSame( array(), $this->option_calls() );
	}

	/** Removal aborts without replacing retained entries when their authoritative read fails. */
	public function test_remove_does_not_write_when_the_store_read_fails(): void {
		$persisted                          = array(
			array(
				'run_id'     => 'run-existing',
				'failed_at'  => 100,
				'start_args' => array(),
				'attempts'   => 1,
				'error'      => array(
					'class'   => null,
					'message' => 'Existing failure.',
				),
			),
		);
		$GLOBALS['a8csp_bgte_test_options'] = array( 'a8csp_bgte_failed_imports' => $persisted );
		$read_failures                      = 0;
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ) use ( &$read_failures ): void {
				++$read_failures;
				$wpdb->last_error = 'scripted remove read failure';
			}
		);

		( new FailedRunStore( 'imports', $this->rows ) )->remove( 'run-existing' );

		self::assertSame( 1, $read_failures );
		self::assertSame( $persisted, $this->option( 'a8csp_bgte_failed_imports' ) );
		self::assertSame( array(), $this->option_calls() );
	}

	/**
	 * Purging deletes the complete option and reports the retained-entry count.
	 *
	 * @return  void
	 */
	public function test_purge_deletes_the_store_and_returns_its_entry_count(): void {
		$store = new FailedRunStore( 'reports', $this->rows );
		$store->record( 'run-a', 100, array(), 1, new EngineError( 'First failure.' ) );
		$store->record( 'run-b', 200, array(), 2, new EngineError( 'Second failure.' ) );

		self::assertSame( 2, $store->purge() );
		self::assertNull( $this->option( 'a8csp_bgte_failed_reports' ) );
		self::assertSame( 0, $store->purge() );
	}

	/**
	 * An authoritative read failure remains distinct from an absent store.
	 *
	 * @return  void
	 */
	public function test_purge_returns_failure_when_the_authoritative_read_fails(): void {
		$store = new FailedRunStore( 'read-failure', $this->rows );
		$store->record( 'run-a', 100, array(), 1, new EngineError( 'Failure.' ) );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted read failure';
			}
		);

		self::assertNull( $store->purge() );
		self::assertNotNull( $this->option( 'a8csp_bgte_failed_read-failure' ) );
	}

	/**
	 * A failed exact delete leaves the selected store intact and reports failure.
	 *
	 * @return  void
	 */
	public function test_purge_returns_failure_when_the_exact_delete_fails(): void {
		$store = new FailedRunStore( 'delete-failure', $this->rows );
		$store->record( 'run-a', 100, array(), 1, new EngineError( 'Failure.' ) );
		$this->wpdb->before_next(
			'delete',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted delete failure';
			}
		);
		$this->wpdb->script_result( 'delete', false );

		self::assertNull( $store->purge() );
		self::assertNotNull( $this->option( 'a8csp_bgte_failed_delete-failure' ) );
	}

	/**
	 * A concurrent append loses the first CAS and the retry deletes the newer exact row.
	 *
	 * @return  void
	 */
	public function test_purge_retries_a_cas_loss_and_reports_the_deleted_snapshot_count(): void {
		$store = new FailedRunStore( 'cas-retry', $this->rows );
		$store->record( 'run-a', 100, array(), 1, new EngineError( 'First failure.' ) );
		$this->wpdb->before_next(
			'delete',
			static function ( WpdbLockSpy $wpdb ) use ( $store ): void {
				$store->record( 'run-b', 200, array(), 2, new EngineError( 'Second failure.' ) );
			}
		);

		self::assertSame( 2, $store->purge() );
		self::assertNull( $this->option( 'a8csp_bgte_failed_cas-retry' ) );
	}

	/**
	 * Three consecutive concurrent appends exhaust the bounded exact-delete attempts.
	 *
	 * @return  void
	 */
	public function test_purge_reports_failure_after_three_cas_losses(): void {
		$store = new FailedRunStore( 'cas-exhaustion', $this->rows );
		$store->record( 'run-a', 100, array(), 1, new EngineError( 'Failure A.' ) );

		foreach ( array( 'b', 'c', 'd' ) as $index => $suffix ) {
			$this->wpdb->before_next(
				'delete',
				static function ( WpdbLockSpy $wpdb ) use ( $store, $index, $suffix ): void {
					$store->record(
						'run-' . $suffix,
						200 + $index,
						array(),
						2 + $index,
						new EngineError( 'Failure ' . \strtoupper( $suffix ) . '.' )
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
	}

	/**
	 * A wholly malformed row has the same zero valid entries as all() and is still deleted.
	 *
	 * @return  void
	 */
	public function test_purge_deletes_malformed_raw_storage_with_a_zero_count(): void {
		$GLOBALS['a8csp_bgte_test_options'] = array(
			'a8csp_bgte_failed_malformed' => 'not a failed-run list',
		);

		$store = new FailedRunStore( 'malformed', $this->rows );

		$result = $store->all();
		if ( $result->is_failure() ) {
			self::fail( $result->error->message );
		}

		self::assertSame( array(), $result->value );
		self::assertSame( 0, $store->purge() );
		self::assertNull( $this->option( 'a8csp_bgte_failed_malformed' ) );
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
				),
			);
		}
		$GLOBALS['a8csp_bgte_test_options'] = array( 'a8csp_bgte_failed_oversized' => $entries );

		$store = new FailedRunStore( 'oversized', $this->rows );

		$store->remove( 'run-00' );
		$result = $store->all();
		if ( $result->is_failure() ) {
			self::fail( $result->error->message );
		}

		self::assertSame(
			\array_map(
				static fn ( int $index ): string => 'run-' . \str_pad( (string) $index, 2, '0', STR_PAD_LEFT ),
				\range( 2, 21 )
			),
			\array_column( $result->value, 'run_id' )
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

/** Serialized poison probe for hardened failed-run option reads. */
final class FailedRunStorePoison {
	public static int $wakeups = 0;

	/** Records unsafe native object construction. */
	public function __wakeup(): void {
		++self::$wakeups;
	}
}

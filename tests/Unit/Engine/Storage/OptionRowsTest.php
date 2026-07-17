<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Storage;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RowDeleteOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the authoritative option-row seam: direct SQL shape, raw-value compare-and-swap, cache
 * invalidation, and site binding.
 */
#[CoversClass( OptionRows::class )]
#[UsesClass( RowDeleteOutcome::class )]
final class OptionRowsTest extends TestCase {
	private const KEY = 'a8csp_bgte_run_email-digest_run-123';

	/** Loads the guarded WordPress cache and site functions. */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 2 ) . '/wp-lock-stubs.php';
	}

	/** Resets the current site and object-cache ledgers. */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_blog_id']     = 1;
		$GLOBALS['a8csp_bgte_test_cache']       = array();
		$GLOBALS['a8csp_bgte_test_cache_calls'] = array();
	}

	/** Row-delete outcomes expose only the three lowercase-backed storage states. */
	public function test_row_delete_outcome_pins_cases_and_backing_values(): void {
		self::assertSame( array( RowDeleteOutcome::Deleted, RowDeleteOutcome::ValueMismatch, RowDeleteOutcome::DeleteFailed ), RowDeleteOutcome::cases() );
		self::assertSame( array( 'deleted', 'value_mismatch', 'delete_failed' ), \array_column( RowDeleteOutcome::cases(), 'value' ) );
	}

	/** An UPDATE-only replacement cannot recreate a row deleted before the CAS. */
	public function test_compare_and_swap_is_insertless_when_the_expected_row_is_absent(): void {
		$wpdb = new WpdbLockSpy();
		$rows = new OptionRows( $wpdb );

		self::assertFalse( $rows->compare_and_swap( self::KEY, 'expected-raw', 'replacement-raw' ) );
		self::assertArrayNotHasKey( self::KEY, $wpdb->rows );
		self::assertCount( 1, $wpdb->recorded_queries );
		self::assertStringStartsWith( 'UPDATE ', $wpdb->recorded_queries[0] );
	}

	/** Replacement and deletion require byte-identical expected values. */
	public function test_compare_and_swap_and_delete_if_value_matches_use_binary_expected_values(): void {
		$wpdb = new WpdbLockSpy();
		$wpdb->put( self::KEY, 'Run-State' );
		$rows = new OptionRows( $wpdb );

		self::assertFalse( $rows->compare_and_swap( self::KEY, 'run-state', 'replacement-raw' ) );
		$found = $rows->read( self::KEY );
		self::assertFalse( $found->is_failure() );
		self::assertSame( 'Run-State', $found->value );
		self::assertTrue( $rows->compare_and_swap( self::KEY, 'Run-State', 'replacement-raw' ) );
		self::assertSame( RowDeleteOutcome::ValueMismatch, $rows->delete_if_value_matches( self::KEY, 'Run-State' ) );
		self::assertSame( RowDeleteOutcome::Deleted, $rows->delete_if_value_matches( self::KEY, 'replacement-raw' ) );
		$missing = $rows->read( self::KEY );
		self::assertFalse( $missing->is_failure() );
		self::assertNull( $missing->value );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $wpdb->recorded_queries[0] );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $wpdb->recorded_queries[2] );
	}

	/** Already-absent exact deletion is a value mismatch rather than idempotent success. */
	public function test_delete_if_value_matches_reports_value_mismatch_when_the_row_is_already_absent(): void {
		$rows = new OptionRows( new WpdbLockSpy() );

		self::assertSame( RowDeleteOutcome::ValueMismatch, $rows->delete_if_value_matches( self::KEY, 'expected-raw' ) );
	}

	/** A database delete error is distinct from a zero-row value mismatch. */
	public function test_delete_if_value_matches_reports_database_failure(): void {
		$wpdb = new WpdbLockSpy();
		$wpdb->put( self::KEY, 'expected-raw' );
		$wpdb->script_result( 'delete', false );
		$rows = new OptionRows( $wpdb );

		self::assertSame( RowDeleteOutcome::DeleteFailed, $rows->delete_if_value_matches( self::KEY, 'expected-raw' ) );
		self::assertSame( 'expected-raw', $wpdb->rows[ self::KEY ] );
	}

	/** Literal wildcard characters are escaped and imprecise database matches are filtered. */
	public function test_option_names_escapes_and_refilters_a_literal_prefix(): void {
		$prefix                    = 'a8csp_bgte_%_';
		$expected                  = $prefix . 'intent';
		$wpdb                      = new WpdbLockSpy();
		$wpdb->option_name_results = array( $expected, 'a8cspXbgteXwildcard-match', 42 );

		$result = ( new OptionRows( $wpdb ) )->option_names( $prefix );
		self::assertFalse( $result->is_failure() );
		self::assertSame( array( $expected ), $result->value );
		self::assertStringContainsString( "LIKE 'a8csp\\\\_bgte\\\\_\\\\%\\\\_%'", $wpdb->recorded_queries[0] );
	}

	/**
	 * Cursor-paged enumeration keeps only names under the escaped literal prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_option_names_after_refilters_the_literal_prefix_boundary(): void {
		$prefix                    = 'a8csp_bgte_%_';
		$expected                  = $prefix . 'intent';
		$wpdb                      = new WpdbLockSpy();
		$wpdb->option_name_results = array( $expected, 'a8cspXbgteXwildcard-match', 42 );

		$result = ( new OptionRows( $wpdb ) )->option_names_after( $prefix, null, 10 );

		self::assertFalse( $result->is_failure() );
		self::assertSame(
			array(
				'names'       => array( $expected ),
				'next_cursor' => null,
				'scanned'     => 3,
			),
			$result->value
		);
		self::assertStringContainsString( "LIKE 'a8csp\\\\_bgte\\\\_\\\\%\\\\_%'", $wpdb->recorded_queries[0] );
	}

	/**
	 * Cursor-paged enumeration excludes its cursor and orders names by exact bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_option_names_after_excludes_the_cursor_and_orders_by_binary_name(): void {
		$prefix = 'a8csp_bgte_run_';
		$wpdb   = new WpdbLockSpy();
		$wpdb->put( $prefix . 'B', 'second' );
		$wpdb->put( $prefix . 'a', 'third' );
		$wpdb->put( $prefix . 'A', 'cursor' );

		$result = ( new OptionRows( $wpdb ) )->option_names_after( $prefix, $prefix . 'A', 10 );

		self::assertFalse( $result->is_failure() );
		self::assertSame(
			array(
				'names'       => array( $prefix . 'B', $prefix . 'a' ),
				'next_cursor' => null,
				'scanned'     => 2,
			),
			$result->value
		);
		self::assertStringContainsString( 'BINARY `option_name` > BINARY ', $wpdb->recorded_queries[0] );
		self::assertStringContainsString( 'ORDER BY BINARY `option_name` ASC', $wpdb->recorded_queries[0] );
	}

	/**
	 * Cursor-paged enumeration applies its limit and exposes a short final page.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_option_names_after_applies_the_limit_and_returns_a_short_final_page(): void {
		$prefix = 'a8csp_bgte_run_';
		$wpdb   = new WpdbLockSpy();
		$wpdb->put( $prefix . '1', 'first' );
		$wpdb->put( $prefix . '2', 'second' );
		$wpdb->put( $prefix . '3', 'third' );
		$rows = new OptionRows( $wpdb );

		$first = $rows->option_names_after( $prefix, null, 2 );
		self::assertFalse( $first->is_failure() );
		self::assertSame(
			array(
				'names'       => array( $prefix . '1', $prefix . '2' ),
				'next_cursor' => $prefix . '2',
				'scanned'     => 2,
			),
			$first->value
		);

		$last = $rows->option_names_after( $prefix, $prefix . '2', 2 );
		self::assertFalse( $last->is_failure() );
		self::assertSame(
			array(
				'names'       => array( $prefix . '3' ),
				'next_cursor' => null,
				'scanned'     => 1,
			),
			$last->value
		);
	}

	/**
	 * Raw case-insensitive candidates drive exhaustion and the opaque cursor before bytewise filtering.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_option_names_after_pages_by_raw_case_colliding_candidates(): void {
		$prefix    = 'a8csp_bgte_run_';
		$collision = 'A8CSP_BGTE_RUN_collision';
		$wpdb      = new WpdbLockSpy();
		$wpdb->put( $collision, 'foreign' );
		$wpdb->put( $prefix . '1', 'first' );
		$wpdb->put( $prefix . '2', 'second' );
		$rows = new OptionRows( $wpdb );

		$first = $rows->option_names_after( $prefix, null, 2 );
		self::assertFalse( $first->is_failure() );
		self::assertSame(
			array(
				'names'       => array( $prefix . '1' ),
				'next_cursor' => $prefix . '1',
				'scanned'     => 2,
			),
			$first->value
		);

		$last = $rows->option_names_after( $prefix, $first->value['next_cursor'], 2 );
		self::assertFalse( $last->is_failure() );
		self::assertSame(
			array(
				'names'       => array( $prefix . '2' ),
				'next_cursor' => null,
				'scanned'     => 1,
			),
			$last->value
		);
	}

	/**
	 * A non-advancing raw cursor is reported as an authoritative storage failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_option_names_after_rejects_a_non_advancing_raw_cursor(): void {
		$cursor                    = 'a8csp_bgte_run_cursor';
		$wpdb                      = new WpdbLockSpy();
		$wpdb->option_name_results = array( $cursor );

		$result = ( new OptionRows( $wpdb ) )->option_names_after( 'a8csp_bgte_run_', $cursor, 1 );

		self::assertTrue( $result->is_failure() );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame( EngineErrorReason::StorageFailure, $result->error->reason );
	}

	/**
	 * Cursor-paged enumeration rejects a non-positive limit.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_option_names_after_rejects_a_non_positive_limit(): void {
		$this->expectException( \InvalidArgumentException::class );

		(void) ( new OptionRows( new WpdbLockSpy() ) )->option_names_after( 'a8csp_bgte_', null, 0 );
	}

	/**
	 * Cursor-paged enumeration returns a storage failure when its query fails.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_option_names_after_returns_an_explicit_failed_outcome(): void {
		$wpdb = new WpdbLockSpy();
		$wpdb->before_next(
			'scan',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'scripted option-name read failure';
			}
		);

		$result = ( new OptionRows( $wpdb ) )->option_names_after( 'a8csp_bgte_', null, 10 );

		self::assertTrue( $result->is_failure() );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame( 'Authoritative option-name read failed; repair WordPress option reads and retry.', $result->error->message );
		self::assertSame( array( 'storage_error' => 'scripted option-name read failure' ), $result->error->context );
	}

	/** A bounded page keysets past rejected candidates and counts only accepted names. */
	public function test_option_names_page_applies_the_limit_after_validation(): void {
		$prefix       = 'a8csp_bgte_run_owner:email-digest_';
		$first_valid  = $prefix . \sprintf( '%020d-%019d', 1, 1 );
		$second_valid = $prefix . \sprintf( '%020d-%019d', 2, 2 );
		$wpdb         = new WpdbLockSpy();
		$wpdb->put( $prefix . \sprintf( '!%039d', 1 ), 'malformed-run-row' );
		$wpdb->put( $prefix . \sprintf( '!%039d', 2 ), 'malformed-run-row' );
		$wpdb->put( $first_valid, 'first-run-row' );
		$wpdb->put( $second_valid, 'second-run-row' );

		$page = ( new OptionRows( $wpdb ) )->option_names_page( $prefix, \strlen( $first_valid ), 1, static fn ( string $name ): bool => 1 === \preg_match( '/\A\d{20}-\d{19}\z/D', \substr( $name, \strlen( $prefix ) ) ) );

		self::assertSame(
			array(
				'names' => array( $first_valid ),
				'total' => 2,
			),
			$page
		);
	}

	/** Authoritative reads distinguish found, missing, and failed outcomes. */
	public function test_read_returns_explicit_found_missing_and_failed_outcomes(): void {
		$wpdb = new WpdbLockSpy();
		$wpdb->put( self::KEY, 'Run-State' );
		$rows = new OptionRows( $wpdb );

		$found = $rows->read( self::KEY );
		self::assertFalse( $found->is_failure() );
		self::assertSame( 'Run-State', $found->value );

		unset( $wpdb->rows[ self::KEY ], $wpdb->autoload[ self::KEY ] );
		$missing = $rows->read( self::KEY );
		self::assertFalse( $missing->is_failure() );
		self::assertNull( $missing->value );

		$wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'scripted row read failure';
			}
		);
		$failed = $rows->read( self::KEY );
		self::assertTrue( $failed->is_failure() );
		self::assertInstanceOf( EngineError::class, $failed->error );
		self::assertSame( 'Authoritative option-row read failed; repair WordPress option reads and retry.', $failed->error->message );
		self::assertSame(
			array(
				'option_name'   => self::KEY,
				'storage_error' => 'scripted row read failure',
			),
			$failed->error->context
		);
	}

	/** Option-name enumeration returns a failed outcome when its query fails. */
	public function test_option_names_returns_an_explicit_failed_outcome(): void {
		$wpdb = new WpdbLockSpy();
		$wpdb->before_next(
			'scan',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'scripted option-name read failure';
			}
		);

		$result = ( new OptionRows( $wpdb ) )->option_names( 'a8csp_bgte_' );

		self::assertTrue( $result->is_failure() );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame( 'Authoritative option-name read failed; repair WordPress option reads and retry.', $result->error->message );
		self::assertSame( array( 'storage_error' => 'scripted option-name read failure' ), $result->error->context );
	}

	/**
	 * INSERT IGNORE acquires only an absent non-autoloaded row and carries Core's lock marker.
	 *
	 * @return  void
	 */
	public function test_insert_if_absent_models_exclusive_core_lock_statement(): void {
		$wpdb = new WpdbLockSpy();
		$rows = new OptionRows( $wpdb );
		$row  = self::row( 'run-owner', 100, 100 );

		self::assertTrue( $rows->insert_if_absent( self::KEY, self::raw( $row ) ) );
		self::assertFalse( $rows->insert_if_absent( self::KEY, self::raw( self::row( 'run-rival', 200, 200 ) ) ) );

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
		$rows = new OptionRows( $wpdb );
		self::prime_stale_caches();

		$found = $rows->read( self::KEY );
		self::assertFalse( $found->is_failure() );
		self::assertSame( '', $found->value );
		unset( $wpdb->rows[ self::KEY ], $wpdb->autoload[ self::KEY ] );
		$missing = $rows->read( self::KEY );
		self::assertFalse( $missing->is_failure() );
		self::assertNull( $missing->value );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_cache_calls'] );
	}

	/** Prepared values containing placeholder text remain byte-for-byte data. */
	public function test_prepared_values_cannot_be_reparsed_as_placeholders(): void {
		$wpdb = new WpdbLockSpy();
		$rows = new OptionRows( $wpdb );
		$key  = self::KEY . '-%s';
		$row  = self::row( 'run-%s-owner', 100, 100 );

		self::assertTrue( $rows->insert_if_absent( $key, self::raw( $row ) ) );
		self::assertSame( self::raw( $row ), $wpdb->rows[ $key ] );
	}

	/**
	 * Replace writes only while both the option name and exact raw value still match.
	 *
	 * @return  void
	 */
	public function test_compare_and_swap_is_an_exact_raw_value_compare_and_swap(): void {
		$wpdb    = new WpdbLockSpy();
		$old_row = self::row( 'run-owner', 100, 100 );
		$new_row = self::row( 'run-owner', 100, 200 );
		$old_raw = self::raw( $old_row );
		$wpdb->put( self::KEY, $old_raw );
		$rows = new OptionRows( $wpdb );

		self::assertFalse( $rows->compare_and_swap( self::KEY, self::raw( self::row( 'run-loser', 50, 50 ) ), self::raw( $new_row ) ) );
		self::assertSame( $old_raw, $wpdb->rows[ self::KEY ] );
		self::assertTrue( $rows->compare_and_swap( self::KEY, $old_raw, self::raw( $new_row ) ) );
		self::assertSame( self::raw( $new_row ), $wpdb->rows[ self::KEY ] );
		self::assertStringContainsString( 'WHERE `option_name` = ', $wpdb->recorded_queries[0] );
		self::assertStringContainsString( 'AND BINARY `option_value` = BINARY ', $wpdb->recorded_queries[0] );
	}

	/**
	 * An unchanged MySQL update succeeds only after an authoritative read confirms the expected row.
	 *
	 * @return  void
	 */
	public function test_compare_and_swap_confirms_an_identical_value_after_zero_affected_rows(): void {
		$wpdb = new WpdbLockSpy();
		$row  = self::row( 'run-owner', 100, 100 );
		$raw  = self::raw( $row );
		$wpdb->put( self::KEY, $raw );
		$rows = new OptionRows( $wpdb );

		self::assertTrue( $rows->compare_and_swap( self::KEY, $raw, $raw ) );
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
	public function test_compare_and_swap_rejects_a_lost_identical_value_compare_and_swap(): void {
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
		$rows = new OptionRows( $wpdb );

		self::assertFalse( $rows->compare_and_swap( self::KEY, $raw, $raw ) );
		self::assertSame( $winner_raw, $wpdb->rows[ self::KEY ] );
	}

	/** Collation-equivalent but byte-different raw values cannot satisfy either CAS predicate. */
	public function test_compare_and_swap_uses_binary_raw_value_equality(): void {
		$wpdb         = new WpdbLockSpy();
		$expected_raw = self::raw( self::row( 'run-owner', 100, 100 ) );
		$winner_raw   = self::raw( self::row( 'RUN-OWNER', 100, 100 ) );
		$wpdb->put( self::KEY, $winner_raw );
		$rows = new OptionRows( $wpdb );

		self::assertFalse( $rows->compare_and_swap( self::KEY, $expected_raw, self::raw( self::row( 'run-owner', 100, 200 ) ) ) );
		self::assertSame( RowDeleteOutcome::ValueMismatch, $rows->delete_if_value_matches( self::KEY, $expected_raw ) );
		self::assertSame( $winner_raw, $wpdb->rows[ self::KEY ] );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $wpdb->recorded_queries[0] );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $wpdb->recorded_queries[1] );
	}

	/** A database error is not the zero-row identical-update case and does not trigger confirmation. */
	public function test_compare_and_swap_does_not_confirm_an_identical_value_after_a_database_error(): void {
		$wpdb = new WpdbLockSpy();
		$row  = self::row( 'run-owner', 100, 100 );
		$raw  = self::raw( $row );
		$wpdb->put( self::KEY, $raw );
		$wpdb->script_result( 'update', false );
		$rows = new OptionRows( $wpdb );
		self::prime_stale_caches();

		self::assertFalse( $rows->compare_and_swap( self::KEY, $raw, $raw ) );
		self::assertCount( 1, $wpdb->recorded_queries );
		self::assertStringStartsWith( 'UPDATE ', $wpdb->recorded_queries[0] );
		self::assert_cache_purge();
	}

	/**
	 * Delete removes only the exact raw row selected by the caller.
	 *
	 * @return  void
	 */
	public function test_delete_if_value_matches_is_an_exact_raw_value_compare_and_swap(): void {
		$wpdb = new WpdbLockSpy();
		$raw  = self::raw( self::row( 'run-owner', 100, 100 ) );
		$wpdb->put( self::KEY, $raw );
		$rows = new OptionRows( $wpdb );

		self::assertSame( RowDeleteOutcome::ValueMismatch, $rows->delete_if_value_matches( self::KEY, self::raw( self::row( 'run-loser', 50, 50 ) ) ) );
		self::assertSame( $raw, $wpdb->rows[ self::KEY ] );
		self::assertSame( RowDeleteOutcome::Deleted, $rows->delete_if_value_matches( self::KEY, $raw ) );
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
		$rows = new OptionRows( $wpdb );
		$row  = self::row( 'run-owner', 100, 100 );

		self::prime_stale_caches();
		$rows->insert_if_absent( self::KEY, self::raw( $row ) );
		self::assert_cache_purge();

		self::prime_stale_caches();
		$raw = $wpdb->rows[ self::KEY ];
		$rows->compare_and_swap( self::KEY, $raw, self::raw( self::row( 'run-owner', 100, 200 ) ) );
		self::assert_cache_purge();

		self::prime_stale_caches();
		self::assertSame( RowDeleteOutcome::Deleted, $rows->delete_if_value_matches( self::KEY, $wpdb->rows[ self::KEY ] ) );
		self::assert_cache_purge();
	}

	/** Lost inserts and CAS attempts still invalidate both stale cache representations. */
	public function test_failed_mutations_still_purge_options_and_notoptions_caches(): void {
		$wpdb = new WpdbLockSpy();
		$rows = new OptionRows( $wpdb );
		$row  = self::row( 'run-owner', 100, 100 );
		$raw  = self::raw( $row );
		$wpdb->put( self::KEY, $raw );

		self::prime_stale_caches();
		self::assertFalse( $rows->insert_if_absent( self::KEY, self::raw( self::row( 'run-rival', 200, 200 ) ) ) );
		self::assert_cache_purge();

		self::prime_stale_caches();
		self::assertFalse( $rows->compare_and_swap( self::KEY, 'stale-raw', self::raw( self::row( 'run-owner', 100, 200 ) ) ) );
		self::assert_cache_purge();

		self::prime_stale_caches();
		self::assertSame( RowDeleteOutcome::ValueMismatch, $rows->delete_if_value_matches( self::KEY, 'stale-raw' ) );
		self::assert_cache_purge();
	}

	/**
	 * Site-bound rows reject every single-row operation after an ambient blog switch.
	 *
	 * @param   callable(OptionRows): mixed $operation Operation under test.
	 *
	 * @return  void
	 */
	#[DataProvider( 'site_bound_single_row_operation_provider' )]
	public function test_every_single_row_operation_rejects_switch_to_blog_misuse( callable $operation ): void {
		$wpdb = new WpdbLockSpy();
		$rows = new OptionRows( $wpdb );

		$GLOBALS['a8csp_bgte_test_blog_id'] = 2;

		try {
			$operation( $rows );
		} catch ( \LogicException $exception ) {
			self::assertStringContainsString( 'switch_to_blog', $exception->getMessage() );
			self::assertSame( array(), $wpdb->recorded_queries );
			self::assertSame( array(), $GLOBALS['a8csp_bgte_test_cache_calls'] );
			return;
		}

		self::fail( 'Every single-row option operation must reject use after switch_to_blog().' );
	}

	/**
	 * Returns one call for each public single-row option operation.
	 *
	 * @return  iterable<string, array{callable(OptionRows): mixed}>
	 */
	public static function site_bound_single_row_operation_provider(): iterable {
		$row_raw      = 'replacement-raw';
		$expected_raw = 'expected-raw';

		yield 'insert_if_absent' => array( static fn ( OptionRows $rows ): bool => $rows->insert_if_absent( self::KEY, $row_raw ) );
		yield 'read' => array( static fn ( OptionRows $rows ): AbstractResult => $rows->read( self::KEY ) );
		yield 'compare_and_swap' => array( static fn ( OptionRows $rows ): bool => $rows->compare_and_swap( self::KEY, $expected_raw, $row_raw ) );
		yield 'delete_if_value_matches' => array( static fn ( OptionRows $rows ): RowDeleteOutcome => $rows->delete_if_value_matches( self::KEY, $expected_raw ) );
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
		self::assertSame( array( 'wp_cache_delete', 'wp_cache_get', 'wp_cache_set' ), \array_column( $calls, 'function' ) );
		self::assertSame( array( self::KEY, 'options' ), $calls[0]['args'] );
		self::assertSame( array( 'notoptions', 'options', false ), $calls[1]['args'] );
		self::assertSame( array( 'notoptions', array( 'other' => true ), 'options', 0 ), $calls[2]['args'] );

		/** @var array<string, array<int|string, mixed>> $cache */
		$cache = $GLOBALS['a8csp_bgte_test_cache'];
		self::assertArrayNotHasKey( self::KEY, $cache['options'] );
		self::assertSame( array( 'other' => true ), $cache['options']['notoptions'] );
	}
}

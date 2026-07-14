<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Storage;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins authoritative raw option-row compare-and-swap operations.
 */
#[CoversClass( OptionRows::class )]
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

	/** An UPDATE-only replacement cannot recreate a row deleted before the CAS. */
	public function test_replace_is_insertless_when_the_expected_row_is_absent(): void {
		$wpdb = new WpdbLockSpy();
		$rows = new OptionRows( $wpdb );

		self::assertFalse( $rows->replace( self::KEY, 'expected-raw', 'replacement-raw' ) );
		self::assertArrayNotHasKey( self::KEY, $wpdb->rows );
		self::assertCount( 1, $wpdb->recorded_queries );
		self::assertStringStartsWith( 'UPDATE ', $wpdb->recorded_queries[0] );
	}

	/** Replacement and deletion require byte-identical expected values. */
	public function test_replace_and_delete_use_binary_expected_values(): void {
		$wpdb = new WpdbLockSpy();
		$wpdb->put( self::KEY, 'Run-State' );
		$rows = new OptionRows( $wpdb );

		self::assertFalse( $rows->replace( self::KEY, 'run-state', 'replacement-raw' ) );
		self::assertSame( 'Run-State', $rows->select( self::KEY ) );
		self::assertTrue( $rows->replace( self::KEY, 'Run-State', 'replacement-raw' ) );
		self::assertFalse( $rows->delete( self::KEY, 'Run-State' ) );
		self::assertTrue( $rows->delete( self::KEY, 'replacement-raw' ) );
		self::assertNull( $rows->select( self::KEY ) );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $wpdb->recorded_queries[0] );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $wpdb->recorded_queries[2] );
	}

	/** Already-absent exact deletion is a loss rather than idempotent success. */
	public function test_delete_reports_loss_when_the_row_is_already_absent(): void {
		$rows = new OptionRows( new WpdbLockSpy() );

		self::assertFalse( $rows->delete( self::KEY, 'expected-raw' ) );
	}

	/** Literal wildcard characters are escaped and imprecise database matches are filtered. */
	public function test_option_names_escapes_and_refilters_a_literal_prefix(): void {
		$prefix                    = 'a8csp_bgte_%_';
		$expected                  = $prefix . 'intent';
		$wpdb                      = new WpdbLockSpy();
		$wpdb->option_name_results = array( $expected, 'a8cspXbgteXwildcard-match', 42 );

		self::assertSame( array( $expected ), ( new OptionRows( $wpdb ) )->option_names( $prefix ) );
		self::assertStringContainsString(
			"LIKE 'a8csp\\\\_bgte\\\\_\\\\%\\\\_%'",
			$wpdb->recorded_queries[0]
		);
	}
}

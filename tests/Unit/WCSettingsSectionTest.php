<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Integrations\WC_Settings_Section;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the WooCommerce presence gate, initialization branches, and pure version comparison
 * without WordPress.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( WC_Settings_Section::class )]
final class WCSettingsSectionTest extends TestCase {
	/**
	 * Satisfies the production files' `ABSPATH` boot guard before their classes are first
	 * autoloaded.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once __DIR__ . '/wp-hook-stubs.php';
		require_once __DIR__ . '/plugin-metadata-stubs.php';
	}

	/**
	 * Starts each test with an empty hook-registration ledger.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_hooks'] = array();
	}

	/**
	 * Without WooCommerce core present, the presence gate reports the settings section as not
	 * needed.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_is_needed_is_false_without_the_real_plugin(): void {
		self::assertFalse( ( new WC_Settings_Section() )->is_needed() );
	}

	/**
	 * With WooCommerce present but below the header floor, the misconfiguration speaks through one
	 * notice and registers no settings wiring.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	public function test_initialize_below_floor_registers_only_the_notice(): void {
		\define( 'WC_VERSION', '9.0.0' );

		( new WC_Settings_Section() )->initialize();

		self::assertContains( 'admin_notices', $GLOBALS['a8csp_bgte_test_hooks'] );

		foreach ( $GLOBALS['a8csp_bgte_test_hooks'] as $hook_name ) {
			self::assertStringStartsNotWith( 'woocommerce_', $hook_name );
		}
	}

	/**
	 * With WooCommerce at the header floor, initialization registers both settings filters and no
	 * version notice.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	public function test_initialize_at_floor_registers_the_settings_filters(): void {
		\define( 'WC_VERSION', '10.0.0' );

		( new WC_Settings_Section() )->initialize();

		self::assertContains( 'woocommerce_get_sections_advanced', $GLOBALS['a8csp_bgte_test_hooks'] );
		self::assertContains( 'woocommerce_get_settings_advanced', $GLOBALS['a8csp_bgte_test_hooks'] );
		self::assertNotContains( 'admin_notices', $GLOBALS['a8csp_bgte_test_hooks'] );
	}

	/**
	 * Without a header-declared floor, every installed WooCommerce version meets the requirement.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_minimum_wc_version_accepts_a_missing_floor(): void {
		self::assertTrue( WC_Settings_Section::meets_minimum_wc_version( '1.0.0', null ) );
	}

	/**
	 * An installed WooCommerce version below the header-declared floor fails the requirement.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_minimum_wc_version_rejects_a_version_below_the_floor(): void {
		self::assertFalse( WC_Settings_Section::meets_minimum_wc_version( '9.9.0', '10.0.0' ) );
	}

	/**
	 * An installed WooCommerce version equal to the header-declared floor meets the requirement.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_minimum_wc_version_accepts_a_version_equal_to_the_floor(): void {
		self::assertTrue( WC_Settings_Section::meets_minimum_wc_version( '10.0.0', '10.0.0' ) );
	}

	/**
	 * An installed WooCommerce version above the header-declared floor meets the requirement.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_minimum_wc_version_accepts_a_version_above_the_floor(): void {
		self::assertTrue( WC_Settings_Section::meets_minimum_wc_version( '10.0.1', '10.0.0' ) );
	}
}

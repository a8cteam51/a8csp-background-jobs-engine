<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real `Plugin::boot()` component loop outside WordPress through the recording hook
 * stubs — the open-gate components register their hooks, a closed-gate component registers nothing,
 * and a second boot is a no-op.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Plugin::class )]
final class PluginBootGateTest extends TestCase {
	/**
	 * Satisfies the production files' `ABSPATH` boot guard and loads the recording hook stubs before
	 * the component classes are first autoloaded.
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
	 * Open-gate components register their hooks while the WooCommerce-gated component registers
	 * nothing when WooCommerce is absent.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_boot_initializes_open_gate_components_and_skips_closed_gates(): void {
		( new Plugin() )->boot();

		self::assertContains( 'init', $GLOBALS['a8csp_bgte_test_hooks'] );
		self::assertContains( 'enqueue_block_editor_assets', $GLOBALS['a8csp_bgte_test_hooks'] );
		self::assertContains( 'admin_init', $GLOBALS['a8csp_bgte_test_hooks'] );

		foreach ( $GLOBALS['a8csp_bgte_test_hooks'] as $hook_name ) {
			self::assertStringStartsNotWith( 'woocommerce_', $hook_name );
		}
	}

	/**
	 * A second boot on the same instance leaves the hook-registration ledger unchanged.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_second_boot_is_a_no_op(): void {
		$plugin = new Plugin();
		$plugin->boot();

		$hook_count = \count( $GLOBALS['a8csp_bgte_test_hooks'] );

		$plugin->boot();

		self::assertCount( $hook_count, $GLOBALS['a8csp_bgte_test_hooks'] );
	}
}

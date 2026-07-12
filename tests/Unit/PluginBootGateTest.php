<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Log;
use A8C\SpecialProjects\BackgroundTasksEngine\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real `Plugin::boot()` path outside WordPress through the recording hook stubs. The
 * component registry boots in place, and a second boot is a no-op.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Plugin::class )]
#[UsesClass( Log::class )]
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
	#[\Override]
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
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_hooks']                = array();
		$GLOBALS['a8csp_bgte_test_action_registrations'] = array();
	}

	/**
	 * The component registry registers the engine's log channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_boot_registers_the_log_channel_hook(): void {
		( new Plugin() )->boot();

		self::assertSame( array( 'a8csp/background_tasks/log' ), $GLOBALS['a8csp_bgte_test_hooks'] );
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
		$plugin->boot();

		self::assertSame( array( 'a8csp/background_tasks/log' ), $GLOBALS['a8csp_bgte_test_hooks'] );
	}
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Logging\ErrorLogSink;
use A8C\SpecialProjects\BackgroundTasksEngine\Plugin;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real `Plugin::boot()` path outside WordPress through the recording hook stubs. The
 * component registry boots in place, and a second boot is a no-op.
 *
 */
#[CoversClass( Plugin::class )]
#[UsesClass( Component::class )]
#[UsesClass( ErrorLogSink::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PluginBootGateTest extends TestCase {
	/**
	 * Satisfies the production files' `ABSPATH` boot guard and loads the recording hook stubs before
	 * the component classes are first autoloaded.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once __DIR__ . '/wp-hook-stubs.php';
		require_once __DIR__ . '/wp-lock-stubs.php';
		require_once __DIR__ . '/wp-options-stubs.php';
		require_once __DIR__ . '/wp-time-constant-stubs.php';
		require_once __DIR__ . '/Engine/Scheduling/wp-json-encode-stub.php';
		require_once __DIR__ . '/wp-cron-stubs.php';
	}

	/**
	 * Starts each test with an empty hook-registration ledger.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_hooks']                = array();
		$GLOBALS['a8csp_bgte_test_action_registrations'] = array();
		$GLOBALS['a8csp_bgte_test_filter_registrations'] = array();
		$GLOBALS['a8csp_bgte_test_blog_id']              = 1;
		$GLOBALS['a8csp_bgte_test_options']              = array();
		$GLOBALS['a8csp_bgte_test_option_calls']         = array();
		$GLOBALS['a8csp_bgte_test_option_autoload']      = array();
		$GLOBALS['a8csp_bgte_test_cron_array']           = array();
		$GLOBALS['a8csp_bgte_test_cron_calls']           = array();
		$GLOBALS['a8csp_bgte_test_cron_results']         = array();
		$GLOBALS['a8csp_bgte_test_cron_event_sequence']  = 0;
		$GLOBALS['wpdb']                                 = new WpdbLockSpy();
	}

	/**
	 * The component registry registers logging, scheduler, and orchestration hooks.
	 *
	 * @return  void
	 */
	public function test_boot_registers_the_plugin_component_hooks(): void {
		( new Plugin() )->boot();

		self::assertSame(
			array(
				'a8csp_background_tasks/log',
				'cron_schedules',
				'a8csp_background_tasks/start',
				'a8csp_background_tasks/continue',
				'a8csp_background_tasks/run',
				'a8csp_background_tasks/cleanup',
				'a8csp_background_tasks/schedule_due',
				'init',
			),
			$GLOBALS['a8csp_bgte_test_hooks']
		);
		$action_registrations = $GLOBALS['a8csp_bgte_test_action_registrations'] ?? null;
		self::assertIsArray( $action_registrations );
		$init_registration = $action_registrations[6] ?? null;
		self::assertIsArray( $init_registration );
		self::assertSame( 'init', $init_registration['hook_name'] ?? null );
		self::assertSame( 10, $init_registration['priority'] ?? null );
	}

	/**
	 * A second boot on the same instance leaves the hook-registration ledger unchanged.
	 *
	 * @return  void
	 */
	public function test_second_boot_is_a_no_op(): void {
		$plugin = new Plugin();
		$plugin->boot();
		$plugin->boot();

		self::assertSame(
			array(
				'a8csp_background_tasks/log',
				'cron_schedules',
				'a8csp_background_tasks/start',
				'a8csp_background_tasks/continue',
				'a8csp_background_tasks/run',
				'a8csp_background_tasks/cleanup',
				'a8csp_background_tasks/schedule_due',
				'init',
			),
			$GLOBALS['a8csp_bgte_test_hooks']
		);
	}
}

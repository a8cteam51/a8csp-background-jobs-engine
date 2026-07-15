<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\EngineFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the plugin entry file when activation loads it after plugins_loaded has fired.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PluginActivationBootTest extends TestCase {
	// region TESTS.

	/**
	 * An activation request boots immediately because its plugins_loaded callback cannot run retroactively.
	 *
	 * @return  void
	 */
	public function test_activation_request_boots_the_engine_immediately(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once __DIR__ . '/wp-time-constant-stubs.php';
		require_once __DIR__ . '/wp-update-stubs.php';
		require_once __DIR__ . '/wp-lock-stubs.php';
		require_once __DIR__ . '/wp-options-stubs.php';
		require_once __DIR__ . '/Engine/Backends/wp-json-encode-stub.php';
		require_once __DIR__ . '/wp-cron-stubs.php';

		$GLOBALS['a8csp_bgte_test_hooks']                = array();
		$GLOBALS['a8csp_bgte_test_action_registrations'] = array();
		$GLOBALS['a8csp_bgte_test_filter_registrations'] = array();
		$GLOBALS['a8csp_bgte_test_filter_values']        = array();
		$GLOBALS['a8csp_bgte_test_did_actions']          = array( 'plugins_loaded' => 1 );
		$GLOBALS['a8csp_bgte_test_blog_id']              = 1;
		$GLOBALS['a8csp_bgte_test_options']              = array();
		$GLOBALS['a8csp_bgte_test_option_calls']         = array();
		$GLOBALS['a8csp_bgte_test_option_autoload']      = array();
		$GLOBALS['a8csp_bgte_test_cron_array']           = array();
		$GLOBALS['a8csp_bgte_test_cron_calls']           = array();
		$GLOBALS['a8csp_bgte_test_cron_results']         = array();
		$GLOBALS['a8csp_bgte_test_cron_event_sequence']  = 0;
		$GLOBALS['wpdb']                                 = new WpdbLockSpy();

		require_once \dirname( __DIR__, 2 ) . '/a8csp-background-tasks-engine.php';

		self::assertInstanceOf( EngineFacade::class, Component::get_engine() );
		$hooks = $GLOBALS['a8csp_bgte_test_hooks'];
		self::assertNotContains( 'plugins_loaded', $hooks );
	}

	// endregion.
}

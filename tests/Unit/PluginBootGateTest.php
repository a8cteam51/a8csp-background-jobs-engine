<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Logging\ErrorLogSink;
use A8C\SpecialProjects\BackgroundTasksEngine\Plugin;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the complete `Plugin::boot()` path outside WordPress through the recording hook stubs.
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
		require_once __DIR__ . '/Engine/Backends/wp-json-encode-stub.php';
		require_once __DIR__ . '/wp-cron-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/functions.php';
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

		$GLOBALS['a8csp_bgte_test_filter_registration_callbacks'] = array();

		$GLOBALS['a8csp_bgte_test_filter_values']       = array();
		$GLOBALS['a8csp_bgte_test_did_actions']         = array();
		$GLOBALS['a8csp_bgte_test_doing_actions']       = array();
		$GLOBALS['a8csp_bgte_test_blog_id']             = 1;
		$GLOBALS['a8csp_bgte_test_options']             = array();
		$GLOBALS['a8csp_bgte_test_option_calls']        = array();
		$GLOBALS['a8csp_bgte_test_option_autoload']     = array();
		$GLOBALS['a8csp_bgte_test_cron_array']          = array();
		$GLOBALS['a8csp_bgte_test_cron_calls']          = array();
		$GLOBALS['a8csp_bgte_test_cron_results']        = array();
		$GLOBALS['a8csp_bgte_test_cron_event_sequence'] = 0;
		$GLOBALS['wpdb']                                = new WpdbLockSpy();
	}

	/**
	 * Plugin boot publishes the consumer facade through the public front door.
	 *
	 * @return  void
	 */
	public function test_boot_publishes_the_public_consumer_facade(): void {
		( new Plugin() )->boot();
		$GLOBALS['a8csp_bgte_test_did_actions'] = array( 'init' => 1 );

		self::assertInstanceOf( Consumer::class, \a8csp_bgte( 'plugin-boot-gate' ) );
	}

	/**
	 * A boot throw poisons the stored plugin entry and the public consumer seam fails loudly.
	 *
	 * @return  void
	 */
	public function test_failed_accessor_boot_leaves_the_public_consumer_unavailable(): void {
		$GLOBALS['wpdb'] = new \stdClass();
		$throwable       = null;
		try {
			\a8csp_bgte_plugin();
		} catch ( \TypeError $caught ) {
			$throwable = $caught;
		}

		self::assertInstanceOf( \TypeError::class, $throwable );
		$GLOBALS['wpdb'] = new WpdbLockSpy();

		$GLOBALS['a8csp_bgte_test_did_actions'] = array( 'init' => 1 );

		$this->expectException( \LogicException::class );

		\a8csp_bgte( 'plugin-boot-gate' );
	}
}

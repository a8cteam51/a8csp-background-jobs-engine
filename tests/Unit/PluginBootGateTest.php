<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\AbstractComponent;
use A8C\SpecialProjects\BackgroundJobsEngine\ComponentCollection;
use A8C\SpecialProjects\BackgroundJobsEngine\Plugin;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Logging\EngineLogger;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
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
#[UsesClass( AbstractComponent::class )]
#[UsesClass( ComponentCollection::class )]
#[UsesClass( Component::class )]
#[UsesClass( EngineLogger::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PluginBootGateTest extends TestCase {
	// region LIFECYCLE.

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
		require_once __DIR__ . '/Runtime/Backends/wp-json-encode-stub.php';
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

		$GLOBALS['a8csp_bgje_test_hooks']                = array();
		$GLOBALS['a8csp_bgje_test_action_registrations'] = array();
		$GLOBALS['a8csp_bgje_test_filter_registrations'] = array();

		$GLOBALS['a8csp_bgje_test_filter_registration_callbacks'] = array();

		$GLOBALS['a8csp_bgje_test_filter_values']       = array();
		$GLOBALS['a8csp_bgje_test_did_actions']         = array();
		$GLOBALS['a8csp_bgje_test_doing_actions']       = array();
		$GLOBALS['a8csp_bgje_test_blog_id']             = 1;
		$GLOBALS['a8csp_bgje_test_options']             = array();
		$GLOBALS['a8csp_bgje_test_option_calls']        = array();
		$GLOBALS['a8csp_bgje_test_option_autoload']     = array();
		$GLOBALS['a8csp_bgje_test_cron_array']          = array();
		$GLOBALS['a8csp_bgje_test_cron_calls']          = array();
		$GLOBALS['a8csp_bgje_test_cron_results']        = array();
		$GLOBALS['a8csp_bgje_test_cron_event_sequence'] = 0;
		$GLOBALS['wpdb']                                = new WpdbLockSpy();
	}

	// endregion.

	// region TESTS.

	/**
	 * Public portal operations remain unavailable before the retained plugin boots.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_public_portal_operations_are_unavailable_before_boot(): void {
		$error = \a8csp_bgje( 'plugin-boot-gate' )->jobs()->dispatch( 'job' );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'engine_unavailable', $error->get_error_code() );
		self::assertSame( 'The background jobs engine graph is unavailable before its plugins_loaded boot callback completes successfully; invoke engine operations from init or a later hook.', $error->get_error_message() );
		self::assertNull( $error->get_error_data() );
	}

	/**
	 * Plugin boot makes public portal operations reach the published graph.
	 *
	 * @return  void
	 */
	public function test_boot_makes_public_portal_operations_available(): void {
		$plugin = new Plugin();
		self::assertFalse( $plugin->is_booted() );

		$plugin->boot();
		self::assertTrue( $plugin->is_booted() );
		$GLOBALS['a8csp_bgje_test_did_actions'] = array( 'init' => 1 );

		$error = \a8csp_bgje( 'plugin-boot-gate' )->jobs()->dispatch( 'job' );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'unknown_job', $error->get_error_code() );
		self::assertSame( 'Background-work "plugin-boot-gate:job" is not registered; register it before dispatching.', $error->get_error_message() );
		self::assertSame( array( 'identity' => 'plugin-boot-gate:job' ), $error->get_error_data() );
	}

	/**
	 * The retained plugin ignores a second boot without rebuilding registrations or hooks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_second_boot_retains_the_graph_and_hook_registrations(): void {
		$plugin = \a8csp_bgje_plugin();
		$plugin->boot();
		$jobs = \a8csp_bgje( 'plugin-double-boot' )->jobs();
		self::assertTrue( $jobs->register( ( new RecordingJob( 'retained' ) )->definition() ) );
		$actions = $GLOBALS['a8csp_bgje_test_action_registrations'] ?? null;
		$filters = $GLOBALS['a8csp_bgje_test_filter_registrations'] ?? null;
		self::assertIsArray( $actions );
		self::assertIsArray( $filters );

		$plugin->boot();

		$duplicate = $jobs->register( ( new RecordingJob( 'retained' ) )->definition() );
		self::assertInstanceOf( \WP_Error::class, $duplicate );
		self::assertSame( 'already_registered', $duplicate->get_error_code() );
		self::assertSame( $actions, $GLOBALS['a8csp_bgje_test_action_registrations'] );
		self::assertSame( $filters, $GLOBALS['a8csp_bgje_test_filter_registrations'] );
	}

	/**
	 * A boot throw poisons the retained plugin instance and the public portal reports unavailability.
	 *
	 * @return  void
	 */
	public function test_failed_boot_leaves_public_portal_operations_unavailable(): void {
		$GLOBALS['wpdb'] = new \stdClass();
		$throwable       = null;
		$plugin          = \a8csp_bgje_plugin();
		try {
			$plugin->boot();
		} catch ( \TypeError $caught ) {
			$throwable = $caught;
		}

		self::assertInstanceOf( \TypeError::class, $throwable );
		self::assertFalse( $plugin->is_booted() );
		$GLOBALS['wpdb'] = new WpdbLockSpy();
		$plugin->boot();
		self::assertFalse( $plugin->is_booted() );

		$GLOBALS['a8csp_bgje_test_did_actions'] = array( 'init' => 1 );

		$error = \a8csp_bgje( 'plugin-boot-gate' )->jobs()->dispatch( 'job' );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'engine_unavailable', $error->get_error_code() );
		self::assertSame( 'The background jobs engine graph is unavailable before its plugins_loaded boot callback completes successfully; invoke engine operations from init or a later hook.', $error->get_error_message() );
		self::assertNull( $error->get_error_data() );
	}

	// endregion.
}

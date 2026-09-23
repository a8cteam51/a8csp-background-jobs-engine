<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Proves the metadata reader tracks plugin load order: extra plugin headers exist only once the
 * plugin registering them has loaded, and this plugin can load first. The include-time
 * requirements read must therefore not be memoized into the reads taken from `plugins_loaded` on.
 *
 * The reader keeps per-request state in a function static, so the test runs in its own process
 * to observe a fresh one.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class PluginMetadataCacheTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Defines the bootstrap file's constants, stages the WordPress stubs, then loads the real
	 * `functions-bootstrap.php` under test.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		\defined( 'A8CSP_BGJE_FILE' ) || \define( 'A8CSP_BGJE_FILE', \dirname( __DIR__, 2 ) . '/a8csp-background-jobs-engine.php' );

		require_once __DIR__ . '/plugin-metadata-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/functions-bootstrap.php';
	}

	// endregion.

	// region TESTS.

	/**
	 * Walks the reader through the request timeline: the include-time read (before any plugin
	 * beyond this one has loaded) misses the extra header and must not be cached; the
	 * `plugins_loaded` read sees it and latches; later mutations no longer change the answer.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_plugins_loaded_read_sees_headers_registered_after_include_time(): void {
		$GLOBALS['a8csp_bgje_test_did_actions'] = array();
		$GLOBALS['a8csp_bgje_test_plugin_data'] = array( 'Name' => 'A8CSP Background Jobs Engine' );
		self::assertNull( \a8csp_bgje_get_plugin_metadata( 'WC requires at least' ), 'The include-time read runs before the registering plugin loads, so the extra header is absent' );

		$GLOBALS['a8csp_bgje_test_did_actions'] = array( 'plugins_loaded' => 1 );
		$GLOBALS['a8csp_bgje_test_plugin_data'] = array(
			'Name'                 => 'A8CSP Background Jobs Engine',
			'WC requires at least' => '11.1',
		);
		self::assertSame( '11.1', \a8csp_bgje_get_plugin_metadata( 'WC requires at least' ), 'A read at plugins_loaded must see the header another plugin registered after this plugin was included' );

		$GLOBALS['a8csp_bgje_test_plugin_data'] = array( 'Name' => 'mutated' );
		self::assertSame( '11.1', \a8csp_bgje_get_plugin_metadata( 'WC requires at least' ), 'A read taken once every plugin has loaded is stable and memoized for the request' );
	}

	// endregion.
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Plugin;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the plugin boots on a supported runtime inside wp-env: the requirements gate passes,
 * the plugin boot callback is wired, and the accessor retains the booted plugin instance.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[Group( 'degraded' )]
final class PluginBootTest extends IntegrationTestCase {
	// region TESTS.

	/**
	 * On an at-floor runtime the requirements gate passes and `plugins_loaded` is wired to the
	 * plugin's boot callback at the default priority.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_plugin_boots_on_supported_runtime(): void {
		self::assertTrue( \constant( 'A8CSP_BGJE_REQUIREMENTS_RESULT' ) );
		self::assertTrue( \function_exists( 'a8csp_bgje_plugin' ) );
		self::assertSame( 10, has_action( 'plugins_loaded', array( \a8csp_bgje_plugin(), 'boot' ) ) );
		self::assertTrue( \a8csp_bgje_plugin()->is_booted() );
	}

	/**
	 * `Plugin::boot()` is idempotent: the `plugins_loaded` boot has already run, and a second call
	 * leaves the accessor's retained instance unchanged.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_second_boot_does_not_replace_cached_plugin(): void {
		$plugin = \a8csp_bgje_plugin();
		self::assertInstanceOf( Plugin::class, $plugin );

		$plugin->boot();

		self::assertSame( $plugin, \a8csp_bgje_plugin() );
	}

	// endregion.
}

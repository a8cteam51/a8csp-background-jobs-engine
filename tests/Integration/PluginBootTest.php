<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Plugin;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the plugin boots on a supported runtime inside wp-env: the requirements gate passes,
 * the named accessor is wired, and repeated access returns the booted plugin instance.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[Group( 'degraded' )]
final class PluginBootTest extends IntegrationTestCase {
	// region TESTS.

	/**
	 * On an at-floor runtime the requirements gate passes, `plugins_loaded` is wired to the named
	 * plugin accessor at priority zero; WordPress ignores the action callback's return value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_plugin_boots_on_supported_runtime(): void {
		self::assertTrue( \constant( 'A8CSP_BGTE_REQUIREMENTS_RESULT' ) );
		self::assertTrue( \function_exists( 'a8csp_bgte_plugin' ) );
		self::assertSame( 0, has_action( 'plugins_loaded', 'a8csp_bgte_plugin' ) );
		self::assertInstanceOf( Plugin::class, a8csp_bgte_plugin() );
	}

	/**
	 * `Plugin::boot()` is idempotent: the `plugins_loaded` boot has already run, and a second call
	 * leaves the accessor's cached instance unchanged.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_second_boot_does_not_replace_cached_plugin(): void {
		$plugin = a8csp_bgte_plugin();
		self::assertInstanceOf( Plugin::class, $plugin );

		$plugin->boot();

		self::assertSame( $plugin, a8csp_bgte_plugin() );
	}

	// endregion.
}

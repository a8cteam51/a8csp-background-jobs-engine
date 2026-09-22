<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\AbstractIntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the plugin boots on a supported runtime inside wp-env: the requirements gate passes,
 * the plugin boot callback is wired, and the accessor retains the booted plugin instance.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[Group( 'degraded' )]
final class PluginBootTest extends AbstractIntegrationTestCase {
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
		self::assertTrue( \a8csp_bgje_validate_requirements() );
		self::assertTrue( \function_exists( 'a8csp_bgje_plugin' ) );
		self::assertSame( 10, has_action( 'plugins_loaded', array( \a8csp_bgje_plugin(), 'boot' ) ) );
		self::assertTrue( \a8csp_bgje_plugin()->is_booted() );
		$result = \a8csp_bgje( 'plugin-boot-test' )->jobs()->dispatch( 'unregistered' );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'unknown_job', $result->get_error_code() );
	}

	/**
	 * The entry file registers both self-updater filters, so an installed copy is offered releases
	 * and its View details request is answered locally.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_self_updater_filters_are_registered(): void {
		self::assertSame( 10, has_filter( 'update_plugins_github.com', 'a8csp_bgje_check_github_release_update' ) );
		self::assertSame( 10, has_filter( 'plugins_api', 'a8csp_bgje_get_github_release_information' ) );
	}

	// endregion.
}

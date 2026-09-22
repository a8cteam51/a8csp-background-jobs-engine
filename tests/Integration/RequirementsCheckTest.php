<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\AbstractIntegrationTestCase;

/**
 * Verifies the requirements gate degrades gracefully on a below-floor runtime.
 *
 * Runs in both matrix entries: at-floor it must pass, below-floor (WP 7.0.4)
 * it must yield a WP_Error without loading the plugin proper.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class RequirementsCheckTest extends AbstractIntegrationTestCase {
	// region TESTS.

	/**
	 * The requirements result reflects the runtime it booted on.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_requirements_gate_matches_runtime(): void {
		$requirements = \a8csp_bgje_validate_requirements();
		$wp_version   = \get_bloginfo( 'version' );

		if ( \version_compare( $wp_version, '7.1', '<' ) ) {
			self::assertInstanceOf( \WP_Error::class, $requirements );
			self::assertFalse( \function_exists( 'a8csp_bgje' ) );
		} else {
			self::assertTrue( $requirements );
		}
	}

	// endregion.
}

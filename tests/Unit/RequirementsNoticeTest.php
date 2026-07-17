<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the requirements failure notice is visible across every WordPress admin context.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversFunction( 'a8csp_bgte_output_requirements_error' )]
final class RequirementsNoticeTest extends TestCase {
	/**
	 * The shared admin-notice hook covers both site and network admin headers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_requirements_failure_registers_the_shared_admin_notice_hook(): void {
		\defined( 'ABSPATH' ) || \define( 'ABSPATH', __DIR__ . '/' );
		require_once __DIR__ . '/wp-update-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/functions-bootstrap.php';

		$GLOBALS['a8csp_bgte_test_hooks']                = array();
		$GLOBALS['a8csp_bgte_test_action_registrations'] = array();

		\a8csp_bgte_output_requirements_error( new \WP_Error( 'requirements_failed' ) );

		self::assertSame( array( 'all_admin_notices' ), $GLOBALS['a8csp_bgte_test_hooks'] );
	}
}

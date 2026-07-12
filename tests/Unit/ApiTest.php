<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the procedural API before the engine component has booted.
 *
 */
#[CoversFunction( 'a8csp_bgte_engine' )]
#[CoversFunction( 'a8csp_bgte_enqueue_task' )]
#[CoversFunction( 'a8csp_bgte_start_batch' )]
#[CoversFunction( 'a8csp_bgte_retry_failed_run' )]
#[CoversFunction( 'a8csp_bgte_sync_schedules' )]
final class ApiTest extends TestCase {
	/**
	 * Access remains nullable and every mutation names the boot-order correction.
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_pre_boot_api_returns_failures_without_fatal_errors(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require \dirname( __DIR__, 2 ) . '/includes/api.php';

		self::assertNull( \a8csp_bgte_engine() );

		$results = array(
			\a8csp_bgte_enqueue_task( 'email-digest', array( 'site_id' => 7 ), delay: 30, unique: true, priority: 5 ),
			\a8csp_bgte_start_batch( 'catalog-sync', array( 'site_id' => 7 ), unique: true, priority: 23 ),
			\a8csp_bgte_retry_failed_run( 'email-digest', 'run-1' ),
			\a8csp_bgte_sync_schedules( 'consumer-plugin', array() ),
		);

		foreach ( $results as $result ) {
			self::assertInstanceOf( Failure::class, $result );
			self::assertInstanceOf( EngineError::class, $result->error );
			self::assertSame(
				'The background tasks engine is unavailable; call after the engine boots on plugins_loaded.',
				$result->error->message
			);
		}
	}
}

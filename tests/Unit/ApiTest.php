<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Failure;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the procedural API lifecycle guard and unavailable-engine fallbacks.
 *
 */
#[CoversFunction( 'a8csp_bgte_engine' )]
#[CoversFunction( 'a8csp_bgte_enqueue_task' )]
#[CoversFunction( 'a8csp_bgte_start_batch' )]
#[CoversFunction( 'a8csp_bgte_retry_failed_run' )]
#[CoversFunction( 'a8csp_bgte_cancel_run' )]
#[CoversFunction( 'a8csp_bgte_sync_schedules' )]
#[CoversFunction( 'a8csp_bgte_run_schedule_now' )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ApiTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Loads the WordPress lifecycle and diagnostic seams with the procedural API.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once __DIR__ . '/wp-cron-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/functions.php';
	}

	/**
	 * Resets the lifecycle script and incorrect-use ledger.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_did_actions']          = array();
		$GLOBALS['a8csp_bgte_test_doing_it_wrong_calls'] = array();
	}

	// endregion.

	// region TESTS.

	/**
	 * Access before plugins_loaded reports the correction and returns null.
	 *
	 * @return  void
	 */
	public function test_engine_access_before_plugins_loaded_reports_incorrect_use(): void {
		self::assertNull( \a8csp_bgte_engine() );
		self::assertSame(
			array(
				array(
					'function_name' => 'a8csp_bgte_engine',
					'message'       => 'Call a8csp_bgte_engine() after plugins_loaded, when the engine has booted.',
					'version'       => '1.0.0',
				),
			),
			$GLOBALS['a8csp_bgte_test_doing_it_wrong_calls']
		);
	}

	/**
	 * Access after plugins_loaded returns the engine retained by the component.
	 *
	 * @return  void
	 */
	public function test_engine_access_after_plugins_loaded_delegates_to_the_component(): void {
		$GLOBALS['a8csp_bgte_test_did_actions'] = array( 'plugins_loaded' => 1 );

		$engine          = ( new \ReflectionClass( Engine::class ) )->newInstanceWithoutConstructor();
		$engine_property = new \ReflectionProperty( Component::class, 'engine' );
		$engine_property->setValue( null, $engine );

		self::assertSame( $engine, \a8csp_bgte_engine() );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_doing_it_wrong_calls'] );
	}

	/**
	 * Every mutation returns its unavailable-engine failure after the lifecycle gate.
	 *
	 * @return  void
	 */
	public function test_post_boot_api_returns_failures_when_the_component_is_unavailable(): void {
		$GLOBALS['a8csp_bgte_test_did_actions'] = array( 'plugins_loaded' => 1 );

		$results = array(
			\a8csp_bgte_enqueue_task( 'email-digest', array( 'site_id' => 7 ), delay: 30, unique: true, priority: 5 ),
			\a8csp_bgte_start_batch( 'catalog-sync', array( 'site_id' => 7 ), unique: true, priority: 23 ),
			\a8csp_bgte_retry_failed_run( 'email-digest', 'run-1' ),
			\a8csp_bgte_cancel_run( 'email-digest', 'run-1' ),
			\a8csp_bgte_sync_schedules( 'consumer-plugin', array() ),
			\a8csp_bgte_run_schedule_now( 'consumer-plugin', 'nightly' ),
		);

		foreach ( $results as $result ) {
			self::assertInstanceOf( Failure::class, $result );
			self::assertInstanceOf( EngineError::class, $result->error );
			self::assertSame(
				'The background tasks engine is unavailable; call after the engine boots on plugins_loaded.',
				$result->error->message
			);
		}

		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_doing_it_wrong_calls'] );
	}

	// endregion.
}

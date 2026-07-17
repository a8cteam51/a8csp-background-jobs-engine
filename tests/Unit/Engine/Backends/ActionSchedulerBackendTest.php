<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Backends;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingErrorReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Action Scheduler procedural boundary that cannot run through EngineRig.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( ActionSchedulerBackend::class )]
final class ActionSchedulerBackendTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string HOOK = 'a8csp_bgte_test_hook';

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded Action Scheduler functions before the backend is autoloaded.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 2 ) . '/wp-hook-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/as-function-stubs.php';
	}

	/**
	 * Resets the procedural boundary state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_as_calls']    = array();
		$GLOBALS['a8csp_bgte_test_as_results']  = array();
		$GLOBALS['a8csp_bgte_test_did_actions'] = array(
			'init'                  => 1,
			'action_scheduler_init' => 1,
		);
	}

	// endregion.

	// region TESTS.

	/**
	 * Runtime readiness follows Action Scheduler initialization while absence follows its function table.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_runtime_facts_distinguish_readiness_from_absence(): void {
		$backend = new ActionSchedulerBackend();
		self::assertTrue( $backend->is_ready() );
		self::assertFalse( $backend->is_absent() );

		$GLOBALS['a8csp_bgte_test_did_actions'] = array( 'init' => 1 );
		self::assertFalse( $backend->is_ready() );
		self::assertFalse( $backend->is_absent() );
	}

	/**
	 * Successful writes preserve the Action Scheduler identity and uniqueness contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_writes_preserve_vendor_identity_and_uniqueness_arguments(): void {
		$backend = new ActionSchedulerBackend();

		$recurring = $backend->schedule_recurring( self::HOOK, 300, array( 'schedule-17' ), 1_700_000_300, 'reports', 23 );
		$async     = $backend->enqueue_async( self::HOOK, array( 'run-18' ), 'reports|run-18', 31 );

		self::assertInstanceOf( Success::class, $recurring );
		self::assertInstanceOf( Success::class, $async );
		self::assertSame( array( 1_700_000_300, 300, self::HOOK, array( 'schedule-17' ), 'reports', true, 23 ), $this->calls( 'as_schedule_recurring_action' )[0]['args'] );
		self::assertSame( array( self::HOOK, array( 'run-18' ), 'reports|run-18', true, 31 ), $this->calls( 'as_enqueue_async_action' )[0]['args'] );
	}

	/**
	 * An unready adapter fails before calling any Action Scheduler write function.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unready_adapter_rejects_writes_without_touching_the_vendor_api(): void {
		$GLOBALS['a8csp_bgte_test_did_actions'] = array( 'init' => 1 );

		$result = ( new ActionSchedulerBackend() )->enqueue_async( self::HOOK );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::BackendNotReady, $result->error->reason );
		self::assertSame( array(), $this->calls() );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns recorded Action Scheduler calls, optionally filtered by function.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $function_name Function name, or null for every call.
	 *
	 * @return  list<array{function: string, args: list<mixed>}>
	 */
	private function calls( ?string $function_name = null ): array {
		/** @var list<array{function: string, args: list<mixed>}> $calls */
		$calls = $GLOBALS['a8csp_bgte_test_as_calls'];

		return null === $function_name
			? $calls
			: \array_values( \array_filter( $calls, static fn ( array $call ): bool => $function_name === $call['function'] ) );
	}

	// endregion.
}

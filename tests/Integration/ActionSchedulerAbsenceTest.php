<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies Action Scheduler absence through the live degraded runtime.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[Group( 'degraded' )]
final class ActionSchedulerAbsenceTest extends IntegrationTestCase {
	// region LIFECYCLE.

	/**
	 * Restricts the test to the Action Scheduler-absent environment.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		if ( \function_exists( 'as_enqueue_async_action' ) ) {
			self::markTestSkipped( 'This test requires the Action Scheduler-absent environment.' );
		}
	}

	// endregion.

	// region TESTS.

	/**
	 * An absent Action Scheduler reports its state and rejects writes without calling vendor code.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_absent_action_scheduler_rejects_writes_without_fataling(): void {
		$backend = new ActionSchedulerBackend();
		self::assertTrue( $backend->is_absent() );
		self::assertFalse( $backend->is_ready() );

		$result = $backend->enqueue_async( 'a8csp_bgte/integration/action_scheduler_absence' );
		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::BackendNotReady, $result->error->reason );
	}

	// endregion.
}

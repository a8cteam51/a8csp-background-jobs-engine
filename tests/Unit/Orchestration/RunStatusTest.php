<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Orchestration;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\RunStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the run-status vocabulary and terminal-state boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RunStatus::class )]
final class RunStatusTest extends TestCase {

	/**
	 * Satisfies the production file's `ABSPATH` boot guard before first autoload.
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
	}

	/**
	 * The five cases and their persisted values remain an exact closed set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cases_and_backing_values_are_exact(): void {
		$statuses = RunStatus::cases();

		self::assertSame(
			array(
				RunStatus::Running,
				RunStatus::Completed,
				RunStatus::Failed,
				RunStatus::Stopped,
				RunStatus::Superseded,
			),
			$statuses
		);
		self::assertSame(
			array(
				'running',
				'completed',
				'failed',
				'stopped',
				'superseded',
			),
			\array_map( static fn ( RunStatus $status ): string => $status->value, $statuses )
		);
	}

	/**
	 * Running is the sole status from which the orchestrator can transition.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_terminal_truth_table(): void {
		self::assertFalse( RunStatus::Running->is_terminal() );
		self::assertTrue( RunStatus::Completed->is_terminal() );
		self::assertTrue( RunStatus::Failed->is_terminal() );
		self::assertTrue( RunStatus::Stopped->is_terminal() );
		self::assertTrue( RunStatus::Superseded->is_terminal() );
	}
}

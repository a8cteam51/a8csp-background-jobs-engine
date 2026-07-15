<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Api\Run;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\RunStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the run-status vocabulary.
 *
 */
#[CoversClass( RunStatus::class )]
final class RunStatusTest extends TestCase {

	/**
	 * Satisfies the production file's `ABSPATH` boot guard before first autoload.
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
	 * @return  void
	 */
	public function test_cases_and_backing_values_are_exact(): void {
		$statuses = RunStatus::cases();

		self::assertSame(
			array(
				RunStatus::Running,
				RunStatus::Completed,
				RunStatus::Failed,
				RunStatus::Cancelled,
				RunStatus::Superseded,
			),
			$statuses
		);
		self::assertSame(
			array(
				'running',
				'completed',
				'failed',
				'cancelled',
				'superseded',
			),
			\array_map( static fn ( RunStatus $status ): string => $status->value, $statuses )
		);
	}
}

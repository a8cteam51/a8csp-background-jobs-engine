<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the reusable logger recorder used by orchestration tests.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversNothing]
final class RecordingLoggerTest extends TestCase {
	/**
	 * Repeated calls append complete records in invocation order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_multiple_calls_append_records_in_order(): void {
		$logger = new RecordingLogger();

		$logger->info( 'First record.', array( 'run_id' => 'run-1' ) );
		$logger->warning( 'Second record.', array( 'run_id' => 'run-2' ) );

		self::assertSame(
			array(
				array(
					'level'   => 'info',
					'message' => 'First record.',
					'context' => array( 'run_id' => 'run-1' ),
				),
				array(
					'level'   => 'warning',
					'message' => 'Second record.',
					'context' => array( 'run_id' => 'run-2' ),
				),
			),
			$logger->records
		);
	}
}

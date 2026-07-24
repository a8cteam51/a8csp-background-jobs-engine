<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the run-status vocabulary.
 *
 */
#[CoversClass( RunStatus::class )]
final class RunStatusTest extends TestCase {
	// region LIFECYCLE.

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

	// endregion.

	// region TESTS.

	/**
	 * The persisted values remain an exact order-independent closed set.
	 *
	 * @load-bearing durability
	 * @pin-rationale Run-status backing values are persisted in run rows, so their closed value set must remain decodable independent of declaration order.
	 *
	 * @return  void
	 */
	public function test_backing_values_are_an_exact_set(): void {
		self::assertEqualsCanonicalizing(
			array(
				'running',
				'completed',
				'failed',
				'cancelled',
				'superseded',
			),
			\array_map( static fn ( RunStatus $status ): string => $status->value, RunStatus::cases() )
		);
	}

	// endregion.
}

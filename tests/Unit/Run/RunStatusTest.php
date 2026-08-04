<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Run;

use A8C\SpecialProjects\BackgroundJobsEngine\RunStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the public run-status vocabulary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RunStatus::class )]
final class RunStatusTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Satisfies the production files' ABSPATH boot guard before first autoload.
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

	// endregion.

	// region TESTS.

	/**
	 * The public backing values remain an exact order-independent set.
	 *
	 * @load-bearing durability
	 * @pin-rationale The public run-status enum is persisted in run rows, so its closed backing-value set must remain decodable independent of declaration order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_backing_values_are_an_exact_set(): void {
		self::assertEqualsCanonicalizing(
			array( 'running', 'completed', 'failed', 'cancelled', 'superseded' ),
			\array_map( static fn ( RunStatus $status ): string => $status->value, RunStatus::cases() )
		);
	}

	// endregion.
}

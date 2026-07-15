<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Api\Schedule;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\CatchUpPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the closed schedule-catch-up vocabulary.
 *
 */
#[CoversClass( CatchUpPolicy::class )]
final class CatchUpPolicyTest extends TestCase {

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
	 * The two cases and their persisted values remain an exact closed set.
	 *
	 * @return  void
	 */
	public function test_cases_and_backing_values_are_exact(): void {
		$policies = CatchUpPolicy::cases();

		self::assertSame(
			array( CatchUpPolicy::RunOnce, CatchUpPolicy::Skip ),
			$policies
		);
		self::assertSame(
			array( 'run_once', 'skip' ),
			\array_map( static fn ( CatchUpPolicy $policy ): string => $policy->value, $policies )
		);
	}
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Api\Schedule;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\OverlapPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the closed schedule-overlap vocabulary.
 *
 */
#[CoversClass( OverlapPolicy::class )]
final class OverlapPolicyTest extends TestCase {

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
	 * The three cases and their persisted values remain an exact closed set.
	 *
	 * @return  void
	 */
	public function test_cases_and_backing_values_are_exact(): void {
		$policies = OverlapPolicy::cases();

		self::assertSame(
			array(
				OverlapPolicy::Allow,
				OverlapPolicy::Skip,
				OverlapPolicy::Replace,
			),
			$policies
		);
		self::assertSame(
			array( 'allow', 'skip', 'replace' ),
			\array_map( static fn ( OverlapPolicy $policy ): string => $policy->value, $policies )
		);
	}
}

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
	 * The public backing values remain an exact order-independent set.
	 *
	 * @return  void
	 */
	public function test_backing_values_are_an_exact_set(): void {
		self::assertEqualsCanonicalizing( array( 'allow', 'skip', 'replace' ), \array_map( static fn ( OverlapPolicy $policy ): string => $policy->value, OverlapPolicy::cases() ) );
	}
}

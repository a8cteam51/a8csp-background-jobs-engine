<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Schedule;

use A8C\SpecialProjects\BackgroundJobsEngine\CatchUpPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the closed schedule-catch-up vocabulary.
 *
 */
#[CoversClass( CatchUpPolicy::class )]
final class CatchUpPolicyTest extends TestCase {
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
	 * The public backing values remain an exact order-independent set.
	 *
	 * @return  void
	 */
	public function test_backing_values_are_an_exact_set(): void {
		self::assertEqualsCanonicalizing( array( 'run_once', 'skip' ), \array_map( static fn ( CatchUpPolicy $policy ): string => $policy->value, CatchUpPolicy::cases() ) );
	}

	// endregion.
}

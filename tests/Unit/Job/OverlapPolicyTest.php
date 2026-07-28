<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\OverlapPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the closed schedule-overlap vocabulary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( OverlapPolicy::class )]
final class OverlapPolicyTest extends TestCase {
	// region LIFECYCLE.

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

	// endregion.

	// region TESTS.

	/**
	 * The public backing values remain an exact order-independent set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_backing_values_are_an_exact_set(): void {
		self::assertEqualsCanonicalizing( array( 'allow', 'reject', 'replace' ), \array_map( static fn ( OverlapPolicy $policy ): string => $policy->value, OverlapPolicy::cases() ) );
	}

	// endregion.
}

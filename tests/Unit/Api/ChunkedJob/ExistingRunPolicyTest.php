<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Api\ChunkedJob;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ExistingRunPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the closed existing-chunked-job-run policy vocabulary.
 */
#[CoversClass( ExistingRunPolicy::class )]
final class ExistingRunPolicyTest extends TestCase {

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
		self::assertEqualsCanonicalizing( array( 'reject', 'replace' ), \array_map( static fn ( ExistingRunPolicy $policy ): string => $policy->value, ExistingRunPolicy::cases() ) );
	}
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Api\Batch;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\ExistingRunPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the closed existing-batch-run policy vocabulary.
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
	 * The two cases and their backing values remain an exact closed set.
	 *
	 * @return  void
	 */
	public function test_cases_and_backing_values_are_exact(): void {
		$policies = ExistingRunPolicy::cases();

		self::assertSame(
			array(
				ExistingRunPolicy::Reject,
				ExistingRunPolicy::Replace,
			),
			$policies
		);
		self::assertSame( array( 'reject', 'replace' ), \array_map( static fn ( ExistingRunPolicy $policy ): string => $policy->value, $policies ) );
	}
}

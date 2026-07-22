<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Exercises the production clock against the current system instant.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( SystemClock::class )]
final class SystemClockTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Satisfies the production file's direct-access guard.
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
	 * The reported instant falls within the surrounding system-time reads.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_now_returns_the_current_system_instant(): void {
		$clock  = new SystemClock();
		$before = \time();
		$now    = self::read_clock( $clock );
		$after  = \time();

		self::assertGreaterThanOrEqual( $before, $now->getTimestamp() );
		self::assertLessThanOrEqual( $after, $now->getTimestamp() );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Reads an instant through the stable clock contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ClockInterface $clock Clock implementation under test.
	 *
	 * @return  \DateTimeImmutable
	 */
	private static function read_clock( ClockInterface $clock ): \DateTimeImmutable {
		return $clock->now();
	}

	// endregion.
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Utilities\Clock;

use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Clock\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the production clock against the current system instant.
 *
 */
#[CoversClass( SystemClock::class )]
final class SystemClockTest extends TestCase {
	/**
	 * Satisfies the production file's direct-access guard.
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
	 * The reported instant falls within the surrounding system-time reads.
	 *
	 * @return  void
	 */
	public function test_now_returns_the_current_system_instant(): void {
		$before = \time();
		$now    = ( new SystemClock() )->now();
		$after  = \time();

		self::assertGreaterThanOrEqual( $before, $now->getTimestamp() );
		self::assertLessThanOrEqual( $after, $now->getTimestamp() );
	}
}

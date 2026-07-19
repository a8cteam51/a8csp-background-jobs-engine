<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the deterministic clock fixture used by orchestration tests.
 *
 */
#[CoversNothing]
final class FixedClockTest extends TestCase {
	/**
	 * Construction from a zoned instant retains its Unix timestamp.
	 *
	 * @return  void
	 */
	public function test_non_utc_instant_retains_the_same_epoch_integer(): void {
		$instant = new \DateTimeImmutable( '2026-07-12 14:30:00', new \DateTimeZone( 'Pacific/Auckland' ) );

		$clock = new FixedClock( $instant );

		self::assertSame( $instant->getTimestamp(), $clock->now()->getTimestamp() );
	}
}

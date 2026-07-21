<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Internal\Schedule;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Schedule\Recurrence;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins fixed-interval recurrence construction.
 *
 */
#[CoversClass( Recurrence::class )]
final class RecurrenceTest extends TestCase {

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
	 * The minimum fixed interval remains valid.
	 *
	 * @return  void
	 */
	public function test_every_retains_a_positive_interval(): void {
		$recurrence = Recurrence::every( 1 );

		self::assertSame( 1, $recurrence->interval );
		self::assertNull( $recurrence->anchor );
		self::assertSame(
			array(
				'type'  => 'every',
				'value' => 1,
			),
			$recurrence->fingerprint_value()
		);
	}

	/**
	 * Anchored recurrences retain one canonical UTC epoch phase.
	 *
	 * @return  void
	 */
	public function test_every_anchored_reduces_and_fingerprints_the_utc_phase(): void {
		$recurrence = Recurrence::every_anchored( 86_400, 90_000 );

		self::assertSame( 86_400, $recurrence->interval );
		self::assertSame( 3_600, $recurrence->anchor );
		self::assertSame(
			array(
				'type'   => 'every',
				'value'  => 86_400,
				'anchor' => 3_600,
			),
			$recurrence->fingerprint_value()
		);
	}

	/**
	 * Zero names the positive interval the caller must supply.
	 *
	 * @return  void
	 */
	public function test_every_rejects_zero_with_the_fix(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Recurrence interval must be positive; pass a value of at least one second.' );

		Recurrence::every( 0 );
	}

	/**
	 * Negative values name the positive interval the caller must supply.
	 *
	 * @return  void
	 */
	public function test_every_rejects_a_negative_interval_with_the_fix(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Recurrence interval must be positive; pass a value of at least one second.' );

		Recurrence::every( -1 );
	}

	/**
	 * Anchored recurrence intervals retain the positive lower boundary.
	 *
	 * @return  void
	 */
	public function test_every_anchored_rejects_zero_with_the_fix(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Recurrence interval must be positive; pass a value of at least one second.' );

		Recurrence::every_anchored( 0, 0 );
	}

	/**
	 * Negative anchored recurrence intervals retain the positive lower boundary.
	 *
	 * @return  void
	 */
	public function test_every_anchored_rejects_a_negative_interval_with_the_fix(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Recurrence interval must be positive; pass a value of at least one second.' );

		Recurrence::every_anchored( -1, 0 );
	}

	/**
	 * Negative anchors identify the non-negative UTC phase the caller must supply.
	 *
	 * @return  void
	 */
	public function test_every_anchored_rejects_a_negative_anchor_with_the_fix(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Recurrence anchor must be non-negative; pass a UTC phase offset of zero seconds or greater.' );

		Recurrence::every_anchored( 300, -1 );
	}
}

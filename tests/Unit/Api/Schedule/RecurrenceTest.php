<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Api\Schedule;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Recurrence;
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

		self::assertSame( 1, $recurrence->interval() );
		self::assertSame(
			array(
				'type'  => 'every',
				'value' => 1,
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
}

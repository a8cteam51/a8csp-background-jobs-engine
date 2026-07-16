<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\EngineRig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the arithmetic boundary that lifecycle behavior cannot reach in finite time.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RunState::class )]
final class RunStateTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Loads guarded production files before the value object is exercised.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		EngineRig::bootstrap();
	}

	// endregion.

	// region TESTS.

	/**
	 * Attempt increments remain positive and saturate instead of overflowing persisted integers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_attempt_increment_is_positive_and_saturating(): void {
		self::assertSame( 1, RunState::increment_attempts_safely( -5 ) );
		self::assertSame( 1, RunState::increment_attempts_safely( 0 ) );
		self::assertSame( 3, RunState::increment_attempts_safely( 2 ) );
		self::assertSame( \PHP_INT_MAX, RunState::increment_attempts_safely( \PHP_INT_MAX ) );
	}

	// endregion.
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\Engine;
use A8C\SpecialProjects\BackgroundJobsEngine\Jobs;
use A8C\SpecialProjects\BackgroundJobsEngine\Runs;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedules;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the owner-bound handle's capability-manager wiring.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Engine::class )]
final class EngineTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Loads the public functions and engine test seams.
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
	 * Each portal returns the owner-bound manager for its capability.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_portals_return_capability_managers(): void {
		$engine = \a8csp_bgje( 'engine-test' );

		self::assertInstanceOf( Jobs::class, $engine->jobs() );
		self::assertInstanceOf( Schedules::class, $engine->schedules() );
		self::assertInstanceOf( Runs::class, $engine->runs() );
	}

	// endregion.
}

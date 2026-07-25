<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Boundary;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the public admission-failure value.
 *
 */
#[CoversClass( BoundaryError::class )]
final class BoundaryErrorTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Satisfies the production files' `ABSPATH` boot guard before first autoload.
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
	 * Callers without safe structured detail receive an empty context.
	 *
	 * @return  void
	 */
	public function test_context_defaults_to_an_empty_array(): void {
		$error = new BoundaryError( ErrorCode::BackendUnavailable, 'No backend is ready.' );

		self::assertSame( array(), $error->context );
	}

	// endregion.
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Boundary;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\ErrorInterface;
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
	 * The error exposes its stable code, engine-authored message, and structured context exactly.
	 *
	 * @return  void
	 */
	public function test_carries_the_public_admission_failure_contract(): void {
		$context = array( 'run_id' => 'run-incumbent' );
		$error   = new BoundaryError( ErrorCode::OverlapHeld, 'The work is already running.', $context );

		self::assertInstanceOf( ErrorInterface::class, $error );
		self::assertSame( ErrorCode::OverlapHeld, $error->code );
		self::assertSame( 'The work is already running.', $error->message );
		self::assertSame( $context, $error->context );
	}

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

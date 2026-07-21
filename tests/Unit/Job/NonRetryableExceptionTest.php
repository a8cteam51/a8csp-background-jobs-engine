<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\NonRetryableException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the client-ready non-retryable exception hierarchy.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( NonRetryableException::class )]
final class NonRetryableExceptionTest extends TestCase {

	/**
	 * Satisfies the production files' `ABSPATH` boot guard before first autoload.
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

	/**
	 * The concrete exception remains catchable as a runtime exception.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_is_a_runtime_exception(): void {
		$exception = new NonRetryableException( 'Retrying cannot succeed.' );

		self::assertInstanceOf( \RuntimeException::class, $exception );
	}

	/**
	 * A client subclass stays within the hierarchy the engine treats as non-retryable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_a_subclass_stays_non_retryable(): void {
		$exception = new class( 'Retrying cannot succeed.' ) extends NonRetryableException {};

		self::assertInstanceOf( NonRetryableException::class, $exception );
	}
}

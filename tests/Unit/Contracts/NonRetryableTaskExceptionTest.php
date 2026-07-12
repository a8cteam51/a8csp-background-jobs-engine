<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Contracts;

use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\NonRetryableExceptionInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\NonRetryableTaskException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the consumer-ready non-retryable exception hierarchy.
 *
 */
#[CoversClass( NonRetryableTaskException::class )]
final class NonRetryableTaskExceptionTest extends TestCase {

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

	/**
	 * The concrete exception is catchable by both the runtime and retry-bypass contracts.
	 *
	 * @return  void
	 */
	public function test_is_a_runtime_exception_with_the_non_retryable_marker(): void {
		$exception = new NonRetryableTaskException( 'Retrying cannot succeed.' );

		self::assertInstanceOf( \RuntimeException::class, $exception );
		self::assertInstanceOf( NonRetryableExceptionInterface::class, $exception );
	}
}

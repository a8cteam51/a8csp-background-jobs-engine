<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Api\Error;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ErrorInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the consumer-visible terminal failure value.
 *
 */
#[CoversClass( RunFailure::class )]
final class RunFailureTest extends TestCase {

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
	 * Every terminal failure field remains directly observable.
	 *
	 * @return  void
	 */
	public function test_constructor_retains_the_complete_failure(): void {
		$failure = new RunFailure( name: 'recount-comments', run_id: 'run-7', attempts: 3, stage: 'execution', code: ApiErrorCode::ExecutionFailed, summary: 'Background-work execution failed because RuntimeException was thrown.', failed_chunk: array( 'post_id' => 42 ), );

		self::assertInstanceOf( ErrorInterface::class, $failure );
		self::assertSame( 'recount-comments', $failure->name );
		self::assertSame( 'run-7', $failure->run_id );
		self::assertSame( 3, $failure->attempts );
		self::assertSame( 'execution', $failure->stage );
		self::assertSame( ApiErrorCode::ExecutionFailed, $failure->code );
		self::assertSame( 'Background-work execution failed because RuntimeException was thrown.', $failure->summary );
		self::assertSame( array( 'post_id' => 42 ), $failure->failed_chunk );
	}
}

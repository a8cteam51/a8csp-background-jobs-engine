<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Orchestration;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the engine failure detail and its absent exception-class default.
 *
 */
#[CoversClass( EngineError::class )]
final class EngineErrorTest extends TestCase {

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
	 * Message and exception class retain the caller's exact values.
	 *
	 * @return  void
	 */
	public function test_carries_message_and_exception_class_unchanged(): void {
		$error = new EngineError(
			message: 'Index refresh failed.',
			exception_class: \RuntimeException::class,
		);

		self::assertSame( 'Index refresh failed.', $error->message );
		self::assertSame( \RuntimeException::class, $error->exception_class );
		self::assertObjectNotHasProperty( 'context', $error );
	}

	/**
	 * Callers without an exception class receive a null default.
	 *
	 * @return  void
	 */
	public function test_defaults_exception_class(): void {
		$error = new EngineError( 'Work failed.' );

		self::assertSame( 'Work failed.', $error->message );
		self::assertNull( $error->exception_class );
		self::assertObjectNotHasProperty( 'context', $error );
	}
}

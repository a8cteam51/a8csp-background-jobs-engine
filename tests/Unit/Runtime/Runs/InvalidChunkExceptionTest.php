<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\InvalidChunkException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the trusted construction boundary for retained invalid-chunk diagnostics.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( InvalidChunkException::class )]
final class InvalidChunkExceptionTest extends TestCase {
	/**
	 * Defines the guarded plugin runtime before the internal exception is autoloaded.
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
	 * Consumers cannot inject arbitrary text through direct construction.
	 *
	 * @load-bearing security
	 * @pin-rationale Constructor visibility is the only evidence that arbitrary consumer strings cannot enter the trusted failure-detail channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_constructor_is_private(): void {
		$constructor = ( new \ReflectionClass( InvalidChunkException::class ) )->getConstructor();

		self::assertNotNull( $constructor );
		self::assertTrue( $constructor->isPrivate() );
	}

	/**
	 * Engine factories produce only stable byte-count diagnostics from integer inputs.
	 *
	 * @load-bearing security
	 * @pin-rationale Exact factory bytes prove every trusted failure detail is engine-authored and contains only bounded integer interpolation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_factories_produce_byte_exact_engine_authored_messages(): void {
		self::assertSame( 'Chunked Job chunk arguments must contain only null, scalar, or nested array values.', InvalidChunkException::nonPortable()->getMessage() );
		self::assertSame( 'Chunked Job chunk arguments contain 8193 JSON bytes; the limit is 8192 bytes.', InvalidChunkException::chunkTooLarge( 8_193, 8_192 )->getMessage() );
		self::assertSame( 'Chunked Job queue contains 1048577 persisted serialization bytes; the limit is 1048576 bytes.', InvalidChunkException::queueTooLarge( 1_048_577, 1_048_576 )->getMessage() );
	}
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Run;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the client-visible terminal failure value.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RunFailure::class )]
final class RunFailureTest extends TestCase {
	// region LIFECYCLE.

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

	// endregion.

	// region TESTS.

	/**
	 * Every terminal failure field remains directly observable, and the diagnostic payload is generic.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_constructor_retains_the_complete_failure(): void {
		$run_id  = RunId::from( '00000000001721664000-0000000000000000042' );
		$failure = new RunFailure( identity: 'consumer-plugin:recount-comments', run_id: $run_id, attempts: 3, stage: RunFailureStage::from( 'execution' ), code: ErrorCode::ExecutionFailed, summary: 'Background-work execution failed because RuntimeException was thrown.', details: array( 'failed_chunk' => array( 'post_id' => 42 ) ), );

		self::assertSame( 'consumer-plugin:recount-comments', $failure->identity );
		self::assertSame( $run_id, $failure->run_id );
		self::assertSame( 3, $failure->attempts );
		self::assertSame( RunFailureStage::from( 'execution' ), $failure->stage );
		self::assertSame( ErrorCode::ExecutionFailed, $failure->code );
		self::assertSame( 'Background-work execution failed because RuntimeException was thrown.', $failure->summary );
		self::assertSame( array( 'failed_chunk' => array( 'post_id' => 42 ) ), $failure->details );
	}

	// endregion.
}

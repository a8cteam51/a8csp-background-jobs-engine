<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the client-visible terminal failure value.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RunFailure::class )]
#[CoversClass( RunFailureStage::class )]
final class RunFailureTest extends TestCase {

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
	 * Every terminal failure field remains directly observable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_constructor_retains_the_complete_failure(): void {
		$failure = new RunFailure( identity: 'consumer-plugin:recount-comments', run_id: 'run-7', attempts: 3, stage: RunFailureStage::Execution, code: ErrorCode::ExecutionFailed, summary: 'Background-work execution failed because RuntimeException was thrown.', failed_chunk: array( 'post_id' => 42 ), );

		self::assertSame( 'consumer-plugin:recount-comments', $failure->identity );
		self::assertSame( 'run-7', $failure->run_id );
		self::assertSame( 3, $failure->attempts );
		self::assertSame( RunFailureStage::Execution, $failure->stage );
		self::assertSame( ErrorCode::ExecutionFailed, $failure->code );
		self::assertSame( 'Background-work execution failed because RuntimeException was thrown.', $failure->summary );
		self::assertSame( array( 'post_id' => 42 ), $failure->failed_chunk );
	}

	/**
	 * Every terminalization stage exposes its persisted scalar value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_stage_cases_expose_the_persisted_values(): void {
		self::assertEqualsCanonicalizing( array( 'execution', 'queue_generation', 'crash_reclaim', 'scheduling' ), \array_map( static fn ( RunFailureStage $stage ): string => $stage->value, RunFailureStage::cases() ) );
	}
}

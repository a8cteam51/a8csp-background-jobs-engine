<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the immutable run projection.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Run::class )]
final class RunTest extends TestCase {

	/**
	 * Satisfies the production files' ABSPATH boot guard before first autoload.
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
	 * Every projection field remains independently observable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_constructor_retains_each_projection_field(): void {
		$run = new Run( identity: 'consumer-plugin:recount-comments', run_id: 'run-7', status: RunStatus::Failed, );

		self::assertSame( 'consumer-plugin:recount-comments', $run->identity );
		self::assertSame( 'run-7', $run->run_id );
		self::assertSame( RunStatus::Failed, $run->status );
	}
}

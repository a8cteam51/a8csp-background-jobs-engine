<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Run;

use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunStatus;
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
	 * Every projection field remains independently observable, and the identifier is the typed value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_constructor_retains_each_projection_field(): void {
		$id  = RunId::from( '00000000001721664000-0000000000000000042' );
		$run = new Run( identity: 'consumer-plugin:recount-comments', id: $id, status: RunStatus::Failed, );

		self::assertSame( 'consumer-plugin:recount-comments', $run->identity );
		self::assertSame( $id, $run->id );
		self::assertSame( RunStatus::Failed, $run->status );
	}
}

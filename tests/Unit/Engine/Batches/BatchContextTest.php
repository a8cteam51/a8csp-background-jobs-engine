<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Batches;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches\BatchContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins attempt-local batch queue mutations and run metadata.
 *
 */
#[CoversClass( BatchContext::class )]
final class BatchContextTest extends TestCase {

	/**
	 * Satisfies the production boot guard before the context is autoloaded.
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
	 * Accessors retain the exact run identity and start arguments supplied by orchestration.
	 *
	 * @return  void
	 */
	public function test_accessors_return_the_attempt_run_metadata(): void {
		$start_args = array( 'site_id' => 7 );
		$context    = new BatchContext( 'run-7', $start_args, array() );

		self::assertSame( 'run-7', $context->get_run_id() );
		self::assertSame( $start_args, $context->get_start_args() );
	}

	/**
	 * Appends retain call order while each prepend becomes the new queue head.
	 *
	 * @return  void
	 */
	public function test_queue_mutations_remain_buffered_in_exact_processing_order(): void {
		$context = new BatchContext(
			'run-7',
			array( 'site_id' => 7 ),
			array(
				array( 'chunk' => 'existing-1' ),
				array( 'chunk' => 'existing-2' ),
			)
		);

		$context->enqueue( array( 'chunk' => 'appended-1' ) );
		$context->prepend( array( 'chunk' => 'prepended-1' ) );
		$context->prepend( array( 'chunk' => 'prepended-2' ) );
		$context->enqueue( array( 'chunk' => 'appended-2' ) );

		self::assertSame(
			array(
				array( 'chunk' => 'prepended-2' ),
				array( 'chunk' => 'prepended-1' ),
				array( 'chunk' => 'existing-1' ),
				array( 'chunk' => 'existing-2' ),
				array( 'chunk' => 'appended-1' ),
				array( 'chunk' => 'appended-2' ),
			),
			$context->get_queue()
		);
	}
}

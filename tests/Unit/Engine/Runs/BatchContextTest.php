<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\BatchContext;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\PortableArguments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins attempt-local batch queue mutations and run metadata.
 *
 */
#[CoversClass( BatchContext::class )]
#[UsesClass( PortableArguments::class )]
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

	/**
	 * Appending non-portable arguments throws without changing the buffered queue.
	 *
	 * @return  void
	 */
	public function test_enqueue_rejects_non_portable_arguments_without_mutating_the_queue(): void {
		$initial = array( array( 'chunk' => 'existing' ) );
		$context = new BatchContext( 'run-7', array( 'site_id' => 7 ), $initial );
		$caught  = null;

		try {
			$context->enqueue( array( 'private-payload' => static fn (): null => null ) );
		} catch ( \InvalidArgumentException $exception ) {
			$caught = $exception;
		}

		self::assertInstanceOf( \InvalidArgumentException::class, $caught );
		self::assertSame( 'Batch chunk arguments must contain only null, scalar, or nested array values.', $caught->getMessage() );
		self::assertSame( $initial, $context->get_queue() );
	}

	/**
	 * Prepending non-portable arguments throws without changing the buffered queue.
	 *
	 * @return  void
	 */
	public function test_prepend_rejects_non_portable_arguments_without_mutating_the_queue(): void {
		$initial = array( array( 'chunk' => 'existing' ) );
		$context = new BatchContext( 'run-7', array( 'site_id' => 7 ), $initial );
		$caught  = null;

		try {
			$context->prepend( array( 'private-payload' => new \stdClass() ) );
		} catch ( \InvalidArgumentException $exception ) {
			$caught = $exception;
		}

		self::assertInstanceOf( \InvalidArgumentException::class, $caught );
		self::assertSame( 'Batch chunk arguments must contain only null, scalar, or nested array values.', $caught->getMessage() );
		self::assertSame( $initial, $context->get_queue() );
	}
}

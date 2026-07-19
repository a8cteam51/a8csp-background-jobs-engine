<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Api\Batch;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\AbstractBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchContextInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the defaults inherited by batch implementations.
 *
 */
#[CoversClass( AbstractBatch::class )]
#[UsesClass( RetryPolicy::class )]
#[UsesClass( RunFailure::class )]
final class AbstractBatchTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Loads WordPress constants before the default retry policy is first instantiated.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 2 ) . '/wp-time-constant-stubs.php';
	}

	// endregion.

	// region TESTS.

	/**
	 * A batch inherits the shared five-minute ceiling for queue and chunk invocations.
	 *
	 * @return  void
	 */
	public function test_default_max_callback_runtime_is_five_minutes(): void {
		self::assertSame( 300, self::batch()->max_callback_runtime() );
	}

	/**
	 * A batch that supplies only its identity and work methods receives a fresh default policy.
	 *
	 * @return  void
	 */
	public function test_default_policy_matches_a_new_retry_policy_field_for_field(): void {
		$batch    = self::batch();
		$expected = new RetryPolicy();
		$actual   = $batch->get_retry_policy();

		self::assertSame( $expected->max_attempts, $actual->max_attempts );
		self::assertSame( $expected->base_delay, $actual->base_delay );
		self::assertSame( $expected->multiplier, $actual->multiplier );
		self::assertSame( $expected->max_delay, $actual->max_delay );
	}

	/**
	 * Terminal notifications are optional for subclasses.
	 *
	 * @return  void
	 */
	public function test_terminal_callbacks_are_no_ops(): void {
		$batch   = self::batch();
		$failure = new RunFailure( identity: 'consumer-plugin:refresh-index', run_id: 'run-7', attempts: 3, stage: RunFailureStage::Execution, code: ApiErrorCode::ExecutionFailed, summary: 'Background-work execution failed.', failed_chunk: array( 'post_id' => 42 ), );

		$batch->on_completed( 'run-7', array( 'post_type' => 'post' ) );
		$batch->on_failed( 'run-7', array( 'post_type' => 'post' ), $failure );

		self::addToAssertionCount( 1 );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns a concrete batch that supplies only its required work methods.
	 *
	 * @return  AbstractBatch
	 */
	private static function batch(): AbstractBatch {
		return new class() extends AbstractBatch {

			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return 'refresh-index';
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
			 *
			 * @return  iterable<array<array-key, mixed>>
			 */
			#[\Override]
			public function generate_queue( array $start_args ): iterable {
				return array();
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param   array<array-key, mixed> $chunk_args Arguments for this chunk.
			 * @param   BatchContextInterface   $context    Controlled access to this chunk's run.
			 *
			 * @return  void
			 */
			#[\Override]
			public function process_chunk( array $chunk_args, BatchContextInterface $context ): void {}

		};
	}

	// endregion.
}

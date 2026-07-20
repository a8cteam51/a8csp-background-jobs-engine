<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Api\ChunkedJob;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\AbstractChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ChunkContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\OverlapPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the defaults inherited by chunked job implementations.
 *
 */
#[CoversClass( AbstractChunkedJob::class )]
#[UsesClass( RetryPolicy::class )]
#[UsesClass( RunFailure::class )]
#[UsesClass( OverlapPolicy::class )]
final class AbstractChunkedJobTest extends TestCase {
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
	 * A chunked job inherits the shared execution and overlap invariants.
	 *
	 * @return  void
	 */
	public function test_default_execution_and_overlap_invariants_are_applied(): void {
		$chunked_job = self::chunked_job();

		self::assertSame( 300, $chunked_job->max_callback_runtime() );
		self::assertSame( OverlapPolicy::Reject, $chunked_job->overlap_policy() );
		self::assertNull( $chunked_job->overlap_key( array( 'site_id' => 7 ) ) );
	}

	/**
	 * A chunked job that supplies only its identity and work methods receives a fresh default policy.
	 *
	 * @return  void
	 */
	public function test_default_policy_matches_a_new_retry_policy_field_for_field(): void {
		$chunked_job = self::chunked_job();
		$expected    = new RetryPolicy();
		$actual      = $chunked_job->get_retry_policy();

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
		$chunked_job = self::chunked_job();
		$failure     = new RunFailure( identity: 'consumer-plugin:refresh-index', run_id: 'run-7', attempts: 3, stage: RunFailureStage::Execution, code: ApiErrorCode::ExecutionFailed, summary: 'Background-work execution failed.', failed_chunk: array( 'post_id' => 42 ), );

		$chunked_job->on_completed( 'run-7', array( 'post_type' => 'post' ) );
		$chunked_job->on_failed( 'run-7', array( 'post_type' => 'post' ), $failure );

		self::addToAssertionCount( 1 );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns a concrete chunked job that supplies only its required work methods.
	 *
	 * @return  AbstractChunkedJob
	 */
	private static function chunked_job(): AbstractChunkedJob {
		return new class() extends AbstractChunkedJob {

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
			 * @param   ChunkContextInterface   $context    Controlled access to this chunk's run.
			 *
			 * @return  void
			 */
			#[\Override]
			public function process_chunk( array $chunk_args, ChunkContextInterface $context ): void {}

		};
	}

	// endregion.
}

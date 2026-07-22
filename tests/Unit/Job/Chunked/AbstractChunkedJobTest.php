<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Job\Chunked;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\AbstractChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the defaults inherited by chunked job implementations.
 *
 * @since   1.0.0
 * @version 1.0.0
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

		require_once \dirname( __DIR__, 2 ) . '/wp-time-constant-stubs.php';
	}

	// endregion.

	// region TESTS.

	/**
	 * A chunked job inherits the shared execution and overlap invariants.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_terminal_callbacks_are_no_ops(): void {
		$chunked_job = self::chunked_job();
		$failure     = new RunFailure( identity: 'consumer-plugin:refresh-index', run_id: RunId::from( '00000000001721664000-0000000000000000007' ), attempts: 3, stage: RunFailureStage::Execution, code: ErrorCode::ExecutionFailed, summary: 'Background-work execution failed.', failed_chunk: array( 'post_id' => 42 ), );

		$chunked_job->on_completed( 'run-7', array( 'post_type' => 'post' ), null );
		$chunked_job->on_failed( 'run-7', array( 'post_type' => 'post' ), $failure );

		self::addToAssertionCount( 1 );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns a concrete chunked job that supplies only its required work methods.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractChunkedJob
	 */
	private static function chunked_job(): AbstractChunkedJob {
		return new class() extends AbstractChunkedJob {

			/**
			 * {@inheritDoc}
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @return  string
			 */
			#[\Override]
			public function get_name(): string {
				return 'refresh-index';
			}

			/**
			 * {@inheritDoc}
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
			 * @param   RunContext              $context    Controlled access to this run.
			 *
			 * @return  iterable<array<array-key, mixed>>
			 */
			#[\Override]
			public function generate_queue( array $start_args, RunContext $context ): iterable {
				return array();
			}

			/**
			 * {@inheritDoc}
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   array<array-key, mixed> $chunk_args Arguments for this chunk.
			 * @param   ChunkContext            $context    Controlled access to this chunk's run.
			 *
			 * @return  void
			 */
			#[\Override]
			public function process_chunk( array $chunk_args, ChunkContext $context ): void {}

		};
	}

	// endregion.
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Models;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ChunkContextInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the consumer-facing job defaults and chunk-context boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( \A8CSP_Job::class )]
#[CoversClass( \A8CSP_ChunkedJob::class )]
#[CoversClass( \A8CSP_ChunkContext::class )]
final class PublicFacadeTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Satisfies the production files' WordPress boot guard before first autoload.
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
	 * Both sibling authoring contracts expose the same independent defaults and optional callbacks.
	 *
	 * @return  void
	 */
	public function test_job_kinds_are_siblings_with_shared_defaults_and_optional_callbacks(): void {
		$job         = self::job();
		$chunked_job = self::chunked_job();
		$args        = array( 'site_id' => 7 );
		$failure     = array(
			'run_id'       => 'run-job',
			'attempts'     => 1,
			'stage'        => 'execution',
			'code'         => 'execution_failed',
			'summary'      => 'boom',
			'failed_chunk' => null,
		);

		$job->on_completed( 'run-job', $args, null );
		$job->on_failed( 'run-job', $args, $failure );
		$chunked_job->on_completed( 'run-chunked', $args, 'run-chunked-previous' );
		$chunked_job->on_failed( 'run-chunked', $args, $failure );

		self::assertSame( 300, \A8CSP_Job::DEFAULT_MAX_CALLBACK_RUNTIME );
		self::assertSame( 300, $job->max_callback_runtime() );
		self::assertSame( 'reject', $job->overlap_policy() );
		self::assertNull( $job->overlap_key( $args ) );
		self::assertSame( array(), $job->retry() );
		self::assertSame( 300, \A8CSP_ChunkedJob::DEFAULT_MAX_CALLBACK_RUNTIME );
		self::assertSame( 300, $chunked_job->max_callback_runtime() );
		self::assertSame( 'reject', $chunked_job->overlap_policy() );
		self::assertNull( $chunked_job->overlap_key( $args ) );
		self::assertSame( array(), $chunked_job->retry() );
	}

	/**
	 * Every public context operation forwards exact arguments and return values to the internal context.
	 *
	 * @return  void
	 */
	public function test_chunk_context_forwards_queue_mutations_and_run_metadata(): void {
		$inner   = new class() implements ChunkContextInterface {
			/** @var list<array<array-key, mixed>> */
			public array $enqueued = array();

			/** @var list<array<array-key, mixed>> */
			public array $prepended = array();

			/** {@inheritDoc} */
			#[\Override]
			public function enqueue( array $chunk_args ): void {
				$this->enqueued[] = $chunk_args;
			}

			/** {@inheritDoc} */
			#[\Override]
			public function prepend( array $chunk_args ): void {
				$this->prepended[] = $chunk_args;
			}

			/** {@inheritDoc} */
			#[\Override]
			public function get_run_id(): string {
				return 'run-17';
			}

			/** {@inheritDoc} */
			#[\Override]
			public function get_start_args(): array {
				return array(
					'site_id' => 7,
					'mode'    => 'full',
				);
			}
		};
		$context = new \A8CSP_ChunkContext( $inner );

		$context->enqueue( array( 'offset' => 10 ) );
		$context->enqueue( array( 'offset' => 20 ) );
		$context->prepend( array( 'offset' => 0 ) );

		self::assertSame( array( array( 'offset' => 10 ), array( 'offset' => 20 ) ), $inner->enqueued );
		self::assertSame( array( array( 'offset' => 0 ) ), $inner->prepended );
		self::assertSame( 'run-17', $context->get_run_id() );
		self::assertSame(
			array(
				'site_id' => 7,
				'mode'    => 'full',
			),
			$context->get_start_args()
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns a concrete one-off job that implements only required work methods.
	 *
	 * @return  \A8CSP_Job
	 */
	private static function job(): \A8CSP_Job {
		return new class() extends \A8CSP_Job {
			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return 'refresh-index';
			}

			/** {@inheritDoc} */
			#[\Override]
			public function handle( array $args, string $run_id ): void {}
		};
	}

	/**
	 * Returns a concrete chunked job that implements only required work methods.
	 *
	 * @return  \A8CSP_ChunkedJob
	 */
	private static function chunked_job(): \A8CSP_ChunkedJob {
		return new class() extends \A8CSP_ChunkedJob {
			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return 'recount-comments';
			}

			/** {@inheritDoc} */
			#[\Override]
			public function generate_queue( array $start_args, string $run_id ): iterable {
				return array();
			}

			/** {@inheritDoc} */
			#[\Override]
			public function process_chunk( array $chunk_args, \A8CSP_ChunkContext $context ): void {}
		};
	}

	// endregion.
}

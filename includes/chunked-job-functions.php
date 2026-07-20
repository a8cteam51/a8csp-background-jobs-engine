<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ChunkContextInterface as InternalChunkContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ChunkedJobInterface as InternalChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailure as InternalFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\RetryPolicy as InternalRetry;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Run\RunContextInterface as InternalRunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\OverlapPolicy;

use function A8C\SpecialProjects\BackgroundJobsEngine\Bridge\failure_to_array;
use function A8C\SpecialProjects\BackgroundJobsEngine\Bridge\retry_policy;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers one chunked job for an owner.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string            $owner Client plugin owner.
 * @param   \A8CSP_ChunkedJob $job   Consumer-authored chunked job.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  true|\WP_Error
 */
#[\NoDiscard( 'a chunked-job-registration failure must be handled, not dropped' )]
function a8csp_bgje_chunked_job_register( string $owner, \A8CSP_ChunkedJob $job ): true|\WP_Error {
	try {
		$client = \a8csp_bgje( $owner );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	try {
		$adapter = new class( $job ) implements InternalChunkedJob {
			// region FIELDS AND CONSTANTS

			/**
			 * Materialized internal retry value.
			 *
			 * @var InternalRetry|null
			 */
			private ?InternalRetry $retry_policy = null;

			// endregion

			// region MAGIC METHODS

			/**
			 * Constructor.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   \A8CSP_ChunkedJob $job Consumer-authored chunked job.
			 *
			 * @throws  \InvalidArgumentException When the retry declaration is invalid.
			 */
			public function __construct(
				private readonly \A8CSP_ChunkedJob $job,
			) {
				$this->get_retry_policy();
			}

			// endregion

			// region INHERITED METHODS

			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return $this->job->get_name();
			}

			/** {@inheritDoc} */
			#[\Override]
			public function max_callback_runtime(): int {
				return $this->job->max_callback_runtime();
			}

			/** {@inheritDoc} */
			#[\Override]
			public function overlap_policy(): OverlapPolicy {
				return OverlapPolicy::Reject;
			}

			/** {@inheritDoc} */
			#[\Override]
			public function overlap_key( array $start_args ): ?string {
				unset( $start_args );

				return null;
			}

			/** {@inheritDoc} */
			#[\Override]
			public function get_retry_policy(): InternalRetry {
				return $this->retry_policy ??= retry_policy( $this->job->retry() );
			}

			/**
			 * Forwards initial queue generation to the consumer-authored job.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
			 * @param   InternalRunContext      $context    Internal run context.
			 *
			 * @return  iterable<array<array-key, mixed>>
			 */
			#[\Override]
			public function generate_queue( array $start_args, InternalRunContext $context ): iterable {
				unset( $context );

				return $this->job->generate_queue( $start_args );
			}

			/**
			 * Wraps the internal context before forwarding one queued chunk.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   array<array-key, mixed> $chunk_args Arguments for this chunk.
			 * @param   InternalChunkContext    $context    Internal chunk context.
			 *
			 * @return  void
			 */
			#[\Override]
			public function process_chunk( array $chunk_args, InternalChunkContext $context ): void {
				$this->job->process_chunk( $chunk_args, new \A8CSP_ChunkContext( $context ) );
			}

			/**
			 * Forwards a completed run to the consumer-authored job.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   string                  $run_id                    Run identifier.
			 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
			 * @param   string|null             $previous_completed_run_id Previous completed run identifier retained inside the engine.
			 *
			 * @return  void
			 */
			#[\Override]
			public function on_completed( string $run_id, array $start_args, ?string $previous_completed_run_id ): void {
				unset( $previous_completed_run_id );

				$this->job->on_completed( $run_id, $start_args );
			}

			/**
			 * Converts and forwards a failed run to the consumer-authored job.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   string                  $run_id     Run identifier.
			 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
			 * @param   InternalFailure         $failure    Internal terminal failure.
			 *
			 * @return  void
			 */
			#[\Override]
			public function on_failed( string $run_id, array $start_args, InternalFailure $failure ): void {
				$this->job->on_failed( $run_id, $start_args, failure_to_array( $failure ) );
			}

			// endregion
		};
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	try {
		$client->chunked_jobs()->register( $adapter );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	} catch ( \LogicException $exception ) {
		return new \WP_Error( 'already_registered', $exception->getMessage() );
	}

	return true;
}

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- Local validation exceptions are translated to WP_Error before crossing the procedural boundary.
/**
 * Creates and schedules one chunked job run for an owner.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $owner      Client plugin owner.
 * @param   string                  $name       Owner-local chunked job name.
 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
 * @param   int                     $priority   Advisory priority from 0 through 255.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  string|\WP_Error
 */
#[\NoDiscard( 'a chunked-job-start failure must be handled, not dropped' )]
function a8csp_bgje_chunked_job_start( string $owner, string $name, array $start_args = array(), int $priority = 10 ): string|\WP_Error {
	try {
		$result = \a8csp_bgje( $owner )->chunked_jobs()->start( $name, $start_args, $priority );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	if ( $result->is_failure() ) {
		return new \WP_Error( $result->error->code->value, $result->error->message, $result->error->context );
	}

	return $result->value;
}
// phpcs:enable

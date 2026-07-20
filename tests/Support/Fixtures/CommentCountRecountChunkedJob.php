<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\Fixtures;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ChunkContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ChunkedJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Run\RunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\OverlapPolicy;

/**
 * Demonstrates a chunked job that recounts comments one post per independently retried chunk.
 *
 * The persisted start arguments contain only a post-type key. Queue generation loads the matching
 * post IDs from WordPress and persists one identifier per chunk.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class CommentCountRecountChunkedJob implements ChunkedJobInterface {
	// region FIELDS AND CONSTANTS.

	/**
	 * Stable chunked job identity registered with the engine.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string NAME = 'a8csp-jobs-engine-demo-comment-count-recount';

	/**
	 * Client-owned action fired after one post's comment count is refreshed.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string RECOUNTED_HOOK = 'a8csp_bgje_demo/comment_count_recounted';

	/**
	 * Client-owned action fired after every chunk succeeds.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string SUCCEEDED_HOOK = 'a8csp_bgje_demo/comment_count_recount_succeeded';

	/**
	 * Client-owned action fired after a terminal chunked job failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string FAILED_HOOK = 'a8csp_bgje_demo/comment_count_recount_failed';

	// endregion.

	// region INHERITED METHODS.

	/**
	 * Returns the stable chunked job identity registered with the engine.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	#[\Override]
	public function get_name(): string {
		return self::NAME;
	}

	/**
	 * Returns the shared ceiling for one queue generation or recount invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int
	 */
	#[\Override]
	public function max_callback_runtime(): int {
		return self::DEFAULT_MAX_CALLBACK_RUNTIME;
	}

	/**
	 * Refuses a matching live comment-count recount.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  OverlapPolicy
	 */
	#[\Override]
	public function overlap_policy(): OverlapPolicy {
		return OverlapPolicy::Reject;
	}

	/**
	 * Uses the canonical recount arguments as the overlap identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 *
	 * @return  string|null
	 */
	#[\Override]
	public function overlap_key( array $start_args ): ?string {
		return null;
	}

	/**
	 * Loads post IDs by the small `post_type` key carried on the start action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   RunContextInterface     $context    Controlled access to this run.
	 *
	 * @throws  NonRetryableException When `post_type` is absent or not a registered post type.
	 *
	 * @return  iterable<array<array-key, mixed>>
	 */
	#[\Override]
	public function generate_queue( array $start_args, RunContextInterface $context ): iterable {
		$post_type = $start_args['post_type'] ?? null;
		// A typo'd post type would drain an empty queue and report success; failing loudly on an
		// unregistered key is a permanent input defect, so it escapes the retry ladder.
		if ( ! \is_string( $post_type ) || ! \post_type_exists( $post_type ) ) {
			throw new NonRetryableException( 'Comment-count recount arguments require a registered post_type; pass the post type key when starting the chunked job.' );
		}

		$post_ids = \get_posts(
			array(
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'order'          => 'ASC',
				'orderby'        => 'ID',
				'post_status'    => 'publish',
				'post_type'      => $post_type,
				'posts_per_page' => -1,
			)
		);

		foreach ( $post_ids as $post_id ) {
			if ( \is_int( $post_id ) ) {
				yield array( 'post_id' => $post_id );
			}
		}
	}

	/**
	 * Recounts one post and publishes a client-owned observation action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $chunk_args Arguments for this chunk.
	 * @param   ChunkContextInterface   $context    Controlled access to this chunk's run.
	 *
	 * @throws  NonRetryableException When the queued post identifier is invalid or its post is gone.
	 * @throws  \RuntimeException         When the refreshed comment count is not persisted.
	 *
	 * @return  void
	 */
	#[\Override]
	public function process_chunk( array $chunk_args, ChunkContextInterface $context ): void {
		$post_id = $chunk_args['post_id'] ?? null;
		if ( ! \is_int( $post_id ) || 1 > $post_id ) {
			throw new NonRetryableException( 'Comment-count chunks require a positive integer post_id; generate each chunk from a persisted post ID.' );
		}

		// WordPress returns false only when the post no longer exists — a permanent missing
		// reference, not a transient failure, so it escapes the retry ladder.
		if ( ! \wp_update_comment_count_now( $post_id ) ) {
			throw new NonRetryableException( \sprintf( 'Post %d no longer exists; regenerate the chunked job queue from current post IDs.', $post_id ) );
		}

		// The core helper reports success without checking its database update, so comparing the
		// uncached field with the authoritative approved count keeps a transient failure retryable.
		\clean_post_cache( $post_id );
		$stored_comment_count   = (int) \get_post_field( 'comment_count', $post_id, 'raw' );
		$approved_comment_count = (int) \get_comments(
			array(
				'count'   => true,
				'post_id' => $post_id,
				'status'  => 'approve',
			)
		);
		if ( $approved_comment_count !== $stored_comment_count ) {
			throw new \RuntimeException( \sprintf( 'Post %1$d stores comment_count %2$d but has %3$d approved comments; fix the database write before retrying the chunk.', $post_id, $stored_comment_count, $approved_comment_count ) );
		}

		/**
		 * Fires after the demo chunked job refreshes one post's comment count.
		 *
		 * @since   1.0.0
		 * @version 1.0.0
		 *
		 * @param   int    $post_id Post whose comment count was refreshed.
		 * @param   string $run_id  Engine-assigned chunked job run identifier.
		 */
		\do_action( self::RECOUNTED_HOOK, $post_id, $context->get_run_id() );
	}

	/**
	 * Publishes the successful run identifier and original start arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id                    Run identifier.
	 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
	 * @param   string|null             $previous_completed_run_id Previous completed run identifier for this identity, or null.
	 *
	 * @return  void
	 */
	#[\Override]
	public function on_completed( string $run_id, array $start_args, ?string $previous_completed_run_id ): void {
		/**
		 * Fires after every comment-count chunk succeeds.
		 *
		 * @since   1.0.0
		 * @version 1.0.0
		 *
		 * @param   string                  $run_id     Engine-assigned chunked job run identifier.
		 * @param   array<array-key, mixed> $start_args Original chunked job start arguments.
		 */
		\do_action( self::SUCCEEDED_HOOK, $run_id, $start_args );
	}

	/**
	 * Publishes terminal failure detail for the client's alerting code.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 * @param   RunFailure              $failure    Persisted terminal-failure value.
	 *
	 * @return  void
	 */
	#[\Override]
	public function on_failed( string $run_id, array $start_args, RunFailure $failure ): void {
		/**
		 * Fires after the demo chunked job reaches terminal failure.
		 *
		 * @since   1.0.0
		 * @version 1.0.0
		 *
		 * @param   string                  $run_id     Engine-assigned chunked job run identifier.
		 * @param   array<array-key, mixed> $start_args Original chunked job start arguments.
		 * @param   RunFailure              $failure    Persisted terminal-failure value.
		 */
		\do_action( self::FAILED_HOOK, $run_id, $start_args, $failure );
	}

	/**
	 * Returns the bounded retry policy applied independently to each failed chunk.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RetryPolicy
	 */
	#[\Override]
	public function get_retry_policy(): RetryPolicy {
		return new RetryPolicy( max_attempts: 3, base_delay: 5, multiplier: 2, max_delay: \MINUTE_IN_SECONDS );
	}

	// endregion.
}

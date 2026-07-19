<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ChunkedJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ExistingRunPolicy;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers one chunked job for an owner.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string              $owner Client plugin owner.
 * @param   ChunkedJobInterface $chunked_job Chunked Job to register.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  true|\WP_Error
 */
#[\NoDiscard( 'a chunked-job-registration failure must be handled, not dropped' )]
function a8csp_bgje_chunked_job_register( string $owner, ChunkedJobInterface $chunked_job ): true|\WP_Error {
	try {
		$client = \a8csp_bgje( $owner );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	try {
		$client->chunked_jobs()->register( $chunked_job );
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
 * @param   string                  $existing   Existing-run policy: reject or replace.
 * @param   int                     $priority   Advisory priority from 0 through 255.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  string|\WP_Error
 */
#[\NoDiscard( 'a chunked-job-start failure must be handled, not dropped' )]
function a8csp_bgje_chunked_job_start( string $owner, string $name, array $start_args = array(), string $existing = 'reject', int $priority = 10 ): string|\WP_Error {
	try {
		$policy = ExistingRunPolicy::tryFrom( $existing );
		if ( null === $policy ) {
			throw new \InvalidArgumentException( 'existing must be reject or replace' );
		}

		$result = \a8csp_bgje( $owner )->chunked_jobs()->start( $name, $start_args, $policy, $priority );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	if ( $result->is_failure() ) {
		return new \WP_Error( $result->error->code->value, $result->error->message, $result->error->context );
	}

	return $result->value;
}
// phpcs:enable

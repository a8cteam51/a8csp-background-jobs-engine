<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\CallableJob;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers one callable-backed job for an owner.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @phpstan-param callable(array<array-key, mixed>): mixed $handler
 * @phpstan-param array{max_runtime?: int|null, retry?: RetryPolicy|null} $options
 *
 * @param   string   $owner   Client plugin owner.
 * @param   string   $name    Owner-local job name.
 * @param   callable $handler Job handler.
 * @param   array    $options Optional job policy overrides.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  true|\WP_Error
 */
#[\NoDiscard( 'a job-registration failure must be handled, not dropped' )]
function a8csp_bgje_job_register( string $owner, string $name, callable $handler, array $options = array() ): true|\WP_Error {
	try {
		$client = \a8csp_bgje( $owner );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	try {
		$client->jobs()->register( new CallableJob( $name, \Closure::fromCallable( $handler ), $options['max_runtime'] ?? null, $options['retry'] ?? null ) );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	} catch ( \LogicException $exception ) {
		return new \WP_Error( 'already_registered', $exception->getMessage() );
	}

	return true;
}

/**
 * Creates and schedules one job run for an owner.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $owner         Client plugin owner.
 * @param   string                  $name          Owner-local job name.
 * @param   array<array-key, mixed> $args          Job arguments.
 * @param   int                     $delay_seconds Scheduling delay in seconds.
 * @param   string|null             $dedup_key     Optional opaque deduplication key.
 * @param   int                     $priority      Advisory priority from 0 through 255.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  string|\WP_Error
 */
#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
function a8csp_bgje_job_enqueue( string $owner, string $name, array $args = array(), int $delay_seconds = 0, ?string $dedup_key = null, int $priority = 10 ): string|\WP_Error {
	try {
		$result = \a8csp_bgje( $owner )->jobs()->enqueue( $name, $args, $delay_seconds, $dedup_key, $priority );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	if ( $result->is_failure() ) {
		return new \WP_Error( $result->error->code->value, $result->error->message, $result->error->context );
	}

	return $result->value;
}

<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers one job or chunked job for an owner.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string       $owner Client plugin owner.
 * @param   JobInterface $job   Job to register.
 *
 * @return  true|\WP_Error
 */
#[\NoDiscard( 'a job-registration failure must be handled, not dropped' )]
function a8csp_bgje_register( string $owner, JobInterface $job ): true|\WP_Error {
	return a8csp_bgje( $owner )->jobs()->register( $job );
}

/**
 * Registers one callable-backed job for an owner.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $owner   Client plugin owner.
 * @param   string                  $name    Stable owner-local job name.
 * @param   callable                $handler Job handler.
 * @param   array<array-key, mixed> $options Optional policy and lifecycle-callback overrides.
 *
 * @return  true|\WP_Error
 */
#[\NoDiscard( 'a job-registration failure must be handled, not dropped' )]
function a8csp_bgje_register_callable( string $owner, string $name, callable $handler, array $options = array() ): true|\WP_Error {
	try {
		$allowed = array( 'max_runtime', 'retry', 'overlap', 'overlap_key', 'on_completed', 'on_failed' );
		foreach ( $options as $option => $value ) {
			if ( ! \is_string( $option ) || ! \in_array( $option, $allowed, true ) ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'Callable job options accept only max_runtime, retry, overlap, overlap_key, on_completed, and on_failed.' );
			}
		}

		$max_runtime = null;
		if ( \array_key_exists( 'max_runtime', $options ) ) {
			if ( ! \is_int( $options['max_runtime'] ) ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'max_runtime must be an integer' );
			}

			$max_runtime = $options['max_runtime'];
		}

		$retry = null;
		if ( \array_key_exists( 'retry', $options ) ) {
			if ( ! \is_array( $options['retry'] ) ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'retry must be an array' );
			}

			$declaration = $options['retry'];
			foreach ( $declaration as $field => $value ) {
				if ( ! \is_string( $field ) || ! \in_array( $field, array( 'max_attempts', 'base_delay', 'multiplier', 'max_delay' ), true ) || ! \is_int( $value ) ) {
					return new \WP_Error( ErrorCode::InvalidArgument->value, 'Retry declarations accept only integer max_attempts, base_delay, multiplier, and max_delay fields.' );
				}
			}

			$defaults = new RetryPolicy();
			$retry    = new RetryPolicy( max_attempts: $declaration['max_attempts'] ?? $defaults->max_attempts, base_delay: $declaration['base_delay'] ?? $defaults->base_delay, multiplier: $declaration['multiplier'] ?? $defaults->multiplier, max_delay: $declaration['max_delay'] ?? $defaults->max_delay );
		}

		$overlap = null;
		if ( \array_key_exists( 'overlap', $options ) ) {
			if ( ! \is_string( $options['overlap'] ) ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'overlap must be a string' );
			}

			$overlap = OverlapPolicy::tryFrom( $options['overlap'] );
			if ( null === $overlap ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'Overlap policy accepts only allow, reject, or replace.' );
			}
		}

		$overlap_key = null;
		if ( \array_key_exists( 'overlap_key', $options ) ) {
			if ( ! \is_callable( $options['overlap_key'] ) ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'overlap_key must be callable' );
			}

			$overlap_key = \Closure::fromCallable( $options['overlap_key'] );
		}

		$on_completed = null;
		if ( \array_key_exists( 'on_completed', $options ) ) {
			if ( ! \is_callable( $options['on_completed'] ) ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'on_completed must be callable' );
			}

			$on_completed = \Closure::fromCallable( $options['on_completed'] );
		}

		$on_failed = null;
		if ( \array_key_exists( 'on_failed', $options ) ) {
			if ( ! \is_callable( $options['on_failed'] ) ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'on_failed must be callable' );
			}

			$on_failed = \Closure::fromCallable( $options['on_failed'] );
		}

		return a8csp_bgje( $owner )->jobs()->register_callable( name: $name, handler: $handler, max_runtime: $max_runtime, retry: $retry, overlap: $overlap, overlap_key: $overlap_key, on_completed: $on_completed, on_failed: $on_failed );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
	}
}

/**
 * Creates and schedules one run for a registered job.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $owner         Client plugin owner.
 * @param   string                  $name          Owner-local job name.
 * @param   array<array-key, mixed> $args          Job arguments.
 * @param   int                     $delay_seconds Scheduling delay in seconds.
 * @param   int                     $priority      Advisory priority from 0 through 255.
 *
 * @return  Run|\WP_Error
 */
#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
function a8csp_bgje_enqueue( string $owner, string $name, array $args = array(), int $delay_seconds = 0, int $priority = 10 ): Run|\WP_Error {
	return a8csp_bgje( $owner )->jobs()->enqueue( $name, $args, $delay_seconds, $priority );
}

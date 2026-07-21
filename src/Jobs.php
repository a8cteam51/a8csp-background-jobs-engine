<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\DuplicateRegistrationException;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Client;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\AbstractJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\CallableJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\AbstractChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunStatus;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound public service for supported job operations.
 *
 * Owner validation and engine resolution remain lazy until a verb is invoked. Every expected
 * validation, registration, readiness, or engine failure crosses this boundary as a `WP_Error`.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Jobs {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Client plugin owner.
	 */
	public function __construct(
		private string $owner,
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers one supported job kind under the bound owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   JobInterface $job Job to register.
	 *
	 * @return  true|\WP_Error
	 */
	#[\NoDiscard( 'a job-registration failure must be handled, not dropped' )]
	public function register( JobInterface $job ): true|\WP_Error {
		try {
			if ( $job instanceof AbstractChunkedJob ) {
				$this->client()->chunked_jobs()->register( $job );
			} elseif ( $job instanceof AbstractJob ) {
				$this->client()->jobs()->register( $job );
			} else {
				return new \WP_Error( 'invalid_argument', \sprintf( 'Job kind "%s" is not supported.', $job::class ) );
			}
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'invalid_argument', $exception->getMessage() );
		} catch ( DuplicateRegistrationException $exception ) {
			return new \WP_Error( 'already_registered', $exception->getMessage() );
		} catch ( \LogicException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}

		return true;
	}

	/**
	 * Registers one callable-backed job under the bound owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name    Stable owner-local job name.
	 * @param   callable                $handler Job handler.
	 * @param   array<array-key, mixed> $options Optional policy and lifecycle-callback overrides.
	 *
	 * @return  true|\WP_Error
	 */
	#[\NoDiscard( 'a job-registration failure must be handled, not dropped' )]
	public function register_callable( string $name, callable $handler, array $options = array() ): true|\WP_Error {
		try {
			$callable_job = self::callable_job( $name, $handler, $options );
			$this->client()->jobs()->register( $callable_job );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'invalid_argument', $exception->getMessage() );
		} catch ( DuplicateRegistrationException $exception ) {
			return new \WP_Error( 'already_registered', $exception->getMessage() );
		} catch ( \LogicException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}

		return true;
	}

	/**
	 * Creates and schedules one run for a registered job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name          Owner-local job name.
	 * @param   array<array-key, mixed> $args          Job arguments.
	 * @param   int                     $delay_seconds Scheduling delay in seconds.
	 * @param   int                     $priority      Advisory priority from 0 through 255.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
	public function enqueue( string $name, array $args = array(), int $delay_seconds = 0, int $priority = 10 ): Run|\WP_Error {
		try {
			$result = $this->client()->jobs()->enqueue( $name, $args, $delay_seconds, $priority );
			if ( $result->is_failure() ) {
				return self::wp_error( $result->error );
			}

			return $this->run( $name, $result->value, RunStatus::Running );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'invalid_argument', $exception->getMessage() );
		} catch ( \LogicException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	/**
	 * Creates and schedules one run for a registered chunked job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name       Owner-local chunked job name.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   int                     $priority   Advisory priority from 0 through 255.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a chunked-job-start failure must be handled, not dropped' )]
	public function start( string $name, array $start_args = array(), int $priority = 10 ): Run|\WP_Error {
		try {
			$result = $this->client()->chunked_jobs()->start( $name, $start_args, $priority );
			if ( $result->is_failure() ) {
				return self::wp_error( $result->error );
			}

			return $this->run( $name, $result->value, RunStatus::Running );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'invalid_argument', $exception->getMessage() );
		} catch ( \LogicException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Builds one callable-backed job from validated public options.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name    Stable owner-local job name.
	 * @param   callable                $handler Job handler.
	 * @param   array<array-key, mixed> $options Optional policy and lifecycle-callback overrides.
	 *
	 * @throws  \InvalidArgumentException When an option name, type, or policy is invalid.
	 *
	 * @return  CallableJob
	 */
	private static function callable_job( string $name, callable $handler, array $options ): CallableJob {
		$allowed = array( 'max_runtime', 'retry', 'overlap', 'overlap_key', 'on_completed', 'on_failed' );
		foreach ( $options as $option => $value ) {
			if ( ! \is_string( $option ) || ! \in_array( $option, $allowed, true ) ) {
				throw new \InvalidArgumentException( 'Callable job options accept only max_runtime, retry, overlap, overlap_key, on_completed, and on_failed.' );
			}
		}

		$max_runtime = null;
		if ( \array_key_exists( 'max_runtime', $options ) ) {
			if ( ! \is_int( $options['max_runtime'] ) ) {
				throw new \InvalidArgumentException( 'max_runtime must be an integer' );
			}

			$max_runtime = $options['max_runtime'];
		}

		$retry = null;
		if ( \array_key_exists( 'retry', $options ) ) {
			if ( ! \is_array( $options['retry'] ) ) {
				throw new \InvalidArgumentException( 'retry must be an array' );
			}

			$retry = self::retry_policy( $options['retry'] );
		}

		$overlap = null;
		if ( \array_key_exists( 'overlap', $options ) ) {
			if ( ! \is_string( $options['overlap'] ) ) {
				throw new \InvalidArgumentException( 'overlap must be a string' );
			}

			$overlap = OverlapPolicy::tryFrom( $options['overlap'] );
			if ( null === $overlap ) {
				throw new \InvalidArgumentException( 'Overlap policy accepts only allow, reject, or replace.' );
			}
		}

		$overlap_key  = self::closure_option( $options, 'overlap_key' );
		$on_completed = self::closure_option( $options, 'on_completed' );
		$on_failed    = self::closure_option( $options, 'on_failed' );

		return new CallableJob( $name, \Closure::fromCallable( $handler ), $max_runtime, $retry, $overlap, $overlap_key, $on_completed, $on_failed );
	}

	/**
	 * Converts one optional callable option to a closure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $options Callable-job options.
	 * @param   string                  $name    Callable option name.
	 *
	 * @throws  \InvalidArgumentException When the declared option is not callable.
	 *
	 * @return  \Closure|null
	 */
	private static function closure_option( array $options, string $name ): ?\Closure {
		if ( ! \array_key_exists( $name, $options ) ) {
			return null;
		}

		$value = $options[ $name ];
		if ( ! \is_callable( $value ) ) {
			// Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( '%s must be callable', $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return \Closure::fromCallable( $value );
	}

	/**
	 * Builds a retry policy from a validated partial declaration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $declaration Retry declaration.
	 *
	 * @throws  \InvalidArgumentException When a field name, type, or invariant is invalid.
	 *
	 * @return  RetryPolicy
	 */
	private static function retry_policy( array $declaration ): RetryPolicy {
		foreach ( $declaration as $field => $value ) {
			if ( ! \is_string( $field ) || ! \in_array( $field, array( 'max_attempts', 'base_delay', 'multiplier', 'max_delay' ), true ) || ! \is_int( $value ) ) {
				throw new \InvalidArgumentException( 'Retry declarations accept only integer max_attempts, base_delay, multiplier, and max_delay fields.' );
			}
		}

		$defaults = new RetryPolicy();

		return new RetryPolicy( max_attempts: $declaration['max_attempts'] ?? $defaults->max_attempts, base_delay: $declaration['base_delay'] ?? $defaults->base_delay, multiplier: $declaration['multiplier'] ?? $defaults->multiplier, max_delay: $declaration['max_delay'] ?? $defaults->max_delay );
	}

	/**
	 * Resolves the internal client for the bound owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \InvalidArgumentException When the owner violates the client-owner contract.
	 * @throws  \LogicException           When the internal graph is unavailable.
	 *
	 * @return  Client
	 */
	private function client(): Client {
		return Component::client( $this->owner );
	}

	/**
	 * Projects one internal run identifier into the public value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string    $name   Owner-local job or chunked job name.
	 * @param   string    $run_id Run identifier.
	 * @param   RunStatus $status Public lifecycle state.
	 *
	 * @throws  \InvalidArgumentException When the owner or name violates the identity contract.
	 *
	 * @return  Run
	 */
	private function run( string $name, string $run_id, RunStatus $status ): Run {
		return new Run( JobIdentity::compose( $this->owner, $name ), $run_id, $status );
	}

	/**
	 * Converts one internal client failure to the WordPress error boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ApiError $error Internal client failure.
	 *
	 * @return  \WP_Error
	 */
	private static function wp_error( ApiError $error ): \WP_Error {
		return new \WP_Error( $error->code->value, $error->message, $error->context );
	}

	// endregion
}

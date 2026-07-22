<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Client;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\AbstractJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\Batch\AbstractBatchJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\CallableJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\AbstractChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\DuplicateRegistrationException;

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
			} elseif ( $job instanceof AbstractBatchJob ) {
				return new \WP_Error( ErrorCode::InvalidArgument->value, 'Batch jobs are not supported.' );
			} else {
				return new \WP_Error( ErrorCode::InvalidArgument->value, \sprintf( 'Job kind "%s" is not supported.', $job::class ) );
			}
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( DuplicateRegistrationException $exception ) {
			return new \WP_Error( ErrorCode::AlreadyRegistered->value, $exception->getMessage() );
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
	 * @param   string             $name         Stable owner-local job name.
	 * @param   callable           $handler      Job handler.
	 * @param   int|null           $max_runtime  Optional callback-runtime ceiling in seconds.
	 * @param   RetryPolicy|null   $retry        Optional retry policy.
	 * @param   OverlapPolicy|null $overlap      Optional overlap policy.
	 * @param   callable|null      $overlap_key  Optional argument-aware overlap-key resolver.
	 * @param   callable|null      $on_completed Optional completed-run callback.
	 * @param   callable|null      $on_failed    Optional failed-run callback.
	 *
	 * @return  true|\WP_Error
	 */
	#[\NoDiscard( 'a job-registration failure must be handled, not dropped' )]
	public function register_callable(
		string $name,
		callable $handler,
		?int $max_runtime = null,
		?RetryPolicy $retry = null,
		?OverlapPolicy $overlap = null,
		?callable $overlap_key = null,
		?callable $on_completed = null,
		?callable $on_failed = null,
	): true|\WP_Error {
		try {
			$callable_job = new CallableJob( $name, \Closure::fromCallable( $handler ), $max_runtime, $retry, $overlap, self::optional_closure( $overlap_key ), self::optional_closure( $on_completed ), self::optional_closure( $on_failed ) );
			$this->client()->jobs()->register( $callable_job );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( DuplicateRegistrationException $exception ) {
			return new \WP_Error( ErrorCode::AlreadyRegistered->value, $exception->getMessage() );
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
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
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
			return new \WP_Error( ErrorCode::InvalidArgument->value, $exception->getMessage() );
		} catch ( \LogicException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Converts one optional callable to the closure required by the callable-job representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   callable|null $callback Optional callback.
	 *
	 * @return  \Closure|null
	 */
	private static function optional_closure( ?callable $callback ): ?\Closure {
		return null === $callback ? null : \Closure::fromCallable( $callback );
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
		return new Run( JobIdentity::compose( $this->owner, $name ), RunId::from( $run_id ), $status );
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

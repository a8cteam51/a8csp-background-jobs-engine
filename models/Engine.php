<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Client;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\CallableJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\DuplicateRegistrationException;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound public handle for supported background-work operations.
 *
 * Owner validation and engine resolution remain lazy until a verb is invoked. Every expected
 * validation, registration, readiness, or engine failure crosses this boundary as a `WP_Error`.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Engine {
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
	 * Registers one job or chunked job under the bound owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Job|ChunkedJob $job Job to register.
	 *
	 * @return  true|\WP_Error
	 */
	#[\NoDiscard( 'a job-registration failure must be handled, not dropped' )]
	public function register( Job|ChunkedJob $job ): true|\WP_Error {
		try {
			if ( $job instanceof ChunkedJob ) {
				$this->client()->chunked_jobs()->register( $job );
			} else {
				$this->client()->jobs()->register( $job );
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

	/**
	 * Synchronizes the bound owner's complete declared schedule set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $schedules Complete schedule specification set.
	 *
	 * @return  true|\WP_Error
	 */
	#[\NoDiscard( 'a schedule-sync failure must be handled, not dropped' )]
	public function sync_schedules( array $schedules ): true|\WP_Error {
		try {
			$built  = self::schedules( $schedules );
			$result = $this->client()->schedules()->sync( $built );
			if ( $result->is_failure() ) {
				return self::wp_error( $result->error );
			}

			return true;
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'invalid_argument', $exception->getMessage() );
		} catch ( \LogicException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	/**
	 * Immediately dispatches one declared schedule target.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Owner-local schedule name.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a schedule dispatch-now failure must be handled, not dropped' )]
	public function dispatch_schedule( string $name ): Run|\WP_Error {
		try {
			$result = $this->client()->schedules()->dispatch_now( $name );
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
	 * Returns the retained status of one run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Owner-local job or chunked job name.
	 * @param   string $run_id Run identifier.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a run-inspection result must be handled, not dropped' )]
	public function inspect_run( string $name, string $run_id ): Run|\WP_Error {
		try {
			$result = $this->client()->runs()->inspect( $name, $run_id );
			if ( $result->is_failure() ) {
				return self::wp_error( $result->error );
			}
			if ( null === $result->value ) {
				return new \WP_Error( ErrorCode::RunNotRetained->value, 'The requested run is not retained.' );
			}

			// The public projection covers every internal run status, so from() always resolves here.
			return $this->run( $name, $run_id, RunStatus::from( $result->value->value ) );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'invalid_argument', $exception->getMessage() );
		} catch ( \LogicException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	/**
	 * Returns the most recently retained completed run for one background-work name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Owner-local job or chunked job name.
	 *
	 * @return  Run|null|\WP_Error
	 */
	#[\NoDiscard( 'a last-completed-run result must be handled, not dropped' )]
	public function last_completed_run( string $name ): Run|null|\WP_Error {
		try {
			$result = $this->client()->runs()->last_completed_run_id( $name );
			if ( $result->is_failure() ) {
				return self::wp_error( $result->error );
			}
			if ( null === $result->value ) {
				return null;
			}

			return $this->run( $name, $result->value, RunStatus::Completed );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'invalid_argument', $exception->getMessage() );
		} catch ( \LogicException $exception ) {
			return new \WP_Error( ErrorCode::EngineUnavailable->value, $exception->getMessage() );
		}
	}

	/**
	 * Starts a fresh run from one retained failed run's original arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Owner-local job or chunked job name.
	 * @param   string $run_id Retained failed-run identifier.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed_run( string $name, string $run_id ): Run|\WP_Error {
		try {
			$result = $this->client()->runs()->retry_failed( $name, $run_id );
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
	 * Cancels one retained run that has not passed its cancellation boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Owner-local job or chunked job name.
	 * @param   string $run_id Retained run identifier.
	 *
	 * @return  Run|\WP_Error
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	public function cancel_run( string $name, string $run_id ): Run|\WP_Error {
		try {
			$result = $this->client()->runs()->cancel( $name, $run_id );
			if ( $result->is_failure() ) {
				return self::wp_error( $result->error );
			}

			return $this->run( $name, $run_id, RunStatus::Cancelled );
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
	 * Builds internal schedule values from public specification arrays.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $specifications Complete schedule specification set.
	 *
	 * @throws  \InvalidArgumentException When an entry or field is malformed.
	 *
	 * @return  list<Schedule>
	 */
	private static function schedules( array $specifications ): array {
		$schedules = array();
		foreach ( $specifications as $specification ) {
			if ( ! \is_array( $specification ) ) {
				throw new \InvalidArgumentException( 'schedule entries must be arrays' );
			}

			if ( ! isset( $specification['name'], $specification['every'], $specification['job'] ) ) {
				throw new \InvalidArgumentException( 'schedule entries must include name, every, and job' );
			}

			$name  = $specification['name'];
			$every = $specification['every'];
			$job   = $specification['job'];
			if ( ! \is_string( $name ) || ! \is_string( $job ) ) {
				throw new \InvalidArgumentException( 'name and job must be strings' );
			}
			if ( ! \is_int( $every ) ) {
				throw new \InvalidArgumentException( 'every must be an integer number of seconds' );
			}

			$anchor = $specification['anchor'] ?? null;
			if ( null !== $anchor && ! \is_int( $anchor ) ) {
				throw new \InvalidArgumentException( 'anchor must be an integer number of seconds' );
			}

			$args = $specification['args'] ?? array();
			if ( ! \is_array( $args ) ) {
				throw new \InvalidArgumentException( 'args must be an array' );
			}

			$catch_up_value = $specification['catch_up'] ?? 'run_once';
			if ( ! \is_string( $catch_up_value ) ) {
				throw new \InvalidArgumentException( 'catch_up must be run_once or skip' );
			}
			$catch_up = CatchUpPolicy::tryFrom( $catch_up_value );
			if ( null === $catch_up ) {
				throw new \InvalidArgumentException( 'catch_up must be run_once or skip' );
			}

			$priority = $specification['priority'] ?? 10;
			if ( ! \is_int( $priority ) ) {
				throw new \InvalidArgumentException( 'priority must be an integer' );
			}

			$recurrence  = null === $anchor ? Recurrence::every( $every ) : Recurrence::every_anchored( $every, $anchor );
			$schedules[] = new Schedule( $name, $recurrence, $job, $args, $catch_up, $priority );
		}

		return $schedules;
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

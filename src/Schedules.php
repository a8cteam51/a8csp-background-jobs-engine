<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Client;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunStatus;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound public service for supported schedule operations.
 *
 * Owner validation and engine resolution remain lazy until a verb is invoked. Every expected
 * validation, readiness, or engine failure crosses this boundary as a `WP_Error`.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Schedules {
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
	public function sync( array $schedules ): true|\WP_Error {
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
	public function dispatch( string $name ): Run|\WP_Error {
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

	// endregion

	// region HELPERS

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

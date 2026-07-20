<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Api\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\AdmissionValidator;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\JobIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound facade for registering and dispatching jobs.
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
	 * @param   string              $owner  Client plugin owner.
	 * @param   JobsEngineInterface $engine Job engine operations.
	 */
	public function __construct(
		private string $owner,
		private JobsEngineInterface $engine,
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers one job under the bound owner and its declared local name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OneOffJobInterface $job Job to register.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity is invalid or belongs to a chunked job.
	 * @throws  \LogicException           When the job identity is already registered.
	 *
	 * @return  void
	 */
	public function register( OneOffJobInterface $job ): void {
		$this->engine->register_job( JobIdentity::compose( $this->owner, $job->get_name() ), $job );
	}

	/**
	 * Creates and schedules one run for a registered job.
	 *
	 * The registered Job supplies its overlap policy and argument-aware collision identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name      Owner-local job name.
	 * @param   array<array-key, mixed> $args      Job arguments.
	 * @param   int                     $delay     Scheduling delay in seconds.
	 * @param   int                     $priority  Advisory priority from 0 through 255.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity, delay, or priority is invalid, or arguments are not portable.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
	public function enqueue( string $name, array $args = array(), int $delay = 0, int $priority = 10 ): AbstractResult {
		$identity = JobIdentity::compose( $this->owner, $name );
		AdmissionValidator::assert_priority( $priority, \sprintf( 'Job "%s"', $name ) );

		if ( 0 > $delay ) {
			// Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( 'Job "%1$s" delay %2$d is invalid; pass a non-negative number of seconds.', $name, $delay ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$payload_error = AdmissionValidator::assert_portable_args( $args, \sprintf( 'Job "%s"', $name ) );
		if ( null !== $payload_error ) {
			return new Failure( $payload_error );
		}

		return $this->engine->enqueue( $identity, $args, $delay, $priority );
	}

	// endregion
}

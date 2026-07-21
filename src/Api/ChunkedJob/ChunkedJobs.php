<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\AdmissionValidator;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\JobIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound facade for registering and starting chunked jobs.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ChunkedJobs {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                     $owner  Client plugin owner.
	 * @param   ChunkedJobsEngineInterface $engine Chunked Job engine operations.
	 */
	public function __construct(
		private string $owner,
		private ChunkedJobsEngineInterface $engine,
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers one chunked job under the bound owner and its declared local name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ChunkedJobInterface $chunked_job Chunked Job to register.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity is invalid or belongs to a job.
	 * @throws  \LogicException           When the chunked job identity is already registered.
	 *
	 * @return  void
	 */
	public function register( ChunkedJobInterface $chunked_job ): void {
		$this->engine->register_chunked_job( JobIdentity::compose( $this->owner, $chunked_job->get_name() ), $chunked_job );
	}

	/**
	 * Creates and schedules one run for a registered chunked job.
	 *
	 * The registered Job supplies its overlap policy and argument-aware collision identity.
	 *
	 * A scheduling failure after replacement ownership transfers leaves the incumbent fenced; a
	 * caller handles the returned failure by starting the chunked job again.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name       Owner-local chunked job name.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   int                     $priority   Advisory priority from 0 through 255.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity or priority is invalid, or arguments are not portable.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	#[\NoDiscard( 'a chunked-job-start failure must be handled, not dropped' )]
	public function start( string $name, array $start_args = array(), int $priority = 10 ): AbstractResult {
		$identity = JobIdentity::compose( $this->owner, $name );
		AdmissionValidator::assert_priority( $priority, \sprintf( 'Chunked Job "%s"', $name ) );
		$payload_error = AdmissionValidator::assert_portable_args( $start_args, \sprintf( 'Chunked Job "%s"', $name ) );
		if ( null !== $payload_error ) {
			return new Failure( $payload_error );
		}

		return $this->engine->start( $identity, $start_args, $priority );
	}

	// endregion
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Internal\ChunkedJob;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\AbstractResult;

\defined( 'ABSPATH' ) || exit;

/**
 * Engine operations required by the owner-bound chunked job facade.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface ChunkedJobsEngineInterface {
	// region METHODS

	/**
	 * Registers one chunked job under its complete owner-qualified identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string              $identity Complete owner-qualified chunked job identity.
	 * @param   ChunkedJobInterface $chunked_job    Chunked Job to register.
	 *
	 * @throws  \InvalidArgumentException When the identity and chunked job name disagree, or a job owns the identity.
	 * @throws  \LogicException           When the chunked job identity is already registered.
	 *
	 * @return  void
	 */
	public function register_chunked_job( string $identity, ChunkedJobInterface $chunked_job ): void;

	/**
	 * Creates and schedules one run for a registered chunked job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $identity   Complete owner-qualified chunked job identity.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   int                     $priority   Advisory priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	public function start( string $identity, array $start_args, int $priority ): AbstractResult;

	// endregion
}

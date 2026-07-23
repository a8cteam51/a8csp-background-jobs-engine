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

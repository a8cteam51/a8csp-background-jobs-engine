<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Internal\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;

\defined( 'ABSPATH' ) || exit;

/**
 * Engine operations required by the owner-bound job facade.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface JobsEngineInterface {
	// region METHODS

	/**
	 * Registers one job definition under its complete owner-qualified identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string        $identity   Complete owner-qualified job identity.
	 * @param   JobDefinition $definition Job definition to register.
	 *
	 * @throws  \InvalidArgumentException When the identity, kind, or execution role is invalid.
	 * @throws  \LogicException           When the job identity is already registered.
	 *
	 * @return  void
	 */
	public function register( string $identity, JobDefinition $definition ): void;

	/**
	 * Creates and schedules one run for a registered job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $identity  Complete owner-qualified job identity.
	 * @param   array<array-key, mixed> $args      Job arguments.
	 * @param   int                     $delay     Scheduling delay in seconds.
	 * @param   int                     $priority  Advisory priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	public function enqueue( string $identity, array $args, int $delay, int $priority ): AbstractResult;

	// endregion
}

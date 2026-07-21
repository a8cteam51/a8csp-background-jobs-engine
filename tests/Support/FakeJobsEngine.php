<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\OneOffJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\JobsEngineInterface;

/** Records calls made through the typed job-engine client-testing seam. */
final class FakeJobsEngine implements JobsEngineInterface {
	// region FIELDS AND CONSTANTS.

	/** @var list<array<array-key, mixed>> */
	public array $calls = array();

	// endregion.

	// region MAGIC METHODS.

	/**
	 * @phpstan-param AbstractResult<string, \A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiError> $enqueue_result
	 *
	 * @param   AbstractResult $enqueue_result Scripted enqueue result.
	 */
	public function __construct(
		private readonly AbstractResult $enqueue_result,
	) {}

	// endregion.

	// region METHODS.

	/**
	 * Records one job registration.
	 *
	 * @param   string        $identity Complete owner-qualified job identity.
	 * @param   OneOffJobInterface $job     Job to register.
	 *
	 * @return  void
	 */
	#[\Override]
	public function register_job( string $identity, OneOffJobInterface $job ): void {
		$this->calls[] = array( 'register_job', $identity, $job );
	}

	/**
	 * Records one job admission and returns the scripted result.
	 *
	 * @param   string                  $identity  Complete owner-qualified job identity.
	 * @param   array<array-key, mixed> $args      Job arguments.
	 * @param   int                     $delay     Scheduling delay in seconds.
	 * @param   int                     $priority  Advisory priority.
	 *
	 * @phpstan-return AbstractResult<string, \A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiError>
	 *
	 * @return  AbstractResult
	 */
	#[\Override]
	public function enqueue( string $identity, array $args, int $delay, int $priority ): AbstractResult {
		$this->calls[] = array( 'enqueue', $identity, $args, $delay, $priority );

		return $this->enqueue_result;
	}

	// endregion.
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\ChunkedJob\ChunkedJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\ChunkedJob\ChunkedJobsEngineInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\AbstractResult;

/** Records calls made through the typed chunked-job-engine client-testing seam. */
final class FakeChunkedJobsEngine implements ChunkedJobsEngineInterface {
	// region FIELDS AND CONSTANTS.

	/** @var list<array<array-key, mixed>> */
	public array $calls = array();

	// endregion.

	// region MAGIC METHODS.

	/**
	 * @phpstan-param AbstractResult<string, \A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError> $start_result
	 *
	 * @param   AbstractResult $start_result Scripted start result.
	 */
	public function __construct(
		private readonly AbstractResult $start_result,
	) {}

	// endregion.

	// region METHODS.

	/**
	 * Records one chunked job registration.
	 *
	 * @param   string         $identity Complete owner-qualified chunked job identity.
	 * @param   ChunkedJobInterface $chunked_job    Chunked Job to register.
	 *
	 * @return  void
	 */
	#[\Override]
	public function register_chunked_job( string $identity, ChunkedJobInterface $chunked_job ): void {
		$this->calls[] = array( 'register_chunked_job', $identity, $chunked_job );
	}

	/**
	 * Records one chunked job admission and returns the scripted result.
	 *
	 * @param   string                  $identity   Complete owner-qualified chunked job identity.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   int                     $priority   Advisory priority.
	 *
	 * @phpstan-return AbstractResult<string, \A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError>
	 *
	 * @return  AbstractResult
	 */
	#[\Override]
	public function start( string $identity, array $start_args, int $priority ): AbstractResult {
		$this->calls[] = array( 'start', $identity, $start_args, $priority );

		return $this->start_result;
	}

	// endregion.
}

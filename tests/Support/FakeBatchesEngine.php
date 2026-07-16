<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchesEngineInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\ExistingRunPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;

/** Records calls made through the typed batch-engine consumer-testing seam. */
final class FakeBatchesEngine implements BatchesEngineInterface {
	// region FIELDS AND CONSTANTS.

	/** @var list<array<array-key, mixed>> */
	public array $calls = array();

	// endregion.

	// region MAGIC METHODS.

	/**
	 * @phpstan-param AbstractResult<string, \A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError> $start_result
	 *
	 * @param   AbstractResult $start_result Scripted start result.
	 */
	public function __construct(
		private readonly AbstractResult $start_result,
	) {}

	// endregion.

	// region METHODS.

	/**
	 * Records one batch registration.
	 *
	 * @param   string         $identity Complete owner-qualified batch identity.
	 * @param   BatchInterface $batch    Batch to register.
	 *
	 * @return  void
	 */
	#[\Override]
	public function register_batch( string $identity, BatchInterface $batch ): void {
		$this->calls[] = array( 'register_batch', $identity, $batch );
	}

	/**
	 * Records one batch admission and returns the scripted result.
	 *
	 * @param   string                  $identity   Complete owner-qualified batch identity.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   ExistingRunPolicy       $existing   Existing-run behavior.
	 * @param   int                     $priority   Advisory priority.
	 *
	 * @phpstan-return AbstractResult<string, \A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError>
	 *
	 * @return  AbstractResult
	 */
	#[\Override]
	public function start( string $identity, array $start_args, ExistingRunPolicy $existing, int $priority ): AbstractResult {
		$this->calls[] = array( 'start', $identity, $start_args, $existing, $priority );

		return $this->start_result;
	}

	// endregion.
}

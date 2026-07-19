<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Run\RunsEngineInterface;

/** Records calls made through the typed run-engine client-testing seam. */
final class FakeRunsEngine implements RunsEngineInterface {
	// region FIELDS AND CONSTANTS.

	/** @var list<array<array-key, mixed>> */
	public array $calls = array();

	// endregion.

	// region MAGIC METHODS.

	/**
	 * @phpstan-param AbstractResult<string|null, \A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiError> $last_completed_result
	 * @phpstan-param AbstractResult<string, \A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiError>      $retry_result
	 * @phpstan-param AbstractResult<string, \A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiError>      $cancel_result
	 *
	 * @param   AbstractResult $last_completed_result Scripted inspection result.
	 * @param   AbstractResult $retry_result          Scripted retry result.
	 * @param   AbstractResult $cancel_result         Scripted cancellation result.
	 */
	public function __construct(
		private readonly AbstractResult $last_completed_result,
		private readonly AbstractResult $retry_result,
		private readonly AbstractResult $cancel_result,
	) {}

	// endregion.

	// region METHODS.

	/**
	 * Records one last-completed-run lookup and returns the scripted result.
	 *
	 * @param   string $identity Complete owner-qualified work identity.
	 *
	 * @phpstan-return AbstractResult<string|null, \A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiError>
	 *
	 * @return  AbstractResult
	 */
	#[\Override]
	public function last_completed_run_id( string $identity ): AbstractResult {
		$this->calls[] = array( 'last_completed_run_id', $identity );

		return $this->last_completed_result;
	}

	/**
	 * Records one failed-run retry and returns the scripted result.
	 *
	 * @param   string $identity Complete owner-qualified work identity.
	 * @param   string $run_id   Retained failed-run identifier.
	 *
	 * @phpstan-return AbstractResult<string, \A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiError>
	 *
	 * @return  AbstractResult
	 */
	#[\Override]
	public function retry_failed( string $identity, string $run_id ): AbstractResult {
		$this->calls[] = array( 'retry_failed', $identity, $run_id );

		return $this->retry_result;
	}

	/**
	 * Records one run cancellation and returns the scripted result.
	 *
	 * @param   string $identity Complete owner-qualified work identity.
	 * @param   string $run_id   Retained run identifier.
	 *
	 * @phpstan-return AbstractResult<string, \A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiError>
	 *
	 * @return  AbstractResult
	 */
	#[\Override]
	public function cancel( string $identity, string $run_id ): AbstractResult {
		$this->calls[] = array( 'cancel', $identity, $run_id );

		return $this->cancel_result;
	}

	// endregion.
}

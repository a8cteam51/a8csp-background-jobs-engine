<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\SchedulesEngineInterface;

/** Records calls made through the typed schedule-engine consumer-testing seam. */
final class FakeSchedulesEngine implements SchedulesEngineInterface {
	// region FIELDS AND CONSTANTS.

	/** @var list<array<array-key, mixed>> */
	public array $calls = array();

	// endregion.

	// region MAGIC METHODS.

	/**
	 * @phpstan-param AbstractResult<true, \A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError>   $sync_result
	 * @phpstan-param AbstractResult<string, \A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError> $dispatch_now_result
	 *
	 * @param   AbstractResult $sync_result         Scripted synchronization result.
	 * @param   AbstractResult $dispatch_now_result Scripted immediate-dispatch result.
	 */
	public function __construct(
		private readonly AbstractResult $sync_result,
		private readonly AbstractResult $dispatch_now_result,
	) {}

	// endregion.

	// region METHODS.

	/**
	 * Records one schedule synchronization and returns the scripted result.
	 *
	 * @param   array<array-key, mixed> $declarations Schedule declarations.
	 *
	 * @phpstan-return AbstractResult<true, \A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError>
	 *
	 * @return  AbstractResult
	 */
	#[\Override]
	public function sync( array $declarations ): AbstractResult {
		$this->calls[] = array( 'sync', $declarations );

		return $this->sync_result;
	}

	/**
	 * Records one immediate schedule dispatch and returns the scripted result.
	 *
	 * @param   string $identity Complete owner-qualified schedule identity.
	 *
	 * @phpstan-return AbstractResult<string, \A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError>
	 *
	 * @return  AbstractResult
	 */
	#[\Override]
	public function dispatch_now( string $identity ): AbstractResult {
		$this->calls[] = array( 'dispatch_now', $identity );

		return $this->dispatch_now_result;
	}

	// endregion.
}

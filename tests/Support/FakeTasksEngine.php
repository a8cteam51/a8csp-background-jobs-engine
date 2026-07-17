<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TasksEngineInterface;

/** Records calls made through the typed task-engine client-testing seam. */
final class FakeTasksEngine implements TasksEngineInterface {
	// region FIELDS AND CONSTANTS.

	/** @var list<array<array-key, mixed>> */
	public array $calls = array();

	// endregion.

	// region MAGIC METHODS.

	/**
	 * @phpstan-param AbstractResult<string, \A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError> $enqueue_result
	 *
	 * @param   AbstractResult $enqueue_result Scripted enqueue result.
	 */
	public function __construct(
		private readonly AbstractResult $enqueue_result,
	) {}

	// endregion.

	// region METHODS.

	/**
	 * Records one task registration.
	 *
	 * @param   string        $identity Complete owner-qualified task identity.
	 * @param   TaskInterface $task     Task to register.
	 *
	 * @return  void
	 */
	#[\Override]
	public function register_task( string $identity, TaskInterface $task ): void {
		$this->calls[] = array( 'register_task', $identity, $task );
	}

	/**
	 * Records one task admission and returns the scripted result.
	 *
	 * @param   string                  $identity  Complete owner-qualified task identity.
	 * @param   array<array-key, mixed> $args      Task arguments.
	 * @param   int                     $delay     Scheduling delay in seconds.
	 * @param   string|null             $dedup_key Client deduplication key.
	 * @param   int                     $priority  Advisory priority.
	 *
	 * @phpstan-return AbstractResult<string, \A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError>
	 *
	 * @return  AbstractResult
	 */
	#[\Override]
	public function enqueue( string $identity, array $args, int $delay, ?string $dedup_key, int $priority ): AbstractResult {
		$this->calls[] = array( 'enqueue', $identity, $args, $delay, $dedup_key, $priority );

		return $this->enqueue_result;
	}

	// endregion.
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\RetryPolicy;

/**
 * Records task invocations with an optional observation callback and failure.
 */
final class RecordingTask implements TaskInterface {
	/**
	 * Handler arguments in call order.
	 *
	 * @var list<array<array-key, mixed>>
	 */
	public array $calls = array();

	/** Throwable raised after the invocation is recorded. */
	public ?\Throwable $throwable = null;

	/**
	 * Observation run after recording and before an optional failure.
	 *
	 * @var (\Closure(array<array-key, mixed>): void)|null
	 */
	public ?\Closure $on_handle = null;

	/** Configured retry policy. */
	public RetryPolicy $retry_policy;

	/**
	 * Constructor.
	 *
	 * @param   string $name Stable task name.
	 */
	public function __construct( private readonly string $name ) {
		$this->retry_policy = new RetryPolicy();
	}

	/** {@inheritDoc} */
	#[\Override]
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Records one task invocation before applying scripted behavior.
	 *
	 * @param   array<array-key, mixed> $args Invocation arguments.
	 *
	 * @return  void
	 */
	#[\Override]
	public function handle( array $args ): void {
		$this->calls[] = $args;

		$lifecycle_events = $GLOBALS['a8csp_bgte_test_lifecycle_events'] ?? null;
		if ( \is_array( $lifecycle_events ) ) {
			$lifecycle_events[] = array(
				'type' => 'task',
				'name' => $this->name,
				'args' => $args,
			);

			$GLOBALS['a8csp_bgte_test_lifecycle_events'] = $lifecycle_events;
		}

		if ( null !== $this->on_handle ) {
			( $this->on_handle )( $args );
		}

		if ( null !== $this->throwable ) {
			throw $this->throwable;
		}
	}

	/** {@inheritDoc} */
	#[\Override]
	public function get_retry_policy(): RetryPolicy {
		return $this->retry_policy;
	}
}

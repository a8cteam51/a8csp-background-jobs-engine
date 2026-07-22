<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobExecution;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;

/**
 * Records standard job executions with optional observation and failure behavior.
 */
final class RecordingJob implements JobExecution {
	/**
	 * Handler arguments in call order.
	 *
	 * @var list<array<array-key, mixed>>
	 */
	public array $calls = array();

	/**
	 * Handler contexts in call order.
	 *
	 * @var list<RunContext>
	 */
	public array $contexts = array();

	/** Throwable raised after the invocation is recorded. */
	public ?\Throwable $throwable = null;

	/**
	 * Observation run after recording and before an optional failure.
	 *
	 * @var (\Closure(array<array-key, mixed>): void)|null
	 */
	public ?\Closure $on_handle = null;

	/**
	 * Constructor.
	 *
	 * @param   string $name Stable job name used by definition().
	 */
	public function __construct(
		private readonly string $name,
	) {}

	/**
	 * Composes this execution fixture with its stable name and supplied policy.
	 *
	 * @param   JobOptions|null $options Optional policy declaration.
	 *
	 * @return  JobDefinition
	 */
	public function definition( ?JobOptions $options = null ): JobDefinition {
		return JobDefinition::job( $this->name, $this, $options );
	}

	/**
	 * Records one job invocation before applying scripted behavior.
	 *
	 * @param   array<array-key, mixed> $args    Invocation arguments.
	 * @param   RunContext              $context Controlled access to this run.
	 *
	 * @return  void
	 */
	#[\Override]
	public function handle( array $args, RunContext $context ): void {
		$this->calls[]    = $args;
		$this->contexts[] = $context;

		$lifecycle_events = $GLOBALS['a8csp_bgje_test_lifecycle_events'] ?? null;
		if ( \is_array( $lifecycle_events ) ) {
			$lifecycle_events[] = array(
				'type' => 'job',
				'name' => $this->name,
				'args' => $args,
			);

			$GLOBALS['a8csp_bgje_test_lifecycle_events'] = $lifecycle_events;
		}

		if ( null !== $this->on_handle ) {
			( $this->on_handle )( $args );
		}

		if ( null !== $this->throwable ) {
			throw $this->throwable;
		}
	}
}

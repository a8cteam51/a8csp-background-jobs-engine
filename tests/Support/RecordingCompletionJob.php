<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\CompletionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\JobExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;

/**
 * Records standard job executions and the completion-role callbacks the engine drives for them.
 */
final class RecordingCompletionJob implements JobExecutionInterface, CompletionInterface {
	// region FIELDS AND CONSTANTS.

	/**
	 * Handler arguments in call order.
	 *
	 * @var list<array<array-key, mixed>>
	 */
	public array $calls = array();

	/**
	 * Completion-role payloads in call order.
	 *
	 * @var list<array{run_id: RunId, start_args: array<array-key, mixed>, previous_completed_run_id: RunId|null}>
	 */
	public array $completions = array();

	/** Throwable raised by the completion role after the invocation is recorded. */
	public ?\Throwable $completion_throwable = null;

	/**
	 * Observation run inside the completion role, while the engine still holds the run's state.
	 *
	 * @var (\Closure(RunId): void)|null
	 */
	public ?\Closure $on_completed_observer = null;

	// endregion.

	// region MAGIC METHODS.

	/**
	 * Constructor.
	 *
	 * @param   string $name Stable job name used by definition().
	 */
	public function __construct(
		private readonly string $name,
	) {}

	// endregion.

	// region METHODS.

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
	 * Records one job invocation.
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   RunContextInterface     $context    Controlled access to this run.
	 *
	 * @return  void
	 */
	#[\Override]
	public function handle( array $start_args, RunContextInterface $context ): void {
		$this->calls[] = $start_args;
	}

	/**
	 * Records one completion-role invocation before applying scripted behavior.
	 *
	 * @param   RunId                   $run_id                    Run identifier of the completed run.
	 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
	 * @param   RunId|null              $previous_completed_run_id Previous completed run identifier, or null.
	 *
	 * @return  void
	 */
	#[\Override]
	public function on_completed( RunId $run_id, array $start_args, ?RunId $previous_completed_run_id ): void {
		$this->completions[] = array(
			'run_id'                    => $run_id,
			'start_args'                => $start_args,
			'previous_completed_run_id' => $previous_completed_run_id,
		);

		if ( null !== $this->on_completed_observer ) {
			( $this->on_completed_observer )( $run_id );
		}

		if ( null !== $this->completion_throwable ) {
			throw $this->completion_throwable;
		}
	}

	// endregion.
}

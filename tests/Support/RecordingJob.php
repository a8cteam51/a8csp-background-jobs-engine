<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\AbstractJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;

/**
 * Records job invocations with an optional observation callback and failure.
 */
final class RecordingJob extends AbstractJob {
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

	/**
	 * Completed-run callback payloads in call order.
	 *
	 * @var list<array{run_id: string, start_args: array<array-key, mixed>, previous_completed_run_id: string|null}>
	 */
	public array $completed_calls = array();

	/**
	 * Failed-run callback payloads in call order.
	 *
	 * @var list<array{run_id: string, start_args: array<array-key, mixed>, error: RunFailure}>
	 */
	public array $failed_calls = array();

	/** Throwable raised after the invocation is recorded. */
	public ?\Throwable $throwable = null;

	/** Throwable raised after completed-run handling is recorded. */
	public ?\Throwable $completed_throwable = null;

	/** Throwable raised after failed-run handling is recorded. */
	public ?\Throwable $failed_throwable = null;

	/**
	 * Observation run after recording and before an optional failure.
	 *
	 * @var (\Closure(array<array-key, mixed>): void)|null
	 */
	public ?\Closure $on_handle = null;

	/** @var (\Closure(string, array<array-key, mixed>, string|null): void)|null */
	public ?\Closure $on_completed = null;

	/** @var (\Closure(string, array<array-key, mixed>, RunFailure): void)|null */
	public ?\Closure $on_failed = null;

	/** Configured retry policy. */
	public RetryPolicy $retry_policy;

	/** Configured overlap policy. */
	public OverlapPolicy $overlap_policy = OverlapPolicy::Reject;

	/**
	 * Configured argument-aware overlap-key resolver.
	 *
	 * @var (\Closure(array<array-key, mixed>): ?string)|null
	 */
	public ?\Closure $overlap_key_resolver = null;

	/** Declared ceiling for one handler invocation. */
	public int $max_callback_runtime = self::DEFAULT_MAX_CALLBACK_RUNTIME;

	/** Throwable raised by max_callback_runtime(), or null to return the configured ceiling. */
	public ?\Throwable $max_callback_runtime_throwable = null;

	/**
	 * Constructor.
	 *
	 * @param   string $name Stable job name.
	 */
	public function __construct(
		private readonly string $name,
	) {
		$this->retry_policy = new RetryPolicy();
	}

	/** {@inheritDoc} */
	#[\Override]
	public function get_name(): string {
		return $this->name;
	}

	/** {@inheritDoc} */
	#[\Override]
	public function max_callback_runtime(): int {
		if ( null !== $this->max_callback_runtime_throwable ) {
			throw $this->max_callback_runtime_throwable;
		}

		return $this->max_callback_runtime;
	}

	/** {@inheritDoc} */
	#[\Override]
	public function overlap_policy(): OverlapPolicy {
		return $this->overlap_policy;
	}

	/**
	 * Resolves the configured overlap key or uses the canonical argument identity.
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 *
	 * @return  string|null
	 */
	#[\Override]
	public function overlap_key( array $start_args ): ?string {
		return null === $this->overlap_key_resolver ? null : ( $this->overlap_key_resolver )( $start_args );
	}

	/**
	 * Records one job invocation before applying scripted behavior.
	 *
	 * @param   array<array-key, mixed> $args    Invocation arguments.
	 * @param   RunContext     $context Controlled access to this run.
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

	/**
	 * Records one completed-run callback.
	 *
	 * @param   string                  $run_id                    Run identifier.
	 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
	 * @param   string|null             $previous_completed_run_id Previous completed run identifier for this identity, or null.
	 *
	 * @return  void
	 */
	#[\Override]
	public function on_completed( string $run_id, array $start_args, ?string $previous_completed_run_id ): void {
		$this->completed_calls[] = array(
			'run_id'                    => $run_id,
			'start_args'                => $start_args,
			'previous_completed_run_id' => $previous_completed_run_id,
		);

		if ( null !== $this->on_completed ) {
			( $this->on_completed )( $run_id, $start_args, $previous_completed_run_id );
		}

		if ( null !== $this->completed_throwable ) {
			throw $this->completed_throwable;
		}
	}

	/**
	 * Records one failed-run callback.
	 *
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 * @param   RunFailure              $failure    Persisted terminal-failure value.
	 *
	 * @return  void
	 */
	#[\Override]
	public function on_failed( string $run_id, array $start_args, RunFailure $failure ): void {
		$this->failed_calls[] = array(
			'run_id'     => $run_id,
			'start_args' => $start_args,
			'error'      => $failure,
		);

		if ( null !== $this->on_failed ) {
			( $this->on_failed )( $run_id, $start_args, $failure );
		}

		if ( null !== $this->failed_throwable ) {
			throw $this->failed_throwable;
		}
	}

	/** {@inheritDoc} */
	#[\Override]
	public function get_retry_policy(): RetryPolicy {
		return $this->retry_policy;
	}
}

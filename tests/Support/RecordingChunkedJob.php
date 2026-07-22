<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\AbstractChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;

/**
 * Records chunked job lifecycle invocations with optional observation callbacks and failures.
 */
final class RecordingChunkedJob extends AbstractChunkedJob {
	/**
	 * Initial chunks returned by queue generation.
	 *
	 * @var array<array-key, array<array-key, mixed>>
	 */
	public array $queue = array();

	/**
	 * Queue-generation arguments in call order.
	 *
	 * @var list<array<array-key, mixed>>
	 */
	public array $generate_calls = array();

	/**
	 * Queue-generation contexts in call order.
	 *
	 * @var list<RunContext>
	 */
	public array $generate_contexts = array();

	/**
	 * Chunk-processing arguments and contexts in call order.
	 *
	 * @var list<array{chunk_args: array<array-key, mixed>, context: ChunkContext}>
	 */
	public array $process_calls = array();

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

	/** Throwable raised after queue generation is recorded and observed. */
	public ?\Throwable $generate_throwable = null;

	/** @var (\Closure(): iterable<array-key, array<array-key, mixed>>)|null Lazy generated queue factory. */
	public ?\Closure $generate_queue_factory = null;

	/** Throwable raised after chunk processing is recorded and observed. */
	public ?\Throwable $process_throwable = null;

	/** Throwable raised after completed-run handling is recorded. */
	public ?\Throwable $completed_throwable = null;

	/** Throwable raised after failed-run handling is recorded. */
	public ?\Throwable $failed_throwable = null;

	/**
	 * Observation run after recording completed-run handling and before an optional failure.
	 *
	 * @var (\Closure(string, array<array-key, mixed>, string|null): void)|null
	 */
	public ?\Closure $on_completed = null;

	/**
	 * Observation run after recording failed-run handling and before an optional failure.
	 *
	 * @var (\Closure(string, array<array-key, mixed>, RunFailure): void)|null
	 */
	public ?\Closure $on_failed = null;

	/**
	 * Observation run after recording queue generation and before an optional failure.
	 *
	 * @var (\Closure(array<array-key, mixed>): void)|null
	 */
	public ?\Closure $on_generate = null;

	/**
	 * Observation run after recording chunk processing and before an optional failure.
	 *
	 * @var (\Closure(array<array-key, mixed>, ChunkContext): void)|null
	 */
	public ?\Closure $on_process = null;

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

	/** Declared ceiling for one queue generation or chunk invocation. */
	public int $max_callback_runtime = self::DEFAULT_MAX_CALLBACK_RUNTIME;

	/**
	 * Constructor.
	 *
	 * @param   string $name Stable chunked job name.
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
	 * Records queue generation before applying scripted behavior.
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   RunContext     $context    Controlled access to this run.
	 *
	 * @return  iterable<array<array-key, mixed>>
	 */
	#[\Override]
	public function generate_queue( array $start_args, RunContext $context ): iterable {
		$this->generate_calls[]    = $start_args;
		$this->generate_contexts[] = $context;
		$this->record_lifecycle_event( 'generate' );

		if ( null !== $this->on_generate ) {
			( $this->on_generate )( $start_args );
		}

		if ( null !== $this->generate_throwable ) {
			throw $this->generate_throwable;
		}
		if ( null !== $this->generate_queue_factory ) {
			return ( $this->generate_queue_factory )();
		}

		return $this->queue;
	}

	/**
	 * Records one chunk invocation before applying scripted behavior.
	 *
	 * @param   array<array-key, mixed> $chunk_args Arguments for this chunk.
	 * @param   ChunkContext   $context    Controlled access to this chunk's run.
	 *
	 * @return  void
	 */
	#[\Override]
	public function process_chunk( array $chunk_args, ChunkContext $context ): void {
		$this->process_calls[] = array(
			'chunk_args' => $chunk_args,
			'context'    => $context,
		);
		$this->record_lifecycle_event( 'process' );

		if ( null !== $this->on_process ) {
			( $this->on_process )( $chunk_args, $context );
		}

		if ( null !== $this->process_throwable ) {
			throw $this->process_throwable;
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
		$this->record_lifecycle_event( 'completed' );

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
		$this->record_lifecycle_event( 'failed' );

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

	/**
	 * Appends one chunked job boundary to the shared lifecycle ledger when enabled.
	 *
	 * @param   string $operation Chunked Job lifecycle operation.
	 *
	 * @return  void
	 */
	private function record_lifecycle_event( string $operation ): void {
		$lifecycle_events = $GLOBALS['a8csp_bgje_test_lifecycle_events'] ?? null;
		if ( ! \is_array( $lifecycle_events ) ) {
			return;
		}

		$lifecycle_events[] = array(
			'type'      => 'chunked_job',
			'operation' => $operation,
		);

		$GLOBALS['a8csp_bgje_test_lifecycle_events'] = $lifecycle_events;
	}
}

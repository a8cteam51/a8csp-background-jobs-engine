<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkedJobExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContextInterface;

/**
 * Records chunked job executions with optional observation and failure behavior.
 */
final class RecordingChunkedJob implements ChunkedJobExecutionInterface {
	// region FIELDS AND CONSTANTS.

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
	 * @var list<RunContextInterface>
	 */
	public array $generate_contexts = array();

	/**
	 * Chunk-processing arguments and contexts in call order.
	 *
	 * @var list<array{chunk_args: array<array-key, mixed>, context: ChunkContextInterface}>
	 */
	public array $process_calls = array();

	/** Throwable raised after queue generation is recorded and observed. */
	public ?\Throwable $generate_throwable = null;

	/** @var (\Closure(): iterable<array-key, array<array-key, mixed>>)|null Lazy generated queue factory. */
	public ?\Closure $generate_queue_factory = null;

	/** Throwable raised after chunk processing is recorded and observed. */
	public ?\Throwable $process_throwable = null;

	/**
	 * Observation run after recording queue generation and before an optional failure.
	 *
	 * @var (\Closure(array<array-key, mixed>): void)|null
	 */
	public ?\Closure $on_generate = null;

	/**
	 * Observation run after recording chunk processing and before an optional failure.
	 *
	 * @var (\Closure(array<array-key, mixed>, ChunkContextInterface): void)|null
	 */
	public ?\Closure $on_process = null;

	// endregion.

	// region MAGIC METHODS.

	/**
	 * Constructor.
	 *
	 * @param   string $name Stable chunked job name used by definition().
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
		return JobDefinition::chunked_job( $this->name, $this, $options );
	}

	/**
	 * Records queue generation before applying scripted behavior.
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   RunContextInterface     $context    Controlled access to this run.
	 *
	 * @return  iterable<array<array-key, mixed>>
	 */
	#[\Override]
	public function generate_queue( array $start_args, RunContextInterface $context ): iterable {
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
	 * @param   ChunkContextInterface   $context    Controlled access to this chunk's run.
	 *
	 * @return  void
	 */
	#[\Override]
	public function process_chunk( array $chunk_args, ChunkContextInterface $context ): void {
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

	// endregion.

	// region HELPERS.

	/**
	 * Appends one chunked job boundary to the shared lifecycle ledger when enabled.
	 *
	 * @param   string $operation Chunked job execution operation.
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

	// endregion.
}

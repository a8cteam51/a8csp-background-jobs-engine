<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches\BatchContextInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Retry\RetryPolicy;

/**
 * Records batch lifecycle invocations with optional observation callbacks and failures.
 */
final class RecordingBatch implements BatchInterface {
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
	 * Chunk-processing arguments and contexts in call order.
	 *
	 * @var list<array{chunk_args: array<array-key, mixed>, context: BatchContextInterface}>
	 */
	public array $process_calls = array();

	/**
	 * Successful-run callback payloads in call order.
	 *
	 * @var list<array{run_id: string, start_args: array<array-key, mixed>}>
	 */
	public array $success_calls = array();

	/**
	 * Failed-run callback payloads in call order.
	 *
	 * @var list<array{run_id: string, start_args: array<array-key, mixed>, error: EngineError}>
	 */
	public array $failure_calls = array();

	/** Throwable raised after queue generation is recorded and observed. */
	public ?\Throwable $generate_throwable = null;

	/** Throwable raised after chunk processing is recorded and observed. */
	public ?\Throwable $process_throwable = null;

	/** Throwable raised after successful-run handling is recorded. */
	public ?\Throwable $success_throwable = null;

	/** Throwable raised after failed-run handling is recorded. */
	public ?\Throwable $failure_throwable = null;

	/**
	 * Observation run after recording successful-run handling and before an optional failure.
	 *
	 * @var (\Closure(string, array<array-key, mixed>): void)|null
	 */
	public ?\Closure $on_success = null;

	/**
	 * Observation run after recording queue generation and before an optional failure.
	 *
	 * @var (\Closure(array<array-key, mixed>): void)|null
	 */
	public ?\Closure $on_generate = null;

	/**
	 * Observation run after recording chunk processing and before an optional failure.
	 *
	 * @var (\Closure(array<array-key, mixed>, BatchContextInterface): void)|null
	 */
	public ?\Closure $on_process = null;

	/** Configured retry policy. */
	public RetryPolicy $retry_policy;

	/**
	 * Constructor.
	 *
	 * @param   string $name Stable batch name.
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
	 * Records queue generation before applying scripted behavior.
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 *
	 * @return  iterable<array<array-key, mixed>>
	 */
	#[\Override]
	public function generate_queue( array $start_args ): iterable {
		$this->generate_calls[] = $start_args;
		$this->record_lifecycle_event( 'generate' );

		if ( null !== $this->on_generate ) {
			( $this->on_generate )( $start_args );
		}

		if ( null !== $this->generate_throwable ) {
			throw $this->generate_throwable;
		}

		return $this->queue;
	}

	/**
	 * Records one chunk invocation before applying scripted behavior.
	 *
	 * @param   array<array-key, mixed> $chunk_args Arguments for this chunk.
	 * @param   BatchContextInterface   $context    Controlled access to this chunk's run.
	 *
	 * @return  void
	 */
	#[\Override]
	public function process_chunk( array $chunk_args, BatchContextInterface $context ): void {
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
	 * Records one successful-run callback.
	 *
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 *
	 * @return  void
	 */
	#[\Override]
	public function on_success( string $run_id, array $start_args ): void {
		$this->success_calls[] = array(
			'run_id'     => $run_id,
			'start_args' => $start_args,
		);
		$this->record_lifecycle_event( 'success' );

		if ( null !== $this->on_success ) {
			( $this->on_success )( $run_id, $start_args );
		}

		if ( null !== $this->success_throwable ) {
			throw $this->success_throwable;
		}
	}

	/**
	 * Records one failed-run callback.
	 *
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 * @param   EngineError             $error      Persisted failure detail.
	 *
	 * @return  void
	 */
	#[\Override]
	public function on_failure( string $run_id, array $start_args, EngineError $error ): void {
		$this->failure_calls[] = array(
			'run_id'     => $run_id,
			'start_args' => $start_args,
			'error'      => $error,
		);
		$this->record_lifecycle_event( 'failure' );

		if ( null !== $this->failure_throwable ) {
			throw $this->failure_throwable;
		}
	}

	/** {@inheritDoc} */
	#[\Override]
	public function get_retry_policy(): RetryPolicy {
		return $this->retry_policy;
	}

	/**
	 * Appends one batch boundary to the shared lifecycle ledger when enabled.
	 *
	 * @param   string $operation Batch lifecycle operation.
	 *
	 * @return  void
	 */
	private function record_lifecycle_event( string $operation ): void {
		$lifecycle_events = $GLOBALS['a8csp_bgte_test_lifecycle_events'] ?? null;
		if ( ! \is_array( $lifecycle_events ) ) {
			return;
		}

		$lifecycle_events[] = array(
			'type'      => 'batch',
			'operation' => $operation,
		);

		$GLOBALS['a8csp_bgte_test_lifecycle_events'] = $lifecycle_events;
	}
}

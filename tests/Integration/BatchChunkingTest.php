<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchContextInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;

/**
 * Verifies one-action-per-chunk batch dispatch and transactional context queue mutations.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class BatchChunkingTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Public owner unique to this integration-test graph. */
	private const string OWNER = 'integration-batch-chunking';

	/** Batch identity unique within the request-persistent integration registry. */
	private const string NAME = 'integration-batch-chunking';

	/** Owner-qualified batch identity persisted by the engine. */
	private const string IDENTITY = self::OWNER . ':' . self::NAME;

	// endregion.

	// region TESTS.

	/**
	 * Three generated chunks expand and reorder through context mutations before one terminal success.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_batch_processes_each_visible_action_in_mutated_queue_order(): void {
		$start_args        = array(
			'site_id' => 17,
			'mode'    => 'reindex',
		);
		$batch             = new RecordingBatch( self::NAME );
		$batch->queue      = array(
			array( 'chunk' => 'one' ),
			array( 'chunk' => 'two' ),
			array( 'chunk' => 'three' ),
		);
		$batch->on_process = static function ( array $chunk_args, BatchContextInterface $context ): void {
			if ( 'one' !== ( $chunk_args['chunk'] ?? null ) ) {
				return;
			}

			$context->enqueue( array( 'chunk' => 'tail' ) );
			$context->prepend( array( 'chunk' => 'front' ) );
		};

		$client = \a8csp_bgte( self::OWNER );
		$client->batches()->register( $batch );

		$this->expect_option( 'a8csp_bgte_latest_run_' . self::IDENTITY );
		$continue_delay_calls = array();
		\add_filter(
			'a8csp_background_tasks/continue_delay',
			static function ( int $delay, string $name, string $run_id ) use ( &$continue_delay_calls ): int {
				$continue_delay_calls[] = array( $delay, $name, $run_id );

				return 0;
			},
			10,
			3
		);

		$completion_observations = array();
		\add_action(
			'a8csp_background_tasks/completed/' . self::IDENTITY,
			static function ( string $run_id, array $args ) use ( $batch, &$completion_observations ): void {
				$completion_observations[] = array(
					'hook'            => 'named',
					'payload'         => array( $run_id, $args ),
					'completed_calls' => \count( $batch->completed_calls ),
				);
			},
			10,
			2
		);
		\add_action(
			'a8csp_background_tasks/completed',
			static function ( string $name, string $run_id, array $args ) use ( $batch, &$completion_observations ): void {
				$completion_observations[] = array(
					'hook'            => 'generic',
					'payload'         => array( $name, $run_id, $args ),
					'completed_calls' => \count( $batch->completed_calls ),
				);
			},
			10,
			3
		);

		$result = $client->batches()->start( self::NAME, $start_args );
		self::assertInstanceOf( Success::class, $result, 'The registered batch must start through the public API' );
		self::assertIsString( $result->value );
		$run_id = $result->value;

		self::assertSame( array(), $batch->generate_calls, 'Starting a batch must not generate its queue inline' );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute the batch start action' );
		self::assertSame( array( $start_args ), $batch->generate_calls, 'Batch start must generate its queue exactly once' );

		$expected_chunks = array(
			array( 'chunk' => 'one' ),
			array( 'chunk' => 'front' ),
			array( 'chunk' => 'two' ),
			array( 'chunk' => 'three' ),
			array( 'chunk' => 'tail' ),
		);
		foreach ( $expected_chunks as $expected_chunk ) {
			$process_calls_before = $batch->process_calls;
			$process_call_count   = \count( $process_calls_before );

			self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute one queue-advance action' );
			self::assertSame( $process_calls_before, $batch->process_calls, 'A CONTINUE action must leave the process ledger unchanged; dispatch the visible chunk through its RUN action' );
			self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute one visible chunk action' );
			self::assertCount( $process_call_count + 1, $batch->process_calls, 'A RUN action must process exactly one batch chunk' );
			self::assertSame( $expected_chunk, $batch->process_calls[ $process_call_count ]['chunk_args'] ?? null, 'A RUN action must process the chunk exposed by the preceding CONTINUE action' );
		}

		$process_calls_before = $batch->process_calls;
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must observe the drained queue' );
		self::assertSame( $process_calls_before, $batch->process_calls, 'A drained-queue CONTINUE action must leave the process ledger unchanged; dispatch chunks only through RUN actions' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute terminal batch cleanup' );
		self::assertSame( \array_fill( 0, 6, array( 60, self::IDENTITY, $run_id ) ), $continue_delay_calls, 'The zero-delay filter must receive its default, batch name, and run ID for the lock and every chunk' );

		self::assertSame( $expected_chunks, \array_column( $batch->process_calls, 'chunk_args' ), 'Batch chunks must run in generated, prepended, remaining, and appended order' );
		foreach ( $batch->process_calls as $process_call ) {
			self::assertSame( $run_id, $process_call['context']->get_run_id() );
			self::assertSame( $start_args, $process_call['context']->get_start_args() );
		}

		self::assertSame(
			array(
				array(
					'run_id'     => $run_id,
					'start_args' => $start_args,
				),
			),
			$batch->completed_calls,
			'Batch on_completed() must run exactly once with run ID and original start arguments'
		);
		self::assertSame(
			array(
				array(
					'hook'            => 'named',
					'payload'         => array( $run_id, $start_args ),
					'completed_calls' => 1,
				),
				array(
					'hook'            => 'generic',
					'payload'         => array( self::IDENTITY, $run_id, $start_args ),
					'completed_calls' => 1,
				),
			),
			$completion_observations,
			'Completed hooks must follow on_completed() and preserve identity-specific then generic payload order'
		);

		$last_completed = $client->runs()->last_completed_run_id( self::NAME );
		self::assertInstanceOf( Success::class, $last_completed );
		self::assertSame( $run_id, $last_completed->value );
		$runs = $this->inspection()->runs( self::IDENTITY );
		self::assertSame( array(), $runs['live'], 'Terminal batch completion must leave no live run' );
		self::assertSame(
			array(
				array(
					'run_id'       => $run_id,
					'outcome'      => 'completed',
					'failed_store' => false,
				),
			),
			$runs['history'],
			'Inspection must retain the completed lifecycle outcome'
		);
	}

	// endregion.
}

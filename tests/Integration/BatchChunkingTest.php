<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchContextInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;

/**
 * Verifies one-action-per-chunk batch dispatch and transactional context queue mutations.
 */
final class BatchChunkingTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Public owner unique to this integration-test graph. */
	private const OWNER = 'integration-batch-chunking';

	/** Batch identity unique within the request-persistent integration registry. */
	private const NAME = 'integration-batch-chunking';

	/** Owner-qualified batch identity persisted by the engine. */
	private const IDENTITY = self::OWNER . ':' . self::NAME;

	// endregion.

	// region TESTS.

	/**
	 * Three generated chunks expand and reorder through context mutations before one terminal success.
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

		$consumer = \a8csp_bgte( self::OWNER );
		$consumer->batches()->register( $batch );

		$this->expect_option( 'a8csp_bgte_latest_' . self::IDENTITY );
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
					'hook'          => 'named',
					'payload'       => array( $run_id, $args ),
					'success_calls' => \count( $batch->success_calls ),
				);
			},
			10,
			2
		);
		\add_action(
			'a8csp_background_tasks/completed',
			static function ( string $name, string $run_id, array $args ) use ( $batch, &$completion_observations ): void {
				$completion_observations[] = array(
					'hook'          => 'generic',
					'payload'       => array( $name, $run_id, $args ),
					'success_calls' => \count( $batch->success_calls ),
				);
			},
			10,
			3
		);

		$result = $consumer->batches()->start( self::NAME, $start_args );
		self::assertInstanceOf( Success::class, $result, 'The registered batch must start through the public API' );
		self::assertIsString( $result->value );
		$run_id = $result->value;
		$group  = self::IDENTITY . '|' . $run_id;

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
		$run_action_ids  = array();
		foreach ( $expected_chunks as $expected_chunk ) {
			$process_calls_before = $batch->process_calls;
			$process_call_count   = \count( $process_calls_before );

			self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute one queue-advance action' );
			self::assertSame( $process_calls_before, $batch->process_calls, 'A CONTINUE action must leave the process ledger unchanged; dispatch the visible chunk through its RUN action' );
			$run_action_ids[] = $this->assert_pending_chunk_action( self::IDENTITY, $run_id, $group, $expected_chunk );
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
		self::assertCount( 5, \array_unique( $run_action_ids ), 'Every processed chunk must have its own Action Scheduler row' );
		foreach ( $run_action_ids as $action_id ) {
			self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $this->action_scheduler_store()->get_status( $action_id ), 'Action Scheduler must complete every per-chunk run action' );
		}

		self::assertSame(
			array(
				array(
					'run_id'     => $run_id,
					'start_args' => $start_args,
				),
			),
			$batch->success_calls,
			'Batch success must run exactly once with run ID and original start arguments'
		);
		self::assertSame(
			array(
				array(
					'hook'          => 'named',
					'payload'       => array( $run_id, $start_args ),
					'success_calls' => 1,
				),
				array(
					'hook'          => 'generic',
					'payload'       => array( self::IDENTITY, $run_id, $start_args ),
					'success_calls' => 1,
				),
			),
			$completion_observations,
			'Completed hooks must follow on_success and preserve identity-specific then generic payload order'
		);

		$args_hash = self::args_hash( $start_args );
		self::assertFalse( \get_option( 'a8csp_bgte_run_' . self::IDENTITY . '_' . $run_id, false ), 'Terminal batch success must delete the active run option' );
		self::assertFalse( \get_option( 'a8csp_bgte_lock_' . self::IDENTITY . '_' . $args_hash, false ), 'Terminal batch success must release the overlap lock' );
		self::assertFalse( \get_option( 'a8csp_bgte_failed_' . self::IDENTITY, false ), 'Terminal batch success must not create a failed-run row' );
		self::assertSame(
			array(
				'all'     => $run_id,
				'by_hash' => array( $args_hash => $run_id ),
			),
			\get_option( 'a8csp_bgte_latest_' . self::IDENTITY, null ),
			'Terminal batch success must retain the latest pointers'
		);
		self::assertSame(
			array(
				'started'  => array( $run_id ),
				'terminal' => array(
					array(
						'run_id' => $run_id,
						'status' => 'completed',
					),
				),
				'by_hash'  => array(
					$args_hash => array(
						'started'  => array( $run_id ),
						'terminal' => array(
							array(
								'run_id' => $run_id,
								'status' => 'completed',
							),
						),
					),
				),
			),
			\get_option( 'a8csp_bgte_history_' . self::IDENTITY, null ),
			'Terminal batch success must retain one started and completed history entry'
		);
		self::assertSame(
			array(
				'a8csp_bgte_history_' . self::IDENTITY,
				'a8csp_bgte_latest_' . self::IDENTITY,
			),
			\array_column( $this->engine_option_rows(), 'option_name' ),
			'Completed batch state must contain only its history ring and latest pointer'
		);
	}

	// endregion.
}

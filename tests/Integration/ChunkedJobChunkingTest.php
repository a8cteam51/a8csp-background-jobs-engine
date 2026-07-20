<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ChunkContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;

/**
 * Verifies one-action-per-chunk chunked job dispatch and transactional context queue mutations.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class ChunkedJobChunkingTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Public owner unique to this integration-test graph. */
	private const string OWNER = 'integration-chunked-job-chunking';

	/** Chunked Job identity unique within the request-persistent integration registry. */
	private const string NAME = 'integration-chunked-job-chunking';

	/** Owner-qualified chunked job identity persisted by the engine. */
	private const string IDENTITY = self::OWNER . ':' . self::NAME;

	/** Chunked Job identity for the Action Scheduler float-fidelity regression. */
	private const string FIDELITY_NAME = 'integration-chunked-job-chunk-fidelity';

	/** Owner-qualified identity for the Action Scheduler float-fidelity regression. */
	private const string FIDELITY_IDENTITY = self::OWNER . ':' . self::FIDELITY_NAME;

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
	public function test_chunked_job_processes_each_visible_action_in_mutated_queue_order(): void {
		$start_args              = array(
			'site_id' => 17,
			'mode'    => 'reindex',
		);
		$chunked_job             = new RecordingChunkedJob( self::NAME );
		$chunked_job->queue      = array(
			array( 'chunk' => 'one' ),
			array( 'chunk' => 'two' ),
			array( 'chunk' => 'three' ),
		);
		$chunked_job->on_process = static function ( array $chunk_args, ChunkContextInterface $context ): void {
			if ( 'one' !== ( $chunk_args['chunk'] ?? null ) ) {
				return;
			}

			$context->enqueue( array( 'chunk' => 'tail' ) );
			$context->prepend( array( 'chunk' => 'front' ) );
		};

		$client = \a8csp_bgje( self::OWNER );
		$client->chunked_jobs()->register( $chunked_job );

		$this->expect_option( 'a8csp_bgje_latest_run_' . self::IDENTITY );
		$continue_delay_calls = array();
		\add_filter(
			'a8csp_jobs_engine/continue_delay',
			static function ( int $delay, string $name, string $run_id ) use ( &$continue_delay_calls ): int {
				$continue_delay_calls[] = array( $delay, $name, $run_id );

				return 0;
			},
			10,
			3
		);

		$completion_observations = array();
		\add_action(
			'a8csp_jobs_engine/completed/' . self::IDENTITY,
			static function ( string $run_id, array $args ) use ( $chunked_job, &$completion_observations ): void {
				$completion_observations[] = array(
					'hook'            => 'named',
					'payload'         => array( $run_id, $args ),
					'completed_calls' => \count( $chunked_job->completed_calls ),
				);
			},
			10,
			2
		);
		\add_action(
			'a8csp_jobs_engine/completed',
			static function ( string $name, string $run_id, array $args ) use ( $chunked_job, &$completion_observations ): void {
				$completion_observations[] = array(
					'hook'            => 'generic',
					'payload'         => array( $name, $run_id, $args ),
					'completed_calls' => \count( $chunked_job->completed_calls ),
				);
			},
			10,
			3
		);

		$result = $client->chunked_jobs()->start( self::NAME, $start_args );
		self::assertInstanceOf( Success::class, $result, 'The registered chunked job must start through the public API' );
		self::assertIsString( $result->value );
		$run_id = $result->value;

		self::assertSame( array(), $chunked_job->generate_calls, 'Starting a chunked job must not generate its queue inline' );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute the chunked job start action' );
		self::assertSame( array( $start_args ), $chunked_job->generate_calls, 'Chunked Job start must generate its queue exactly once' );

		$expected_chunks = array(
			array( 'chunk' => 'one' ),
			array( 'chunk' => 'front' ),
			array( 'chunk' => 'two' ),
			array( 'chunk' => 'three' ),
			array( 'chunk' => 'tail' ),
		);
		foreach ( $expected_chunks as $expected_chunk ) {
			$process_calls_before = $chunked_job->process_calls;
			$process_call_count   = \count( $process_calls_before );

			self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute one chunked job continuation' );
			self::assertCount( $process_call_count + 1, $chunked_job->process_calls, 'A CONTINUE action must process exactly one chunked job chunk' );
			self::assertSame( $expected_chunk, $chunked_job->process_calls[ $process_call_count ]['chunk_args'] ?? null, 'A CONTINUE action must process the authoritative queue head' );
		}

		$process_calls_before = $chunked_job->process_calls;
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must observe the drained queue' );
		self::assertSame( $process_calls_before, $chunked_job->process_calls, 'A drained-queue CONTINUE action must leave the process ledger unchanged' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute terminal chunked job cleanup' );
		self::assertSame( \array_fill( 0, 6, array( 60, self::IDENTITY, $run_id ) ), $continue_delay_calls, 'The zero-delay filter must receive its default, chunked job name, and run ID for the lock and every chunk' );

		self::assertSame( $expected_chunks, \array_column( $chunked_job->process_calls, 'chunk_args' ), 'Chunked Job chunks must run in generated, prepended, remaining, and appended order' );
		foreach ( $chunked_job->process_calls as $process_call ) {
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
			$chunked_job->completed_calls,
			'Chunked Job on_completed() must run exactly once with run ID and original start arguments'
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
		self::assertSame( array(), $runs['live'], 'Terminal chunked job completion must leave no live run' );
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

	/**
	 * Action Scheduler delivers a float chunk from the authoritative run row without numeric coercion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_action_scheduler_delivers_float_chunk_from_the_authoritative_run_row(): void {
		$chunked_job        = new RecordingChunkedJob( self::FIDELITY_NAME );
		$chunked_job->queue = array( array( 'value' => 1.0 ) );
		$client             = \a8csp_bgje( self::OWNER );
		$client->chunked_jobs()->register( $chunked_job );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::FIDELITY_IDENTITY );
		\add_filter( 'a8csp_jobs_engine/continue_delay', static fn ( int $delay, string $name, string $run_id ): int => 0, 10, 3 );

		$result = $client->chunked_jobs()->start( self::FIDELITY_NAME, array() );
		self::assertInstanceOf( Success::class, $result );
		self::assertIsString( $result->value );
		$run_id = $result->value;
		$group  = self::FIDELITY_IDENTITY . '|' . $run_id;

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must materialize the float chunk' );
		$run_state = \get_option( 'a8csp_bgje_run_' . self::FIDELITY_IDENTITY . '_' . $run_id, null );
		self::assertIsArray( $run_state );
		$queue = $run_state['queue'] ?? null;
		self::assertIsArray( $queue );
		$persisted_chunk = $queue[0] ?? null;
		self::assertIsArray( $persisted_chunk );
		self::assertIsFloat( $persisted_chunk['value'] ?? null, 'The engine-owned run row must preserve 1.0 as a float' );
		self::assertSame( 1.0, $persisted_chunk['value'] );

		$this->assert_pending_chunk_continuation( self::FIDELITY_IDENTITY, $run_id, $group, $persisted_chunk );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must deliver the token-only continuation' );

		self::assertCount( 1, $chunked_job->process_calls, 'The token-only delivery must process the authoritative chunk exactly once' );
		$delivered_chunk = $chunked_job->process_calls[0]['chunk_args'] ?? null;
		self::assertIsArray( $delivered_chunk );
		self::assertIsFloat( $delivered_chunk['value'] ?? null, 'The real Action Scheduler path must preserve 1.0 as a float' );
		self::assertSame( $persisted_chunk, $delivered_chunk, 'Chunk processing must receive the exact persisted value' );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must observe the drained queue' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must complete the chunked job cleanup' );
		self::assertSame( array(), $chunked_job->failed_calls, 'The fidelity run must not terminalize as a failure' );
		self::assertSame(
			array(
				array(
					'run_id'     => $run_id,
					'start_args' => array(),
				),
			),
			$chunked_job->completed_calls,
			'The fidelity run must complete exactly once'
		);
	}

	// endregion.
}

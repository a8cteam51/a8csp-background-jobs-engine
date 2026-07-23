<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
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

	/** Chunked Job identity for generic and identity-specific queue filter ordering. */
	private const string QUEUE_FILTER_NAME = 'integration-chunked-job-queue-filter';

	/** Owner-qualified identity for generic and identity-specific queue filter ordering. */
	private const string QUEUE_FILTER_IDENTITY = self::OWNER . ':' . self::QUEUE_FILTER_NAME;

	/** Chunked Job identity for generic and identity-specific continuation-delay filter ordering. */
	private const string CONTINUE_DELAY_FILTER_NAME = 'integration-chunked-job-continue-delay-filter';

	/** Owner-qualified identity for generic and identity-specific continuation-delay filter ordering. */
	private const string CONTINUE_DELAY_FILTER_IDENTITY = self::OWNER . ':' . self::CONTINUE_DELAY_FILTER_NAME;

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

			$context->append_chunk( array( 'chunk' => 'tail' ) );
			$context->prepend_chunk( array( 'chunk' => 'front' ) );
		};

		$client = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::OWNER );
		$client->register( $chunked_job->definition() );

		$this->expect_option( 'a8csp_bgje_latest_run_' . self::IDENTITY );
		$continue_delay_calls = array();
		\add_filter(
			'a8csp_bgje/continue_delay',
			static function ( int $delay, string $name, string $run_id ) use ( &$continue_delay_calls ): int {
				$continue_delay_calls[] = array( $delay, $name, $run_id );

				return 0;
			},
			10,
			3
		);

		$completion_observations = array();
		\add_action(
			'a8csp_bgje/completed/' . self::IDENTITY,
			static function ( RunId $run_id, array $args, ?RunId $previous_completed_run_id ) use ( &$completion_observations ): void {
				$completion_observations[] = array(
					'hook'    => 'named',
					'payload' => array( (string) $run_id, $args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id ),
				);
			},
			10,
			3
		);
		\add_action(
			'a8csp_bgje/completed',
			static function ( string $name, RunId $run_id, array $args, ?RunId $previous_completed_run_id ) use ( &$completion_observations ): void {
				$completion_observations[] = array(
					'hook'    => 'generic',
					'payload' => array( $name, (string) $run_id, $args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id ),
				);
			},
			10,
			4
		);

		$result = $client->dispatch( self::NAME, $start_args );
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
			self::assertSame( $run_id, (string) $process_call['context']->get_run_id() );
			self::assertSame( $start_args, $process_call['context']->get_start_args() );
		}

		self::assertSame(
			array(
				array(
					'hook'    => 'named',
					'payload' => array( $run_id, $start_args, null ),
				),
				array(
					'hook'    => 'generic',
					'payload' => array( self::IDENTITY, $run_id, $start_args, null ),
				),
			),
			$completion_observations,
			'Completed hooks must preserve identity-specific then generic payload order'
		);

		$last_completed = $client->last_completed_run_id( self::NAME );
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
	 * Generic queue filtering feeds identity-specific filtering before the resulting queue persists.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_queue_filters_run_generic_then_identity_specific_before_queue_persistence(): void {
		$start_args         = array( 'scope' => 'queue-filter-order' );
		$generated_queue    = array( array( 'source' => 'generated' ) );
		$generic_queue      = array( array( 'source' => 'generic' ) );
		$specific_queue     = array( array( 'source' => 'specific' ) );
		$chunked_job        = new RecordingChunkedJob( self::QUEUE_FILTER_NAME );
		$chunked_job->queue = $generated_queue;

		$client = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::OWNER );
		$client->register( $chunked_job->definition() );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::QUEUE_FILTER_IDENTITY );

		$filter_calls = array();
		\add_filter(
			'a8csp_bgje/queue',
			static function ( array $queue, string $identity, array $args, string $run_id ) use ( &$filter_calls, $generic_queue ): array {
				$filter_calls[] = array( 'generic', $queue, $identity, $args, $run_id );

				return $generic_queue;
			},
			10,
			4
		);
		\add_filter(
			'a8csp_bgje/queue/' . self::QUEUE_FILTER_IDENTITY,
			static function ( array $queue, array $args, string $run_id ) use ( &$filter_calls, $specific_queue ): array {
				$filter_calls[] = array( 'specific', $queue, $args, $run_id );

				return $specific_queue;
			},
			10,
			3
		);
		\add_filter(
			'a8csp_bgje/continue_delay',
			static fn ( int $delay, string $identity ): int => self::QUEUE_FILTER_IDENTITY === $identity ? 0 : $delay,
			10,
			2
		);

		$result = $client->dispatch( self::QUEUE_FILTER_NAME, $start_args );
		self::assertInstanceOf( Success::class, $result );
		self::assertIsString( $result->value );
		$run_id = $result->value;

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must generate and filter the chunk queue' );
		$run_state       = \get_option( RunStore::OPTION_PREFIX . self::QUEUE_FILTER_IDENTITY . '_' . $run_id, null );
		$persisted_queue = \is_array( $run_state ) ? ( $run_state['kind_state'] ?? null ) : null;

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must process the identity-specific queue value' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must observe the filtered queue as drained' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must complete filtered-queue cleanup' );

		self::assertSame(
			array(
				array( 'generic', $generated_queue, self::QUEUE_FILTER_IDENTITY, $start_args, $run_id ),
				array( 'specific', $generic_queue, $start_args, $run_id ),
			),
			$filter_calls,
			'Queue filters must chain the generic value into the identity-specific filter'
		);
		self::assertSame( $specific_queue, $persisted_queue, 'The identity-specific queue value must be authoritative before chunk processing' );
		self::assertSame( $specific_queue, \array_column( $chunked_job->process_calls, 'chunk_args' ), 'Chunk processing must receive the identity-specific queue value' );
	}

	/**
	 * Generic continuation-delay filtering feeds identity-specific filtering between chunks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_continue_delay_filters_run_generic_then_identity_specific_between_chunks(): void {
		$chunked_job        = new RecordingChunkedJob( self::CONTINUE_DELAY_FILTER_NAME );
		$chunked_job->queue = array(
			array( 'chunk' => 'one' ),
			array( 'chunk' => 'two' ),
		);

		$client = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::OWNER );
		$client->register( $chunked_job->definition() );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::CONTINUE_DELAY_FILTER_IDENTITY );

		$filter_calls = array();
		\add_filter(
			'a8csp_bgje/continue_delay',
			static function ( int $delay, string $identity, string $run_id ) use ( &$filter_calls ): int {
				$filter_calls[] = array( 'generic', $delay, $identity, $run_id );

				return 300;
			},
			10,
			3
		);
		\add_filter(
			'a8csp_bgje/continue_delay/' . self::CONTINUE_DELAY_FILTER_IDENTITY,
			static function ( int $delay, string $run_id ) use ( &$filter_calls ): int {
				$filter_calls[] = array( 'specific', $delay, $run_id );

				return 0;
			},
			10,
			2
		);

		$result = $client->dispatch( self::CONTINUE_DELAY_FILTER_NAME, array() );
		self::assertInstanceOf( Success::class, $result );
		self::assertIsString( $result->value );
		$run_id = $result->value;
		$this->expect_option( RunStore::OPTION_PREFIX . self::CONTINUE_DELAY_FILTER_IDENTITY . '_' . $run_id );
		$this->expect_option( OverlapGuard::OPTION_PREFIX . self::CONTINUE_DELAY_FILTER_IDENTITY . '_' . self::args_hash( array() ) );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must generate the two-chunk queue' );
		$filter_calls = array();

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must process the first chunk' );
		self::assertSame(
			array(
				array( 'generic', 60, self::CONTINUE_DELAY_FILTER_IDENTITY, $run_id ),
				array( 'specific', 300, $run_id ),
			),
			$filter_calls,
			'Continue-delay filters must chain the generic value into the identity-specific filter'
		);
		self::assertSame( array( array( 'chunk' => 'one' ) ), \array_column( $chunked_job->process_calls, 'chunk_args' ) );
		$this->assert_pending_chunk_continuation( self::CONTINUE_DELAY_FILTER_IDENTITY, $run_id, self::CONTINUE_DELAY_FILTER_IDENTITY . '|' . $run_id, array( 'chunk' => 'two' ) );

		$filter_calls = array();
		self::assertSame( 1, $this->run_next_due_action(), 'The identity-specific zero delay must make the second chunk immediately due' );
		self::assertSame(
			array(
				array( 'generic', 60, self::CONTINUE_DELAY_FILTER_IDENTITY, $run_id ),
				array( 'specific', 300, $run_id ),
			),
			$filter_calls,
			'Every inter-chunk delay must preserve generic then identity-specific order'
		);
		self::assertSame(
			array(
				array( 'chunk' => 'one' ),
				array( 'chunk' => 'two' ),
			),
			\array_column( $chunked_job->process_calls, 'chunk_args' )
		);

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must observe the queue as drained' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must complete continuation-delay cleanup' );
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
		$client             = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::OWNER );
		$client->register( $chunked_job->definition() );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::FIDELITY_IDENTITY );
		\add_filter( 'a8csp_bgje/continue_delay', static fn ( int $delay, string $name, string $run_id ): int => 0, 10, 3 );

		$terminal_hooks = array();
		\add_action(
			'a8csp_bgje/completed/' . self::FIDELITY_IDENTITY,
			static function ( RunId $run_id, array $args, ?RunId $previous_completed_run_id ) use ( &$terminal_hooks ): void {
				$terminal_hooks[] = array( 'completed', (string) $run_id, $args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id );
			},
			10,
			3
		);
		\add_action(
			'a8csp_bgje/failed',
			static function ( RunFailure $failure ) use ( &$terminal_hooks ): void {
				if ( self::FIDELITY_IDENTITY === $failure->identity ) {
					$terminal_hooks[] = array( 'failed', $failure );
				}
			},
			10,
			1
		);

		$result = $client->dispatch( self::FIDELITY_NAME, array() );
		self::assertInstanceOf( Success::class, $result );
		self::assertIsString( $result->value );
		$run_id = $result->value;
		$group  = self::FIDELITY_IDENTITY . '|' . $run_id;

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must materialize the float chunk' );
		$run_state = \get_option( 'a8csp_bgje_active_run_' . self::FIDELITY_IDENTITY . '_' . $run_id, null );
		self::assertIsArray( $run_state );
		$queue = $run_state['kind_state'] ?? null;
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
		self::assertSame(
			array(
				array( 'completed', $run_id, array(), null ),
			),
			$terminal_hooks,
			'The fidelity run must publish exactly one completed hook payload'
		);
	}

	// endregion.
}

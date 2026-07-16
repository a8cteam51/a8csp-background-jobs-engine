<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\ExistingRunPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Logging\ErrorLogSink;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;

/**
 * Verifies held-lock rejection and stale crash reclaim semantics.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class OverlapLockTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Consumer owner isolated to overlap integration coverage. */
	private const OWNER = 'integration-overlap-lock';

	/** Batch identity isolated to the held-lock Skip case. */
	private const SKIP_NAME = 'integration-overlap-skip';

	/** Owner-qualified identity isolated to the held-lock Skip case. */
	private const SKIP_IDENTITY = self::OWNER . ':' . self::SKIP_NAME;

	/** Batch identity isolated to the stale crash reclaim case. */
	private const RECLAIM_NAME = 'integration-overlap-reclaim';

	/** Owner-qualified identity isolated to the stale crash reclaim case. */
	private const RECLAIM_IDENTITY = self::OWNER . ':' . self::RECLAIM_NAME;

	// endregion.

	// region TESTS.

	/**
	 * Reject under a fresh held lock returns the exact already-running failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A rejected contender must not replace the incumbent lock or enqueue another row; public outcomes cannot prove the real Action Scheduler and option-store state remained owned by the incumbent.
	 *
	 * @return  void
	 */
	public function test_reject_policy_refuses_a_fresh_held_lock(): void {
		$start_args   = array( 'scope' => 'skip' );
		$batch        = new RecordingBatch( self::SKIP_NAME );
		$batch->queue = array(
			array( 'chunk' => 'one' ),
			array( 'chunk' => 'two' ),
		);

		$this->register_batch( $batch );
		$this->expect_option( 'a8csp_bgte_latest_' . self::SKIP_IDENTITY );
		$this->filter_continue_delay_to_zero();

		$run_a     = $this->start_batch( self::SKIP_NAME, $start_args, ExistingRunPolicy::Reject );
		$group_a   = self::SKIP_IDENTITY . '|' . $run_a;
		$args_hash = self::args_hash( $start_args );
		$lock_name = 'a8csp_bgte_lock_' . self::SKIP_IDENTITY . '_' . $args_hash;

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must generate the rejecting incumbent queue' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must expose the rejecting incumbent first chunk' );
		$first_action_id = $this->assert_pending_chunk_action( self::SKIP_IDENTITY, $run_a, $group_a, array( 'chunk' => 'one' ) );

		$store               = $this->action_scheduler_store();
		$action_count_before = (int) $store->query_actions( array(), 'count' );
		$result              = \a8csp_bgte( self::OWNER )->batches()->start( self::SKIP_NAME, $start_args, existing: ExistingRunPolicy::Reject );

		self::assertInstanceOf( Failure::class, $result, 'Reject must refuse a second start under the fresh lock' );
		self::assertInstanceOf( ApiError::class, $result->error );
		self::assertSame( ApiErrorCode::OverlapHeld, $result->error->code );
		self::assertSame( array( 'run_id' => $run_a ), $result->error->context );
		self::assertSame( \sprintf( 'Batch "%1$s" is already running as run "%2$s"; wait for that run to finish before starting the same arguments.', self::SKIP_IDENTITY, $run_a ), $result->error->message, 'The rejected held-lock failure must identify the incumbent run exactly' );
		self::assertSame( $action_count_before, (int) $store->query_actions( array(), 'count' ), 'A rejected start must not create an Action Scheduler row' );
		$lock = \get_option( $lock_name, null );
		self::assertIsArray( $lock );
		self::assertSame( $run_a, $lock['run_id'] ?? null, 'A rejected start must preserve the incumbent lock owner' );
		self::assertSame(
			array(
				'all'     => $run_a,
				'by_hash' => array( $args_hash => $run_a ),
			),
			\get_option( 'a8csp_bgte_latest_' . self::SKIP_IDENTITY, null ),
			'A rejected start must preserve the incumbent latest pointers'
		);
		self::assertSame(
			array(
				'started'  => array( $run_a ),
				'terminal' => array(),
				'by_hash'  => array(
					$args_hash => array(
						'started'  => array( $run_a ),
						'terminal' => array(),
					),
				),
			),
			\get_option( 'a8csp_bgte_history_' . self::SKIP_IDENTITY, null ),
			'A rejected start must not create a second history entry'
		);

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must process the incumbent first chunk' );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $first_action_id ), 'Action Scheduler must complete the incumbent first chunk action' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must expose the incumbent second chunk' );
		$second_action_id = $this->assert_pending_chunk_action( self::SKIP_IDENTITY, $run_a, $group_a, array( 'chunk' => 'two' ) );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must process the incumbent second chunk' );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $second_action_id ), 'Action Scheduler must complete the incumbent second chunk action' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must observe the drained incumbent queue' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must complete incumbent cleanup' );

		self::assertSame( array( array( 'chunk' => 'one' ), array( 'chunk' => 'two' ) ), \array_column( $batch->process_calls, 'chunk_args' ), 'The accepted incumbent must process both chunks after the rejected start' );
		self::assertSame(
			array(
				array(
					'run_id'     => $run_a,
					'start_args' => $start_args,
				),
			),
			$batch->completed_calls,
			'The accepted incumbent must complete normally after the rejected start'
		);
		self::assertFalse( \get_option( $lock_name, false ), 'Incumbent completion must release the overlap lock' );
		self::assertFalse( \get_option( 'a8csp_bgte_run_' . self::SKIP_IDENTITY . '_' . $run_a, false ) );
		self::assertSame(
			array(
				'a8csp_bgte_history_' . self::SKIP_IDENTITY,
				'a8csp_bgte_latest_' . self::SKIP_IDENTITY,
			),
			\array_column( $this->engine_option_rows(), 'option_name' ),
			'Reject-policy completion must retain only history and latest pointer state'
		);
	}

	/**
	 * A stale crash heartbeat is reclaimed and the orphan stops before chunk execution.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Stale-lock reclaim transfers ownership while an orphaned real Action Scheduler delivery remains pending; public hooks cannot prove the old delivery failed its ownership fence before processing a chunk.
	 *
	 * @return  void
	 */
	public function test_stale_heartbeat_reclaim_supersedes_the_orphaned_run(): void {
		$start_args   = array( 'scope' => 'reclaim' );
		$batch        = new RecordingBatch( self::RECLAIM_NAME );
		$batch->queue = array(
			array( 'chunk' => 'one' ),
			array( 'chunk' => 'two' ),
		);

		$this->register_batch( $batch );
		$this->expect_option( 'a8csp_bgte_latest_' . self::RECLAIM_IDENTITY );
		$this->filter_continue_delay_to_zero();

		$named_superseded   = array();
		$generic_superseded = array();
		$log_records        = array();
		\remove_action( 'a8csp_background_tasks/log', array( ErrorLogSink::class, 'log' ), 10 );
		\add_action(
			'a8csp_background_tasks/superseded/' . self::RECLAIM_IDENTITY,
			static function ( string $run_id, array $args ) use ( &$named_superseded ): void {
				$named_superseded[] = array( $run_id, $args );
			},
			10,
			2
		);
		\add_action(
			'a8csp_background_tasks/superseded',
			static function ( string $name, string $run_id, array $args ) use ( &$generic_superseded ): void {
				$generic_superseded[] = array( $name, $run_id, $args );
			},
			10,
			3
		);
		\add_action(
			'a8csp_background_tasks/log',
			static function ( string $level, string $message, array $context ) use ( &$log_records ): void {
				$log_records[] = array( $level, $message, $context );
			},
			10,
			3
		);

		$run_a     = $this->start_batch( self::RECLAIM_NAME, $start_args, ExistingRunPolicy::Reject );
		$group_a   = self::RECLAIM_IDENTITY . '|' . $run_a;
		$args_hash = self::args_hash( $start_args );
		$lock_name = 'a8csp_bgte_lock_' . self::RECLAIM_IDENTITY . '_' . $args_hash;

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must generate the crash-simulated incumbent queue' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must expose the crash-simulated incumbent chunk' );
		$run_a_action_id = $this->assert_pending_chunk_action( self::RECLAIM_IDENTITY, $run_a, $group_a, array( 'chunk' => 'one' ) );

		$aged_lock = \get_option( $lock_name, null );
		self::assertIsArray( $aged_lock );
		$aged_lock['heartbeat_at'] = \time() - ( 15 * \MINUTE_IN_SECONDS ) - 1;
		self::assertTrue( \update_option( $lock_name, $aged_lock, false ), 'The crash simulation must age the persisted heartbeat beyond the default stale window' );

		$run_b   = $this->start_batch( self::RECLAIM_NAME, $start_args, ExistingRunPolicy::Reject );
		$group_b = self::RECLAIM_IDENTITY . '|' . $run_b;
		self::assertNotSame( $run_a, $run_b, 'Stale reclaim must allocate a fresh run identifier' );
		self::assertCount( 1, $log_records );
		self::assertSame( 'warning', $log_records[0][0] ?? null );
		self::assertSame(
			array(
				'name'        => self::RECLAIM_IDENTITY,
				'args_hash'   => $args_hash,
				'dead_run_id' => $run_a,
				'run_id'      => $run_b,
			),
			$log_records[0][2],
			'Stale reclaim must expose the dead and replacement owners as structured context'
		);
		$lock = \get_option( $lock_name, null );
		self::assertIsArray( $lock );
		self::assertSame( $run_b, $lock['run_id'] ?? null, 'The reclaimed lock must belong to the fresh run' );
		self::assertIsArray( \get_option( 'a8csp_bgte_run_' . self::RECLAIM_IDENTITY . '_' . $run_a, null ) );
		self::assertIsArray( \get_option( 'a8csp_bgte_run_' . self::RECLAIM_IDENTITY . '_' . $run_b, null ) );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must deliver the orphaned incumbent chunk after reclaim' );
		self::assertSame( array(), $batch->process_calls, 'The orphaned incumbent must stop before chunk execution' );
		self::assertSame( array( array( $run_a, $start_args ) ), $named_superseded, 'The identity-specific superseded hook must receive the reclaimed incumbent payload once' );
		self::assertSame( array( array( self::RECLAIM_IDENTITY, $run_a, $start_args ) ), $generic_superseded, 'The generic superseded hook must prepend the reclaimed batch name once' );
		self::assertFalse( \get_option( 'a8csp_bgte_run_' . self::RECLAIM_IDENTITY . '_' . $run_a, false ), 'The orphaned incumbent delivery must delete its active run option' );
		$lock = \get_option( $lock_name, null );
		self::assertIsArray( $lock );
		self::assertSame( $run_b, $lock['run_id'] ?? null, 'Orphan cleanup must preserve the reclaimed lock owner' );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $this->action_scheduler_store()->get_status( $run_a_action_id ), 'Action Scheduler must complete the quietly superseded orphan delivery' );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must generate the reclaimed run queue' );
		$this->drive_generated_batch_to_completion( $batch, self::RECLAIM_IDENTITY, $run_b, $group_b, array( array( 'chunk' => 'one' ), array( 'chunk' => 'two' ) ) );

		self::assertSame( array( array( 'chunk' => 'one' ), array( 'chunk' => 'two' ) ), \array_column( $batch->process_calls, 'chunk_args' ), 'Only the reclaimed run must process the batch chunks' );
		self::assertSame(
			array(
				array(
					'run_id'     => $run_b,
					'start_args' => $start_args,
				),
			),
			$batch->completed_calls,
			'The reclaimed run must complete normally'
		);
		self::assertSame( array(), $batch->failed_calls, 'Stale reclaim must not invoke the batch on_failed() callback' );
		self::assertSame( array( array( $run_a, $start_args ) ), $named_superseded, 'Reclaimed-run completion must not repeat the identity-specific superseded hook' );
		self::assertSame( array( array( self::RECLAIM_IDENTITY, $run_a, $start_args ) ), $generic_superseded, 'Reclaimed-run completion must not repeat the generic superseded hook' );
		self::assertCount( 2, $log_records );
		self::assertSame( array( 'warning', 'info' ), \array_column( $log_records, 0 ) );
		self::assertSame(
			array(
				'batch_name'    => self::RECLAIM_IDENTITY,
				'run_id'        => $run_a,
				'latest_run_id' => $run_b,
			),
			$log_records[1][2] ?? null,
			'Orphan cleanup must expose the superseded and current owners as structured context'
		);
		self::assertFalse( \get_option( $lock_name, false ), 'Reclaimed run completion must release the overlap lock' );
		self::assertFalse( \get_option( 'a8csp_bgte_run_' . self::RECLAIM_IDENTITY . '_' . $run_b, false ) );
		self::assertFalse( \get_option( 'a8csp_bgte_failed_' . self::RECLAIM_IDENTITY, false ) );
		self::assertSame(
			array(
				'started'  => array( $run_a, $run_b ),
				'terminal' => array(
					array(
						'run_id' => $run_a,
						'status' => 'superseded',
					),
					array(
						'run_id' => $run_b,
						'status' => 'completed',
					),
				),
				'by_hash'  => array(
					$args_hash => array(
						'started'  => array( $run_a, $run_b ),
						'terminal' => array(
							array(
								'run_id' => $run_a,
								'status' => 'superseded',
							),
							array(
								'run_id' => $run_b,
								'status' => 'completed',
							),
						),
					),
				),
			),
			\get_option( 'a8csp_bgte_history_' . self::RECLAIM_IDENTITY, null ),
			'Reclaim history must retain the superseded orphan and completed replacement'
		);
		self::assertSame(
			array(
				'a8csp_bgte_history_' . self::RECLAIM_IDENTITY,
				'a8csp_bgte_latest_' . self::RECLAIM_IDENTITY,
			),
			\array_column( $this->engine_option_rows(), 'option_name' ),
			'Reclaim completion must retain only history and latest pointer state'
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Registers one batch through the live engine facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RecordingBatch $batch Batch fixture.
	 *
	 * @return  void
	 */
	private function register_batch( RecordingBatch $batch ): void {
		\a8csp_bgte( self::OWNER )->batches()->register( $batch );
	}

	/**
	 * Forces inter-chunk actions due immediately for deterministic runner sequencing.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function filter_continue_delay_to_zero(): void {
		\add_filter( 'a8csp_background_tasks/continue_delay', static fn ( int $delay, string $name, string $run_id ): int => 0, 10, 3 );
	}

	/**
	 * Starts a batch through the owner-bound facade and returns its run identifier.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name       Stable batch name.
	 * @param   array<array-key, mixed> $start_args Batch start arguments.
	 * @param   ExistingRunPolicy       $existing   Behavior when a fresh matching run exists.
	 *
	 * @return  string
	 */
	private function start_batch( string $name, array $start_args, ExistingRunPolicy $existing = ExistingRunPolicy::Replace ): string {
		$result = \a8csp_bgte( self::OWNER )->batches()->start( $name, $start_args, existing: $existing );
		self::assertInstanceOf( Success::class, $result, 'The batch must start through the public API' );
		self::assertIsString( $result->value );

		return $result->value;
	}

	/**
	 * Drives a generated queue through per-chunk actions and terminal cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RecordingBatch                $batch           Batch fixture.
	 * @param   string                        $name            Stable batch name.
	 * @param   string                        $run_id          Run identifier.
	 * @param   string                        $group           Per-run Action Scheduler group.
	 * @param   list<array<array-key, mixed>> $expected_chunks Expected chunks in processing order.
	 *
	 * @return  void
	 */
	private function drive_generated_batch_to_completion(
		RecordingBatch $batch,
		string $name,
		string $run_id,
		string $group,
		array $expected_chunks
	): void {
		foreach ( $expected_chunks as $expected_chunk ) {
			$process_call_count = \count( $batch->process_calls );
			self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must expose one accepted chunk' );
			self::assertCount( $process_call_count, $batch->process_calls, 'An accepted continue action must not process its exposed chunk inline' );
			$action_id = $this->assert_pending_chunk_action( $name, $run_id, $group, $expected_chunk );
			self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute one accepted chunk' );
			self::assertCount( $process_call_count + 1, $batch->process_calls, 'An accepted run action must process one chunk' );
			self::assertSame( $expected_chunk, $batch->process_calls[ $process_call_count ]['chunk_args'] ?? null, 'An accepted run action must process the chunk exposed by its continue action' );
			self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $this->action_scheduler_store()->get_status( $action_id ), 'Action Scheduler must complete the accepted chunk action' );
		}

		$process_calls_before = $batch->process_calls;
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must observe the accepted run drained queue' );
		self::assertSame( $process_calls_before, $batch->process_calls, 'The drained-queue continue action must not execute chunk work' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute accepted run cleanup' );
	}

	// endregion.
}

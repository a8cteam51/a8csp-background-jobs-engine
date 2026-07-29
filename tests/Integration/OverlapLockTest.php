<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\AbstractIntegrationTestCase;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;

/**
 * Verifies held-lock rejection and stale crash reclaim semantics.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class OverlapLockTest extends AbstractIntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Client scope isolated to overlap integration coverage. */
	private const string SCOPE = 'integration-overlap-lock';

	/** Chunked Job identity isolated to the held-lock Reject case. */
	private const string REJECT_NAME = 'integration-overlap-reject';

	/** Scope-qualified identity isolated to the held-lock Reject case. */
	private const string REJECT_IDENTITY = self::SCOPE . ':' . self::REJECT_NAME;

	/** Chunked Job identity isolated to the stale crash reclaim case. */
	private const string RECLAIM_NAME = 'integration-overlap-reclaim';

	/** Scope-qualified identity isolated to the stale crash reclaim case. */
	private const string RECLAIM_IDENTITY = self::SCOPE . ':' . self::RECLAIM_NAME;

	/** Chunked Job identity isolated to lock-staleness filter ordering. */
	private const string FILTER_NAME = 'integration-overlap-filter-order';

	/** Scope-qualified identity isolated to lock-staleness filter ordering. */
	private const string FILTER_IDENTITY = self::SCOPE . ':' . self::FILTER_NAME;

	// endregion.

	// region TESTS.

	/**
	 * Lock-staleness filters compose generic then identity-specific during dispatch.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_lock_staleness_filters_run_generic_then_identity_specific_during_dispatch(): void {
		$start_args         = array( 'scope' => 'filter-order' );
		$chunked_job        = new RecordingChunkedJob( self::FILTER_NAME );
		$chunked_job->queue = array( array( 'chunk' => 'only' ) );

		$this->register_chunked_job( $chunked_job );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::FILTER_IDENTITY );
		$this->filter_continue_delay_to_zero();

		$run_id    = $this->start( self::FILTER_NAME, $start_args );
		$args_hash = self::args_hash( $start_args );
		$lock_name = 'a8csp_bgje_overlap_lock_' . self::FILTER_IDENTITY . '_' . $args_hash;
		$aged_lock = \get_option( $lock_name, null );
		self::assertIsArray( $aged_lock );
		$aged_lock['heartbeat_at'] = \time() - \HOUR_IN_SECONDS;
		self::assertTrue( \update_option( $lock_name, $aged_lock, false ), 'The lock fixture must be older than the generic window and younger than the identity-specific window' );

		$observations    = array();
		$generic_filter  = static function ( int $seconds, string $identity ) use ( &$observations ): int {
			$observations[] = array( 'generic', $seconds, $identity );

			return \MINUTE_IN_SECONDS;
		};
		$specific_filter = static function ( int $seconds ) use ( &$observations ): int {
			$observations[] = array( 'specific', $seconds );

			return \DAY_IN_SECONDS;
		};
		\add_filter( 'a8csp_bgje/lock_staleness', $generic_filter, 10, 2 );
		\add_filter( 'a8csp_bgje/lock_staleness/' . self::FILTER_IDENTITY, $specific_filter, 10, 1 );

		try {
			$result = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::SCOPE )->dispatch( self::FILTER_NAME, $start_args );
		} finally {
			\remove_filter( 'a8csp_bgje/lock_staleness', $generic_filter, 10 );
			\remove_filter( 'a8csp_bgje/lock_staleness/' . self::FILTER_IDENTITY, $specific_filter, 10 );
		}

		self::assertInstanceOf( Failure::class, $result, 'The identity-specific day-long window must keep the hour-old incumbent lock held' );
		self::assertInstanceOf( BoundaryError::class, $result->error );
		self::assertSame( ErrorCode::OverlapHeld, $result->error->code );
		self::assertSame(
			array(
				array( 'generic', 15 * \MINUTE_IN_SECONDS, self::FILTER_IDENTITY ),
				array( 'specific', \MINUTE_IN_SECONDS ),
			),
			$observations,
			'The identity-specific filter must receive and override the generic filter result'
		);
		$held_lock = \get_option( $lock_name, null );
		self::assertIsArray( $held_lock );
		self::assertSame( $run_id, $held_lock['run_id'] ?? null, 'The final identity-specific window must preserve the incumbent lock owner' );

		$held_lock['heartbeat_at'] = \time();
		self::assertTrue( \update_option( $lock_name, $held_lock, false ), 'The accepted incumbent must regain a current heartbeat before its queued delivery runs' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must generate the accepted incumbent queue' );
		$this->drive_generated_chunked_job_to_completion(
			$chunked_job,
			self::FILTER_IDENTITY,
			$run_id,
			self::FILTER_IDENTITY,
			array( array( 'chunk' => 'only' ) )
		);
	}

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
		$start_args         = array( 'scope' => 'reject' );
		$chunked_job        = new RecordingChunkedJob( self::REJECT_NAME );
		$chunked_job->queue = array(
			array( 'chunk' => 'one' ),
			array( 'chunk' => 'two' ),
		);

		$this->register_chunked_job( $chunked_job );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::REJECT_IDENTITY );
		$this->filter_continue_delay_to_zero();

		$completion_observations = array();
		\add_action(
			'a8csp_bgje/completed/' . self::REJECT_IDENTITY,
			static function ( RunId $run_id, array $args, ?RunId $previous_completed_run_id ) use ( &$completion_observations ): void {
				$completion_observations[] = array( 'named', (string) $run_id, $args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id );
			},
			10,
			3
		);
		\add_action(
			'a8csp_bgje/completed',
			static function ( string $name, RunId $run_id, array $args, ?RunId $previous_completed_run_id ) use ( &$completion_observations ): void {
				$completion_observations[] = array( 'generic', $name, (string) $run_id, $args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id );
			},
			10,
			4
		);

		$run_a     = $this->start( self::REJECT_NAME, $start_args );
		$group_a   = self::REJECT_IDENTITY;
		$args_hash = self::args_hash( $start_args );
		$lock_name = 'a8csp_bgje_overlap_lock_' . self::REJECT_IDENTITY . '_' . $args_hash;

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must generate the rejecting incumbent queue' );
		$first_action_id = $this->assert_pending_chunk_continuation( self::REJECT_IDENTITY, $run_a, $group_a, array( 'chunk' => 'one' ) );

		$store               = $this->action_scheduler_store();
		$action_count_before = (int) $store->query_actions( array(), 'count' );
		$result              = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::SCOPE )->dispatch( self::REJECT_NAME, $start_args );

		self::assertInstanceOf( Failure::class, $result, 'Reject must refuse a second start under the fresh lock' );
		self::assertInstanceOf( BoundaryError::class, $result->error );
		self::assertSame( ErrorCode::OverlapHeld, $result->error->code );
		self::assertSame( array( 'run_id' => $run_a ), $result->error->context );
		self::assertSame( \sprintf( 'chunked_job "%1$s" is already running as run "%2$s"; wait for that run to finish before dispatching the same arguments or overlap key.', self::REJECT_IDENTITY, $run_a ), $result->error->message, 'The rejected held-lock failure must identify the incumbent run exactly' );
		self::assertSame( $action_count_before, (int) $store->query_actions( array(), 'count' ), 'A rejected start must not create an Action Scheduler row' );
		$lock = \get_option( $lock_name, null );
		self::assertIsArray( $lock );
		self::assertSame( $run_a, $lock['run_id'] ?? null, 'A rejected start must preserve the incumbent lock owner' );
		self::assertSame(
			array(
				'by_hash' => array( $args_hash => $run_a ),
			),
			\get_option( 'a8csp_bgje_latest_run_' . self::REJECT_IDENTITY, null ),
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
			\get_option( 'a8csp_bgje_run_history_' . self::REJECT_IDENTITY, null ),
			'A rejected start must not create a second history entry'
		);

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must process the incumbent first chunk' );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $first_action_id ), 'Action Scheduler must complete the incumbent first continuation' );
		$second_action_id = $this->assert_pending_chunk_continuation( self::REJECT_IDENTITY, $run_a, $group_a, array( 'chunk' => 'two' ) );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must process the incumbent second chunk' );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $second_action_id ), 'Action Scheduler must complete the incumbent second continuation' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must observe the drained incumbent queue' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must complete incumbent cleanup' );

		self::assertSame( array( array( 'chunk' => 'one' ), array( 'chunk' => 'two' ) ), \array_column( $chunked_job->process_calls, 'chunk_args' ), 'The accepted incumbent must process both chunks after the rejected start' );
		self::assertSame(
			array(
				array( 'named', $run_a, $start_args, null ),
				array( 'generic', self::REJECT_IDENTITY, $run_a, $start_args, null ),
			),
			$completion_observations,
			'The accepted incumbent must publish its identity-specific and generic completion payloads in order'
		);
		self::assertFalse( \get_option( $lock_name, false ), 'Incumbent completion must release the overlap lock' );
		self::assertFalse( \get_option( 'a8csp_bgje_active_run_' . self::REJECT_IDENTITY . '_' . $run_a, false ) );
		self::assertSame(
			array(
				'a8csp_bgje_latest_run_' . self::REJECT_IDENTITY,
				'a8csp_bgje_run_history_' . self::REJECT_IDENTITY,
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
		$start_args         = array( 'scope' => 'reclaim' );
		$chunked_job        = new RecordingChunkedJob( self::RECLAIM_NAME );
		$chunked_job->queue = array(
			array( 'chunk' => 'one' ),
			array( 'chunk' => 'two' ),
		);

		$this->register_chunked_job( $chunked_job );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::RECLAIM_IDENTITY );
		$this->filter_continue_delay_to_zero();

		$named_superseded   = array();
		$generic_superseded = array();
		$terminal_hooks     = array();
		$log_records        = array();
		\add_filter( 'a8csp_bgje/log_to_error_log', static fn (): bool => false );
		\add_action(
			'a8csp_bgje/superseded/' . self::RECLAIM_IDENTITY,
			static function ( RunId $run_id, array $args ) use ( &$named_superseded ): void {
				$named_superseded[] = array( (string) $run_id, $args );
			},
			10,
			2
		);
		\add_action(
			'a8csp_bgje/superseded',
			static function ( string $name, RunId $run_id, array $args ) use ( &$generic_superseded ): void {
				$generic_superseded[] = array( $name, (string) $run_id, $args );
			},
			10,
			3
		);
		\add_action(
			'a8csp_bgje/completed/' . self::RECLAIM_IDENTITY,
			static function ( RunId $run_id, array $args, ?RunId $previous_completed_run_id ) use ( &$terminal_hooks ): void {
				$terminal_hooks[] = array( 'completed-named', (string) $run_id, $args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id );
			},
			10,
			3
		);
		\add_action(
			'a8csp_bgje/completed',
			static function ( string $name, RunId $run_id, array $args, ?RunId $previous_completed_run_id ) use ( &$terminal_hooks ): void {
				if ( self::RECLAIM_IDENTITY === $name ) {
					$terminal_hooks[] = array( 'completed-generic', $name, (string) $run_id, $args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id );
				}
			},
			10,
			4
		);
		\add_action(
			'a8csp_bgje/failed',
			static function ( RunFailure $failure ) use ( &$terminal_hooks ): void {
				if ( self::RECLAIM_IDENTITY === $failure->identity ) {
					$terminal_hooks[] = array( 'failed', $failure );
				}
			},
			10,
			1
		);
		\add_action(
			'a8csp_bgje/log',
			static function ( string $level, string $message, array $context ) use ( &$log_records ): void {
				$log_records[] = array( $level, $message, $context );
			},
			10,
			3
		);

		$run_a     = $this->start( self::RECLAIM_NAME, $start_args );
		$group_a   = self::RECLAIM_IDENTITY;
		$args_hash = self::args_hash( $start_args );
		$lock_name = 'a8csp_bgje_overlap_lock_' . self::RECLAIM_IDENTITY . '_' . $args_hash;

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must generate the crash-simulated incumbent queue' );
		$run_a_action_id = $this->assert_pending_chunk_continuation( self::RECLAIM_IDENTITY, $run_a, $group_a, array( 'chunk' => 'one' ) );

		$aged_lock = \get_option( $lock_name, null );
		self::assertIsArray( $aged_lock );
		$aged_lock['heartbeat_at'] = \time() - ( 15 * \MINUTE_IN_SECONDS ) - 1;
		self::assertTrue( \update_option( $lock_name, $aged_lock, false ), 'The crash simulation must age the persisted heartbeat beyond the default stale window' );

		$run_b   = $this->start( self::RECLAIM_NAME, $start_args );
		$group_b = self::RECLAIM_IDENTITY;
		self::assertNotSame( $run_a, $run_b, 'Stale reclaim must allocate a fresh run identifier' );
		self::assertCount( 1, $log_records );
		self::assertSame( 'info', $log_records[0][0] ?? null );
		self::assertSame(
			array(
				'identity'      => self::RECLAIM_IDENTITY,
				'run_id'        => $run_a,
				'latest_run_id' => $run_b,
			),
			$log_records[0][2],
			'Stale takeover must expose the superseded and replacement owners as structured context'
		);
		$lock = \get_option( $lock_name, null );
		self::assertIsArray( $lock );
		self::assertSame( $run_b, $lock['run_id'] ?? null, 'The reclaimed lock must belong to the fresh run' );
		self::assertFalse( \get_option( 'a8csp_bgje_active_run_' . self::RECLAIM_IDENTITY . '_' . $run_a, false ), 'Stale takeover must finish the incumbent supersession during admission' );
		self::assertIsArray( \get_option( 'a8csp_bgje_active_run_' . self::RECLAIM_IDENTITY . '_' . $run_b, null ) );
		self::assertSame( array( array( $run_a, $start_args ) ), $named_superseded, 'Stale takeover must publish the identity-specific superseded hook during admission' );
		self::assertSame( array( array( self::RECLAIM_IDENTITY, $run_a, $start_args ) ), $generic_superseded, 'Stale takeover must publish the generic superseded hook during admission' );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must deliver the orphaned incumbent chunk after reclaim' );
		self::assertSame( array(), $chunked_job->process_calls, 'The orphaned incumbent must stop before chunk execution' );
		self::assertSame( array( array( $run_a, $start_args ) ), $named_superseded, 'The orphaned incumbent delivery must not repeat the identity-specific superseded hook' );
		self::assertSame( array( array( self::RECLAIM_IDENTITY, $run_a, $start_args ) ), $generic_superseded, 'The orphaned incumbent delivery must not repeat the generic superseded hook' );
		self::assertFalse( \get_option( 'a8csp_bgje_active_run_' . self::RECLAIM_IDENTITY . '_' . $run_a, false ), 'The orphaned incumbent delivery must leave its admission-time cleanup intact' );
		$lock = \get_option( $lock_name, null );
		self::assertIsArray( $lock );
		self::assertSame( $run_b, $lock['run_id'] ?? null, 'Orphan cleanup must preserve the reclaimed lock owner' );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $this->action_scheduler_store()->get_status( $run_a_action_id ), 'Action Scheduler must complete the quietly superseded orphan delivery' );
		self::assertCount( 2, $log_records );
		self::assertSame( array( 'info', 'debug' ), \array_column( $log_records, 0 ) );
		self::assertSame( 'Stale delivery for a finished or cancelled run was dropped.', $log_records[1][1] ?? null );
		self::assertSame(
			array(
				'identity' => self::RECLAIM_IDENTITY,
				'run_id'   => $run_a,
			),
			$log_records[1][2],
			'The orphaned delivery must identify the already-finished incumbent'
		);

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must generate the reclaimed run queue' );
		$this->drive_generated_chunked_job_to_completion( $chunked_job, self::RECLAIM_IDENTITY, $run_b, $group_b, array( array( 'chunk' => 'one' ), array( 'chunk' => 'two' ) ) );

		self::assertSame( array( array( 'chunk' => 'one' ), array( 'chunk' => 'two' ) ), \array_column( $chunked_job->process_calls, 'chunk_args' ), 'Only the reclaimed run must process the chunked job chunks' );
		self::assertSame(
			array(
				array( 'completed-named', $run_b, $start_args, null ),
				array( 'completed-generic', self::RECLAIM_IDENTITY, $run_b, $start_args, null ),
			),
			$terminal_hooks,
			'The reclaimed run must publish only its identity-specific and generic completion payloads in order'
		);
		self::assertSame( array( array( $run_a, $start_args ) ), $named_superseded, 'Reclaimed-run completion must not repeat the identity-specific superseded hook' );
		self::assertSame( array( array( self::RECLAIM_IDENTITY, $run_a, $start_args ) ), $generic_superseded, 'Reclaimed-run completion must not repeat the generic superseded hook' );
		self::assertCount( 2, $log_records );
		self::assertSame( array( 'info', 'debug' ), \array_column( $log_records, 0 ) );
		self::assertSame(
			array(
				'identity'      => self::RECLAIM_IDENTITY,
				'run_id'        => $run_a,
				'latest_run_id' => $run_b,
			),
			$log_records[0][2],
			'Admission-time supersession must retain its structured context'
		);
		self::assertFalse( \get_option( $lock_name, false ), 'Reclaimed run completion must release the overlap lock' );
		self::assertFalse( \get_option( 'a8csp_bgje_active_run_' . self::RECLAIM_IDENTITY . '_' . $run_b, false ) );
		self::assertFalse( \get_option( 'a8csp_bgje_failed_runs_' . self::RECLAIM_IDENTITY, false ) );
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
			\get_option( 'a8csp_bgje_run_history_' . self::RECLAIM_IDENTITY, null ),
			'Reclaim history must retain the superseded orphan and completed replacement'
		);
		self::assertSame(
			array(
				'a8csp_bgje_latest_run_' . self::RECLAIM_IDENTITY,
				'a8csp_bgje_run_history_' . self::RECLAIM_IDENTITY,
			),
			\array_column( $this->engine_option_rows(), 'option_name' ),
			'Reclaim completion must retain only history and latest pointer state'
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Registers one chunked job through the live engine facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RecordingChunkedJob $chunked_job Chunked Job fixture.
	 *
	 * @return  void
	 */
	private function register_chunked_job( RecordingChunkedJob $chunked_job ): void {
		\A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::SCOPE )->register( $chunked_job->definition() );
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
		\add_filter( 'a8csp_bgje/continue_delay', static fn ( int $delay, string $name, string $run_id ): int => 0, 10, 3 );
	}

	/**
	 * Starts a chunked job through the scope-bound facade and returns its run identifier.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name       Stable chunked job name.
	 * @param   array<array-key, mixed> $start_args Chunked Job start arguments.
	 *
	 * @return  string
	 */
	private function start( string $name, array $start_args ): string {
		$result = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::SCOPE )->dispatch( $name, $start_args );
		self::assertInstanceOf( Success::class, $result, 'The chunked job must start through the public API' );
		self::assertInstanceOf( Run::class, $result->value );

		return (string) $result->value->id;
	}

	/**
	 * Drives a generated queue through per-chunk actions and terminal cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RecordingChunkedJob                $chunked_job     Chunked Job fixture.
	 * @param   string                             $name            Stable chunked job name.
	 * @param   string                             $run_id          Run identifier.
	 * @param   string                             $group           Identity Action Scheduler group.
	 * @param   list<array<array-key, mixed>>      $expected_chunks Expected chunks in processing order.
	 *
	 * @return  void
	 */
	private function drive_generated_chunked_job_to_completion(
		RecordingChunkedJob $chunked_job,
		string $name,
		string $run_id,
		string $group,
		array $expected_chunks
	): void {
		foreach ( $expected_chunks as $expected_chunk ) {
			$process_call_count = \count( $chunked_job->process_calls );
			$action_id          = $this->assert_pending_chunk_continuation( $name, $run_id, $group, $expected_chunk );
			self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute one accepted continuation' );
			self::assertCount( $process_call_count + 1, $chunked_job->process_calls, 'An accepted continue action must process one chunk' );
			self::assertSame( $expected_chunk, $chunked_job->process_calls[ $process_call_count ]['chunk_args'] ?? null, 'An accepted continue action must process the authoritative queue head' );
			self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $this->action_scheduler_store()->get_status( $action_id ), 'Action Scheduler must complete the accepted continuation' );
		}

		$process_calls_before = $chunked_job->process_calls;
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must observe the accepted run drained queue' );
		self::assertSame( $process_calls_before, $chunked_job->process_calls, 'The drained-queue continue action must not execute chunk work' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute accepted run cleanup' );
	}

	// endregion.
}

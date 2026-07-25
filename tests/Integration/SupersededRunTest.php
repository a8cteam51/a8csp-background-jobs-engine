<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\ChunkedRunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\AbstractIntegrationTestCase;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;

/**
 * Verifies a replacement chunked job fences stale deliveries before the newer run completes.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class SupersededRunTest extends AbstractIntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Client owner isolated to supersession coverage. */
	private const string OWNER = 'integration-superseded';

	/** Chunked Job identity unique within the request-persistent integration registry. */
	private const string NAME = 'integration-superseded-run';

	/** Owner-qualified chunked job identity persisted by the engine. */
	private const string IDENTITY = self::OWNER . ':' . self::NAME;

	// endregion.

	// region TESTS.

	/**
	 * A same-arguments replacement supersedes the incumbent and completes without stale work.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Replacement transfers the overlap fence while stale start and chunk deliveries remain in the real queue; public lifecycle hooks cannot prove those exact deliveries lost ownership before invoking consumer work.
	 *
	 * @return  void
	 */
	public function test_replacement_supersedes_incumbent_before_stale_chunk_execution(): void {
		$start_args         = array(
			'site_id' => 73,
			'mode'    => 'replace',
		);
		$chunked_job        = new RecordingChunkedJob( self::NAME );
		$chunked_job->queue = array(
			array( 'chunk' => 'one' ),
			array( 'chunk' => 'two' ),
		);

		$client = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::OWNER );
		$client->register( $chunked_job->definition( new JobOptions( overlap: OverlapPolicy::Replace ) ) );

		$this->expect_option( 'a8csp_bgje_latest_run_' . self::IDENTITY );
		\add_filter( 'a8csp_bgje/continue_delay', static fn ( int $delay, string $name, string $run_id ): int => 0, 10, 3 );

		/** @var list<array{string, array<array-key, mixed>}> $named_superseded */
		$named_superseded = array();
		/** @var list<array{string, string, array<array-key, mixed>}> $generic_superseded */
		$generic_superseded = array();
		/** @var list<array{string, array<array-key, mixed>, string|null}> $named_completed */
		$named_completed = array();
		/** @var list<array{string, string, array<array-key, mixed>, string|null}> $generic_completed */
		$generic_completed = array();
		/** @var list<RunFailure> $failed */
		$failed = array();
		/** @var list<array{string, string, array<array-key, mixed>}> $log_records */
		$log_records = array();
		\add_filter( 'a8csp_bgje/log_to_error_log', static fn (): bool => false );
		\add_action(
			'a8csp_bgje/superseded/' . self::IDENTITY,
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
			'a8csp_bgje/completed/' . self::IDENTITY,
			static function ( RunId $run_id, array $args, ?RunId $previous_completed_run_id ) use ( &$named_completed ): void {
				$named_completed[] = array( (string) $run_id, $args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id );
			},
			10,
			3
		);
		\add_action(
			'a8csp_bgje/completed',
			static function ( string $name, RunId $run_id, array $args, ?RunId $previous_completed_run_id ) use ( &$generic_completed ): void {
				$generic_completed[] = array( $name, (string) $run_id, $args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id );
			},
			10,
			4
		);
		\add_action(
			'a8csp_bgje/failed',
			static function ( RunFailure $failure ) use ( &$failed ): void {
				if ( self::IDENTITY === $failure->identity ) {
					$failed[] = $failure;
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

		$run_a_result = $client->dispatch( self::NAME, $start_args );
		self::assertInstanceOf( Success::class, $run_a_result, 'The incumbent chunked job must start through the public API' );
		self::assertInstanceOf( Run::class, $run_a_result->value );
		$run_a      = (string) $run_a_result->value->id;
		$group_a    = self::IDENTITY . '|' . $run_a;
		$start_a_id = $this->assert_pending_start_action( $run_a, $group_a );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must generate the incumbent queue' );
		self::assertSame( array( $start_args ), $chunked_job->generate_calls, 'The incumbent start action must generate its queue once' );
		self::assertSame( array(), $chunked_job->process_calls, 'Queue generation must not process a chunk inline' );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $this->action_scheduler_store()->get_status( $start_a_id ), 'Action Scheduler must complete the incumbent start action' );

		$run_a_action_id = $this->assert_pending_chunk_continuation( self::IDENTITY, $run_a, $group_a, array( 'chunk' => 'one' ) );

		$run_b_result = $client->dispatch( self::NAME, $start_args );
		self::assertInstanceOf( Success::class, $run_b_result, 'A normal chunked job start must replace the same-arguments incumbent' );
		self::assertInstanceOf( Run::class, $run_b_result->value );
		$run_b      = (string) $run_b_result->value->id;
		$group_b    = self::IDENTITY . '|' . $run_b;
		$start_b_id = $this->assert_pending_start_action( $run_b, $group_b );
		self::assertNotSame( $run_a, $run_b, 'Replacement must allocate a fresh run identifier' );

		$args_hash = self::args_hash( $start_args );
		$lock      = \get_option( 'a8csp_bgje_overlap_lock_' . self::IDENTITY . '_' . $args_hash, null );
		self::assertIsArray( $lock );
		self::assertSame( $run_b, $lock['run_id'] ?? null, 'The replacement chunked job must take ownership of the overlap lock' );
		self::assertSame(
			array(
				'all'     => $run_b,
				'by_hash' => array( $args_hash => $run_b ),
			),
			\get_option( 'a8csp_bgje_latest_run_' . self::IDENTITY, null ),
			'The replacement chunked job must become latest for the shared argument identity'
		);
		self::assertFalse( \get_option( 'a8csp_bgje_active_run_' . self::IDENTITY . '_' . $run_a, false ), 'Replacement admission must finish the incumbent supersession' );
		self::assertIsArray( \get_option( 'a8csp_bgje_active_run_' . self::IDENTITY . '_' . $run_b, null ) );
		self::assertSame( array( array( $run_a, $start_args ) ), $named_superseded, 'Replacement admission must publish the identity-specific superseded hook' );
		self::assertSame( array( array( self::IDENTITY, $run_a, $start_args ) ), $generic_superseded, 'Replacement admission must publish the generic superseded hook' );
		self::assertCount( 1, $log_records );
		self::assertSame( 'info', $log_records[0][0] ?? null );
		self::assertSame(
			array(
				'identity'      => self::IDENTITY,
				'run_id'        => $run_a,
				'latest_run_id' => $run_b,
			),
			$log_records[0][2],
			'Admission-time supersession must expose incumbent and replacement ownership as structured context'
		);

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must deliver the incumbent chunk after replacement' );

		self::assertSame( array(), $chunked_job->process_calls, 'The superseded incumbent delivery must not execute chunk work' );
		self::assertSame( array( array( $run_a, $start_args ) ), $named_superseded, 'The stale incumbent delivery must not repeat the identity-specific superseded hook' );
		self::assertSame( array( array( self::IDENTITY, $run_a, $start_args ) ), $generic_superseded, 'The stale incumbent delivery must not repeat the generic superseded hook' );
		self::assertCount( 2, $log_records );
		self::assertSame( array( 'info', 'debug' ), \array_column( $log_records, 0 ) );
		self::assertSame( 'Stale delivery for a finished or cancelled run was dropped.', $log_records[1][1] ?? null );
		self::assertSame(
			array(
				'identity' => self::IDENTITY,
				'run_id'   => $run_a,
			),
			$log_records[1][2],
			'The stale delivery must identify the already-finished incumbent'
		);
		$stale_delivery_log_records = $log_records;
		self::assertFalse( \get_option( 'a8csp_bgje_active_run_' . self::IDENTITY . '_' . $run_a, false ), 'The stale incumbent delivery must leave its admission-time cleanup intact' );
		$lock = \get_option( 'a8csp_bgje_overlap_lock_' . self::IDENTITY . '_' . $args_hash, null );
		self::assertIsArray( $lock );
		self::assertSame( $run_b, $lock['run_id'] ?? null, 'Incumbent cleanup must preserve the replacement lock owner' );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $this->action_scheduler_store()->get_status( $run_a_action_id ), 'Action Scheduler must complete the quietly superseded incumbent delivery' );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must generate the replacement queue' );
		self::assertSame( array( $start_args, $start_args ), $chunked_job->generate_calls, 'The incumbent and replacement must each generate their own queue' );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $this->action_scheduler_store()->get_status( $start_b_id ), 'Action Scheduler must complete the replacement start action' );

		$expected_chunks  = array(
			array( 'chunk' => 'one' ),
			array( 'chunk' => 'two' ),
		);
		$run_b_action_ids = array();
		foreach ( $expected_chunks as $expected_chunk ) {
			/** @var list<array{chunk_args: array<array-key, mixed>, context: ChunkedRunContextInterface}> $process_calls */
			$process_calls      = $chunked_job->process_calls;
			$process_call_count = \count( $process_calls );
			$run_b_action_ids[] = $this->assert_pending_chunk_continuation( self::IDENTITY, $run_b, $group_b, $expected_chunk );
			self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute one replacement continuation' );
			/** @var list<array{chunk_args: array<array-key, mixed>, context: ChunkedRunContextInterface}> $process_calls */
			$process_calls = $chunked_job->process_calls;
			self::assertCount( $process_call_count + 1, $process_calls, 'A replacement continue action must process one chunk' );
			self::assertSame( $expected_chunk, $process_calls[ $process_call_count ]['chunk_args'] ?? null, 'The replacement continue action must process the authoritative queue head' );
		}

		/** @var list<array{chunk_args: array<array-key, mixed>, context: ChunkedRunContextInterface}> $process_calls_before */
		$process_calls_before = $chunked_job->process_calls;
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must observe the drained replacement queue' );
		self::assertSame( $process_calls_before, $chunked_job->process_calls, 'The drained-queue continue action must not execute chunk work' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute replacement terminal cleanup' );

		/** @var list<array{chunk_args: array<array-key, mixed>, context: ChunkedRunContextInterface}> $process_calls */
		$process_calls = $chunked_job->process_calls;
		self::assertSame( $expected_chunks, \array_column( $process_calls, 'chunk_args' ) );
		foreach ( $process_calls as $process_call ) {
			self::assertSame( $run_b, (string) $process_call['context']->get_run_id() );
			self::assertSame( $start_args, $process_call['context']->get_start_args() );
		}
		foreach ( $run_b_action_ids as $action_id ) {
			self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $this->action_scheduler_store()->get_status( $action_id ), 'Action Scheduler must complete every replacement chunk action' );
		}
		self::assertSame( array( array( $run_b, $start_args, null ) ), $named_completed, 'The identity-specific completed hook must receive only the replacement payload' );
		self::assertSame( array( array( self::IDENTITY, $run_b, $start_args, null ) ), $generic_completed, 'The generic completed hook must prepend the chunked job name to the replacement payload' );
		self::assertSame( array(), $failed, 'Supersession and replacement completion must not publish a failed hook' );
		self::assertSame( array( array( $run_a, $start_args ) ), $named_superseded, 'The replacement lifecycle must not repeat the identity-specific superseded hook' );
		self::assertSame( array( array( self::IDENTITY, $run_a, $start_args ) ), $generic_superseded, 'The replacement lifecycle must not repeat the generic superseded hook' );
		self::assertSame( $stale_delivery_log_records, $log_records, 'Replacement completion must not add logs after the stale-delivery diagnostic' );

		self::assertFalse( \get_option( 'a8csp_bgje_active_run_' . self::IDENTITY . '_' . $run_a, false ) );
		self::assertFalse( \get_option( 'a8csp_bgje_active_run_' . self::IDENTITY . '_' . $run_b, false ) );
		self::assertFalse( \get_option( 'a8csp_bgje_overlap_lock_' . self::IDENTITY . '_' . $args_hash, false ), 'Terminal replacement success must release the overlap lock' );
		self::assertFalse( \get_option( 'a8csp_bgje_failed_runs_' . self::IDENTITY, false ), 'Supersession and replacement success must not retain failed-run state' );
		self::assertSame(
			array(
				'all'     => $run_b,
				'by_hash' => array( $args_hash => $run_b ),
			),
			\get_option( 'a8csp_bgje_latest_run_' . self::IDENTITY, null ),
			'Terminal replacement success must retain the replacement pointers'
		);
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
			\get_option( 'a8csp_bgje_run_history_' . self::IDENTITY, null ),
			'History must retain the superseded incumbent and completed replacement in lifecycle order'
		);
		self::assertSame(
			array(
				'a8csp_bgje_latest_run_' . self::IDENTITY,
				'a8csp_bgje_run_history_' . self::IDENTITY,
			),
			\array_column( $this->engine_option_rows(), 'option_name' ),
			'Replacement completion must retain only history and latest pointer state'
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Asserts and returns one pending chunked job start action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Run identifier.
	 * @param   string $group  Per-run Action Scheduler group.
	 *
	 * @return  string
	 */
	private function assert_pending_start_action( string $run_id, string $group ): string {
		$store      = $this->action_scheduler_store();
		$action_ids = $store->query_actions(
			array(
				'hook'     => 'a8csp_bgje/internal/deliver',
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
		self::assertIsArray( $action_ids );
		self::assertCount( 1, $action_ids, 'The engine must store exactly one pending chunked job start action' );
		self::assertIsString( $action_ids[0] ?? null );
		$action_id = $action_ids[0];
		$action    = $store->fetch_action( $action_id );

		self::assertInstanceOf( \ActionScheduler_Action::class, $action );
		self::assertSame( 'a8csp_bgje/internal/deliver', $action->get_hook() );
		self::assertSame( array( self::IDENTITY, $run_id, 1 ), $action->get_args() );
		self::assertSame( $group, $action->get_group() );
		self::assertSame( \ActionScheduler_Store::STATUS_PENDING, $store->get_status( $action_id ) );

		return $action_id;
	}

	// endregion.
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchContextInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\Logging\ErrorLogSink;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;

/**
 * Verifies a replacement batch fences stale deliveries before the newer run completes.
 */
final class SupersededRunTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Consumer owner isolated to supersession coverage. */
	private const OWNER = 'integration-superseded';

	/** Batch identity unique within the request-persistent integration registry. */
	private const NAME = 'integration-superseded-run';

	/** Owner-qualified batch identity persisted by the engine. */
	private const IDENTITY = self::OWNER . ':' . self::NAME;

	// endregion.

	// region TESTS.

	/**
	 * A same-arguments replacement supersedes the incumbent and completes without stale work.
	 *
	 * @return  void
	 */
	public function test_replacement_supersedes_incumbent_before_stale_chunk_execution(): void {
		$start_args   = array(
			'site_id' => 73,
			'mode'    => 'replace',
		);
		$batch        = new RecordingBatch( self::NAME );
		$batch->queue = array(
			array( 'chunk' => 'one' ),
			array( 'chunk' => 'two' ),
		);

		$consumer = \a8csp_bgte( self::OWNER );
		$consumer->batches()->register( $batch );

		$this->expect_option( 'a8csp_bgte_latest_' . self::IDENTITY );
		\add_filter(
			'a8csp_background_tasks/continue_delay',
			static fn ( int $delay, string $name, string $run_id ): int => 0,
			10,
			3
		);

		/** @var list<array{string, array<array-key, mixed>}> $named_superseded */
		$named_superseded = array();
		/** @var list<array{string, string, array<array-key, mixed>}> $generic_superseded */
		$generic_superseded = array();
		/** @var list<array{string, array<array-key, mixed>}> $named_completed */
		$named_completed = array();
		/** @var list<array{string, string, array<array-key, mixed>}> $generic_completed */
		$generic_completed = array();
		/** @var list<array{string, string, array<array-key, mixed>}> $log_records */
		$log_records = array();
		\remove_action( 'a8csp_background_tasks/log', array( ErrorLogSink::class, 'log' ), 10 );
		\add_action(
			'a8csp_background_tasks/superseded/' . self::IDENTITY,
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
			'a8csp_background_tasks/completed/' . self::IDENTITY,
			static function ( string $run_id, array $args ) use ( &$named_completed ): void {
				$named_completed[] = array( $run_id, $args );
			},
			10,
			2
		);
		\add_action(
			'a8csp_background_tasks/completed',
			static function ( string $name, string $run_id, array $args ) use ( &$generic_completed ): void {
				$generic_completed[] = array( $name, $run_id, $args );
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

		$run_a_result = $consumer->batches()->start( self::NAME, $start_args );
		self::assertInstanceOf( Success::class, $run_a_result, 'The incumbent batch must start through the public API' );
		self::assertIsString( $run_a_result->value );
		$run_a      = $run_a_result->value;
		$group_a    = self::IDENTITY . '|' . $run_a;
		$start_a_id = $this->assert_pending_start_action( $run_a, $group_a );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must generate the incumbent queue' );
		self::assertSame( array( $start_args ), $batch->generate_calls, 'The incumbent start action must generate its queue once' );
		self::assertSame( array(), $batch->process_calls, 'Queue generation must not process a chunk inline' );
		self::assertSame(
			\ActionScheduler_Store::STATUS_COMPLETE,
			$this->action_scheduler_store()->get_status( $start_a_id ),
			'Action Scheduler must complete the incumbent start action'
		);

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must dequeue the incumbent first chunk' );
		self::assertSame( array(), $batch->process_calls, 'The incumbent continue action must not process its exposed chunk inline' );
		$run_a_action_id = $this->assert_pending_chunk_action(
			self::IDENTITY,
			$run_a,
			$group_a,
			array( 'chunk' => 'one' )
		);

		$run_b_result = $consumer->batches()->start( self::NAME, $start_args );
		self::assertInstanceOf( Success::class, $run_b_result, 'A normal batch start must replace the same-arguments incumbent' );
		self::assertIsString( $run_b_result->value );
		$run_b      = $run_b_result->value;
		$group_b    = self::IDENTITY . '|' . $run_b;
		$start_b_id = $this->assert_pending_start_action( $run_b, $group_b );
		self::assertNotSame( $run_a, $run_b, 'Replacement must allocate a fresh run identifier' );

		$args_hash = self::args_hash( $start_args );
		$lock      = \get_option( 'a8csp_bgte_lock_' . self::IDENTITY . '_' . $args_hash, null );
		self::assertIsArray( $lock );
		self::assertSame( $run_b, $lock['run_id'] ?? null, 'The replacement batch must take ownership of the overlap lock' );
		self::assertSame(
			array(
				'all'     => $run_b,
				'by_hash' => array( $args_hash => $run_b ),
			),
			\get_option( 'a8csp_bgte_latest_' . self::IDENTITY, null ),
			'The replacement batch must become latest for the shared argument identity'
		);
		self::assertIsArray( \get_option( 'a8csp_bgte_run_' . self::IDENTITY . '_' . $run_a, null ) );
		self::assertIsArray( \get_option( 'a8csp_bgte_run_' . self::IDENTITY . '_' . $run_b, null ) );
		self::assertSame( array(), $named_superseded, 'Starting the replacement must defer incumbent cleanup to its stale delivery' );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must deliver the incumbent chunk after replacement' );

		self::assertSame( array(), $batch->process_calls, 'The superseded incumbent delivery must not execute chunk work' );
		self::assertSame(
			array( array( $run_a, $start_args ) ),
			$named_superseded,
			'The name-specific superseded hook must receive the incumbent run ID and start arguments once'
		);
		self::assertSame(
			array( array( self::IDENTITY, $run_a, $start_args ) ),
			$generic_superseded,
			'The generic superseded hook must prepend the batch name to the same incumbent payload once'
		);
		$expected_log_records = array(
			array(
				'info',
				'Superseded batch run after its ownership fence failed.',
				array(
					'batch_name'    => self::IDENTITY,
					'run_id'        => $run_a,
					'latest_run_id' => $run_b,
				),
			),
		);
		self::assertSame(
			$expected_log_records,
			$log_records,
			'Supersession must emit only its quiet informational log record'
		);
		self::assertFalse(
			\get_option( 'a8csp_bgte_run_' . self::IDENTITY . '_' . $run_a, false ),
			'The stale incumbent delivery must delete its run option'
		);
		$lock = \get_option( 'a8csp_bgte_lock_' . self::IDENTITY . '_' . $args_hash, null );
		self::assertIsArray( $lock );
		self::assertSame( $run_b, $lock['run_id'] ?? null, 'Incumbent cleanup must preserve the replacement lock owner' );
		self::assertSame(
			\ActionScheduler_Store::STATUS_COMPLETE,
			$this->action_scheduler_store()->get_status( $run_a_action_id ),
			'Action Scheduler must complete the quietly superseded incumbent delivery'
		);

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must generate the replacement queue' );
		self::assertSame(
			array( $start_args, $start_args ),
			$batch->generate_calls,
			'The incumbent and replacement must each generate their own queue'
		);
		self::assertSame(
			\ActionScheduler_Store::STATUS_COMPLETE,
			$this->action_scheduler_store()->get_status( $start_b_id ),
			'Action Scheduler must complete the replacement start action'
		);

		$expected_chunks  = array(
			array( 'chunk' => 'one' ),
			array( 'chunk' => 'two' ),
		);
		$run_b_action_ids = array();
		foreach ( $expected_chunks as $expected_chunk ) {
			/** @var list<array{chunk_args: array<array-key, mixed>, context: BatchContextInterface}> $process_calls */
			$process_calls      = $batch->process_calls;
			$process_call_count = \count( $process_calls );
			self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must dequeue one replacement chunk' );
			self::assertCount(
				$process_call_count,
				$batch->process_calls,
				'A replacement continue action must not process its exposed chunk inline'
			);
			$run_b_action_ids[] = $this->assert_pending_chunk_action(
				self::IDENTITY,
				$run_b,
				$group_b,
				$expected_chunk
			);
			self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute one replacement chunk action' );
			/** @var list<array{chunk_args: array<array-key, mixed>, context: BatchContextInterface}> $process_calls */
			$process_calls = $batch->process_calls;
			self::assertCount(
				$process_call_count + 1,
				$process_calls,
				'A replacement run action must process one chunk'
			);
			self::assertSame(
				$expected_chunk,
				$process_calls[ $process_call_count ]['chunk_args'] ?? null,
				'The replacement run action must process the chunk exposed by its continue action'
			);
		}

		/** @var list<array{chunk_args: array<array-key, mixed>, context: BatchContextInterface}> $process_calls_before */
		$process_calls_before = $batch->process_calls;
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must observe the drained replacement queue' );
		self::assertSame(
			$process_calls_before,
			$batch->process_calls,
			'The drained-queue continue action must not execute chunk work'
		);
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute replacement terminal cleanup' );

		/** @var list<array{chunk_args: array<array-key, mixed>, context: BatchContextInterface}> $process_calls */
		$process_calls = $batch->process_calls;
		self::assertSame( $expected_chunks, \array_column( $process_calls, 'chunk_args' ) );
		foreach ( $process_calls as $process_call ) {
			self::assertSame( $run_b, $process_call['context']->get_run_id() );
			self::assertSame( $start_args, $process_call['context']->get_start_args() );
		}
		foreach ( $run_b_action_ids as $action_id ) {
			self::assertSame(
				\ActionScheduler_Store::STATUS_COMPLETE,
				$this->action_scheduler_store()->get_status( $action_id ),
				'Action Scheduler must complete every replacement chunk action'
			);
		}
		self::assertSame(
			array(
				array(
					'run_id'     => $run_b,
					'start_args' => $start_args,
				),
			),
			$batch->success_calls,
			'Only the replacement batch must receive terminal success'
		);
		self::assertSame( array(), $batch->failure_calls, 'Supersession must not invoke the batch failure callback' );
		self::assertSame(
			array( array( $run_b, $start_args ) ),
			$named_completed,
			'The name-specific completed hook must receive only the replacement payload'
		);
		self::assertSame(
			array( array( self::IDENTITY, $run_b, $start_args ) ),
			$generic_completed,
			'The generic completed hook must prepend the batch name to the replacement payload'
		);
		self::assertSame(
			array( array( $run_a, $start_args ) ),
			$named_superseded,
			'The replacement lifecycle must not repeat the name-specific superseded hook'
		);
		self::assertSame(
			array( array( self::IDENTITY, $run_a, $start_args ) ),
			$generic_superseded,
			'The replacement lifecycle must not repeat the generic superseded hook'
		);
		self::assertSame( $expected_log_records, $log_records, 'Superseded stale deliveries must not emit additional logs' );

		self::assertFalse( \get_option( 'a8csp_bgte_run_' . self::IDENTITY . '_' . $run_a, false ) );
		self::assertFalse( \get_option( 'a8csp_bgte_run_' . self::IDENTITY . '_' . $run_b, false ) );
		self::assertFalse(
			\get_option( 'a8csp_bgte_lock_' . self::IDENTITY . '_' . $args_hash, false ),
			'Terminal replacement success must release the overlap lock'
		);
		self::assertFalse(
			\get_option( 'a8csp_bgte_failed_' . self::IDENTITY, false ),
			'Supersession and replacement success must not retain failed-run state'
		);
		self::assertSame(
			array(
				'all'     => $run_b,
				'by_hash' => array( $args_hash => $run_b ),
			),
			\get_option( 'a8csp_bgte_latest_' . self::IDENTITY, null ),
			'Terminal replacement success must retain the replacement pointers'
		);
		self::assertSame(
			array(
				'started'   => array( $run_a, $run_b ),
				'completed' => array(
					array(
						'run_id' => $run_a,
						'status' => 'superseded',
					),
					array(
						'run_id' => $run_b,
						'status' => 'completed',
					),
				),
				'by_hash'   => array(
					$args_hash => array(
						'started'   => array( $run_a, $run_b ),
						'completed' => array(
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
			\get_option( 'a8csp_bgte_history_' . self::IDENTITY, null ),
			'History must retain the superseded incumbent and completed replacement in lifecycle order'
		);
		self::assertSame(
			array(
				'a8csp_bgte_history_' . self::IDENTITY,
				'a8csp_bgte_latest_' . self::IDENTITY,
			),
			\array_column( $this->engine_option_rows(), 'option_name' ),
			'Replacement completion must retain only history and latest pointer state'
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Asserts and returns one pending batch start action.
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
				'hook'     => 'a8csp_background_tasks/start',
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
		self::assertIsArray( $action_ids );
		self::assertCount( 1, $action_ids, 'The engine must store exactly one pending batch start action' );
		self::assertIsString( $action_ids[0] ?? null );
		$action_id = $action_ids[0];
		$action    = $store->fetch_action( $action_id );

		self::assertInstanceOf( \ActionScheduler_Action::class, $action );
		self::assertSame( 'a8csp_background_tasks/start', $action->get_hook() );
		self::assertSame( array( self::IDENTITY, $run_id, 1 ), $action->get_args() );
		self::assertSame( $group, $action->get_group() );
		self::assertSame( \ActionScheduler_Store::STATUS_PENDING, $store->get_status( $action_id ) );

		return $action_id;
	}

	// endregion.
}

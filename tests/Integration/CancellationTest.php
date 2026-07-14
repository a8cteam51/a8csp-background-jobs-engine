<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Retry\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies cancellation fences live deliveries and isolates per-run scheduler groups.
 */
final class CancellationTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Task identity isolated to the executing-refusal race. */
	private const EXECUTING_NAME = 'integration-cancel-executing-refusal';

	/** Task identity isolated to retry-backoff cancellation. */
	private const BACKOFF_NAME = 'integration-cancel-backoff';

	/** Batch identity isolated to between-chunks cancellation. */
	private const BATCH_NAME = 'integration-cancel-between-chunks';

	/** Task identity isolated to sibling-group cancellation. */
	private const SIBLING_NAME = 'integration-cancel-sibling-isolation';

	/** Task identity isolated to the degraded WP-Cron survivor. */
	private const DEGRADED_NAME = 'integration-cancel-wp-cron-survivor';

	// endregion.

	// region TESTS.

	/**
	 * Cancellation refuses an admitted task while the runner completes it normally.
	 *
	 * @return  void
	 */
	public function test_cancel_refuses_an_executing_task_and_the_run_completes(): void {
		$args = array( 'account_id' => 41 );
		$task = new RecordingTask( self::EXECUTING_NAME );

		$engine = \a8csp_bgte_engine();
		self::assertNotNull( $engine, 'The live plugin must publish its engine before cancellation races run' );
		$engine->tasks()->register( $task );
		$this->expect_option( 'a8csp_bgte_latest_' . self::EXECUTING_NAME );

		$enqueued = \a8csp_bgte_enqueue_task( self::EXECUTING_NAME, $args );
		self::assertInstanceOf( Success::class, $enqueued );
		self::assertIsString( $enqueued->value );
		$run_id    = $enqueued->value;
		$group     = self::EXECUTING_NAME . '|' . $run_id;
		$action_id = $this->assert_pending_task_action( self::EXECUTING_NAME, $run_id, $group );
		$store     = $this->action_scheduler_store();

		$observed_status = null;
		$observed_state  = null;
		$cancel_result   = null;
		$task->on_handle = static function ( array $received_args ) use (
			$action_id,
			$engine,
			$run_id,
			$store,
			&$cancel_result,
			&$observed_state,
			&$observed_status
		): void {
			$observed_status = $store->get_status( $action_id );
			$observed_state  = \get_option( 'a8csp_bgte_run_' . self::EXECUTING_NAME . '_' . $run_id, null );
			$cancel_result   = $engine->cancel( self::EXECUTING_NAME, $run_id );
		};

		$completed_action_ids = array();
		$completed_hook       = static function ( int $completed_action_id ) use (
			$action_id,
			&$completed_action_ids
		): void {
			if ( (int) $action_id === $completed_action_id ) {
				$completed_action_ids[] = $completed_action_id;
			}
		};
		\add_action( 'action_scheduler_completed_action', $completed_hook, 10, 1 );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must admit and execute the target task' );
		\remove_action( 'action_scheduler_completed_action', $completed_hook, 10 );

		self::assertSame( \ActionScheduler_Store::STATUS_RUNNING, $observed_status );
		self::assertIsArray( $observed_state );
		self::assertTrue( $observed_state['executing'] ?? false, 'The admitted delivery must persist its executing marker' );
		self::assertInstanceOf( Failure::class, $cancel_result );
		self::assertInstanceOf( EngineError::class, $cancel_result->error );
		self::assertSame(
			\sprintf( 'Run "%s" is executing; a run in flight completes or fails on its own.', $run_id ),
			$cancel_result->error->message
		);
		self::assertSame( array( $args ), $task->calls, 'Refusal must leave the admitted task invocation intact' );
		self::assertSame( array( (int) $action_id ), $completed_action_ids );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $action_id ) );
		self::assertSame(
			array(
				array(
					'run_id' => $run_id,
					'status' => 'completed',
				),
			),
			self::terminal_entries( self::EXECUTING_NAME )
		);
		self::assert_run_storage_cleared( self::EXECUTING_NAME, $run_id, $args );
	}

	/**
	 * A failed attempt can be cancelled while its retry waits in backoff.
	 *
	 * @return  void
	 */
	public function test_cancel_during_backoff_clears_the_retry_and_records_cancelled_history(): void {
		$args               = array( 'account_id' => 42 );
		$task               = new RecordingTask( self::BACKOFF_NAME );
		$task->throwable    = new \RuntimeException( 'Retry after the upstream recovers.' );
		$task->retry_policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 300,
			multiplier: 1,
			max_delay: 300
		);

		$engine = \a8csp_bgte_engine();
		self::assertNotNull( $engine, 'The live plugin must publish its engine before cancellation races run' );
		$engine->tasks()->register( $task );
		$this->expect_option( 'a8csp_bgte_latest_' . self::BACKOFF_NAME );

		$enqueued = \a8csp_bgte_enqueue_task( self::BACKOFF_NAME, $args );
		self::assertInstanceOf( Success::class, $enqueued );
		self::assertIsString( $enqueued->value );
		$run_id            = $enqueued->value;
		$group             = self::BACKOFF_NAME . '|' . $run_id;
		$initial_action_id = $this->assert_pending_task_action( self::BACKOFF_NAME, $run_id, $group );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute only the first failed attempt' );

		$store = $this->action_scheduler_store();
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $initial_action_id ) );
		$retry_action_id = $this->assert_sole_pending_action(
			'a8csp/background_tasks/run',
			$group,
			array( self::BACKOFF_NAME, $run_id, 2 )
		);
		$run_state       = \get_option( 'a8csp_bgte_run_' . self::BACKOFF_NAME . '_' . $run_id, null );
		self::assertIsArray( $run_state );
		self::assertSame( 'running', $run_state['status'] ?? null );
		self::assertSame( 1, $run_state['chunk_retries'] ?? null );
		self::assertSame( 2, $run_state['action_seq'] ?? null );
		self::assertFalse( $run_state['executing'] ?? true, 'The persisted backoff window must be cancellable' );

		$cancelled = $engine->cancel( self::BACKOFF_NAME, $run_id );

		self::assertInstanceOf( Success::class, $cancelled );
		self::assertSame( $run_id, $cancelled->value );
		self::assertSame( array( $args ), $task->calls, 'Cancellation must prevent the pending retry attempt' );
		self::assertSame( \ActionScheduler_Store::STATUS_CANCELED, $store->get_status( $retry_action_id ) );
		self::assertSame(
			array(),
			$store->query_actions(
				array(
					'group'    => $group,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				)
			),
			'The per-run group clear must remove the pending retry'
		);
		self::assertSame(
			array(
				array(
					'run_id' => $run_id,
					'status' => 'cancelled',
				),
			),
			self::terminal_entries( self::BACKOFF_NAME )
		);
		self::assert_run_storage_cleared( self::BACKOFF_NAME, $run_id, $args );
	}

	/**
	 * A batch remains cancellable after one chunk and before its next queue advance.
	 *
	 * @return  void
	 */
	public function test_cancel_between_chunks_preserves_the_retained_head_and_skips_terminal_callbacks(): void {
		$start_args   = array( 'account_id' => 43 );
		$first_chunk  = array( 'chunk' => 'one' );
		$next_chunk   = array( 'chunk' => 'two' );
		$batch        = new RecordingBatch( self::BATCH_NAME );
		$batch->queue = array( $first_chunk, $next_chunk );

		$engine = \a8csp_bgte_engine();
		self::assertNotNull( $engine, 'The live plugin must publish its engine before cancellation races run' );
		$engine->batches()->register( $batch );
		$this->expect_option( 'a8csp_bgte_latest_' . self::BATCH_NAME );

		$started = \a8csp_bgte_start_batch( self::BATCH_NAME, $start_args );
		self::assertInstanceOf( Success::class, $started );
		self::assertIsString( $started->value );
		$run_id = $started->value;
		$group  = self::BATCH_NAME . '|' . $run_id;

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must materialize the batch queue' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must expose the first retained queue head' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must process the first chunk' );

		$run_state = \get_option( 'a8csp_bgte_run_' . self::BATCH_NAME . '_' . $run_id, null );
		self::assertIsArray( $run_state );
		self::assertSame( array( $next_chunk ), $run_state['queue'] ?? null );
		self::assertSame( 4, $run_state['action_seq'] ?? null );
		self::assertFalse( $run_state['executing'] ?? true, 'The inter-chunk state must be cancellable' );
		$continue_action_id = $this->assert_sole_pending_action(
			'a8csp/background_tasks/continue',
			$group,
			array( self::BATCH_NAME, $run_id, 4 )
		);

		$cancelled = $engine->cancel( self::BATCH_NAME, $run_id );

		self::assertInstanceOf( Success::class, $cancelled );
		self::assertSame( $run_id, $cancelled->value );
		self::assertSame( array( $first_chunk ), \array_column( $batch->process_calls, 'chunk_args' ) );
		self::assertSame( array(), $batch->success_calls, 'Cancellation must not invoke the batch success callback' );
		self::assertSame( array(), $batch->failure_calls, 'Cancellation must not invoke the batch failure callback' );
		$store = $this->action_scheduler_store();
		self::assertSame( \ActionScheduler_Store::STATUS_CANCELED, $store->get_status( $continue_action_id ) );
		self::assertSame(
			array(),
			$store->query_actions(
				array(
					'group'    => $group,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				)
			)
		);
		self::assertSame(
			array(
				array(
					'run_id' => $run_id,
					'status' => 'cancelled',
				),
			),
			self::terminal_entries( self::BATCH_NAME )
		);
		self::assert_run_storage_cleared( self::BATCH_NAME, $run_id, $start_args );
	}

	/**
	 * Clearing one run group leaves a sibling run of the same task executable.
	 *
	 * @return  void
	 */
	public function test_cancel_clears_only_the_target_run_group(): void {
		$args_a = array( 'account_id' => 44 );
		$args_b = array( 'account_id' => 45 );
		$task   = new RecordingTask( self::SIBLING_NAME );

		$engine = \a8csp_bgte_engine();
		self::assertNotNull( $engine, 'The live plugin must publish its engine before cancellation races run' );
		$engine->tasks()->register( $task );
		$this->expect_option( 'a8csp_bgte_latest_' . self::SIBLING_NAME );

		$enqueued_a = \a8csp_bgte_enqueue_task( self::SIBLING_NAME, $args_a );
		$enqueued_b = \a8csp_bgte_enqueue_task( self::SIBLING_NAME, $args_b );
		self::assertInstanceOf( Success::class, $enqueued_a );
		self::assertInstanceOf( Success::class, $enqueued_b );
		self::assertIsString( $enqueued_a->value );
		self::assertIsString( $enqueued_b->value );
		$run_a    = $enqueued_a->value;
		$run_b    = $enqueued_b->value;
		$group_a  = self::SIBLING_NAME . '|' . $run_a;
		$group_b  = self::SIBLING_NAME . '|' . $run_b;
		$action_a = $this->assert_pending_task_action( self::SIBLING_NAME, $run_a, $group_a );
		$action_b = $this->assert_pending_task_action( self::SIBLING_NAME, $run_b, $group_b );

		$cancelled = $engine->cancel( self::SIBLING_NAME, $run_a );

		self::assertInstanceOf( Success::class, $cancelled );
		self::assertSame( $run_a, $cancelled->value );
		$store = $this->action_scheduler_store();
		self::assertSame( \ActionScheduler_Store::STATUS_CANCELED, $store->get_status( $action_a ) );
		self::assertSame( \ActionScheduler_Store::STATUS_PENDING, $store->get_status( $action_b ) );
		self::assertSame(
			array( $action_b ),
			$store->query_actions(
				array(
					'group'    => $group_b,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				)
			),
			'The sibling group must retain its pending action'
		);
		self::assertSame(
			1,
			$this->run_matching_due_action(
				static fn ( string $hook, array $action_args ): bool =>
					'a8csp/background_tasks/run' === $hook
					&& ( $action_args[1] ?? null ) === $run_b
			),
			'Action Scheduler must execute the surviving sibling'
		);

		self::assertSame( array( $args_b ), $task->calls );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $action_b ) );
		$history = \get_option( 'a8csp_bgte_history_' . self::SIBLING_NAME, null );
		self::assertIsArray( $history );
		self::assertSame( array( $run_a, $run_b ), $history['started'] ?? null );
		self::assertSame(
			array(
				array(
					'run_id' => $run_a,
					'status' => 'cancelled',
				),
				array(
					'run_id' => $run_b,
					'status' => 'completed',
				),
			),
			$history['completed'] ?? null
		);
		self::assertSame(
			array(
				'all'     => $run_b,
				'by_hash' => array(
					self::args_hash( $args_a ) => $run_a,
					self::args_hash( $args_b ) => $run_b,
				),
			),
			\get_option( 'a8csp_bgte_latest_' . self::SIBLING_NAME, null )
		);
		self::assert_run_storage_cleared( self::SIBLING_NAME, $run_a, $args_a );
		self::assert_run_storage_cleared( self::SIBLING_NAME, $run_b, $args_b );
	}

	/**
	 * WP-Cron's group-clear no-op leaves one delivery that the run-admission gate drops.
	 *
	 * @return  void
	 */
	#[Group( 'degraded' )]
	public function test_wp_cron_group_clear_survivor_dies_at_the_run_admission_gate(): void {
		// A WP-Cron single survives the group-clear no-op only where Action Scheduler is absent;
		// its delivery for the deleted run then reports the generic missing-state warning.
		if ( ! \function_exists( 'as_schedule_single_action' ) ) {
			$this->expectOutputRegex( '/Task run state is missing or corrupt; allow the reconciliation sweep/' );
		}
		$args = array( 'account_id' => 46 );
		$task = new RecordingTask( self::DEGRADED_NAME );

		$engine = \a8csp_bgte_engine();
		self::assertNotNull( $engine, 'The live plugin must publish its engine before cancellation races run' );
		$engine->tasks()->register( $task );
		$this->expect_option( 'a8csp_bgte_latest_' . self::DEGRADED_NAME );

		$raw_deliveries = array();
		\add_action(
			'a8csp/background_tasks/run',
			static function ( string $name, string $run_id, int $action_seq ) use ( &$raw_deliveries ): void {
				if ( self::DEGRADED_NAME === $name ) {
					$raw_deliveries[] = array( $name, $run_id, $action_seq );
				}
			},
			1,
			3
		);

		$enqueued = \a8csp_bgte_enqueue_task( self::DEGRADED_NAME, $args );
		self::assertInstanceOf( Success::class, $enqueued );
		self::assertIsString( $enqueued->value );
		$run_id      = $enqueued->value;
		$group       = self::DEGRADED_NAME . '|' . $run_id;
		$action_args = array( self::DEGRADED_NAME, $run_id, 1 );
		$action_id   = null;
		$cron_before = array();
		if ( \class_exists( \ActionScheduler::class ) ) {
			$action_id = $this->assert_pending_task_action( self::DEGRADED_NAME, $run_id, $group );
		} else {
			$cron_before = $this->wordpress_cron_events( 'a8csp/background_tasks/run', $action_args );
			self::assertCount( 1, $cron_before, 'The degraded backend must retain one pending WP-Cron single' );
			self::assertFalse( $cron_before[0]['schedule'] );
		}

		$cancelled = $engine->cancel( self::DEGRADED_NAME, $run_id );
		self::assertInstanceOf( Success::class, $cancelled );
		self::assertSame( $run_id, $cancelled->value );

		if ( null !== $action_id ) {
			self::assertSame(
				\ActionScheduler_Store::STATUS_CANCELED,
				$this->action_scheduler_store()->get_status( $action_id )
			);
			self::assertSame( array(), $raw_deliveries );
		} else {
			self::assertSame(
				$cron_before,
				$this->wordpress_cron_events( 'a8csp/background_tasks/run', $action_args ),
				'WP-Cron cannot identify a per-run group, so its pending single must survive cancellation'
			);
			self::assertSame(
				1,
				$this->run_matching_due_cron_event(
					static fn ( string $hook, array $event_args ): bool =>
						'a8csp/background_tasks/run' === $hook
						&& $event_args === $action_args
				),
				'The surviving WP-Cron single must reach the shared run-admission hook once'
			);
			self::assertSame( array( $action_args ), $raw_deliveries );
			self::assertSame(
				array(),
				$this->wordpress_cron_events( 'a8csp/background_tasks/run', $action_args )
			);
		}

		self::assertSame( array(), $task->calls, 'A surviving backend delivery must not invoke cancelled user work' );
		self::assertSame(
			array(
				array(
					'run_id' => $run_id,
					'status' => 'cancelled',
				),
			),
			self::terminal_entries( self::DEGRADED_NAME )
		);
		self::assert_run_storage_cleared( self::DEGRADED_NAME, $run_id, $args );
		self::assertSame(
			array(
				'a8csp_bgte_history_' . self::DEGRADED_NAME,
				'a8csp_bgte_latest_' . self::DEGRADED_NAME,
			),
			\array_column( $this->engine_option_rows(), 'option_name' ),
			'Cancelled degraded state must retain only history and the latest pointer'
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Asserts and returns the sole pending Action Scheduler row for one exact hook and group.
	 *
	 * @param   string                  $hook          Action hook.
	 * @param   string                  $group         Per-run action group.
	 * @param   array<array-key, mixed> $expected_args Expected action arguments.
	 *
	 * @return  string
	 */
	private function assert_sole_pending_action( string $hook, string $group, array $expected_args ): string {
		$store      = $this->action_scheduler_store();
		$action_ids = $store->query_actions(
			array(
				'hook'     => $hook,
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
		self::assertIsArray( $action_ids );
		self::assertCount( 1, $action_ids );
		self::assertIsString( $action_ids[0] ?? null );
		$action_id = $action_ids[0];
		$action    = $store->fetch_action( $action_id );

		self::assertInstanceOf( \ActionScheduler_Action::class, $action );
		self::assertSame( $hook, $action->get_hook() );
		self::assertSame( $expected_args, $action->get_args() );
		self::assertSame( $group, $action->get_group() );
		self::assertSame( \ActionScheduler_Store::STATUS_PENDING, $store->get_status( $action_id ) );

		return $action_id;
	}

	/**
	 * Returns the terminal entries for one background-work history.
	 *
	 * @param   string $name Stable task or batch name.
	 *
	 * @return  array<array-key, mixed>
	 */
	private static function terminal_entries( string $name ): array {
		$history = \get_option( 'a8csp_bgte_history_' . $name, null );
		self::assertIsArray( $history );
		$entries = $history['completed'] ?? null;
		self::assertIsArray( $entries );

		return $entries;
	}

	/**
	 * Asserts that a terminal run leaves no active, lock, or failed-run state.
	 *
	 * @param   string                  $name     Stable task or batch name.
	 * @param   string                  $run_id   Run identifier.
	 * @param   array<array-key, mixed> $args     Start arguments.
	 *
	 * @return  void
	 */
	private static function assert_run_storage_cleared( string $name, string $run_id, array $args ): void {
		self::assertFalse( \get_option( 'a8csp_bgte_run_' . $name . '_' . $run_id, false ) );
		self::assertFalse( \get_option( 'a8csp_bgte_lock_' . $name . '_' . self::args_hash( $args ), false ) );
		self::assertFalse( \get_option( 'a8csp_bgte_failed_' . $name, false ) );
	}

	// endregion.
}

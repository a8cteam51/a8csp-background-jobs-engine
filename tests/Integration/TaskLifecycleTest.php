<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Contracts\NonRetryableTaskException;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies task persistence, scheduler dispatch, callbacks, hooks, and terminal cleanup.
 */
final class TaskLifecycleTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Successful task identity unique within the request-persistent integration registry. */
	private const SUCCESS_NAME = 'integration-task-lifecycle-success';

	/** Failed task identity unique within the request-persistent integration registry. */
	private const FAILURE_NAME = 'integration-task-lifecycle-failure';

	// endregion.

	// region TESTS.

	/**
	 * A registered task runs through the available scheduler and leaves only bounded terminal state.
	 *
	 * @return  void
	 */
	#[Group( 'degraded' )]
	public function test_registered_task_completes_through_the_available_scheduler(): void {
		$args = array(
			'account_id' => 42,
			'mode'       => 'refresh',
		);
		$task = new RecordingTask( self::SUCCESS_NAME );

		$engine = \a8csp_bgte_engine();
		self::assertNotNull( $engine, 'The live plugin must publish its engine before integration tests register tasks' );
		$engine->tasks()->register( $task );

		$this->expect_option( 'a8csp_bgte_latest_' . self::SUCCESS_NAME );

		$named_completed   = array();
		$generic_completed = array();
		\add_action(
			'a8csp/background_tasks/completed/' . self::SUCCESS_NAME,
			static function ( string $run_id, array $start_args ) use ( &$named_completed ): void {
				$named_completed[] = array( $run_id, $start_args );
			},
			10,
			2
		);
		\add_action(
			'a8csp/background_tasks/completed',
			static function ( string $name, string $run_id, array $start_args ) use ( &$generic_completed ): void {
				$generic_completed[] = array( $name, $run_id, $start_args );
			},
			10,
			3
		);

		$result = \a8csp_bgte_enqueue_task( self::SUCCESS_NAME, $args );
		self::assertInstanceOf( Success::class, $result, 'The registered task must enqueue through the public API' );
		self::assertIsString( $result->value );
		$run_id = $result->value;
		$group  = self::SUCCESS_NAME . '|' . $run_id;
		if ( \class_exists( \ActionScheduler::class ) ) {
			$action_id = $this->assert_pending_task_action( self::SUCCESS_NAME, $run_id, $group );
		} else {
			$action_id   = null;
			$cron_events = $this->wordpress_cron_events(
				'a8csp/background_tasks/run',
				array( self::SUCCESS_NAME, $run_id, 1 )
			);
			self::assertCount( 1, $cron_events, 'The facade fallback must persist exactly one WP-Cron task occurrence' );
			self::assertFalse( $cron_events[0]['schedule'], 'The facade fallback must enqueue the task as a single WP-Cron event' );
		}

		self::assertCount( 0, $task->calls, 'Enqueueing a task must not invoke its handler inline' );
		self::assertCount( 0, $named_completed, 'Enqueueing a task must not fire its name-specific completed hook inline' );
		self::assertCount( 0, $generic_completed, 'Enqueueing a task must not fire its generic completed hook inline' );

		self::assertSame( 1, $this->run_next_engine_action(), 'The available scheduler must execute the pending task action' );

		self::assertCount( 1, $task->calls, 'The runner drive must invoke the task handler exactly once' );
		self::assertCount( 1, $named_completed, 'The runner drive must fire the name-specific completed hook exactly once' );
		self::assertCount( 1, $generic_completed, 'The runner drive must fire the generic completed hook exactly once' );
		self::assertSame( array( $args ), $task->calls, 'The task must receive its original argument array exactly once' );
		self::assertSame(
			array( array( $run_id, $args ) ),
			$named_completed,
			'The name-specific completed hook must receive run ID and start arguments'
		);
		self::assertSame(
			array( array( self::SUCCESS_NAME, $run_id, $args ) ),
			$generic_completed,
			'The generic completed hook must prepend the task name to the same payload'
		);
		if ( null !== $action_id ) {
			self::assertSame(
				\ActionScheduler_Store::STATUS_COMPLETE,
				$this->action_scheduler_store()->get_status( $action_id ),
				'Action Scheduler must mark the engine run action complete'
			);
		} else {
			self::assertSame(
				array(),
				$this->wordpress_cron_events( 'a8csp/background_tasks/run', array( self::SUCCESS_NAME, $run_id, 1 ) ),
				'WP-Cron completion must clear the delivered task occurrence'
			);
		}

		$args_hash = self::args_hash( $args );
		self::assertFalse(
			\get_option( 'a8csp_bgte_run_' . self::SUCCESS_NAME . '_' . $run_id, false ),
			'Terminal task success must delete the active run option'
		);
		self::assertFalse(
			\get_option( 'a8csp_bgte_lock_' . self::SUCCESS_NAME . '_' . $args_hash, false ),
			'Terminal task success must release the overlap lock'
		);
		self::assertFalse(
			\get_option( 'a8csp_bgte_failed_' . self::SUCCESS_NAME, false ),
			'Terminal task success must not create a failed-run row'
		);
		self::assertSame(
			array(
				'all'     => $run_id,
				'by_hash' => array( $args_hash => $run_id ),
			),
			\get_option( 'a8csp_bgte_latest_' . self::SUCCESS_NAME, null ),
			'Terminal task success must retain the latest global and argument-identity pointers'
		);
		self::assertSame(
			array(
				'started'   => array( $run_id ),
				'completed' => array( $run_id ),
				'by_hash'   => array(
					$args_hash => array(
						'started'   => array( $run_id ),
						'completed' => array( $run_id ),
					),
				),
			),
			\get_option( 'a8csp_bgte_history_' . self::SUCCESS_NAME, null ),
			'Terminal task success must retain one started and completed history entry'
		);
	}

	/**
	 * A non-retryable throwable fails on attempt one and retains only bounded failure state.
	 *
	 * @return  void
	 */
	public function test_non_retryable_task_failure_is_terminal_on_attempt_one(): void {
		$args            = array(
			'account_id' => 84,
			'mode'       => 'delete',
		);
		$task            = new RecordingTask( self::FAILURE_NAME );
		$task->throwable = new NonRetryableTaskException( 'The remote record no longer exists.' );

		$engine = \a8csp_bgte_engine();
		self::assertNotNull( $engine, 'The live plugin must publish its engine before integration tests register tasks' );
		$engine->tasks()->register( $task );

		$this->expect_option( 'a8csp_bgte_latest_' . self::FAILURE_NAME );
		$this->expect_option( 'a8csp_bgte_failed_' . self::FAILURE_NAME );

		$named_failed   = array();
		$generic_failed = array();
		\add_action(
			'a8csp/background_tasks/failed/' . self::FAILURE_NAME,
			static function ( string $run_id, array $start_args, EngineError $error ) use ( &$named_failed ): void {
				$named_failed[] = array( $run_id, $start_args, $error );
			},
			10,
			3
		);
		\add_action(
			'a8csp/background_tasks/failed',
			static function (
				string $name,
				string $run_id,
				array $start_args,
				EngineError $error
			) use ( &$generic_failed ): void {
				$generic_failed[] = array( $name, $run_id, $start_args, $error );
			},
			10,
			4
		);

		$result = \a8csp_bgte_enqueue_task( self::FAILURE_NAME, $args );
		self::assertInstanceOf( Success::class, $result, 'The failing task must enqueue before its handler executes' );
		self::assertIsString( $result->value );
		$run_id    = $result->value;
		$group     = self::FAILURE_NAME . '|' . $run_id;
		$action_id = $this->assert_pending_task_action( self::FAILURE_NAME, $run_id, $group );

		self::assertCount( 0, $task->calls, 'Enqueueing a task must not invoke its handler inline' );
		self::assertCount( 0, $named_failed, 'Enqueueing a task must not fire its name-specific failed hook inline' );
		self::assertCount( 0, $generic_failed, 'Enqueueing a task must not fire its generic failed hook inline' );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute the failing task action' );

		self::assertCount( 1, $task->calls, 'The runner drive must invoke the failing task handler exactly once' );
		self::assertSame( array( $args ), $task->calls, 'A non-retryable task must execute exactly once' );
		self::assertCount( 1, $named_failed, 'The name-specific failed hook must fire exactly once' );
		self::assertCount( 1, $generic_failed, 'The generic failed hook must fire exactly once' );
		$error = $named_failed[0][2] ?? null;
		self::assertInstanceOf( EngineError::class, $error );
		self::assertSame( 'The remote record no longer exists.', $error->message );
		self::assertSame( NonRetryableTaskException::class, $error->exception_class );
		self::assertSame(
			array( array( $run_id, $args, $error ) ),
			$named_failed,
			'The name-specific failed hook must receive run ID, start arguments, and engine error'
		);
		self::assertSame(
			array( array( self::FAILURE_NAME, $run_id, $args, $error ) ),
			$generic_failed,
			'The generic failed hook must prepend the task name to the same failure payload'
		);
		self::assertSame(
			\ActionScheduler_Store::STATUS_COMPLETE,
			$this->action_scheduler_store()->get_status( $action_id ),
			'Action Scheduler must complete a run action whose engine failure is terminally handled'
		);

		$args_hash = self::args_hash( $args );
		self::assertFalse(
			\get_option( 'a8csp_bgte_run_' . self::FAILURE_NAME . '_' . $run_id, false ),
			'Terminal task failure must delete the active run option'
		);
		self::assertFalse(
			\get_option( 'a8csp_bgte_lock_' . self::FAILURE_NAME . '_' . $args_hash, false ),
			'Terminal task failure must release the overlap lock'
		);
		self::assertSame(
			array(
				'all'     => $run_id,
				'by_hash' => array( $args_hash => $run_id ),
			),
			\get_option( 'a8csp_bgte_latest_' . self::FAILURE_NAME, null ),
			'Terminal task failure must retain the latest pointers'
		);
		self::assertSame(
			array(
				'started'   => array( $run_id ),
				'completed' => array( $run_id ),
				'by_hash'   => array(
					$args_hash => array(
						'started'   => array( $run_id ),
						'completed' => array( $run_id ),
					),
				),
			),
			\get_option( 'a8csp_bgte_history_' . self::FAILURE_NAME, null ),
			'Terminal task failure must retain one started and terminal history entry'
		);

		$failed_entries = \get_option( 'a8csp_bgte_failed_' . self::FAILURE_NAME, null );
		self::assertIsArray( $failed_entries );
		self::assertCount( 1, $failed_entries, 'A first-attempt terminal failure must retain exactly one failed entry' );
		$failed_entry = $failed_entries[0] ?? null;
		self::assertIsArray( $failed_entry );
		self::assertSame(
			array( 'run_id', 'failed_at', 'start_args', 'attempts', 'error' ),
			\array_keys( $failed_entry ),
			'The failed store entry must contain exactly the manual-retry fields'
		);
		self::assertSame( $run_id, $failed_entry['run_id'] ?? null );
		self::assertIsInt( $failed_entry['failed_at'] ?? null );
		self::assertSame( $args, $failed_entry['start_args'] ?? null );
		self::assertSame(
			1,
			$failed_entry['attempts'] ?? null,
			'The failed store must record terminal failure on attempt one'
		);
		self::assertSame(
			array(
				'class'   => NonRetryableTaskException::class,
				'message' => 'The remote record no longer exists.',
			),
			$failed_entry['error'] ?? null
		);

		$pending = $this->action_scheduler_store()->query_actions(
			array(
				'hook'     => 'a8csp/background_tasks/run',
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		);
		self::assertSame( array(), $pending, 'A non-retryable failure must not schedule another run attempt' );
	}

	// endregion.
}

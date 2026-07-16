<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\NonRetryableTaskException;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies task persistence, scheduler dispatch, callbacks, hooks, and terminal cleanup.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class TaskLifecycleTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Public owner unique to this integration-test graph. */
	private const OWNER = 'integration-task-lifecycle';

	/** Successful task identity unique within the request-persistent integration registry. */
	private const SUCCESS_NAME = 'integration-task-lifecycle-success';

	/** Owner-qualified successful task identity persisted by the engine. */
	private const SUCCESS_IDENTITY = self::OWNER . ':' . self::SUCCESS_NAME;

	/** Failed task identity unique within the request-persistent integration registry. */
	private const FAILURE_NAME = 'integration-task-lifecycle-failure';

	/** Owner-qualified failed task identity persisted by the engine. */
	private const FAILURE_IDENTITY = self::OWNER . ':' . self::FAILURE_NAME;

	// endregion.

	// region TESTS.

	/**
	 * A registered task runs through the available scheduler and leaves only bounded terminal state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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

		$consumer = \a8csp_bgte( self::OWNER );
		$consumer->tasks()->register( $task );

		$this->expect_option( 'a8csp_bgte_latest_' . self::SUCCESS_IDENTITY );

		$named_completed   = array();
		$generic_completed = array();
		\add_action(
			'a8csp_background_tasks/completed/' . self::SUCCESS_IDENTITY,
			static function ( string $run_id, array $start_args ) use ( &$named_completed ): void {
				$named_completed[] = array( $run_id, $start_args );
			},
			10,
			2
		);
		\add_action(
			'a8csp_background_tasks/completed',
			static function ( string $name, string $run_id, array $start_args ) use ( &$generic_completed ): void {
				$generic_completed[] = array( $name, $run_id, $start_args );
			},
			10,
			3
		);

		$result = $consumer->tasks()->enqueue( self::SUCCESS_NAME, $args );
		self::assertInstanceOf( Success::class, $result, 'The registered task must enqueue through the public API' );
		self::assertIsString( $result->value );
		$run_id = $result->value;

		self::assertCount( 0, $task->calls, 'Enqueueing a task must not invoke its handler inline' );
		self::assertCount( 0, $named_completed, 'Enqueueing a task must not fire its identity-specific completed hook inline' );
		self::assertCount( 0, $generic_completed, 'Enqueueing a task must not fire its generic completed hook inline' );

		self::assertSame( 1, $this->run_next_engine_action(), 'The available scheduler must execute the pending task action' );

		self::assertCount( 1, $task->calls, 'The runner drive must invoke the task handler exactly once' );
		self::assertCount( 1, $named_completed, 'The runner drive must fire the identity-specific completed hook exactly once' );
		self::assertCount( 1, $generic_completed, 'The runner drive must fire the generic completed hook exactly once' );
		self::assertSame( array( $args ), $task->calls, 'The task must receive its original argument array exactly once' );
		self::assertSame( array( array( $run_id, $args ) ), $named_completed, 'The identity-specific completed hook must receive run ID and start arguments' );
		self::assertSame( array( array( self::SUCCESS_IDENTITY, $run_id, $args ) ), $generic_completed, 'The generic completed hook must prepend the task name to the same payload' );
		$last_completed = $consumer->runs()->last_completed_run_id( self::SUCCESS_NAME );
		self::assertInstanceOf( Success::class, $last_completed );
		self::assertSame( $run_id, $last_completed->value );
		$runs = $this->inspection()->runs( self::SUCCESS_IDENTITY );
		self::assertSame( array(), $runs['live'], 'Terminal task success must leave no live run' );
		self::assertSame(
			array(
				array(
					'run_id'   => $run_id,
					'outcome'  => 'completed',
					'retained' => false,
				),
			),
			$runs['history']
		);
	}

	/**
	 * A non-retryable throwable fails on attempt one and retains only bounded failure state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing security
	 * @pin-rationale The upstream exception text exists only in the executing fixture and must not cross the RunFailure hook boundary; the public payload alone cannot prove which hidden source text was withheld.
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

		$consumer = \a8csp_bgte( self::OWNER );
		$consumer->tasks()->register( $task );

		$this->expect_option( 'a8csp_bgte_latest_' . self::FAILURE_IDENTITY );
		$this->expect_option( 'a8csp_bgte_failed_' . self::FAILURE_IDENTITY );

		$named_failed   = array();
		$generic_failed = array();
		\add_action(
			'a8csp_background_tasks/failed/' . self::FAILURE_IDENTITY,
			static function ( string $run_id, array $start_args, RunFailure $failure ) use ( &$named_failed ): void {
				$named_failed[] = array( $run_id, $start_args, $failure );
			},
			10,
			3
		);
		\add_action(
			'a8csp_background_tasks/failed',
			static function ( string $name, string $run_id, array $start_args, RunFailure $failure ) use ( &$generic_failed ): void {
				$generic_failed[] = array( $name, $run_id, $start_args, $failure );
			},
			10,
			4
		);

		$result = $consumer->tasks()->enqueue( self::FAILURE_NAME, $args );
		self::assertInstanceOf( Success::class, $result, 'The failing task must enqueue before its handler executes' );
		self::assertIsString( $result->value );
		$run_id = $result->value;

		self::assertCount( 0, $task->calls, 'Enqueueing a task must not invoke its handler inline' );
		self::assertCount( 0, $named_failed, 'Enqueueing a task must not fire its identity-specific failed hook inline' );
		self::assertCount( 0, $generic_failed, 'Enqueueing a task must not fire its generic failed hook inline' );

		self::assertSame( 1, $this->run_next_engine_action(), 'The available scheduler must execute the failing task action' );

		self::assertCount( 1, $task->calls, 'The runner drive must invoke the failing task handler exactly once' );
		self::assertSame( array( $args ), $task->calls, 'A non-retryable task must execute exactly once' );
		self::assertCount( 1, $named_failed, 'The identity-specific failed hook must fire exactly once' );
		self::assertCount( 1, $generic_failed, 'The generic failed hook must fire exactly once' );
		$failure = $named_failed[0][2] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		$expected_message = \sprintf( 'Background-work execution failed because %s was thrown.', NonRetryableTaskException::class );
		self::assertSame( self::FAILURE_IDENTITY, $failure->identity );
		self::assertSame( $run_id, $failure->run_id );
		self::assertSame( 1, $failure->attempts );
		self::assertSame( RunFailureStage::Execution, $failure->stage );
		self::assertSame( ApiErrorCode::ExecutionFailed, $failure->code );
		self::assertSame( $expected_message, $failure->summary );
		self::assertStringNotContainsString( 'The remote record no longer exists.', $failure->summary, 'RunFailure must redact the upstream exception message at the public hook boundary' );
		self::assertNull( $failure->failed_chunk );
		self::assertSame( array( array( $run_id, $args, $failure ) ), $named_failed, 'The identity-specific failed hook must receive run ID, start arguments, and run failure' );
		self::assertSame( array( array( self::FAILURE_IDENTITY, $run_id, $args, $failure ) ), $generic_failed, 'The generic failed hook must prepend the task name to the same failure payload' );
		self::assertSame( 0, $this->run_next_engine_action(), 'A non-retryable failure must not schedule another run attempt' );
		$runs = $this->inspection()->runs( self::FAILURE_IDENTITY );
		self::assertSame( array(), $runs['live'], 'Terminal task failure must leave no live run' );
		self::assertSame(
			array(
				array(
					'run_id'   => $run_id,
					'outcome'  => 'failed',
					'retained' => true,
				),
			),
			$runs['history'],
			'Inspection must expose the retained failed outcome for manual retry'
		);
	}

	// endregion.
}

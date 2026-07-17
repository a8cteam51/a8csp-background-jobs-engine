<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\NonRetryableException;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;

/**
 * Verifies a non-retryable task failure terminates after its first attempt.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class NonRetryableTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Public owner unique to this integration-test graph. */
	private const string OWNER = 'integration-non-retryable';

	/** Task identity unique within the request-persistent integration registry. */
	private const string NAME = 'integration-non-retryable';

	/** Owner-qualified task identity persisted by the engine. */
	private const string IDENTITY = self::OWNER . ':' . self::NAME;

	// endregion.

	// region TESTS.

	/**
	 * A non-retryable exception fails immediately without scheduling or announcing a retry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing security
	 * @pin-rationale The upstream exception text exists only in the executing fixture and must not cross the RunFailure hook boundary; the public payload alone cannot prove which hidden source text was withheld.
	 *
	 * @return  void
	 */
	public function test_non_retryable_exception_is_terminal_on_attempt_one(): void {
		$args            = array(
			'record_id' => 404,
			'operation' => 'delete',
		);
		$task            = new RecordingTask( self::NAME );
		$task->throwable = new NonRetryableException( 'The requested record is permanently unavailable.' );

		$consumer = \a8csp_bgte( self::OWNER );
		$consumer->tasks()->register( $task );

		$this->expect_option( 'a8csp_bgte_latest_run_' . self::IDENTITY );
		$this->expect_option( 'a8csp_bgte_failed_runs_' . self::IDENTITY );

		$named_retry_scheduled   = array();
		$generic_retry_scheduled = array();
		$named_failed            = array();
		$generic_failed          = array();
		\add_action(
			'a8csp_background_tasks/retry_scheduled/' . self::IDENTITY,
			static function ( string $run_id, array $start_args, int $attempt, int $delay ) use ( &$named_retry_scheduled ): void {
				$named_retry_scheduled[] = array( $run_id, $start_args, $attempt, $delay );
			},
			10,
			4
		);
		\add_action(
			'a8csp_background_tasks/retry_scheduled',
			static function ( string $name, string $run_id, array $start_args, int $attempt, int $delay ) use ( &$generic_retry_scheduled ): void {
				$generic_retry_scheduled[] = array( $name, $run_id, $start_args, $attempt, $delay );
			},
			10,
			5
		);
		\add_action(
			'a8csp_background_tasks/failed/' . self::IDENTITY,
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

		$result = $consumer->tasks()->enqueue( self::NAME, $args );
		self::assertInstanceOf( Success::class, $result, 'The non-retryable task must enqueue before its handler fails' );
		self::assertIsString( $result->value );
		$run_id = $result->value;

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute the non-retryable task action' );

		self::assertSame( array( $args ), $task->calls, 'A non-retryable task must execute exactly once' );
		self::assertSame( array(), $named_retry_scheduled, 'A non-retryable failure must not fire the identity-specific retry-scheduled hook' );
		self::assertSame( array(), $generic_retry_scheduled, 'A non-retryable failure must not fire the generic retry-scheduled hook' );
		self::assertCount( 1, $named_failed, 'A non-retryable failure must fire the identity-specific failed hook once' );
		self::assertCount( 1, $generic_failed, 'A non-retryable failure must fire the generic failed hook once' );

		$failure = $named_failed[0][2] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		$expected_message = \sprintf( 'Background-work execution failed because %s was thrown.', NonRetryableException::class );
		self::assertSame( self::IDENTITY, $failure->identity );
		self::assertSame( $run_id, $failure->run_id );
		self::assertSame( 1, $failure->attempts );
		self::assertSame( RunFailureStage::Execution, $failure->stage );
		self::assertSame( ApiErrorCode::ExecutionFailed, $failure->code );
		self::assertSame( $expected_message, $failure->summary );
		self::assertStringNotContainsString( 'The requested record is permanently unavailable.', $failure->summary, 'RunFailure must redact the upstream exception message at the public hook boundary' );
		self::assertNull( $failure->failed_chunk );
		self::assertSame( array( array( $run_id, $args, $failure ) ), $named_failed, 'The identity-specific failed hook must receive run ID, start arguments, and run failure' );
		self::assertSame( array( array( self::IDENTITY, $run_id, $args, $failure ) ), $generic_failed, 'The generic failed hook must prepend the task name to the same failure payload' );

		self::assertSame( 0, $this->run_next_due_action(), 'A non-retryable failure must not schedule another attempt' );
		$runs = $this->inspection()->runs( self::IDENTITY );
		self::assertSame( array(), $runs['live'], 'Terminal non-retryable failure must leave no live run' );
		self::assertSame(
			array(
				array(
					'run_id'       => $run_id,
					'outcome'      => 'failed',
					'failed_store' => true,
				),
			),
			$runs['history'],
			'Inspection must expose the retained failed outcome for manual retry'
		);
	}

	// endregion.
}

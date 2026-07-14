<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Tasks\Exceptions\NonRetryableTaskException;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;

/**
 * Verifies a non-retryable task failure terminates after its first attempt.
 */
final class NonRetryableTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Task identity unique within the request-persistent integration registry. */
	private const NAME = 'integration-non-retryable';

	// endregion.

	// region TESTS.

	/**
	 * A non-retryable exception fails immediately without scheduling or announcing a retry.
	 *
	 * @return  void
	 */
	public function test_non_retryable_exception_is_terminal_on_attempt_one(): void {
		$args            = array(
			'record_id' => 404,
			'operation' => 'delete',
		);
		$task            = new RecordingTask( self::NAME );
		$task->throwable = new NonRetryableTaskException( 'The requested record is permanently unavailable.' );

		$engine = \a8csp_bgte_engine();
		self::assertNotNull( $engine, 'The live plugin must publish its engine before integration tests register tasks' );
		$engine->tasks()->register( $task );

		$this->expect_option( 'a8csp_bgte_latest_' . self::NAME );
		$this->expect_option( 'a8csp_bgte_failed_' . self::NAME );

		$named_retrying   = array();
		$generic_retrying = array();
		$named_failed     = array();
		$generic_failed   = array();
		\add_action(
			'a8csp_background_tasks/retrying/' . self::NAME,
			static function ( string $run_id, array $start_args, int $attempt, int $delay ) use ( &$named_retrying ): void {
				$named_retrying[] = array( $run_id, $start_args, $attempt, $delay );
			},
			10,
			4
		);
		\add_action(
			'a8csp_background_tasks/retrying',
			static function (
				string $name,
				string $run_id,
				array $start_args,
				int $attempt,
				int $delay
			) use ( &$generic_retrying ): void {
				$generic_retrying[] = array( $name, $run_id, $start_args, $attempt, $delay );
			},
			10,
			5
		);
		\add_action(
			'a8csp_background_tasks/failed/' . self::NAME,
			static function ( string $run_id, array $start_args, EngineError $error ) use ( &$named_failed ): void {
				$named_failed[] = array( $run_id, $start_args, $error );
			},
			10,
			3
		);
		\add_action(
			'a8csp_background_tasks/failed',
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

		$result = \a8csp_bgte_enqueue_task( self::NAME, $args );
		self::assertInstanceOf( Success::class, $result, 'The non-retryable task must enqueue before its handler fails' );
		self::assertIsString( $result->value );
		$run_id    = $result->value;
		$group     = self::NAME . '|' . $run_id;
		$action_id = $this->assert_pending_task_action( self::NAME, $run_id, $group );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute the non-retryable task action' );

		self::assertSame( array( $args ), $task->calls, 'A non-retryable task must execute exactly once' );
		self::assertSame( array(), $named_retrying, 'A non-retryable failure must not fire the name-specific retrying hook' );
		self::assertSame( array(), $generic_retrying, 'A non-retryable failure must not fire the generic retrying hook' );
		self::assertCount( 1, $named_failed, 'A non-retryable failure must fire the name-specific failed hook once' );
		self::assertCount( 1, $generic_failed, 'A non-retryable failure must fire the generic failed hook once' );

		$error = $named_failed[0][2] ?? null;
		self::assertInstanceOf( EngineError::class, $error );
		self::assertSame( 'The requested record is permanently unavailable.', $error->message );
		self::assertSame( NonRetryableTaskException::class, $error->exception_class );
		self::assertSame(
			array( array( $run_id, $args, $error ) ),
			$named_failed,
			'The name-specific failed hook must receive run ID, start arguments, and engine error'
		);
		self::assertSame(
			array( array( self::NAME, $run_id, $args, $error ) ),
			$generic_failed,
			'The generic failed hook must prepend the task name to the same failure payload'
		);

		$store = $this->action_scheduler_store();
		self::assertSame(
			\ActionScheduler_Store::STATUS_COMPLETE,
			$store->get_status( $action_id ),
			'Action Scheduler must complete the terminally handled task action'
		);
		self::assertSame(
			array( $action_id ),
			$store->query_actions(
				array(
					'per_page' => -1,
					'orderby'  => 'action_id',
					'order'    => 'ASC',
				)
			),
			'A non-retryable failure must leave no retry action beyond the original row'
		);

		$args_hash = self::args_hash( $args );
		self::assertFalse(
			\get_option( 'a8csp_bgte_run_' . self::NAME . '_' . $run_id, false ),
			'Terminal non-retryable failure must delete the active run option'
		);
		self::assertFalse(
			\get_option( 'a8csp_bgte_lock_' . self::NAME . '_' . $args_hash, false ),
			'Terminal non-retryable failure must release the overlap lock'
		);
		self::assertSame(
			array(
				'all'     => $run_id,
				'by_hash' => array( $args_hash => $run_id ),
			),
			\get_option( 'a8csp_bgte_latest_' . self::NAME, null ),
			'Terminal non-retryable failure must retain the latest pointers'
		);
		self::assertSame(
			array(
				'started'   => array( $run_id ),
				'completed' => array(
					array(
						'run_id' => $run_id,
						'status' => 'failed',
					),
				),
				'by_hash'   => array(
					$args_hash => array(
						'started'   => array( $run_id ),
						'completed' => array(
							array(
								'run_id' => $run_id,
								'status' => 'failed',
							),
						),
					),
				),
			),
			\get_option( 'a8csp_bgte_history_' . self::NAME, null ),
			'Terminal non-retryable failure must retain one started and terminal history entry'
		);

		$failed_entries = \get_option( 'a8csp_bgte_failed_' . self::NAME, null );
		self::assertIsArray( $failed_entries );
		self::assertCount( 1, $failed_entries, 'A non-retryable failure must retain exactly one failed entry' );
		$failed_entry = $failed_entries[0] ?? null;
		self::assertIsArray( $failed_entry );
		self::assertSame(
			array( 'run_id', 'failed_at', 'start_args', 'attempts', 'error' ),
			\array_keys( $failed_entry ),
			'The failed entry must contain exactly the manual-retry fields'
		);
		self::assertSame( $run_id, $failed_entry['run_id'] ?? null );
		self::assertIsInt( $failed_entry['failed_at'] ?? null );
		self::assertSame( $args, $failed_entry['start_args'] ?? null );
		self::assertSame( 1, $failed_entry['attempts'] ?? null, 'The failed entry must record one consumed attempt' );
		self::assertSame(
			array(
				'class'   => NonRetryableTaskException::class,
				'message' => 'The requested record is permanently unavailable.',
			),
			$failed_entry['error'] ?? null
		);
		self::assertSame(
			array(
				'a8csp_bgte_failed_' . self::NAME,
				'a8csp_bgte_history_' . self::NAME,
				'a8csp_bgte_latest_' . self::NAME,
			),
			\array_column( $this->engine_option_rows(), 'option_name' ),
			'Non-retryable failure must retain only its failed store, history ring, and latest pointer'
		);
	}

	// endregion.
}

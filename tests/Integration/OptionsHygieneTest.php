<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the complete steady-state option footprint after successful task and batch runs.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[Group( 'degraded' )]
final class OptionsHygieneTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Public owner unique to this integration-test graph. */
	private const OWNER = 'integration-options-hygiene';

	/** Task identity unique within the request-persistent integration registry. */
	private const TASK_NAME = 'integration-options-task';

	/** Owner-qualified task identity persisted by the engine. */
	private const TASK_IDENTITY = self::OWNER . ':' . self::TASK_NAME;

	/** Batch identity unique within the request-persistent integration registry. */
	private const BATCH_NAME = 'integration-options-batch';

	/** Owner-qualified batch identity persisted by the engine. */
	private const BATCH_IDENTITY = self::OWNER . ':' . self::BATCH_NAME;

	// endregion.

	// region TESTS.

	/**
	 * Complete task and batch lifecycles retain only non-autoloaded latest and history rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_completed_lifecycles_leave_only_bounded_non_autoloaded_options(): void {
		$task_args    = array( 'scope' => 'task-census' );
		$task         = new RecordingTask( self::TASK_NAME );
		$batch_args   = array( 'scope' => 'batch-census' );
		$batch        = new RecordingBatch( self::BATCH_NAME );
		$batch->queue = array( array( 'chunk' => 'only' ) );

		$consumer = \a8csp_bgte( self::OWNER );
		$consumer->tasks()->register( $task );
		$consumer->batches()->register( $batch );

		$this->expect_option( 'a8csp_bgte_latest_' . self::TASK_IDENTITY );
		$this->expect_option( 'a8csp_bgte_latest_' . self::BATCH_IDENTITY );
		\add_filter( 'a8csp_background_tasks/continue_delay', static fn ( int $delay, string $name, string $run_id ): int => 0, 10, 3 );

		$task_result = $consumer->tasks()->enqueue( self::TASK_NAME, $task_args );
		self::assertInstanceOf( Success::class, $task_result, 'The census task must enqueue through the public API' );
		self::assertIsString( $task_result->value );
		$task_run_id = $task_result->value;
		self::assertSame( 1, $this->run_next_engine_action(), 'The available scheduler must complete the census task' );

		$batch_result = $consumer->batches()->start( self::BATCH_NAME, $batch_args );
		self::assertInstanceOf( Success::class, $batch_result, 'The census batch must start through the public API' );
		self::assertIsString( $batch_result->value );
		$batch_run_id = $batch_result->value;
		self::assertSame( 1, $this->run_next_engine_action(), 'The available scheduler must generate the census batch queue' );
		self::assertSame( 1, $this->run_next_engine_action(), 'The available scheduler must dequeue the census batch chunk' );
		self::assertSame( 1, $this->run_next_engine_action(), 'The available scheduler must process the census batch chunk' );
		self::assertSame( 1, $this->run_next_engine_action(), 'The available scheduler must observe the drained census batch queue' );
		self::assertSame( 1, $this->run_next_engine_action(), 'The available scheduler must complete census batch cleanup' );

		self::assertSame( array( $task_args ), $task->calls, 'The census task must complete its full lifecycle' );
		self::assertCount( 1, $batch->process_calls, 'The census batch must process its only chunk exactly once' );
		self::assertSame( array( 'chunk' => 'only' ), $batch->process_calls[0]['chunk_args'] ?? null );
		self::assertSame(
			array(
				array(
					'run_id'     => $batch_run_id,
					'start_args' => $batch_args,
				),
			),
			$batch->completed_calls,
			'The census batch must invoke its on_completed() callback'
		);

		$rows = $this->engine_option_rows();
		self::assertCount( 4, $rows, 'Two completed identities must retain a bounded four-row engine footprint' );

		$autoloaded_values = \wp_autoload_values_to_autoload();
		foreach ( $rows as $row ) {
			self::assertStringStartsWith( 'a8csp_bgte_', $row['option_name'], 'Every retained row must stay inside the documented engine ownership prefix' );
			self::assertNotContains( $row['autoload'], $autoloaded_values, \sprintf( 'Engine option "%s" must persist with autoload=false', $row['option_name'] ) );
		}

		$task_latest = $consumer->runs()->last_completed_run_id( self::TASK_NAME );
		self::assertInstanceOf( Success::class, $task_latest );
		self::assertSame( $task_run_id, $task_latest->value );
		$batch_latest = $consumer->runs()->last_completed_run_id( self::BATCH_NAME );
		self::assertInstanceOf( Success::class, $batch_latest );
		self::assertSame( $batch_run_id, $batch_latest->value );
	}

	// endregion.
}

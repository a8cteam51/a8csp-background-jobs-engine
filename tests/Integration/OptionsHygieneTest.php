<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the complete steady-state option footprint after successful task and batch runs.
 */
#[Group( 'degraded' )]
final class OptionsHygieneTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Task identity unique within the request-persistent integration registry. */
	private const TASK_NAME = 'integration-options-task';

	/** Batch identity unique within the request-persistent integration registry. */
	private const BATCH_NAME = 'integration-options-batch';

	// endregion.

	// region TESTS.

	/**
	 * Complete task and batch lifecycles retain only non-autoloaded latest and history rows.
	 *
	 * @return  void
	 */
	public function test_completed_lifecycles_leave_only_bounded_non_autoloaded_options(): void {
		$task_args    = array( 'scope' => 'task-census' );
		$task         = new RecordingTask( self::TASK_NAME );
		$batch_args   = array( 'scope' => 'batch-census' );
		$batch        = new RecordingBatch( self::BATCH_NAME );
		$batch->queue = array( array( 'chunk' => 'only' ) );

		$engine = \a8csp_bgte_engine();
		self::assertNotNull( $engine, 'The live plugin must publish its engine before integration tests register work' );
		$engine->tasks()->register( $task );
		$engine->batches()->register( $batch );

		$this->expect_option( 'a8csp_bgte_latest_' . self::TASK_NAME );
		$this->expect_option( 'a8csp_bgte_latest_' . self::BATCH_NAME );
		\add_filter(
			'a8csp/background_tasks/continue_delay',
			static fn ( int $delay, string $name, string $run_id ): int => 0,
			10,
			3
		);

		$task_result = \a8csp_bgte_enqueue_task( self::TASK_NAME, $task_args );
		self::assertInstanceOf( Success::class, $task_result, 'The census task must enqueue through the public API' );
		self::assertIsString( $task_result->value );
		$task_run_id = $task_result->value;
		self::assertSame( 1, $this->run_next_engine_action(), 'The available scheduler must complete the census task' );

		$batch_result = \a8csp_bgte_start_batch( self::BATCH_NAME, $batch_args );
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
			$batch->success_calls,
			'The census batch must complete its terminal success callback'
		);

		$rows = $this->engine_option_rows();
		self::assertSame(
			array(
				'a8csp_bgte_history_' . self::BATCH_NAME,
				'a8csp_bgte_history_' . self::TASK_NAME,
				'a8csp_bgte_latest_' . self::BATCH_NAME,
				'a8csp_bgte_latest_' . self::TASK_NAME,
			),
			\array_column( $rows, 'option_name' ),
			'The complete engine option census must contain exactly two latest pointers and two history rings'
		);

		$autoloaded_values = \wp_autoload_values_to_autoload();
		foreach ( $rows as $row ) {
			self::assertNotContains(
				$row['autoload'],
				$autoloaded_values,
				\sprintf( 'Engine option "%s" must persist with autoload=false', $row['option_name'] )
			);
		}

		$task_latest = \get_option( 'a8csp_bgte_latest_' . self::TASK_NAME );
		self::assertIsArray( $task_latest );
		self::assertSame( $task_run_id, $task_latest['all'] ?? null );
		$batch_latest = \get_option( 'a8csp_bgte_latest_' . self::BATCH_NAME );
		self::assertIsArray( $batch_latest );
		self::assertSame( $batch_run_id, $batch_latest['all'] ?? null );
	}

	// endregion.
}

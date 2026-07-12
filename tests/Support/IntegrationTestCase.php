<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Provides live-WordPress integration isolation and a one-action Action Scheduler runner drive.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
abstract class IntegrationTestCase extends TestCase {
	// region TRAITS.

	use ActionSchedulerIsolationTrait;
	use CronIsolationTrait;
	use HookIsolationTrait;
	use OptionIsolationTrait;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Captures request hooks before clearing persistent engine and scheduler state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->snapshot_wordpress_hooks();
		$this->prepare_engine_options();
		$this->reset_wordpress_cron();
		$this->truncate_action_scheduler_tables();
	}

	/**
	 * Detects engine leaks before restoring clean persistent and request-local state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function tearDown(): void {
		try {
			$this->assert_engine_option_hygiene();
		} finally {
			try {
				try {
					$this->sweep_engine_options();
				} finally {
					try {
						$this->reset_wordpress_cron();
					} finally {
						$this->truncate_action_scheduler_tables();
					}
				}
			} finally {
				$this->restore_wordpress_hooks();
				parent::tearDown();
			}
		}
	}

	// endregion.

	// region HELPERS.

	/**
	 * Runs at most one due action through Action Scheduler's initialized queue runner singleton.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int Number of actions processed.
	 */
	protected function run_next_due_action(): int {
		$one_action       = static fn ( mixed $batch_size ): int => 1;
		$stop_after_batch = static fn ( mixed $memory_exceeded ): bool => true;

		\add_filter( 'action_scheduler_queue_runner_batch_size', $one_action );
		\add_filter( 'action_scheduler_memory_exceeded', $stop_after_batch );

		try {
			$runner = \ActionScheduler::runner();
			self::assertInstanceOf( \ActionScheduler_QueueRunner::class, $runner );
			$processed = $runner->run( 'Integration Test' );
			self::assertIsInt( $processed, 'Action Scheduler queue runner must report its processed-action count' );

			return $processed;
		} finally {
			\remove_filter( 'action_scheduler_queue_runner_batch_size', $one_action );
			\remove_filter( 'action_scheduler_memory_exceeded', $stop_after_batch );
		}
	}

	/**
	 * Returns Action Scheduler's initialized custom-table store.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  \ActionScheduler_Store
	 */
	protected function action_scheduler_store(): \ActionScheduler_Store {
		$store = \ActionScheduler::store();
		self::assertInstanceOf( \ActionScheduler_Store::class, $store );

		return $store;
	}

	// endregion.
}

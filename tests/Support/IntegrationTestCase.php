<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\SchedulerFacade;
use PHPUnit\Framework\TestCase;

/**
 * Provides live-WordPress integration isolation and a one-action Action Scheduler runner drive.
 *
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
	 * Builds the live facade in engine declaration order with a controllable Action Scheduler probe.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param callable(): bool $readiness_probe
	 *
	 * @param   callable $readiness_probe Action Scheduler readiness predicate.
	 *
	 * @return  SchedulerFacade
	 */
	protected function scheduler_facade_with_action_scheduler_probe( callable $readiness_probe ): SchedulerFacade {
		$scheduler = new SchedulerFacade(
			array(
				new ActionSchedulerBackend( $readiness_probe ),
				new WPCronBackend(),
			)
		);
		$scheduler->register_hooks();

		return $scheduler;
	}

	/**
	 * Runs one due engine action through the scheduler available in the current integration environment.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int Number of actions processed.
	 */
	protected function run_next_engine_action(): int {
		return \class_exists( \ActionScheduler::class )
			? $this->run_next_due_action()
			: $this->run_next_due_cron_event();
	}

	/**
	 * Runs at most one due action through Action Scheduler's initialized queue runner singleton.
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
	 * Runs at most one due Action Scheduler action accepted by a hook-and-arguments predicate.
	 *
	 * @phpstan-param callable(string, array<array-key, mixed>): bool $matches
	 *
	 * @param   callable $matches Due-action identity predicate.
	 *
	 * @return  int Number of actions processed.
	 */
	protected function run_matching_due_action( callable $matches ): int {
		$store      = $this->action_scheduler_store();
		$action_ids = $store->query_actions(
			array(
				'status'       => \ActionScheduler_Store::STATUS_PENDING,
				'claimed'      => false,
				'date'         => \as_get_datetime_object(),
				'date_compare' => '<=',
				'per_page'     => -1,
				'orderby'      => 'action_id',
				'order'        => 'ASC',
			)
		);
		self::assertIsArray( $action_ids );

		foreach ( $action_ids as $action_id ) {
			self::assertIsString( $action_id );
			$action = $store->fetch_action( $action_id );
			self::assertInstanceOf( \ActionScheduler_Action::class, $action );

			$hook = $action->get_hook();
			self::assertIsString( $hook );
			$args = $action->get_args();
			self::assertIsArray( $args );
			if ( ! \array_is_list( $args ) || ! $matches( $hook, $args ) ) {
				continue;
			}

			$runner = \ActionScheduler::runner();
			self::assertInstanceOf( \ActionScheduler_QueueRunner::class, $runner );
			$runner->process_action( (int) $action_id, 'Integration Test' );

			return 1;
		}

		return 0;
	}

	/**
	 * Returns Action Scheduler's initialized custom-table store.
	 *
	 * @return  \ActionScheduler_Store
	 */
	protected function action_scheduler_store(): \ActionScheduler_Store {
		$store = \ActionScheduler::store();
		self::assertInstanceOf( \ActionScheduler_Store::class, $store );

		return $store;
	}

	/**
	 * Asserts and returns the sole pending engine run action for a task.
	 *
	 * @param   string $name   Stable task name.
	 * @param   string $run_id Run identifier.
	 * @param   string $group  Per-run Action Scheduler group.
	 *
	 * @return  string
	 */
	protected function assert_pending_task_action( string $name, string $run_id, string $group ): string {
		$store      = $this->action_scheduler_store();
		$action_ids = $store->query_actions(
			array(
				'hook'     => 'a8csp_background_tasks/run',
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
		self::assertIsArray( $action_ids );
		self::assertCount( 1, $action_ids, 'The engine must store exactly one pending task run action' );
		self::assertIsString( $action_ids[0] ?? null );
		$action_id = $action_ids[0];
		$action    = $store->fetch_action( $action_id );

		self::assertInstanceOf( \ActionScheduler_Action::class, $action );
		self::assertSame( 'a8csp_background_tasks/run', $action->get_hook() );
		self::assertSame( array( $name, $run_id, 1 ), $action->get_args() );
		self::assertSame( $group, $action->get_group() );
		self::assertSame( \ActionScheduler_Store::STATUS_PENDING, $store->get_status( $action_id ) );

		return $action_id;
	}

	/**
	 * Asserts and returns one pending batch chunk action.
	 *
	 * @param   string                  $name           Stable batch name.
	 * @param   string                  $run_id         Run identifier.
	 * @param   string                  $group          Per-run Action Scheduler group.
	 * @param   array<array-key, mixed> $expected_chunk Expected chunk arguments.
	 *
	 * @return  string
	 */
	protected function assert_pending_chunk_action(
		string $name,
		string $run_id,
		string $group,
		array $expected_chunk
	): string {
		$store      = $this->action_scheduler_store();
		$action_ids = $store->query_actions(
			array(
				'hook'     => 'a8csp_background_tasks/run',
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
		self::assertIsArray( $action_ids );
		self::assertCount( 1, $action_ids, 'Queue advancement must expose exactly one pending batch chunk action' );
		self::assertIsString( $action_ids[0] ?? null );
		$action_id = $action_ids[0];
		$action    = $store->fetch_action( $action_id );

		self::assertInstanceOf( \ActionScheduler_Action::class, $action );
		self::assertSame( 'a8csp_background_tasks/run', $action->get_hook() );
		self::assertSame( $group, $action->get_group() );
		$action_args = $action->get_args();
		self::assertIsArray( $action_args );
		self::assertSame( array( $name, $run_id, $expected_chunk ), \array_slice( $action_args, 0, 3 ) );
		self::assertIsInt( $action_args[3] ?? null, 'A chunk action must carry its lifecycle sequence token' );
		self::assertSame( \ActionScheduler_Store::STATUS_PENDING, $store->get_status( $action_id ) );

		return $action_id;
	}

	/**
	 * Returns the engine's insertion-ordered argument identity for a scalar tree.
	 *
	 * @param   array<array-key, mixed> $args Start arguments.
	 *
	 * @return  string
	 */
	protected static function args_hash( array $args ): string {
		$encoded = \wp_json_encode( $args, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION );
		self::assertIsString( $encoded );

		return \hash( 'sha256', $encoded );
	}

	// endregion.
}

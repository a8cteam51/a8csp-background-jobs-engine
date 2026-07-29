<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Inspection;
use PHPUnit\Framework\TestCase;

/**
 * Provides live-WordPress integration isolation and a one-action Action Scheduler runner drive.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
abstract class AbstractIntegrationTestCase extends TestCase {
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
	 * Builds the live facade in engine declaration order with controlled Action Scheduler readiness.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ReadinessControlledBackend $backend Readiness-controlled Action Scheduler backend.
	 *
	 * @return  SchedulerFacade
	 */
	protected function scheduler_facade_with_controllable_action_scheduler( ReadinessControlledBackend $backend ): SchedulerFacade {
		$scheduler = new SchedulerFacade(
			array(
				$backend,
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
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int Number of actions processed.
	 */
	protected function run_next_due_action(): int {
		$one_action             = static fn ( mixed $batch_size ): int => 1;
		$stop_after_chunked_job = static fn ( mixed $memory_exceeded ): bool => true;

		\add_filter( 'action_scheduler_queue_runner_batch_size', $one_action );
		\add_filter( 'action_scheduler_memory_exceeded', $stop_after_chunked_job );

		try {
			$runner = \ActionScheduler::runner();
			self::assertInstanceOf( \ActionScheduler_QueueRunner::class, $runner );
			$processed = $runner->run( 'Integration Test' );
			self::assertIsInt( $processed, 'Action Scheduler queue runner must report its processed-action count' );

			return $processed;
		} finally {
			\remove_filter( 'action_scheduler_queue_runner_batch_size', $one_action );
			\remove_filter( 'action_scheduler_memory_exceeded', $stop_after_chunked_job );
		}
	}

	/**
	 * Runs at most one due Action Scheduler action accepted by a hook-and-arguments predicate.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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

	/**
	 * Asserts and returns the sole pending engine run action for a job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $name     Stable job name.
	 * @param   string   $run_id   Run identifier.
	 * @param   string   $group    Per-run Action Scheduler group.
	 * @param   int|null $priority Resolved priority the stored action must carry, or null to leave it unasserted.
	 *
	 * @return  string
	 */
	protected function assert_pending_job_action( string $name, string $run_id, string $group, ?int $priority = null ): string {
		$store      = $this->action_scheduler_store();
		$action_ids = $store->query_actions(
			array(
				'hook'     => 'a8csp_bgje/internal/deliver',
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
		self::assertIsArray( $action_ids );
		self::assertCount( 1, $action_ids, 'The engine must store exactly one pending job run action' );
		self::assertIsString( $action_ids[0] ?? null );
		$action_id = $action_ids[0];
		$action    = $store->fetch_action( $action_id );

		self::assertInstanceOf( \ActionScheduler_Action::class, $action );
		self::assertSame( 'a8csp_bgje/internal/deliver', $action->get_hook() );
		self::assertSame( array( $name, $run_id, 1 ), $action->get_args() );
		self::assertSame( $group, $action->get_group() );
		self::assertSame( \ActionScheduler_Store::STATUS_PENDING, $store->get_status( $action_id ) );
		if ( null !== $priority ) {
			// Only the stored action proves the resolved priority survived the backend call: Action Scheduler supplies its
			// own default when a caller omits one, so an engine-side assertion cannot tell a resolved value from a default.
			self::assertSame( $priority, $action->get_priority(), 'The resolved priority must reach the stored action' );
		}

		return $action_id;
	}

	/**
	 * Asserts and returns one pending chunked job continuation action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name           Stable chunked job name.
	 * @param   string                  $run_id         Run identifier.
	 * @param   string                  $group          Per-run Action Scheduler group.
	 * @param   array<array-key, mixed> $expected_chunk Expected chunk arguments.
	 *
	 * @return  string
	 */
	protected function assert_pending_chunk_continuation( string $name, string $run_id, string $group, array $expected_chunk ): string {
		$run_state = \get_option( 'a8csp_bgje_active_run_' . $name . '_' . $run_id, null );
		self::assertIsArray( $run_state, 'A pending chunked job continuation must retain its authoritative run row' );
		$queue = $run_state['kind_state'] ?? null;
		self::assertIsArray( $queue, 'A pending chunked job continuation must retain its authoritative queue' );
		self::assertSame( $expected_chunk, $queue[0] ?? null, 'The expected chunk must be the authoritative queue head' );
		$action_sequence = $run_state['action_sequence'] ?? null;
		self::assertIsInt( $action_sequence, 'A pending chunked job continuation must retain its lifecycle sequence token' );

		$store      = $this->action_scheduler_store();
		$action_ids = $store->query_actions(
			array(
				'hook'     => 'a8csp_bgje/internal/deliver',
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
		self::assertIsArray( $action_ids );
		self::assertCount( 1, $action_ids, 'Chunk processing must retain exactly one pending chunked job continuation' );
		self::assertIsString( $action_ids[0] ?? null );
		$action_id = $action_ids[0];
		$action    = $store->fetch_action( $action_id );

		self::assertInstanceOf( \ActionScheduler_Action::class, $action );
		self::assertSame( 'a8csp_bgje/internal/deliver', $action->get_hook() );
		self::assertSame( $group, $action->get_group() );
		self::assertSame( array( $name, $run_id, $action_sequence ), $action->get_args(), 'A chunked job continuation must carry only its fenced delivery token' );
		self::assertSame( \ActionScheduler_Store::STATUS_PENDING, $store->get_status( $action_id ) );

		return $action_id;
	}

	/**
	 * Returns the engine's insertion-ordered identity for portable arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $args Start arguments.
	 *
	 * @return  string
	 */
	protected static function args_hash( array $args ): string {
		$hash = PortableArguments::hash( $args );
		self::assertIsString( $hash );

		return $hash;
	}

	/**
	 * Returns the read-only inspection service published by the initialized production graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \LogicException When the integration plugin graph is unavailable.
	 *
	 * @return  Inspection
	 */
	protected function inspection(): Inspection {
		$inspection = Component::get_inspection();
		if ( null === $inspection ) {
			throw new \LogicException( 'Integration inspection is unavailable before the engine graph is initialized.' );
		}

		return $inspection;
	}

	// endregion.
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\AbstractIntegrationTestCase;

/**
 * Pins Action Scheduler 4.0 unique-action semantics for pending and in-progress rows.
 *
 * The engine composes its pending-only idempotent skip with AS-unique as a second layer.
 */
final class ActionSchedulerUniquePinTest extends AbstractIntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Action hook isolated to this Action Scheduler drift detector. */
	private const HOOK = 'a8csp_bgje/integration/as_unique';

	/** Action group isolated to this Action Scheduler drift detector. */
	private const GROUP = 'a8csp-bgje-integration-as-unique';

	// endregion.

	// region TESTS.

	/**
	 * A pending unique action blocks an identical enqueue.
	 *
	 * @return  void
	 */
	public function test_pending_action_blocks_an_identical_unique_enqueue(): void {
		$args = array( 'pending' );

		$first_action_id = \as_enqueue_async_action( self::HOOK, $args, self::GROUP, true );
		self::assertGreaterThan( 0, $first_action_id, 'Action Scheduler must persist the first pending unique action' );

		$store = $this->action_scheduler_store();
		self::assertSame( \ActionScheduler_Store::STATUS_PENDING, $store->get_status( (string) $first_action_id ), 'The uniqueness probe must begin with a pending action' );

		$duplicate_action_id = \as_enqueue_async_action( self::HOOK, $args, self::GROUP, true );
		self::assertSame( 0, $duplicate_action_id, 'Action Scheduler must reject a duplicate of a pending unique action' );
		self::assertSame(
			array( (string) $first_action_id ),
			$store->query_actions(
				array(
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					'per_page' => -1,
					'orderby'  => 'action_id',
					'order'    => 'ASC',
				)
			),
			'Pending unique dedup must retain exactly the original Action Scheduler row'
		);
	}

	/**
	 * An in-progress unique action blocks an identical enqueue from its callback.
	 *
	 * @return  void
	 */
	public function test_in_progress_action_blocks_an_identical_unique_enqueue(): void {
		$store               = $this->action_scheduler_store();
		$args                = array( 'in-progress' );
		$first_action_id     = 0;
		$observed_status     = null;
		$duplicate_action_id = null;
		$execution_calls     = 0;

		\add_action(
			self::HOOK,
			static function () use (
				$store,
				$args,
				&$first_action_id,
				&$observed_status,
				&$duplicate_action_id,
				&$execution_calls
			): void {
				++$execution_calls;
				$observed_status     = $store->get_status( (string) $first_action_id );
				$duplicate_action_id = \as_enqueue_async_action( self::HOOK, $args, self::GROUP, true );
			},
			10,
			0
		);

		$first_action_id = \as_enqueue_async_action( self::HOOK, $args, self::GROUP, true );
		self::assertGreaterThan( 0, $first_action_id, 'Action Scheduler must persist the first in-progress probe action' );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute the in-progress uniqueness probe' );

		self::assertSame( 1, $execution_calls, 'The in-progress uniqueness probe must execute exactly once' );
		self::assertSame( \ActionScheduler_Store::STATUS_RUNNING, $observed_status, 'Action Scheduler must mark the original row in-progress before invoking its callback' );
		self::assertSame( 0, $duplicate_action_id, 'Action Scheduler must reject a duplicate of an in-progress unique action' );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( (string) $first_action_id ), 'Action Scheduler must complete the original uniqueness probe after its callback returns' );
		self::assertSame(
			array( (string) $first_action_id ),
			$store->query_actions(
				array(
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					'per_page' => -1,
					'orderby'  => 'action_id',
					'order'    => 'ASC',
				)
			),
			'In-progress unique dedup must retain exactly the original Action Scheduler row'
		);
	}

	// endregion.
}

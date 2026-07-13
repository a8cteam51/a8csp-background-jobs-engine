<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;

/**
 * Verifies unknown recurring chains are cleared after Action Scheduler creates their successors.
 */
final class UnknownScheduleCleanupTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Schedule-delivery hook shared with the live engine. */
	private const HOOK = 'a8csp/background_tasks/schedule_due';

	/** Unknown registration identity isolated to this integration test. */
	private const KEY = 'integration-owner:unknown-cleanup';

	// endregion.

	// region TESTS.

	/**
	 * A distinct cleanup single removes the successor created after the unknown callback returns.
	 *
	 * @return  void
	 */
	public function test_cleanup_delivery_removes_the_post_callback_recurring_successor(): void {
		$this->expectOutputRegex(
			'/Unknown schedule registration "integration-owner:unknown-cleanup" was delivered;/'
		);
		$action_id = \as_schedule_recurring_action(
			\time() - 1,
			300,
			self::HOOK,
			array( self::KEY ),
			self::KEY,
			true,
			10
		);
		self::assertGreaterThan( 0, $action_id );

		self::assertSame( 1, $this->run_next_due_action() );

		$store       = $this->action_scheduler_store();
		$pending_ids = $store->query_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => self::KEY,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
		self::assertIsArray( $pending_ids );
		self::assertCount( 2, $pending_ids, 'The cleanup single and recurring successor must coexist after the callback' );

		$pending_args = array();
		foreach ( $pending_ids as $pending_id ) {
			self::assertIsString( $pending_id );
			$action = $store->fetch_action( $pending_id );
			self::assertInstanceOf( \ActionScheduler_Action::class, $action );
			$pending_args[] = $action->get_args();
		}
		self::assertContains( array( self::KEY ), $pending_args );
		self::assertContains( array( self::KEY, 'cleanup' ), $pending_args );

		self::assertSame( 1, $this->run_next_due_action() );
		self::assertSame(
			array(),
			$store->query_actions(
				array(
					'hook'     => self::HOOK,
					'group'    => self::KEY,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				)
			),
			'The cleanup single must clear the recurring identity without creating another cleanup'
		);
	}

	// endregion.
}

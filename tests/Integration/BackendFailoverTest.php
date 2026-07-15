<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;

/**
 * Not-ready Action Scheduler occurrences remain dormant and return when readiness recovers.
 */
final class BackendFailoverTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Hook isolated to the backend-readiness transition. */
	private const HOOK = 'a8csp_bgte/integration/backend_failover';

	/** Action Scheduler group isolated to the dormant occurrence. */
	private const ACTION_SCHEDULER_GROUP = 'a8csp-bgte-integration-backend-failover-as';

	/** Advisory group isolated to the WP-Cron fallback occurrence. */
	private const WP_CRON_GROUP = 'a8csp-bgte-integration-backend-failover-cron';

	// endregion.

	// region TESTS.

	/**
	 * A dormant Action Scheduler occurrence survives failover and becomes cancellable after recovery.
	 *
	 * @return  void
	 */
	public function test_action_scheduler_occurrence_is_dormant_not_lost_while_writes_fail_over(): void {
		$action_scheduler_ready = true;
		$scheduler              = $this->scheduler_facade_with_action_scheduler_probe(
			static function () use ( &$action_scheduler_ready ): bool {
				return $action_scheduler_ready;
			}
		);
		$action_scheduler_args  = array( 'action-scheduler' );
		$wp_cron_args           = array( 'wp-cron' );
		$action_scheduler_at    = \time() + 2 * \HOUR_IN_SECONDS;
		$wp_cron_at             = $action_scheduler_at + \MINUTE_IN_SECONDS;

		$scheduled = $scheduler->schedule_single( self::HOOK, $action_scheduler_at, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP, 20 );
		self::assertInstanceOf( Success::class, $scheduled, 'The ready preferred backend must accept the occurrence' );
		self::assertTrue( $scheduled->value );

		$store      = $this->action_scheduler_store();
		$action_ids = $store->query_actions(
			array(
				'hook'     => self::HOOK,
				'args'     => $action_scheduler_args,
				'group'    => self::ACTION_SCHEDULER_GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
		self::assertIsArray( $action_ids );
		self::assertCount( 1, $action_ids, 'The ready write must persist exactly one Action Scheduler occurrence' );
		self::assertIsString( $action_ids[0] ?? null );
		$action_id = $action_ids[0];
		$action    = $store->fetch_action( $action_id );
		self::assertInstanceOf( \ActionScheduler_Action::class, $action );
		self::assertSame( self::HOOK, $action->get_hook() );
		self::assertSame( $action_scheduler_args, $action->get_args() );
		self::assertSame( self::ACTION_SCHEDULER_GROUP, $action->get_group() );
		self::assertSame( $action_scheduler_at, $store->get_date( $action_id )->getTimestamp() );
		self::assertSame( \ActionScheduler_Store::STATUS_PENDING, $store->get_status( $action_id ) );
		self::assertSame( array(), $this->wordpress_cron_events( self::HOOK, $action_scheduler_args ), 'The ready preferred-backend write must not create a WP-Cron duplicate' );

		$action_scheduler_ready = false;

		self::assertFalse( $scheduler->is_scheduled( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP ), 'A not-ready backend occurrence must remain dormant to facade reads' );
		self::assertNull( $scheduler->get_next_scheduled( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP ), 'A not-ready backend timestamp must remain dormant to facade reads' );
		$dormant_clear = $scheduler->unschedule( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP );
		self::assertInstanceOf( Success::class, $dormant_clear, 'Clearing currently-ready backends must succeed' );
		self::assertTrue( $dormant_clear->value );
		self::assertSame(
			array( $action_id ),
			$store->query_actions(
				array(
					'hook'     => self::HOOK,
					'args'     => $action_scheduler_args,
					'group'    => self::ACTION_SCHEDULER_GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				)
			),
			'Dormant clear success must leave the not-ready Action Scheduler row pending'
		);
		self::assertSame( $action_scheduler_at, $store->get_date( $action_id )->getTimestamp() );
		self::assertSame( \ActionScheduler_Store::STATUS_PENDING, $store->get_status( $action_id ) );

		$fallback_scheduled = $scheduler->schedule_single( self::HOOK, $wp_cron_at, $wp_cron_args, self::WP_CRON_GROUP, 30 );
		self::assertInstanceOf( Success::class, $fallback_scheduled, 'The ready fallback must accept a new occurrence' );
		self::assertTrue( $fallback_scheduled->value );
		$wp_cron_events = $this->wordpress_cron_events( self::HOOK, $wp_cron_args );
		self::assertCount( 1, $wp_cron_events, 'The fallback write must persist exactly one WP-Cron occurrence' );
		self::assertSame( $wp_cron_at, $wp_cron_events[0]['timestamp'] );
		self::assertFalse( $wp_cron_events[0]['schedule'] );
		self::assertSame( $wp_cron_args, $wp_cron_events[0]['args'] );
		self::assertNull( $wp_cron_events[0]['interval'] );
		self::assertSame(
			array(),
			$store->query_actions(
				array(
					'hook'     => self::HOOK,
					'args'     => $wp_cron_args,
					'group'    => self::WP_CRON_GROUP,
					'per_page' => -1,
				)
			),
			'Failover must not add the fallback occurrence to the dormant Action Scheduler store'
		);

		$action_scheduler_ready = true;

		self::assertTrue( $scheduler->is_scheduled( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP ), 'The dormant occurrence must become visible when Action Scheduler readiness returns' );
		self::assertSame( $action_scheduler_at, $scheduler->get_next_scheduled( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP ), 'The recovered backend must expose the original occurrence timestamp' );
		$recovered_clear = $scheduler->unschedule( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP );
		self::assertInstanceOf( Success::class, $recovered_clear, 'The recovered backend occurrence must be cancellable' );
		self::assertTrue( $recovered_clear->value );
		self::assertSame(
			array(),
			$store->query_actions(
				array(
					'hook'     => self::HOOK,
					'args'     => $action_scheduler_args,
					'group'    => self::ACTION_SCHEDULER_GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				)
			),
			'Recovered clear success must remove the original occurrence from the pending store'
		);
		self::assertSame( \ActionScheduler_Store::STATUS_CANCELED, $store->get_status( $action_id ), 'Recovered clear success must cancel the exact dormant Action Scheduler row' );
		self::assertSame( $wp_cron_events, $this->wordpress_cron_events( self::HOOK, $wp_cron_args ), 'Clearing the recovered Action Scheduler identity must preserve the fallback WP-Cron occurrence' );
	}

	// endregion.
}

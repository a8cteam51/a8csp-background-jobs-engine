<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;

/**
 * Not-ready Action Scheduler occurrences remain dormant and return when readiness recovers.
 *
 * @since   1.0.0
 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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

		self::assertTrue( $scheduler->is_scheduled( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP ), 'The ready preferred backend must expose the accepted occurrence' );
		self::assertSame( $action_scheduler_at, $scheduler->get_next_scheduled( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP ) );
		self::assertFalse( $scheduler->has_dormant_candidate() );

		$action_scheduler_ready = false;

		self::assertFalse( $scheduler->is_scheduled( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP ), 'A not-ready backend occurrence must remain dormant to facade reads' );
		self::assertNull( $scheduler->get_next_scheduled( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP ), 'A not-ready backend timestamp must remain dormant to facade reads' );
		self::assertTrue( $scheduler->has_dormant_candidate(), 'Inspection must disclose that currently invisible backend work may exist' );
		$dormant_clear = $scheduler->unschedule( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP );
		self::assertInstanceOf( Success::class, $dormant_clear, 'Clearing currently-ready backends must succeed' );
		self::assertTrue( $dormant_clear->value );

		$fallback_scheduled = $scheduler->schedule_single( self::HOOK, $wp_cron_at, $wp_cron_args, self::WP_CRON_GROUP, 30 );
		self::assertInstanceOf( Success::class, $fallback_scheduled, 'The ready fallback must accept a new occurrence' );
		self::assertTrue( $fallback_scheduled->value );
		self::assertTrue( $scheduler->is_scheduled( self::HOOK, $wp_cron_args, self::WP_CRON_GROUP ) );
		self::assertSame( $wp_cron_at, $scheduler->get_next_scheduled( self::HOOK, $wp_cron_args, self::WP_CRON_GROUP ) );
		self::assertFalse( $scheduler->is_scheduled( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP ) );

		$action_scheduler_ready = true;

		self::assertTrue( $scheduler->is_scheduled( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP ), 'The dormant occurrence must become visible when Action Scheduler readiness returns' );
		self::assertSame( $action_scheduler_at, $scheduler->get_next_scheduled( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP ), 'The recovered backend must expose the original occurrence timestamp' );
		self::assertTrue( $scheduler->is_scheduled( self::HOOK, $wp_cron_args, self::WP_CRON_GROUP ), 'Recovery must preserve the fallback occurrence' );
		self::assertSame( $wp_cron_at, $scheduler->get_next_scheduled( self::HOOK, $wp_cron_args, self::WP_CRON_GROUP ) );
		self::assertFalse( $scheduler->has_dormant_candidate() );
		$recovered_clear = $scheduler->unschedule( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP );
		self::assertInstanceOf( Success::class, $recovered_clear, 'The recovered backend occurrence must be cancellable' );
		self::assertTrue( $recovered_clear->value );
		self::assertFalse( $scheduler->is_scheduled( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP ), 'Recovered clear success must remove the original occurrence' );
		self::assertNull( $scheduler->get_next_scheduled( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP ) );
		self::assertTrue( $scheduler->is_scheduled( self::HOOK, $wp_cron_args, self::WP_CRON_GROUP ), 'Clearing the recovered identity must preserve the fallback occurrence' );
		self::assertSame( $wp_cron_at, $scheduler->get_next_scheduled( self::HOOK, $wp_cron_args, self::WP_CRON_GROUP ) );
	}

	// endregion.
}

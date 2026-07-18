<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Logging\HookLogger;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\CleanupIntents;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Randomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\Schedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\SystemClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\WorkRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\ReadinessControlledBackend;

/**
 * Not-ready Action Scheduler occurrences remain dormant and return when readiness recovers.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class BackendFailoverTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Hook isolated to the backend-readiness transition. */
	private const string HOOK = 'a8csp_bgte/integration/backend_failover';

	/** Action Scheduler group isolated to the dormant occurrence. */
	private const string ACTION_SCHEDULER_GROUP = 'a8csp-bgte-integration-backend-failover-as';

	/** Advisory group isolated to the WP-Cron fallback occurrence. */
	private const string WP_CRON_GROUP = 'a8csp-bgte-integration-backend-failover-cron';

	/** Owner isolated to recurring-chain convergence. */
	private const string CONVERGENCE_OWNER = 'integration-backend-convergence';

	/** Owner-qualified schedule identity isolated to recurring-chain convergence. */
	private const string CONVERGENCE_IDENTITY = 'integration-backend-convergence:recurring';

	/** Target task isolated to recurring-chain convergence. */
	private const string CONVERGENCE_TASK = 'integration-backend-convergence-task';

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
		$action_scheduler      = new ReadinessControlledBackend();
		$scheduler             = $this->scheduler_facade_with_controllable_action_scheduler( $action_scheduler );
		$action_scheduler_args = array( 'action-scheduler' );
		$wp_cron_args          = array( 'wp-cron' );
		$action_scheduler_at   = \time() + 2 * \HOUR_IN_SECONDS;
		$wp_cron_at            = $action_scheduler_at + \MINUTE_IN_SECONDS;

		$scheduled = $scheduler->schedule_single( self::HOOK, $action_scheduler_at, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP, 20 );
		self::assertInstanceOf( Success::class, $scheduled, 'The ready preferred backend must accept the occurrence' );
		self::assertTrue( $scheduled->value );

		self::assertTrue( $scheduler->is_scheduled( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP ), 'The ready preferred backend must expose the accepted occurrence' );
		self::assertSame( $action_scheduler_at, $scheduler->get_next_scheduled( self::HOOK, $action_scheduler_args, self::ACTION_SCHEDULER_GROUP ) );
		self::assertFalse( $scheduler->has_dormant_candidate() );

		$action_scheduler->ready = false;

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

		$action_scheduler->ready = true;

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

	/**
	 * A recovered preferred chain converges with its fallback duplicate without changing registration state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_converges_a_recovered_recurring_chain_with_its_fallback_duplicate(): void {
		$registry_option = ScheduleRegistry::option_name( self::CONVERGENCE_OWNER );
		$this->expect_option( $registry_option );
		$action_scheduler       = new ReadinessControlledBackend();
		$scheduler              = $this->scheduler_facade_with_controllable_action_scheduler( $action_scheduler );
		$schedules              = $this->schedules_with_scheduler( $scheduler );
		$schedule               = new Schedule( 'recurring', Recurrence::every( 300 ), self::CONVERGENCE_TASK, priority: 37 );
		$declarations           = array(
			self::CONVERGENCE_IDENTITY => array(
				'schedule' => $schedule,
				'task'     => self::CONVERGENCE_OWNER . ':' . self::CONVERGENCE_TASK,
			),
		);
		$action_scheduler_probe = new SchedulerFacade( array( new ActionSchedulerBackend() ) );
		$wp_cron_probe          = new SchedulerFacade( array( new WPCronBackend() ) );

		$preferred = $schedules->sync( self::CONVERGENCE_OWNER, $declarations );
		self::assertInstanceOf( Success::class, $preferred );
		self::assertSame( 1, $action_scheduler_probe->ready_scheduled_count( OccurrenceDelivery::SCHEDULE_HOOK, array( self::CONVERGENCE_IDENTITY ), self::CONVERGENCE_IDENTITY ) );
		self::assertSame( 0, $wp_cron_probe->ready_scheduled_count( OccurrenceDelivery::SCHEDULE_HOOK, array( self::CONVERGENCE_IDENTITY ), self::CONVERGENCE_IDENTITY ) );
		$registration_before = \get_option( $registry_option, null );
		self::assertIsArray( $registration_before );

		$action_scheduler->ready = false;
		$fallback                = $schedules->sync( self::CONVERGENCE_OWNER, $declarations );
		self::assertInstanceOf( Success::class, $fallback );
		self::assertSame( 1, $action_scheduler_probe->ready_scheduled_count( OccurrenceDelivery::SCHEDULE_HOOK, array( self::CONVERGENCE_IDENTITY ), self::CONVERGENCE_IDENTITY ) );
		self::assertSame( 1, $wp_cron_probe->ready_scheduled_count( OccurrenceDelivery::SCHEDULE_HOOK, array( self::CONVERGENCE_IDENTITY ), self::CONVERGENCE_IDENTITY ) );

		$action_scheduler->ready = true;
		$converged               = $schedules->sync( self::CONVERGENCE_OWNER, $declarations );

		self::assertInstanceOf( Success::class, $converged );
		self::assertSame( 1, $action_scheduler_probe->ready_scheduled_count( OccurrenceDelivery::SCHEDULE_HOOK, array( self::CONVERGENCE_IDENTITY ), self::CONVERGENCE_IDENTITY ) );
		self::assertSame( 0, $wp_cron_probe->ready_scheduled_count( OccurrenceDelivery::SCHEDULE_HOOK, array( self::CONVERGENCE_IDENTITY ), self::CONVERGENCE_IDENTITY ) );
		self::assertSame( $registration_before, \get_option( $registry_option, null ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Builds schedule synchronization against one controllable live scheduler facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   SchedulerFacade $scheduler Scheduling facade under test.
	 *
	 * @return  Schedules
	 */
	private function schedules_with_scheduler( SchedulerFacade $scheduler ): Schedules {
		global $wpdb;

		self::assertInstanceOf( \wpdb::class, $wpdb );
		$rows                 = new OptionRows( $wpdb );
		$work                 = new WorkRegistry();
		$clock                = new SystemClock();
		$logger               = new HookLogger();
		$registry             = new ScheduleRegistry( $rows, $logger );
		$randomizer           = new Randomizer();
		$guard                = new OverlapGuard( $clock, $logger, $rows );
		$stores               = new StoreFactory( $clock, $rows, $logger );
		$lock_windows         = new LockWindows( $clock, $logger );
		$terminal_effects     = new LifecycleEffects( $guard, $stores, $logger );
		$terminal_transitions = new RunTransitions( $guard, $stores, $clock, $lock_windows, $logger, $terminal_effects );
		$dispatcher           = new Dispatcher( $work, $scheduler, $guard, $stores, $clock, $randomizer, $logger, $lock_windows, $terminal_transitions, $terminal_effects );
		$occurrence_lease     = new OccurrenceLease( $rows, $clock, $randomizer );
		$cleanup_intents      = new CleanupIntents( $registry, $scheduler, $rows, $clock, $logger );
		$occurrence_delivery  = new OccurrenceDelivery( $registry, $dispatcher, $occurrence_lease, $cleanup_intents, $clock, $logger );

		return new Schedules( $registry, $scheduler, $clock, $occurrence_delivery );
	}

	// endregion.
}

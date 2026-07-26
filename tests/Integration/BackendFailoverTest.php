<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Logging\EngineLogger;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Randomizer;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\DeliveryScheduler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\ChunkedJobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\JobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\CleanupIntents;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceLease;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\SystemClock;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\AbstractIntegrationTestCase;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\ReadinessControlledBackend;

/**
 * Not-ready Action Scheduler occurrences remain dormant and return when readiness recovers.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class BackendFailoverTest extends AbstractIntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Hook isolated to the backend-readiness transition. */
	private const string HOOK = 'a8csp_bgje/integration/backend_failover';

	/** Action Scheduler group isolated to the dormant occurrence. */
	private const string ACTION_SCHEDULER_GROUP = 'a8csp-bgje-integration-backend-failover-as';

	/** Advisory group isolated to the WP-Cron fallback occurrence. */
	private const string WP_CRON_GROUP = 'a8csp-bgje-integration-backend-failover-cron';

	/** Scope isolated to recurring-chain convergence. */
	private const string CONVERGENCE_SCOPE = 'integration-backend-convergence';

	/** Scope-qualified schedule identity isolated to recurring-chain convergence. */
	private const string CONVERGENCE_IDENTITY = 'integration-backend-convergence:recurring';

	/** Target job isolated to recurring-chain convergence. */
	private const string CONVERGENCE_JOB = 'integration-backend-convergence-job';

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
		$registry_option = ScheduleRegistry::option_name( self::CONVERGENCE_SCOPE );
		$this->expect_option( $registry_option );
		$action_scheduler       = new ReadinessControlledBackend();
		$scheduler              = $this->scheduler_facade_with_controllable_action_scheduler( $action_scheduler );
		$schedules              = $this->schedules_with_scheduler( $scheduler );
		$schedule               = new Schedule( 'recurring', Recurrence::every( 300 ), self::CONVERGENCE_JOB, priority: 37 );
		$declarations           = array(
			self::CONVERGENCE_IDENTITY => array(
				'schedule' => $schedule,
				'job'      => Identity::compose( self::CONVERGENCE_SCOPE, self::CONVERGENCE_JOB ),
			),
		);
		$action_scheduler_probe = new SchedulerFacade( array( new ActionSchedulerBackend() ) );
		$wp_cron_probe          = new SchedulerFacade( array( new WPCronBackend() ) );

		$preferred = $schedules->sync( self::CONVERGENCE_SCOPE, $declarations );
		self::assertInstanceOf( Success::class, $preferred );
		self::assertSame( 1, $action_scheduler_probe->scheduled_count( OccurrenceDelivery::SCHEDULE_HOOK, array( self::CONVERGENCE_IDENTITY ), self::CONVERGENCE_IDENTITY ) );
		self::assertSame( 0, $wp_cron_probe->scheduled_count( OccurrenceDelivery::SCHEDULE_HOOK, array( self::CONVERGENCE_IDENTITY ), self::CONVERGENCE_IDENTITY ) );
		$registration_before = \get_option( $registry_option, null );
		self::assertIsArray( $registration_before );

		$action_scheduler->ready = false;
		$fallback                = $schedules->sync( self::CONVERGENCE_SCOPE, $declarations );
		self::assertInstanceOf( Success::class, $fallback );
		self::assertSame( 1, $action_scheduler_probe->scheduled_count( OccurrenceDelivery::SCHEDULE_HOOK, array( self::CONVERGENCE_IDENTITY ), self::CONVERGENCE_IDENTITY ) );
		self::assertSame( 1, $wp_cron_probe->scheduled_count( OccurrenceDelivery::SCHEDULE_HOOK, array( self::CONVERGENCE_IDENTITY ), self::CONVERGENCE_IDENTITY ) );

		$action_scheduler->ready = true;
		$converged               = $schedules->sync( self::CONVERGENCE_SCOPE, $declarations );

		self::assertInstanceOf( Success::class, $converged );
		self::assertSame( 1, $action_scheduler_probe->scheduled_count( OccurrenceDelivery::SCHEDULE_HOOK, array( self::CONVERGENCE_IDENTITY ), self::CONVERGENCE_IDENTITY ) );
		self::assertSame( 0, $wp_cron_probe->scheduled_count( OccurrenceDelivery::SCHEDULE_HOOK, array( self::CONVERGENCE_IDENTITY ), self::CONVERGENCE_IDENTITY ) );
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
	 * @return  ScheduleOperations
	 */
	private function schedules_with_scheduler( SchedulerFacade $scheduler ): ScheduleOperations {
		global $wpdb;

		self::assertInstanceOf( \wpdb::class, $wpdb );
		$rows                 = new OptionRows( $wpdb );
		$job_registry         = new JobRegistry();
		$clock                = new SystemClock();
		$logger               = new EngineLogger();
		$registry             = new ScheduleRegistry( $rows, $logger );
		$randomizer           = new Randomizer();
		$guard                = new OverlapGuard( $clock, $logger, $rows );
		$overlap_identity     = new OverlapIdentity();
		$stores               = new StoreFactory( $clock, $rows, $logger );
		$lock_windows         = new LockWindows( $clock, $logger );
		$terminal_effects     = new LifecycleEffects( $guard, $stores, $logger );
		$terminal_transitions = new RunTransitions( $guard, $stores, $clock, $lock_windows, $logger, $terminal_effects );
		$delivery_scheduler   = new DeliveryScheduler( $scheduler, $clock );
		$failure_lifecycle    = new FailureLifecycle( $delivery_scheduler, $clock, $randomizer, $logger, $terminal_transitions, $terminal_effects );
		$job_handler          = new JobKindHandler( $job_registry, $logger, $clock, $lock_windows, $terminal_transitions, $terminal_effects, $failure_lifecycle );
		$chunked_job_handler  = new ChunkedJobKindHandler( $job_registry, $delivery_scheduler, $logger, $clock, $lock_windows, $terminal_transitions, $terminal_effects, $failure_lifecycle );
		$handlers             = array(
			$job_handler->key()         => $job_handler,
			$chunked_job_handler->key() => $chunked_job_handler,
		);
		$dispatcher           = new Dispatcher( $job_registry, $handlers, $scheduler, $delivery_scheduler, $guard, $overlap_identity, $stores, $clock, $randomizer, $logger, $lock_windows, $terminal_transitions );
		$occurrence_lease     = new OccurrenceLease( $rows, $clock, $randomizer );
		$cleanup_intents      = new CleanupIntents( $registry, $scheduler, $rows, $clock, $logger );
		$occurrence_delivery  = new OccurrenceDelivery( $registry, $dispatcher, $occurrence_lease, $cleanup_intents, $clock, $logger );

		return new ScheduleOperations( $registry, $scheduler, $clock, $occurrence_delivery );
	}

	// endregion.
}

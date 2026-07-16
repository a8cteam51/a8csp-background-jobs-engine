<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\Batches as ApiBatches;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\Runs as ApiRuns;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedules as ApiSchedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\Tasks as ApiTasks;
use A8C\SpecialProjects\BackgroundTasksEngine\ComponentInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\EngineFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Randomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunReconciliation;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\SystemClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Maintenance\MaintenanceSchedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Maintenance\MaintenanceTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\CleanupIntents;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Logging\ErrorLogSink;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Logging\HookLogger;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Assembles and retains the engine's request-local object graph.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class Component implements ComponentInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * Engine published by the successfully initialized component.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     EngineFacade|null
	 */
	private static ?EngineFacade $engine = null;

	/**
	 * Whether engine wiring is currently in flight.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     bool
	 */
	private static bool $booting = false;

	/**
	 * Read-only inspection service published by the initialized component.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     Inspection|null
	 */
	private static ?Inspection $inspection = null;

	/**
	 * Registered task and batch instances published by the initialized component.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     WorkRegistry|null
	 */
	private static ?WorkRegistry $work = null;

	/**
	 * Schedule operations published by the initialized component.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     Schedules|null
	 */
	private static ?Schedules $schedules = null;

	/**
	 * Background-work admission coordinator published by the initialized component.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     Dispatcher|null
	 */
	private static ?Dispatcher $dispatcher = null;

	/**
	 * Scheduling facade published by the initialized component.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     SchedulerFacade|null
	 */
	private static ?SchedulerFacade $scheduler = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * Keeps the engine available on every supported site.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	#[\Override]
	public function is_needed(): bool {
		return true;
	}

	/**
	 * Builds the engine graph and registers its runtime hooks once.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public function initialize(): void {
		// The in-flight flag keeps a re-entrant resolve during wiring from building a second graph.
		if ( null !== self::$engine || self::$booting ) {
			return;
		}

		self::$booting = true;

		try {
			ErrorLogSink::register();

			global $wpdb;

			/**
			 * WordPress database connection for the current site.
			 *
			 * @var \wpdb $wpdb
			 */
			$option_rows          = new OptionRows( $wpdb );
			$work                 = new WorkRegistry();
			$schedules            = new ScheduleRegistry( $option_rows );
			$logger               = new HookLogger();
			$clock                = new SystemClock();
			$randomizer           = new Randomizer();
			$guard                = new OverlapGuard( $clock, $logger, $option_rows );
			$stores               = new StoreFactory( $clock, $option_rows );
			$lock_windows         = new LockWindows( $clock );
			$terminal_effects     = new LifecycleEffects( $guard, $stores, $logger );
			$terminal_transitions = new RunTransitions( $guard, $stores, $clock, $lock_windows, $logger, $terminal_effects );
			$scheduler            = new SchedulerFacade(
				array(
					new ActionSchedulerBackend(),
					new WPCronBackend(),
				)
			);
			$failure_lifecycle    = new FailureLifecycle( $scheduler, $clock, $randomizer, $logger, $terminal_transitions );
			$action_deliveries    = new ActionDeliveries( $work, $scheduler, $stores, $logger, $clock, $lock_windows, $terminal_transitions, $terminal_effects, $failure_lifecycle );
			$dispatcher           = new Dispatcher( $work, $scheduler, $guard, $stores, $clock, $randomizer, $logger, $lock_windows, $terminal_transitions, $terminal_effects );
			$reconciliation       = new RunReconciliation( $guard, $stores, $clock, $logger, $lock_windows, $terminal_transitions, $terminal_effects, $work, $scheduler );
			$occurrence_lease     = new OccurrenceLease( $option_rows, $clock, $randomizer );
			$cleanup_intents      = new CleanupIntents( $schedules, $scheduler, $option_rows, $clock, $logger );
			$occurrence_delivery  = new OccurrenceDelivery( $schedules, $dispatcher, $occurrence_lease, $cleanup_intents, $clock, $logger );
			$work->register_task( WorkIdentity::compose( WorkIdentity::ENGINE_OWNER, MaintenanceTask::NAME, true ), new MaintenanceTask( $option_rows, $reconciliation, $guard, $cleanup_intents, $logger ) );
			$schedule_api         = new Schedules( $schedules, $scheduler, $clock, $occurrence_delivery );
			$maintenance_schedule = new MaintenanceSchedule( $schedule_api, $logger );
			$inspection           = new Inspection( $schedules, $work, $scheduler, $guard, $stores, $option_rows, $lock_windows, $clock );
			$engine               = new EngineFacade( $schedule_api, $dispatcher, $inspection );

			$scheduler->register_hooks();
			$action_deliveries->register_hooks();
			$occurrence_delivery->register_hooks();

			self::$engine     = $engine;
			self::$inspection = $inspection;
			self::$scheduler  = $scheduler;
			self::$work       = $work;
			self::$schedules  = $schedule_api;
			self::$dispatcher = $dispatcher;
		} finally {
			self::$booting = false;
		}

		// Late maintenance synchronization invokes scheduler filters; publication keeps a consumer
		// resolving from one of those filters on this same graph instead of rebuilding it recursively.
		$maintenance_schedule->register_hooks();
	}

	// endregion

	// region METHODS

	/**
	 * Returns a supported facade set bound to one validated consumer owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Validated consumer owner.
	 *
	 * @throws  \InvalidArgumentException When the owner violates the consumer-owner contract.
	 * @throws  \LogicException           When the internal graph is unavailable.
	 *
	 * @return  Consumer
	 */
	public static function consumer( string $owner ): Consumer {
		WorkIdentity::validate_owner( $owner );
		$work       = self::$work;
		$schedules  = self::$schedules;
		$dispatcher = self::$dispatcher;
		$inspection = self::$inspection;
		if ( null === self::$engine || null === $work || null === $schedules || null === $dispatcher || null === $inspection ) {
			throw new \LogicException( 'The background tasks engine graph is unavailable after engine boot.' );
		}

		$adapter = new ApiAdapter( $owner, $work, $schedules, $dispatcher, $inspection );

		return new Consumer(
			$owner,
			new ApiTasks( $owner, $adapter ),
			new ApiBatches( $owner, $adapter ),
			new ApiSchedules( $owner, $adapter ),
			new ApiRuns( $owner, $adapter )
		);
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the initialized engine, or null before component boot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  EngineFacade|null
	 */
	public static function get_engine(): ?EngineFacade {
		return self::$engine;
	}

	/**
	 * Returns the initialized read-only inspection service, or null before component boot.
	 *
	 * @internal CLI inspection only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Inspection|null
	 */
	public static function get_inspection(): ?Inspection {
		return self::$inspection;
	}

	/**
	 * Returns the initialized scheduling facade, or null before component boot.
	 *
	 * @internal CLI development reset only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  SchedulerFacade|null
	 */
	public static function get_scheduler(): ?SchedulerFacade {
		return self::$scheduler;
	}

	// endregion
}

<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Component as ComponentContract;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine as EngineFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\Randomization\Randomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunReconciliation;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\Clock\SystemClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\TerminalTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\WorkRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\MaintenanceSchedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\MaintenanceTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\Inspection;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\Schedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\Logging\HookLogger;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\WorkIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Assembles and retains the engine's request-local object graph.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class Component implements ComponentContract {
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

		global $wpdb;

		/**
		 * WordPress database connection for the current site.
		 *
		 * @var \wpdb $wpdb
		 */
		$option_rows          = new OptionRows( $wpdb );
		$work                 = new WorkRegistry();
		$tasks                = new TaskRegistry( $work );
		$batches              = new BatchRegistry( $work );
		$schedules            = new ScheduleRegistry( $option_rows );
		$logger               = new HookLogger();
		$clock                = new SystemClock();
		$randomizer           = new Randomizer();
		$guard                = new OverlapGuard( $clock, $logger, $option_rows );
		$stores               = new StoreFactory( $clock, $option_rows );
		$lock_windows         = new LockWindows( $clock );
		$terminal_transitions = new TerminalTransitions( $guard, $stores, $clock, $lock_windows, $logger );
		$scheduler            = new SchedulerFacade(
			array(
				new ActionSchedulerBackend(),
				new WPCronBackend(),
			)
		);
		$failure_lifecycle    = new FailureLifecycle(
			$scheduler,
			$clock,
			$randomizer,
			$logger,
			$terminal_transitions
		);
		$action_deliveries    = new ActionDeliveries(
			$tasks,
			$batches,
			$scheduler,
			$stores,
			$logger,
			$clock,
			$lock_windows,
			$terminal_transitions,
			$failure_lifecycle
		);
		$dispatcher           = new Dispatcher(
			$tasks,
			$batches,
			$scheduler,
			$guard,
			$stores,
			$clock,
			$randomizer,
			$logger,
			$lock_windows,
			$terminal_transitions
		);
		$reconciliation       = new RunReconciliation(
			$guard,
			$stores,
			$clock,
			$logger,
			$lock_windows,
			$terminal_transitions,
			$tasks,
			$batches,
			$scheduler
		);
		$occurrence_lease     = new OccurrenceLease( $option_rows, $clock, $randomizer );
		$occurrence_delivery  = new OccurrenceDelivery(
			$schedules,
			$dispatcher,
			$occurrence_lease,
			$scheduler,
			$option_rows,
			$clock,
			$logger
		);
		$tasks->register(
			WorkIdentity::compose( WorkIdentity::ENGINE_OWNER, MaintenanceTask::NAME, true ),
			new MaintenanceTask(
				$option_rows,
				$reconciliation,
				$guard,
				$occurrence_delivery,
				$logger
			)
		);
		$schedule_api         = new Schedules(
			$schedules,
			$scheduler,
			$clock,
			$occurrence_delivery
		);
		$maintenance_schedule = new MaintenanceSchedule( $schedule_api, $logger );
		$engine               = new EngineFacade(
			new Tasks( $tasks, $dispatcher ),
			$schedule_api,
			new Batches( $batches, $dispatcher ),
			$dispatcher
		);
		$inspection           = new Inspection(
			$schedules,
			$tasks,
			$batches,
			$scheduler,
			$guard,
			$stores,
			$option_rows,
			$lock_windows,
			$clock
		);

		$scheduler->register_hooks();
		$action_deliveries->register_hooks();
		$occurrence_delivery->register_hooks();

		self::$engine     = $engine;
		self::$booting    = false;
		self::$inspection = $inspection;

		// Late maintenance synchronization invokes scheduler filters; publication keeps a consumer
		// resolving from one of those filters on this same graph instead of rebuilding it recursively.
		$maintenance_schedule->register_hooks();
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

	// endregion
}

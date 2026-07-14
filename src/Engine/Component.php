<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Component as ComponentContract;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine as EngineFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Retry\FailureLifecycle;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Randomization\Randomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunReconciliation;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Clock\SystemClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\TerminalTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Tasks\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\MaintenanceSchedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\MaintenanceTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Inspection;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Logging\HookLogger;

\defined( 'ABSPATH' ) || exit;

/**
 * Assembles and retains the engine's request-local object graph.
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
		if ( null !== self::$engine ) {
			return;
		}

		global $wpdb;

		/**
		 * WordPress database connection for the current site.
		 *
		 * @var \wpdb $wpdb
		 */
		$option_rows          = new OptionRows( $wpdb );
		$tasks                = new TaskRegistry();
		$batches              = new BatchRegistry();
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
			$batches
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
		$maintenance_schedule->register_hooks();

		self::$engine     = $engine;
		self::$inspection = $inspection;
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

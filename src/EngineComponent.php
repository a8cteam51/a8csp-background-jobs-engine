<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LockRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Orchestrator;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Randomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\SystemClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\TerminalTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\MaintenanceTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\SchedulerFacade;

\defined( 'ABSPATH' ) || exit;

/**
 * Assembles and retains the engine's request-local object graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class EngineComponent implements Component {
	// region FIELDS AND CONSTANTS

	/**
	 * Engine published by the successfully initialized component.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     Engine|null
	 */
	private static ?Engine $engine = null;

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
		$lock_rows            = new LockRows( $wpdb );
		$guard                = new OverlapGuard( $clock, $logger, $lock_rows );
		$stores               = new StoreFactory( $clock, $option_rows );
		$terminal_transitions = new TerminalTransitions( $guard, $stores, $clock, $logger );
		$scheduler            = new SchedulerFacade(
			array(
				new ActionSchedulerBackend(),
				new WPCronBackend(),
			)
		);
		$orchestrator         = new Orchestrator(
			$tasks,
			$batches,
			$scheduler,
			$guard,
			$stores,
			$logger,
			$clock,
			new LockWindows( $clock ),
			$terminal_transitions,
			$randomizer,
		);
		$tasks->register( new MaintenanceTask( $wpdb, $orchestrator, $guard, $logger ) );
		$schedule_api = new Schedules(
			$schedules,
			$scheduler,
			$clock,
			$orchestrator,
			new OccurrenceLease( $lock_rows, $clock, $randomizer ),
			$logger
		);
		$engine       = new Engine(
			new Tasks( $tasks, $orchestrator ),
			$schedule_api,
			new Batches( $batches, $orchestrator ),
			$orchestrator,
		);

		$scheduler->register_hooks();
		$orchestrator->register_hooks();
		$schedule_api->register_hooks();

		self::$engine = $engine;
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the initialized engine, or null before component boot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Engine|null
	 */
	public static function get_engine(): ?Engine {
		return self::$engine;
	}

	// endregion
}

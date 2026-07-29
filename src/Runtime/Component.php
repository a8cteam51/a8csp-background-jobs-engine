<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\AbstractComponent;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\EngineUnavailableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\BackendInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockRepair;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Logging\EngineLogger;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Maintenance\MaintenanceJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Maintenance\MaintenanceSchedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\DeliveryScheduler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\ChunkedJobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\JobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunReconciliation;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\CleanupIntents;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceLease;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Assembles and retains the engine's request-local object graph.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class Component extends AbstractComponent {
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
	 * Explicit malformed-lock repair service published by the initialized component.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     LockRepair|null
	 */
	private static ?LockRepair $lock_repair = null;

	/**
	 * Registered background-work definitions published by the initialized component.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     JobRegistry|null
	 */
	private static ?JobRegistry $registry = null;

	/**
	 * Schedule operations published by the initialized component.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     ScheduleOperations|null
	 */
	private static ?ScheduleOperations $schedules = null;

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

	/**
	 * Action-delivery hooks retained between readiness and attachment.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     ActionDeliveries|null
	 */
	private ?ActionDeliveries $action_deliveries = null;

	/**
	 * Occurrence-delivery hooks retained between readiness and attachment.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     OccurrenceDelivery|null
	 */
	private ?OccurrenceDelivery $occurrence_delivery = null;

	/**
	 * Maintenance-schedule hooks retained between readiness and attachment.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     MaintenanceSchedule|null
	 */
	private ?MaintenanceSchedule $maintenance_schedule = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * Builds the engine graph and publishes its supported facades.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<BackendInterface>|null $backends
	 *
	 * @param   ClockInterface|null      $clock      Clock boundary, or null for the system clock.
	 * @param   RandomizerInterface|null $randomizer Randomness boundary, or null for secure randomness.
	 * @param   LoggerInterface|null     $logger     Log boundary, or null for the engine logger.
	 * @param   \wpdb|null               $wpdb       Database boundary, or null for the WordPress global.
	 * @param   array|null               $backends   Scheduler boundaries, or null for production backends.
	 *
	 * @throws  \InvalidArgumentException When the scheduler backend list is empty or contains an invalid backend.
	 * @throws  \Throwable                When wiring fails; the in-flight guard is cleared before the failure escapes.
	 *
	 * @return  void
	 */
	#[\Override]
	public function initialize( ?ClockInterface $clock = null, ?RandomizerInterface $randomizer = null, ?LoggerInterface $logger = null, ?\wpdb $wpdb = null, ?array $backends = null ): void {
		// Late maintenance synchronization reaches cron_schedules while hooks attach; re-entrant
		// composition must retain the graph whose hook phase is still in flight.
		if ( self::$booting ) {
			return;
		}

		self::$booting = true;

		try {
			if ( null === $wpdb ) {
				global $wpdb;

				/**
				 * WordPress database connection for the current site.
				 *
				 * @var \wpdb $wpdb
				 */
			}

			$option_rows          = new OptionRows( $wpdb );
			$registry             = new JobRegistry();
			$logger             ??= new EngineLogger();
			$schedules            = new ScheduleRegistry( $option_rows, $logger );
			$clock              ??= new SystemClock();
			$randomizer         ??= new Randomizer();
			$lock_windows         = new LockWindows( $clock, $logger );
			$guard                = new OverlapGuard( $clock, $logger, $option_rows, $lock_windows );
			$overlap_identity     = new OverlapIdentity();
			$stores               = new StoreFactory( $clock, $option_rows, $logger );
			$terminal_effects     = new LifecycleEffects( $guard, $stores, $logger );
			$terminal_transitions = new RunTransitions( $guard, $stores, $clock, $lock_windows, $logger, $terminal_effects );
			$lock_repair          = new LockRepair( $option_rows, $guard, $stores, $lock_windows, $terminal_transitions );
			$backends           ??= array(
				new ActionSchedulerBackend(),
				new WPCronBackend(),
			);
			$scheduler            = new SchedulerFacade( $backends );
			$delivery_scheduler   = new DeliveryScheduler( $scheduler, $clock );
			$failure_lifecycle    = new FailureLifecycle( $delivery_scheduler, $clock, $randomizer, $logger, $terminal_transitions, $terminal_effects );
			$job_handler          = new JobKindHandler( $registry, $logger, $clock, $lock_windows, $terminal_transitions, $terminal_effects, $failure_lifecycle );
			$chunked_job_handler  = new ChunkedJobKindHandler( $registry, $delivery_scheduler, $logger, $clock, $lock_windows, $terminal_transitions, $terminal_effects, $failure_lifecycle );
			$handlers             = array(
				$job_handler->key()         => $job_handler,
				$chunked_job_handler->key() => $chunked_job_handler,
			);
			$action_deliveries    = new ActionDeliveries( $handlers, $stores, $terminal_transitions );
			$dispatcher           = new Dispatcher( $registry, $handlers, $scheduler, $delivery_scheduler, $guard, $overlap_identity, $stores, $clock, $randomizer, $logger, $terminal_transitions );
			$reconciliation       = new RunReconciliation( $guard, $stores, $clock, $logger, $lock_windows, $terminal_transitions, $terminal_effects, $handlers, $delivery_scheduler );
			$occurrence_lease     = new OccurrenceLease( $option_rows, $clock, $randomizer );
			$cleanup_intents      = new CleanupIntents( $schedules, $scheduler, $option_rows, $clock, $logger );
			$occurrence_delivery  = new OccurrenceDelivery( $schedules, $dispatcher, $occurrence_lease, $cleanup_intents, $clock, $logger );
			$maintenance_job      = new MaintenanceJob( $option_rows, $reconciliation, $guard, $cleanup_intents, $logger );
			$dispatcher->register( Identity::compose( Identity::ENGINE_SCOPE, MaintenanceJob::NAME, true ), JobDefinition::job( MaintenanceJob::NAME, $maintenance_job ) );
			$schedule_api         = new ScheduleOperations( $schedules, $scheduler, $clock, $occurrence_delivery, $logger );
			$maintenance_schedule = new MaintenanceSchedule( $schedule_api, $logger );
			$inspection           = new Inspection( $schedules, $registry, $handlers, $scheduler, $guard, $overlap_identity, $stores, $option_rows, $lock_windows, $clock );
			$engine               = new EngineFacade( $schedule_api, $dispatcher );

			$this->action_deliveries    = $action_deliveries;
			$this->occurrence_delivery  = $occurrence_delivery;
			$this->maintenance_schedule = $maintenance_schedule;

			self::$engine      = $engine;
			self::$inspection  = $inspection;
			self::$lock_repair = $lock_repair;
			self::$scheduler   = $scheduler;
			self::$registry    = $registry;
			self::$schedules   = $schedule_api;
			self::$dispatcher  = $dispatcher;
		} catch ( \Throwable $throwable ) {
			self::$booting = false;

			throw $throwable;
		}
	}

	/**
	 * Registers the engine's runtime hooks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public function register_hooks(): void {
		$scheduler            = self::$scheduler;
		$action_deliveries    = $this->action_deliveries;
		$occurrence_delivery  = $this->occurrence_delivery;
		$maintenance_schedule = $this->maintenance_schedule;
		if ( null === $scheduler || null === $action_deliveries || null === $occurrence_delivery || null === $maintenance_schedule ) {
			return;
		}

		try {
			$scheduler->register_hooks();
			$action_deliveries->register_hooks();
			$occurrence_delivery->register_hooks();
			$maintenance_schedule->register_hooks();
		} finally {
			self::$booting = false;
		}
	}

	// endregion

	// region METHODS

	/**
	 * Returns supported operations bound to one validated client scope.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scope Validated client scope.
	 *
	 * @throws  \InvalidArgumentException  When the scope violates the client-scope contract.
	 * @throws  EngineUnavailableException When the internal graph is unavailable.
	 *
	 * @return  ScopeOperations
	 */
	public static function operations( string $scope ): ScopeOperations {
		Identity::validate_scope( $scope );
		$registry   = self::$registry;
		$schedules  = self::$schedules;
		$dispatcher = self::$dispatcher;
		$inspection = self::$inspection;
		if ( null === self::$engine || null === $registry || null === $schedules || null === $dispatcher || null === $inspection ) {
			throw new EngineUnavailableException( 'The background jobs engine graph is unavailable before its plugins_loaded boot callback completes successfully; invoke engine operations from init or a later hook.' );
		}

		return new ScopeOperations( $scope, $schedules, $dispatcher, $inspection );
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
	 * Returns the initialized malformed-lock repair service, or null before component boot.
	 *
	 * @internal Explicit CLI lock repair only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  LockRepair|null
	 */
	public static function get_lock_repair(): ?LockRepair {
		return self::$lock_repair;
	}

	/**
	 * Returns the initialized scheduling facade, or null before component boot.
	 *
	 * @internal Destructive CLI schedule and reset operations only.
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

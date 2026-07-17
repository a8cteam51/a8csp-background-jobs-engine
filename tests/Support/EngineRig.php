<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\EngineFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Inspection;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Maintenance\MaintenanceSchedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Maintenance\MaintenanceTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\CleanupIntents;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\Schedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunReconciliation;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkIdentity;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\WorkRegistry;
use PHPUnit\Framework\Assert;

/**
 * Boots the production engine graph against deterministic interface fakes.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class EngineRig {
	// region FIELDS AND CONSTANTS.

	/** @var array<string, Consumer> */
	private array $consumers = array();

	/** @var non-empty-list<RecordingBackend> */
	private array $backends;

	/**
	 * Retains deterministic boundaries used by one production graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param non-empty-list<RecordingBackend> $backends
	 *
	 * @param   RecordingBackend    $backend    Primary scheduler boundary.
	 * @param   array               $backends   Every scheduler boundary.
	 * @param   FixedClock          $clock      Clock boundary.
	 * @param   HookRecorder        $hooks      Lifecycle observer.
	 * @param   RecordingLogger     $logger     Logger boundary.
	 * @param   RecordingRandomizer $randomizer Randomizer boundary.
	 * @param   WpdbLockSpy         $wpdb       Database boundary.
	 */
	private function __construct(
		private RecordingBackend $backend,
		array $backends,
		private FixedClock $clock,
		private HookRecorder $hooks,
		private RecordingLogger $logger,
		private RecordingRandomizer $randomizer,
		private WpdbLockSpy $wpdb,
	) {
		if ( array() === $backends ) {
			throw new \InvalidArgumentException( 'EngineRig requires at least one recording backend.' );
		}

		$this->backends = \array_values( $backends );
	}

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress seams before production classes are autoloaded.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public static function bootstrap(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__ ) . '/Unit/wp-options-stubs.php';
		require_once \dirname( __DIR__ ) . '/Unit/wp-hook-stubs.php';
		require_once \dirname( __DIR__ ) . '/Unit/wp-lock-stubs.php';
		require_once \dirname( __DIR__ ) . '/Unit/wp-time-constant-stubs.php';
		require_once \dirname( __DIR__ ) . '/Unit/Engine/Backends/wp-json-encode-stub.php';
		require_once \dirname( __DIR__, 2 ) . '/functions.php';
	}

	/**
	 * Builds and publishes one deterministic request-local engine graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $now           Current Unix timestamp.
	 * @param   int $backend_count Number of ready scheduler boundaries.
	 *
	 * @return  self
	 */
	public static function set_up( int $now = 1_700_000_000, int $backend_count = 1 ): self {
		self::bootstrap();
		self::reset_component();
		self::reset_wordpress_state();
		if ( 1 > $backend_count ) {
			throw new \InvalidArgumentException( 'EngineRig requires at least one recording backend.' );
		}

		$backend  = new RecordingBackend();
		$backends = array( $backend );
		for ( $index = 1; $index < $backend_count; ++$index ) {
			$backends[] = new RecordingBackend();
		}
		$clock           = new FixedClock( $now );
		$hooks           = new HookRecorder();
		$logger          = new RecordingLogger();
		$randomizer      = new RecordingRandomizer( 42 );
		$wpdb            = new WpdbLockSpy();
		$GLOBALS['wpdb'] = $wpdb;

		$rig = new self( $backend, $backends, $clock, $hooks, $logger, $randomizer, $wpdb );
		$rig->build_graph();

		return $rig;
	}

	/**
	 * Clears component publication and all rig-owned WordPress test state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function tear_down(): void {
		self::reset_component();
		self::reset_wordpress_state();
		unset( $GLOBALS['wpdb'] );
	}

	// endregion.

	// region GETTERS.

	/**
	 * Returns an owner-bound consumer through the guarded public front door.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Consumer owner.
	 *
	 * @return  Consumer
	 */
	public function consumer( string $owner ): Consumer {
		$consumer                  = \a8csp_bgte( $owner );
		$this->consumers[ $owner ] = $consumer;

		return $consumer;
	}

	/**
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function hooks(): HookRecorder {
		return $this->hooks;
	}

	/**
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function backend(): RecordingBackend {
		return $this->backend;
	}

	/**
	 * Returns every scheduler boundary in facade order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  non-empty-list<RecordingBackend>
	 */
	public function backends(): array {
		return $this->backends;
	}

	/**
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function clock(): FixedClock {
		return $this->clock;
	}

	/**
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function randomizer(): RecordingRandomizer {
		return $this->randomizer;
	}

	/**
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function logger(): RecordingLogger {
		return $this->logger;
	}

	/**
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function wpdb(): WpdbLockSpy {
		return $this->wpdb;
	}

	/**
	 * Returns the read-only inspection service published by the production graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Inspection
	 */
	public function inspection(): Inspection {
		$inspection = Component::get_inspection();
		if ( null === $inspection ) {
			throw new \LogicException( 'EngineRig inspection is unavailable before graph publication or after teardown.' );
		}

		return $inspection;
	}

	// endregion.

	// region METHODS.

	/**
	 * Delivers the next accepted backend action through its registered production hook.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function run_due(): void {
		$delivery = $this->backend->take_next_delivery();
		Assert::assertNotNull( $delivery, 'Expected the backend to retain a delivery.' );
		$timestamp = $delivery['timestamp'];
		if ( null !== $timestamp && $this->clock->timestamp < $timestamp ) {
			$this->clock->timestamp = $timestamp;
		}

		\do_action( $delivery['hook'], ...$delivery['args'] );
	}

	/**
	 * Asserts the latest completed event and retained Runs-facade pointer agree.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function assert_completed(): void {
		$args                  = $this->latest_event( 'completed' );
		[ $identity, $run_id ] = $this->identity_and_run_id( $args );
		$parts                 = WorkIdentity::parts( $identity );
		Assert::assertNotNull( $parts );
		$consumer = $this->consumers[ $parts[0] ] ?? null;
		Assert::assertInstanceOf( Consumer::class, $consumer );
		$result = $consumer->runs()->last_completed_run_id( $parts[1] );
		Assert::assertInstanceOf( Success::class, $result );
		Assert::assertSame( $run_id, $result->value );
	}

	/**
	 * Asserts the latest failed event exposes the requested consumer failure code.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ApiErrorCode|string $code Expected failure code.
	 *
	 * @return  void
	 */
	public function assert_failed( ApiErrorCode|string $code ): void {
		$args    = $this->latest_event( 'failed' );
		$failure = $args[3] ?? null;
		Assert::assertInstanceOf( RunFailure::class, $failure );
		Assert::assertSame( $code instanceof ApiErrorCode ? $code : ApiErrorCode::from( $code ), $failure->code );
	}

	/**
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function assert_retry_scheduled(): void {
		$args     = $this->latest_event( 'retry_scheduled' );
		$identity = $args[0] ?? null;
		Assert::assertIsString( $identity );
		$this->backend->assert_scheduled( $identity );
	}

	/**
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function assert_superseded(): void {
		$this->latest_event( 'superseded' );
	}

	/**
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function assert_cancelled(): void {
		$this->latest_event( 'cancelled' );
	}

	/**
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function assert_no_retry(): void {
		Assert::assertSame( array(), $this->hooks->fired( 'a8csp_background_tasks/retry_scheduled' ) );
	}

	/**
	 * Asserts the primary scheduler boundary retains no delivery for one identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete work or schedule identity.
	 *
	 * @return  void
	 */
	public function assert_no_delivery( string $identity ): void {
		$this->backend->assert_not_scheduled( $identity );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Builds the production graph with replacements only at interface boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function build_graph(): void {
		// This graph mirrors Component's two phases because the component has no injection seam; wiring changes require lockstep updates here.
		$rows                 = new OptionRows( $this->wpdb );
		$work                 = new WorkRegistry();
		$schedules            = new ScheduleRegistry( $rows );
		$guard                = new OverlapGuard( $this->clock, $this->logger, $rows );
		$stores               = new StoreFactory( $this->clock, $rows, $this->logger );
		$lock_windows         = new LockWindows( $this->clock, $this->logger );
		$terminal_effects     = new LifecycleEffects( $guard, $stores, $this->logger );
		$terminal_transitions = new RunTransitions( $guard, $stores, $this->clock, $lock_windows, $this->logger, $terminal_effects );
		$scheduler            = new SchedulerFacade( $this->backends );
		$failure_lifecycle    = new FailureLifecycle( $scheduler, $this->clock, $this->randomizer, $this->logger, $terminal_transitions );
		$action_deliveries    = new ActionDeliveries( $work, $scheduler, $stores, $this->logger, $this->clock, $lock_windows, $terminal_transitions, $terminal_effects, $failure_lifecycle );
		$dispatcher           = new Dispatcher( $work, $scheduler, $guard, $stores, $this->clock, $this->randomizer, $this->logger, $lock_windows, $terminal_transitions, $terminal_effects );
		$reconciliation       = new RunReconciliation( $guard, $stores, $this->clock, $this->logger, $lock_windows, $terminal_transitions, $terminal_effects, $work, $scheduler );
		$occurrence_lease     = new OccurrenceLease( $rows, $this->clock, $this->randomizer );
		$cleanup_intents      = new CleanupIntents( $schedules, $scheduler, $rows, $this->clock, $this->logger );
		$occurrence_delivery  = new OccurrenceDelivery( $schedules, $dispatcher, $occurrence_lease, $cleanup_intents, $this->clock, $this->logger );
		$work->register_task( WorkIdentity::compose( WorkIdentity::ENGINE_OWNER, MaintenanceTask::NAME, true ), new MaintenanceTask( $rows, $reconciliation, $guard, $cleanup_intents, $this->logger ) );
		$schedule_api         = new Schedules( $schedules, $scheduler, $this->clock, $occurrence_delivery );
		$maintenance_schedule = new MaintenanceSchedule( $schedule_api, $this->logger );
		$inspection           = new Inspection( $schedules, $work, $scheduler, $guard, $stores, $rows, $lock_windows, $this->clock );
		$engine               = new EngineFacade( $schedule_api, $dispatcher, $inspection );

		self::publish_component( $engine, $inspection, $scheduler, $work, $schedule_api, $dispatcher );
		$scheduler->register_hooks();
		$action_deliveries->register_hooks();
		$occurrence_delivery->register_hooks();
		$maintenance_schedule->register_hooks();
		$this->activate_registered_hooks();
	}

	/**
	 * Makes do_action() invoke callbacks registered by the production graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function activate_registered_hooks(): void {
		$by_hook = array();
		foreach ( self::action_registrations() as $registration ) {
			$by_hook[ $registration['hook_name'] ][] = $registration;
		}

		$callbacks = array();
		foreach ( $by_hook as $hook => $hook_registrations ) {
			\usort( $hook_registrations, static fn ( array $left, array $right ): int => $left['priority'] <=> $right['priority'] );
			$callbacks[ $hook ] = static function ( mixed ...$args ) use ( $hook_registrations ): void {
				foreach ( $hook_registrations as $registration ) {
					$registration['callback']( ...\array_slice( $args, 0, $registration['accepted_args'] ) );
				}
			};
		}

		$GLOBALS['a8csp_bgte_test_action_callbacks'] = $callbacks;
	}

	/**
	 * Returns validated production action registrations.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-return list<array{hook_name: string, callback: callable, priority: int, accepted_args: int}>
	 *
	 * @return  array
	 */
	private static function action_registrations(): array {
		$registrations = $GLOBALS['a8csp_bgte_test_action_registrations'] ?? array();
		if ( ! \is_array( $registrations ) ) {
			throw new \UnexpectedValueException( 'Initialize the action-registration test ledger as an array.' );
		}

		$validated = array();
		foreach ( $registrations as $registration ) {
			if ( ! \is_array( $registration ) ) {
				throw new \UnexpectedValueException( 'Action registrations must be arrays.' );
			}
			$hook     = $registration['hook_name'] ?? null;
			$callback = $registration['callback'] ?? null;
			$priority = $registration['priority'] ?? null;
			$accepted = $registration['accepted_args'] ?? null;
			if ( ! \is_string( $hook ) || ! \is_callable( $callback ) || ! \is_int( $priority ) || ! \is_int( $accepted ) ) {
				throw new \UnexpectedValueException( 'Action registrations must retain a hook, callback, priority, and accepted-argument count.' );
			}

			$validated[] = array(
				'hook_name'     => $hook,
				'callback'      => $callback,
				'priority'      => $priority,
				'accepted_args' => $accepted,
			);
		}

		return $validated;
	}

	/**
	 * Publishes the graph through the real component front door.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   EngineFacade    $engine     Engine facade.
	 * @param   Inspection      $inspection Inspection facade.
	 * @param   SchedulerFacade $scheduler  Scheduler facade.
	 * @param   WorkRegistry    $work       Registered task and batch instances.
	 * @param   Schedules       $schedules  Schedule engine operations.
	 * @param   Dispatcher      $dispatcher Background-work admission coordinator.
	 *
	 * @return  void
	 */
	private static function publish_component( EngineFacade $engine, Inspection $inspection, SchedulerFacade $scheduler, WorkRegistry $work, Schedules $schedules, Dispatcher $dispatcher ): void {
		self::set_component_property( 'engine', $engine );
		self::set_component_property( 'inspection', $inspection );
		self::set_component_property( 'scheduler', $scheduler );
		self::set_component_property( 'work', $work );
		self::set_component_property( 'schedules', $schedules );
		self::set_component_property( 'dispatcher', $dispatcher );
		self::set_component_property( 'booting', false );
	}

	/**
	 * Clears all request-local component state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private static function reset_component(): void {
		self::set_component_property( 'engine', null );
		self::set_component_property( 'inspection', null );
		self::set_component_property( 'scheduler', null );
		self::set_component_property( 'work', null );
		self::set_component_property( 'schedules', null );
		self::set_component_property( 'dispatcher', null );
		self::set_component_property( 'booting', false );
	}

	/**
	 * Writes one private static composition-root field.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name  Component property name.
	 * @param   mixed  $value Component property value.
	 *
	 * @return  void
	 */
	private static function set_component_property( string $name, mixed $value ): void {
		$property = new \ReflectionProperty( Component::class, $name );
		$property->setValue( null, $value );
	}

	/**
	 * Resets all globals shared by the WordPress unit seams.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private static function reset_wordpress_state(): void {
		$GLOBALS['a8csp_bgte_test_options']              = array();
		$GLOBALS['a8csp_bgte_test_option_calls']         = array();
		$GLOBALS['a8csp_bgte_test_option_autoload']      = array();
		$GLOBALS['a8csp_bgte_test_hooks']                = array();
		$GLOBALS['a8csp_bgte_test_action_registrations'] = array();
		$GLOBALS['a8csp_bgte_test_filter_registrations'] = array();
		$GLOBALS['a8csp_bgte_test_filter_values']        = array();
		$GLOBALS['a8csp_bgte_test_fired_actions']        = array();
		$GLOBALS['a8csp_bgte_test_action_callbacks']     = array();
		$GLOBALS['a8csp_bgte_test_action_observers']     = array();
		$GLOBALS['a8csp_bgte_test_action_throwables']    = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events']     = array();
		$GLOBALS['a8csp_bgte_test_did_actions']          = array(
			'plugins_loaded' => 1,
			'init'           => 1,
		);
		$GLOBALS['a8csp_bgte_test_doing_actions']        = array();
		$GLOBALS['a8csp_bgte_test_blog_id']              = 1;
		$GLOBALS['a8csp_bgte_test_is_multisite']         = false;
		$GLOBALS['a8csp_bgte_test_cache']                = array();
		$GLOBALS['a8csp_bgte_test_cache_calls']          = array();
		unset(
			$GLOBALS['a8csp_bgte_test_before_add_option'],
			$GLOBALS['a8csp_bgte_test_cron_array'],
			$GLOBALS['a8csp_bgte_test_delete_option_results'],
			$GLOBALS['a8csp_bgte_test_get_option'],
			$GLOBALS['a8csp_bgte_test_update_option_results'],
			$GLOBALS['a8csp_bgte_test_update_option_values']
		);
	}

	/**
	 * Returns the latest generic lifecycle event arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $event Generic lifecycle event suffix.
	 *
	 * @return list<mixed>
	 */
	private function latest_event( string $event ): array {
		$events = $this->hooks->fired( 'a8csp_background_tasks/' . $event );
		Assert::assertNotEmpty( $events, \sprintf( 'Expected the generic %s lifecycle hook to fire.', $event ) );

		return $events[ \count( $events ) - 1 ];
	}

	/**
	 * Returns validated identity and run-id fields from a generic lifecycle event.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   array $args Generic lifecycle arguments.
	 *
	 * @return array{string, string}
	 */
	private function identity_and_run_id( array $args ): array {
		$identity = $args[0] ?? null;
		$run_id   = $args[1] ?? null;
		Assert::assertIsString( $identity );
		Assert::assertIsString( $run_id );

		return array( $identity, $run_id );
	}

	// endregion.
}

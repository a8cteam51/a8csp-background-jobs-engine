<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\EngineFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Inspection;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockRepair;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Maintenance\MaintenanceSchedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Maintenance\MaintenanceJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\OwnerOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\CleanupIntents;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceLease;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\DeliveryScheduler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunReconciliation;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\ChunkedJobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\JobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\JobRegistry;
use PHPUnit\Framework\Assert;

/**
 * Boots the production engine graph against deterministic interface fakes.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class EngineRig {
	// region FIELDS AND CONSTANTS.

	/** @var array<string, OwnerOperations> */
	private array $operations = array();

	/** @var non-empty-list<RecordingBackend> */
	private array $backends;

	private MaintenanceJob $maintenance_job;

	// endregion.

	// region MAGIC METHODS.

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
		require_once \dirname( __DIR__ ) . '/Unit/Runtime/Backends/wp-json-encode-stub.php';
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
	 * Returns owner-bound operations from the published component graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Owner identifier.
	 *
	 * @return  OwnerOperations
	 */
	public function operations( string $owner ): OwnerOperations {
		$operations                 = Component::operations( $owner );
		$this->operations[ $owner ] = $operations;

		return $operations;
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
	 * Asserts the latest completed event and retained completion pointer agree.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function assert_completed(): void {
		$args                  = $this->latest_event( 'completed' );
		[ $identity, $run_id ] = $this->identity_and_run_id( $args );

		$work_identity = Identity::tryFrom( $identity );
		Assert::assertNotNull( $work_identity );
		$operations = $this->operations[ $work_identity->owner() ] ?? null;
		Assert::assertInstanceOf( OwnerOperations::class, $operations );
		$result = $operations->last_completed_run( $work_identity->name() );
		Assert::assertInstanceOf( Success::class, $result );
		Assert::assertInstanceOf( Run::class, $result->value );
		Assert::assertSame( (string) $run_id, (string) $result->value->id );
	}

	/**
	 * Asserts the latest failed event exposes the requested boundary failure code.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ErrorCode|string $code Expected failure code.
	 *
	 * @return  void
	 */
	public function assert_failed( ErrorCode|string $code ): void {
		$args = $this->latest_event( 'failed' );
		Assert::assertCount( 1, $args );
		$failure = $args[0] ?? null;
		Assert::assertInstanceOf( RunFailure::class, $failure );
		Assert::assertSame( $code instanceof ErrorCode ? $code : ErrorCode::from( $code ), $failure->code );
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
		Assert::assertSame( array(), $this->hooks->fired( 'a8csp_bgje/retry_scheduled' ) );
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

	/**
	 * Runs the production maintenance contract against the active graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function run_maintenance(): void {
		$this->maintenance_job->handle( array(), new RunContext( RunId::from( '00000000000000000000-0000000000000000002' ), array() ) );
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
		$registry             = new JobRegistry();
		$schedules            = new ScheduleRegistry( $rows, $this->logger );
		$guard                = new OverlapGuard( $this->clock, $this->logger, $rows );
		$overlap_identity     = new OverlapIdentity();
		$stores               = new StoreFactory( $this->clock, $rows, $this->logger );
		$lock_windows         = new LockWindows( $this->clock, $this->logger );
		$terminal_effects     = new LifecycleEffects( $guard, $stores, $this->logger );
		$terminal_transitions = new RunTransitions( $guard, $stores, $this->clock, $lock_windows, $this->logger, $terminal_effects );
		$lock_repair          = new LockRepair( $rows, $guard, $stores, $lock_windows, $terminal_transitions );
		$scheduler            = new SchedulerFacade( $this->backends );
		$delivery_scheduler   = new DeliveryScheduler( $scheduler, $this->clock );
		$failure_lifecycle    = new FailureLifecycle( $delivery_scheduler, $this->clock, $this->randomizer, $this->logger, $terminal_transitions, $terminal_effects );
		$job_handler          = new JobKindHandler( $registry, $this->logger, $this->clock, $lock_windows, $terminal_transitions, $terminal_effects, $failure_lifecycle );
		$chunked_job_handler  = new ChunkedJobKindHandler( $registry, $delivery_scheduler, $this->logger, $this->clock, $lock_windows, $terminal_transitions, $terminal_effects, $failure_lifecycle );
		$handlers             = array(
			$job_handler->key()         => $job_handler,
			$chunked_job_handler->key() => $chunked_job_handler,
		);
		$action_deliveries    = new ActionDeliveries( $handlers, $stores, $terminal_transitions );
		$dispatcher           = new Dispatcher( $registry, $handlers, $scheduler, $delivery_scheduler, $guard, $overlap_identity, $stores, $this->clock, $this->randomizer, $this->logger, $lock_windows, $terminal_transitions );
		$reconciliation       = new RunReconciliation( $guard, $stores, $this->clock, $this->logger, $lock_windows, $terminal_transitions, $terminal_effects, $handlers, $delivery_scheduler );
		$occurrence_lease     = new OccurrenceLease( $rows, $this->clock, $this->randomizer );
		$cleanup_intents      = new CleanupIntents( $schedules, $scheduler, $rows, $this->clock, $this->logger );
		$occurrence_delivery  = new OccurrenceDelivery( $schedules, $dispatcher, $occurrence_lease, $cleanup_intents, $this->clock, $this->logger );

		$this->maintenance_job = new MaintenanceJob( $rows, $reconciliation, $guard, $cleanup_intents, $this->logger );
		$dispatcher->register( Identity::compose( Identity::ENGINE_OWNER, MaintenanceJob::NAME, true ), JobDefinition::job( MaintenanceJob::NAME, $this->maintenance_job ) );
		$schedule_api         = new ScheduleOperations( $schedules, $scheduler, $this->clock, $occurrence_delivery );
		$maintenance_schedule = new MaintenanceSchedule( $schedule_api, $this->logger );
		$inspection           = new Inspection( $schedules, $registry, $handlers, $scheduler, $guard, $overlap_identity, $stores, $rows, $lock_windows, $this->clock );
		$engine               = new EngineFacade( $schedule_api, $dispatcher );

		self::publish_component( $engine, $inspection, $lock_repair, $scheduler, $registry, $schedule_api, $dispatcher );
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

		$GLOBALS['a8csp_bgje_test_action_callbacks'] = $callbacks;
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
		$registrations = $GLOBALS['a8csp_bgje_test_action_registrations'] ?? array();
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
	 * @param   EngineFacade       $engine      Engine facade.
	 * @param   Inspection         $inspection  Inspection facade.
	 * @param   LockRepair         $lock_repair Explicit malformed-lock repair.
	 * @param   SchedulerFacade    $scheduler   Scheduler facade.
	 * @param   JobRegistry        $registry    Registered job and chunked job instances.
	 * @param   ScheduleOperations $schedules   Schedule engine operations.
	 * @param   Dispatcher         $dispatcher  Background-work admission coordinator.
	 *
	 * @return  void
	 */
	private static function publish_component( EngineFacade $engine, Inspection $inspection, LockRepair $lock_repair, SchedulerFacade $scheduler, JobRegistry $registry, ScheduleOperations $schedules, Dispatcher $dispatcher ): void {
		self::set_component_property( 'engine', $engine );
		self::set_component_property( 'inspection', $inspection );
		self::set_component_property( 'lock_repair', $lock_repair );
		self::set_component_property( 'scheduler', $scheduler );
		self::set_component_property( 'registry', $registry );
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
		self::set_component_property( 'lock_repair', null );
		self::set_component_property( 'scheduler', null );
		self::set_component_property( 'registry', null );
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
		$GLOBALS['a8csp_bgje_test_options']              = array();
		$GLOBALS['a8csp_bgje_test_option_calls']         = array();
		$GLOBALS['a8csp_bgje_test_option_autoload']      = array();
		$GLOBALS['a8csp_bgje_test_hooks']                = array();
		$GLOBALS['a8csp_bgje_test_action_registrations'] = array();
		$GLOBALS['a8csp_bgje_test_filter_registrations'] = array();
		$GLOBALS['a8csp_bgje_test_filter_values']        = array();
		$GLOBALS['a8csp_bgje_test_fired_actions']        = array();
		$GLOBALS['a8csp_bgje_test_action_callbacks']     = array();
		$GLOBALS['a8csp_bgje_test_action_observers']     = array();
		$GLOBALS['a8csp_bgje_test_action_throwables']    = array();
		$GLOBALS['a8csp_bgje_test_lifecycle_events']     = array();
		$GLOBALS['a8csp_bgje_test_did_actions']          = array(
			'plugins_loaded' => 1,
			'init'           => 1,
		);
		$GLOBALS['a8csp_bgje_test_doing_actions']        = array();
		$GLOBALS['a8csp_bgje_test_blog_id']              = 1;
		$GLOBALS['a8csp_bgje_test_is_multisite']         = false;
		$GLOBALS['a8csp_bgje_test_cache']                = array();
		$GLOBALS['a8csp_bgje_test_cache_calls']          = array();
		unset(
			$GLOBALS['a8csp_bgje_test_before_add_option'],
			$GLOBALS['a8csp_bgje_test_cron_array'],
			$GLOBALS['a8csp_bgje_test_delete_option_results'],
			$GLOBALS['a8csp_bgje_test_get_option'],
			$GLOBALS['a8csp_bgje_test_update_option_results'],
			$GLOBALS['a8csp_bgje_test_update_option_values']
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
		$latest = \array_last( $this->hooks->fired( 'a8csp_bgje/' . $event ) );
		Assert::assertNotNull( $latest, \sprintf( 'Expected the generic %s lifecycle hook to fire.', $event ) );

		return $latest;
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
	 * @return array{string, RunId}
	 */
	private function identity_and_run_id( array $args ): array {
		$identity = $args[0] ?? null;
		$run_id   = $args[1] ?? null;
		Assert::assertIsString( $identity );
		Assert::assertInstanceOf( RunId::class, $run_id );

		return array( $identity, $run_id );
	}

	// endregion.
}

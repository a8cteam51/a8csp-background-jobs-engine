<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Inspection;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Maintenance\MaintenanceJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\ScopeOperations;
use PHPUnit\Framework\Assert;

/**
 * Boots the production engine graph against deterministic interface fakes.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class EngineRig {
	// region FIELDS AND CONSTANTS.

	/** @var array<string, ScopeOperations> */
	private array $operations = array();

	/** @var non-empty-list<RecordingBackend> */
	private array $backends;

	/** Published read-only inspection service. */
	private Inspection $inspection;

	/** Published schedule operations. */
	private ScheduleOperations $schedules;

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
	 * @param   Inspection          $inspection Published inspection service.
	 * @param   RecordingLogger     $logger     Logger boundary.
	 * @param   RecordingRandomizer $randomizer Randomizer boundary.
	 * @param   ScheduleOperations  $schedules  Published schedule operations.
	 * @param   WpdbLockSpy         $wpdb       Database boundary.
	 */
	private function __construct(
		private RecordingBackend $backend,
		array $backends,
		private FixedClock $clock,
		private HookRecorder $hooks,
		Inspection $inspection,
		private RecordingLogger $logger,
		private RecordingRandomizer $randomizer,
		ScheduleOperations $schedules,
		private WpdbLockSpy $wpdb,
	) {
		if ( array() === $backends ) {
			throw new \InvalidArgumentException( 'EngineRig requires at least one recording backend.' );
		}

		$this->backends   = \array_values( $backends );
		$this->inspection = $inspection;
		$this->schedules  = $schedules;
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

		$component = new Component();
		$component->initialize( clock: $clock, randomizer: $randomizer, logger: $logger, wpdb: $wpdb, backends: $backends );
		$component->register_hooks();
		$inspection = Component::get_inspection();
		$schedules  = Component::get_schedules();
		if ( null === $inspection || null === $schedules ) {
			throw new \LogicException( 'EngineRig requires the Component graph it boots to publish its retained services.' );
		}

		$rig = new self( $backend, $backends, $clock, $hooks, $inspection, $logger, $randomizer, $schedules, $wpdb );
		$rig->activate_registered_hooks();
		return $rig;
	}

	/**
	 * Clears all rig-owned WordPress test state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function tear_down(): void {
		self::reset_wordpress_state();
		unset( $GLOBALS['wpdb'] );
	}

	// endregion.

	// region GETTERS.

	/**
	 * Returns scope-bound operations from the published component graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scope Scope identifier.
	 *
	 * @return  ScopeOperations
	 */
	public function operations( string $scope ): ScopeOperations {
		$operations                 = Component::operations( $scope );
		$this->operations[ $scope ] = $operations;

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
		return $this->inspection;
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
		$operations = $this->operations[ $work_identity->scope() ] ?? null;
		Assert::assertInstanceOf( ScopeOperations::class, $operations );
		$result = $operations->last_completed_run( $work_identity->name() );
		Assert::assertInstanceOf( Run::class, $result );
		Assert::assertSame( (string) $run_id, (string) $result->id );
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
		$result = $this->schedules->dispatch_now( Identity::compose( Identity::ENGINE_SCOPE, MaintenanceJob::NAME, true ) );
		Assert::assertInstanceOf( Success::class, $result, 'Expected the maintenance schedule to accept a manual dispatch.' );
		$this->run_due();
	}

	// endregion.

	// region HELPERS.

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

		// Rig-driven tests assert on captured output, so engine events keep the default error-log sink off.
		$GLOBALS['a8csp_bgje_test_filter_values']['a8csp_bgje/log_to_error_log'] = false;
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

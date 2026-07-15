<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\EngineFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Container;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Inspection;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\MaintenanceTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the retained engine composition root through the owner-bound public front door.
 *
 */
#[CoversClass( Component::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class EngineComponentTest extends TestCase {
	private const OWNER = 'consumer-plugin';

	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress functions and the owner-bound API in each isolated process.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__ ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-hook-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-lock-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-time-constant-stubs.php';
		require_once __DIR__ . '/Backends/wp-json-encode-stub.php';
		require_once \dirname( __DIR__ ) . '/wp-cron-stubs.php';
		require_once \dirname( __DIR__, 3 ) . '/functions.php';
	}

	/**
	 * Resets hook ledgers and supplies the site-bound database seam.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_options']              = array();
		$GLOBALS['a8csp_bgte_test_option_calls']         = array();
		$GLOBALS['a8csp_bgte_test_option_autoload']      = array();
		$GLOBALS['a8csp_bgte_test_hooks']                = array();
		$GLOBALS['a8csp_bgte_test_action_registrations'] = array();
		$GLOBALS['a8csp_bgte_test_filter_registrations'] = array();
		$GLOBALS['a8csp_bgte_test_filter_values']        = array();
		$GLOBALS['a8csp_bgte_test_fired_actions']        = array();
		$GLOBALS['a8csp_bgte_test_action_throwables']    = array();
		$GLOBALS['a8csp_bgte_test_blog_id']              = 1;
		$GLOBALS['a8csp_bgte_test_is_multisite']         = false;
		$GLOBALS['a8csp_bgte_test_cache']                = array();
		$GLOBALS['a8csp_bgte_test_cache_calls']          = array();
		$GLOBALS['a8csp_bgte_test_cron_array']           = array();
		$GLOBALS['a8csp_bgte_test_cron_calls']           = array();
		$GLOBALS['a8csp_bgte_test_cron_results']         = array();
		$GLOBALS['a8csp_bgte_test_cron_event_sequence']  = 0;
		$GLOBALS['a8csp_bgte_test_as_calls']             = array();
		$GLOBALS['a8csp_bgte_test_as_results']           = array();
		$GLOBALS['a8csp_bgte_test_did_actions']          = array( 'plugins_loaded' => 1 );
		$GLOBALS['wpdb']                                 = new WpdbLockSpy();

		$GLOBALS['a8csp_bgte_test_cron_preserve_on_unschedule'] = false;
		unset(
			$GLOBALS['a8csp_bgte_test_before_add_option'],
			$GLOBALS['a8csp_bgte_test_cron_before_unschedule']
		);
	}

	// endregion.

	// region TESTS.

	/**
	 * The composition root is enabled on every supported site.
	 *
	 * @return  void
	 */
	public function test_component_is_always_needed(): void {
		self::assertTrue( ( new Component() )->is_needed() );
	}

	/**
	 * The public front door publishes one engine through owner-bound consumers and registers runtime hooks.
	 *
	 * @return  void
	 */
	public function test_front_door_publishes_one_engine_and_registers_runtime_hooks(): void {
		$first      = \a8csp_bgte( self::OWNER );
		$engine     = Container::get_engine();
		$second     = \a8csp_bgte( self::OWNER );
		$inspection = Component::get_inspection();
		$actions    = $this->registrations( 'a8csp_bgte_test_action_registrations' );
		$filters    = $this->registrations( 'a8csp_bgte_test_filter_registrations' );

		self::assertInstanceOf( Consumer::class, $first );
		self::assertInstanceOf( Consumer::class, $second );
		self::assertInstanceOf( EngineFacade::class, $engine );
		self::assertSame( $engine, Container::get_engine() );
		self::assertInstanceOf( Inspection::class, $inspection );
		self::assertSame(
			array(
				'a8csp_background_tasks/start',
				'a8csp_background_tasks/continue',
				'a8csp_background_tasks/run',
				'a8csp_background_tasks/cleanup',
				'a8csp_background_tasks/schedule_due',
				'init',
			),
			\array_column( $actions, 'hook_name' )
		);
		self::assertSame(
			array( 'cron_schedules' ),
			\array_column( $filters, 'hook_name' )
		);
		self::assertSame(
			array( 3, 3, 4, 3, 1, 1 ),
			\array_column( $actions, 'accepted_args' )
		);
	}

	/**
	 * Container reinitialization retains the engine without duplicating runtime hooks.
	 *
	 * @return  void
	 */
	public function test_container_initialization_is_idempotent(): void {
		Container::boot();

		$engine     = Container::get_engine();
		$inspection = Container::get_inspection();
		Container::boot();

		self::assertInstanceOf( EngineFacade::class, $engine );
		self::assertInstanceOf( Inspection::class, $inspection );
		self::assertSame( $engine, Container::get_engine() );
		self::assertSame( $inspection, Container::get_inspection() );
		self::assertCount( 6, $this->registrations( 'a8csp_bgte_test_action_registrations' ) );
		self::assertCount( 1, $this->registrations( 'a8csp_bgte_test_filter_registrations' ) );
	}

	/**
	 * A boot after init completes syncs the maintenance registration inline, without deferral.
	 *
	 * @return  void
	 */
	public function test_boot_after_init_syncs_maintenance_inline(): void {
		$GLOBALS['a8csp_bgte_test_did_actions'] = array(
			'plugins_loaded' => 1,
			'init'           => 1,
		);

		\a8csp_bgte( self::OWNER );

		$hook_names = \array_column( $this->registrations( 'a8csp_bgte_test_action_registrations' ), 'hook_name' );
		self::assertNotContains( 'init', $hook_names, 'A late boot must not leave a deferred sync behind' );
		$registry = $this->schedule_registry();
		self::assertIsArray( $registry, 'A late boot must synchronize the maintenance registration inline' );
		self::assertArrayHasKey( 'a8csp-bgte', $registry );
	}

	/**
	 * A consumer resolving through a late maintenance scheduler filter shares the published graph.
	 *
	 * @return  void
	 */
	public function test_late_boot_scheduler_filter_reentry_uses_the_initializing_graph(): void {
		$GLOBALS['a8csp_bgte_test_did_actions'] = array(
			'plugins_loaded' => 1,
			'init'           => 1,
		);

		$reentered = false;
		$consumer  = null;
		\add_filter(
			'cron_schedules',
			static function ( array $schedules ) use ( &$reentered, &$consumer ): array {
				if ( ! $reentered ) {
					$reentered = true;
					$consumer  = \a8csp_bgte( self::OWNER );
					$consumer->tasks()->register( new RecordingTask( 'filter-task' ) );
				}

				return $schedules;
			}
		);

		$resolved = \a8csp_bgte( self::OWNER );

		self::assertTrue( $reentered );
		self::assertInstanceOf( Consumer::class, $consumer );
		self::assertInstanceOf( Consumer::class, $resolved );
		self::assertTrue( $resolved->tasks()->enqueue( 'filter-task' )->is_success() );
		self::assertCount( 5, $this->registrations( 'a8csp_bgte_test_action_registrations' ) );
		self::assertCount( 2, $this->registrations( 'a8csp_bgte_test_filter_registrations' ) );
	}

	/**
	 * A boot while init is still executing defers the sync instead of syncing before Action Scheduler.
	 *
	 * @return  void
	 */
	public function test_mid_init_boot_defers_the_maintenance_sync(): void {
		$GLOBALS['a8csp_bgte_test_did_actions']   = array(
			'plugins_loaded' => 1,
			'init'           => 1,
		);
		$GLOBALS['a8csp_bgte_test_doing_actions'] = array( 'init' );

		\a8csp_bgte( self::OWNER );

		$hook_names = \array_column( $this->registrations( 'a8csp_bgte_test_action_registrations' ), 'hook_name' );
		self::assertContains( 'wp_loaded', $hook_names, 'A mid-init boot must defer the sync until init completes' );
		self::assertNotContains( 'init', $hook_names, 'A mid-init boot must not append to the active init bucket' );
		self::assertNull(
			$this->schedule_registry(),
			'A mid-init boot must not synchronize before Action Scheduler initializes'
		);
	}

	/**
	 * The deferred sync runs against the boot-time site when init fires on another site.
	 *
	 * @return  void
	 */
	public function test_deferred_maintenance_sync_returns_to_the_boot_site(): void {
		$GLOBALS['a8csp_bgte_test_is_multisite']       = true;
		$GLOBALS['a8csp_bgte_test_blog_id']            = 1;
		$GLOBALS['a8csp_bgte_test_blog_stack']         = array();
		$GLOBALS['a8csp_bgte_test_blog_switch_calls']  = array();
		$GLOBALS['a8csp_bgte_test_blog_restore_calls'] = array();

		\a8csp_bgte( self::OWNER );

		$init_registrations = \array_values(
			\array_filter(
				$this->registrations( 'a8csp_bgte_test_action_registrations' ),
				static fn ( array $registration ): bool => 'init' === $registration['hook_name']
			)
		);
		self::assertCount( 1, $init_registrations );
		$init_callback = $init_registrations[0]['callback'] ?? null;
		self::assertIsCallable( $init_callback );

		$GLOBALS['a8csp_bgte_test_blog_id'] = 2;
		$init_callback();

		self::assertSame(
			array( 1 ),
			$GLOBALS['a8csp_bgte_test_blog_switch_calls'],
			'The deferred sync must switch back to the boot-time site before writing'
		);
		self::assertCount(
			1,
			$GLOBALS['a8csp_bgte_test_blog_restore_calls'],
			'The deferred sync must restore the interrupted site afterwards'
		);
	}

	/**
	 * Public task, schedule, batch, and retry calls traverse the composed WP-Cron graph unchanged.
	 *
	 * @return  void
	 */
	public function test_live_wp_cron_graph_round_trips_public_task_schedule_batch_and_retry_apis(): void {
		$consumer = \a8csp_bgte( self::OWNER );
		self::assertInstanceOf( Consumer::class, $consumer );

		// Boot defers the engine's own maintenance sync to init; fire it the way WordPress would.
		$init_registrations = \array_values(
			\array_filter(
				$this->registrations( 'a8csp_bgte_test_action_registrations' ),
				static fn ( array $registration ): bool => 'init' === $registration['hook_name']
			)
		);
		self::assertCount( 1, $init_registrations, 'Boot must defer exactly one maintenance sync to init' );
		self::assertSame(
			10,
			$init_registrations[0]['priority'] ?? null,
			'The normal deferred sync must use the default init priority after Action Scheduler initializes at init:1'
		);
		self::assertNull(
			$this->schedule_registry(),
			'Boot must not write the schedule registry before init fires'
		);
		$init_callback = $init_registrations[0]['callback'] ?? null;
		self::assertIsCallable( $init_callback );
		$init_callback();

		$task_identity    = self::OWNER . ':email-digest';
		$batch_identity   = self::OWNER . ':catalog-sync';
		$task_args        = array( 'site_id' => 7 );
		$batch_start_args = array( 'site_id' => 8 );
		$consumer->tasks()->register( new RecordingTask( 'email-digest' ) );
		$consumer->batches()->register( new RecordingBatch( 'catalog-sync' ) );

		$task_result = $consumer->tasks()->enqueue( 'email-digest', $task_args );

		self::assertInstanceOf( Success::class, $task_result );
		self::assertIsString( $task_result->value );
		$task_run = \get_option( 'a8csp_bgte_run_' . $task_identity . '_' . $task_result->value, null );
		self::assertIsArray( $task_run );
		self::assertSame( $task_args, $task_run['start_args'] ?? null );
		self::assertSame(
			array(
				array(
					'schedule' => false,
					'args'     => array( $task_identity, $task_result->value, 1 ),
				),
			),
			$this->cron_events_for_hook( 'a8csp_background_tasks/run' )
		);

		$batch_result = $consumer->batches()->start(
			name: 'catalog-sync',
			start_args: $batch_start_args
		);

		self::assertInstanceOf( Success::class, $batch_result );
		self::assertIsString( $batch_result->value );
		$batch_run = \get_option( 'a8csp_bgte_run_' . $batch_identity . '_' . $batch_result->value, null );
		self::assertIsArray( $batch_run );
		self::assertSame( $batch_start_args, $batch_run['start_args'] ?? null );
		self::assertSame(
			array(
				array(
					'schedule' => false,
					'args'     => array( $batch_identity, $batch_result->value, 1 ),
				),
			),
			$this->cron_events_for_hook( 'a8csp_background_tasks/start' )
		);
		self::assertSame( 3, $this->cron_event_count() );

		$schedule        = new Schedule( 'connection-monitor', Recurrence::every( 300 ), 'email-digest' );
		$schedule_result = $consumer->schedules()->sync( array( $schedule ) );

		self::assertInstanceOf( Success::class, $schedule_result );
		self::assertTrue( $schedule_result->value );
		self::assertSame(
			array(
				array(
					'schedule' => 'a8csp_bgte_every_3600s',
					'args'     => array( 'a8csp-bgte:maintenance' ),
				),
				array(
					'schedule' => 'a8csp_bgte_every_300s',
					'args'     => array( 'consumer-plugin:connection-monitor' ),
				),
			),
			$this->cron_events_for_hook( 'a8csp_background_tasks/schedule_due' )
		);
		$registrations = $this->schedule_registry();
		self::assertIsArray( $registrations );
		$maintenance_registrations = $registrations['a8csp-bgte'] ?? null;
		self::assertIsArray( $maintenance_registrations );
		$maintenance = $maintenance_registrations['a8csp-bgte:maintenance'] ?? null;
		self::assertIsArray( $maintenance );
		$expected_maintenance = new Schedule(
			'maintenance',
			Recurrence::every( \HOUR_IN_SECONDS ),
			MaintenanceTask::NAME,
			array(),
			OverlapPolicy::Skip,
			CatchUpPolicy::RunOnce
		);
		self::assertSame( $expected_maintenance->fingerprint(), $maintenance['fingerprint'] ?? null );
		self::assertSame( 0, $maintenance['misfires'] ?? null );
		self::assertSame( 0, $maintenance['skips'] ?? null );
		$owner_registrations = $registrations['consumer-plugin'] ?? null;
		self::assertIsArray( $owner_registrations );
		$registration = $owner_registrations['consumer-plugin:connection-monitor'] ?? null;
		self::assertIsArray( $registration );
		self::assertSame(
			$schedule->fingerprint(),
			$registration['fingerprint'] ?? null
		);
		self::assertIsInt( $registration['next_due'] ?? null );
		self::assertNull( $registration['last_fired'] ?? null );
		self::assertSame( 4, $this->cron_event_count() );
		$next_due = $registration['next_due'] ?? null;

		$run_now_result = $consumer->schedules()->run_now( 'connection-monitor' );

		self::assertInstanceOf( Success::class, $run_now_result );
		self::assertIsString( $run_now_result->value );
		$run_now_state = \get_option( 'a8csp_bgte_run_' . $task_identity . '_' . $run_now_result->value, null );
		self::assertIsArray( $run_now_state );
		$registrations = $this->schedule_registry();
		self::assertIsArray( $registrations );
		$owner_registrations = $registrations['consumer-plugin'] ?? null;
		self::assertIsArray( $owner_registrations );
		$registration = $owner_registrations['consumer-plugin:connection-monitor'] ?? null;
		self::assertIsArray( $registration );
		self::assertSame( $next_due, $registration['next_due'] ?? null );
		self::assertIsInt( $registration['last_fired'] ?? null );
		self::assertSame( 5, $this->cron_event_count() );

		$options_before_retry    = $GLOBALS['a8csp_bgte_test_options'];
		$registry_before_retry   = $this->schedule_registry();
		$cron_before_retry       = \get_option( 'cron', array() );
		$cron_calls_before_retry = $GLOBALS['a8csp_bgte_test_cron_calls'];

		$retry_result = $consumer->runs()->retry_failed( 'unknown', 'missing-run' );

		self::assertInstanceOf( Failure::class, $retry_result );
		self::assertInstanceOf( ApiError::class, $retry_result->error );
		self::assertSame( ApiErrorCode::UnknownWork, $retry_result->error->code );
		self::assertSame( array( 'name' => 'consumer-plugin:unknown' ), $retry_result->error->context );
		self::assertSame(
			'Background-work "consumer-plugin:unknown" is not registered; register the matching task or batch before retrying its failed run.',
			$retry_result->error->message
		);
		self::assertSame( $options_before_retry, $GLOBALS['a8csp_bgte_test_options'] );
		self::assertSame( $registry_before_retry, $this->schedule_registry() );
		self::assertSame( $cron_before_retry, \get_option( 'cron', array() ) );
		self::assertSame( $cron_calls_before_retry, $GLOBALS['a8csp_bgte_test_cron_calls'] );
	}

	/**
	 * The composed scheduler writes to Action Scheduler before the always-ready WP-Cron fallback.
	 *
	 * @return  void
	 */
	public function test_live_graph_prefers_action_scheduler_before_wp_cron(): void {
		require_once \dirname( __DIR__ ) . '/as-function-stubs.php';

		$GLOBALS['a8csp_bgte_test_did_actions'] = array(
			'plugins_loaded'        => 1,
			'init'                  => 1,
			'action_scheduler_init' => 1,
		);

		$consumer = \a8csp_bgte( self::OWNER );
		self::assertInstanceOf( Consumer::class, $consumer );
		$as_calls = $GLOBALS['a8csp_bgte_test_as_calls'] ?? null;
		self::assertIsArray( $as_calls );
		self::assertContains(
			'as_schedule_recurring_action',
			\array_column( $as_calls, 'function' )
		);
		$GLOBALS['a8csp_bgte_test_as_calls']   = array();
		$GLOBALS['a8csp_bgte_test_cron_calls'] = array();
		$consumer->tasks()->register( new RecordingTask( 'preferred-backend' ) );

		$result = $consumer->tasks()->enqueue( 'preferred-backend', array( 'source' => 'test' ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertIsString( $result->value );
		self::assertSame(
			array(
				array(
					'function' => 'as_enqueue_async_action',
					'args'     => array(
						'a8csp_background_tasks/run',
						array( 'consumer-plugin:preferred-backend', $result->value, 1 ),
						'consumer-plugin:preferred-backend|' . $result->value,
						false,
						10,
					),
				),
			),
			$GLOBALS['a8csp_bgte_test_as_calls']
		);
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_cron_calls'] );
		self::assertSame( array(), \get_option( 'cron', array() ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns the schedule registry from the authoritative raw row when present.
	 *
	 * @return  array<array-key, mixed>|null
	 */
	private function schedule_registry(): ?array {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		self::assertInstanceOf( WpdbLockSpy::class, $wpdb );
		$raw = $wpdb->rows['a8csp_bgte_schedules'] ?? null;
		if ( \is_string( $raw ) ) {
			$registry = \maybe_unserialize( $raw );
			self::assertIsArray( $registry );

			return $registry;
		}

		$registry = \get_option( 'a8csp_bgte_schedules', null );
		self::assertTrue( null === $registry || \is_array( $registry ) );

		return $registry;
	}

	/**
	 * Returns typed hook registrations from one test ledger.
	 *
	 * @param   string $global_name Global ledger name.
	 *
	 * @return  list<array{hook_name: string, callback: mixed, priority: int, accepted_args: int}>
	 */
	private function registrations( string $global_name ): array {
		$registrations = $GLOBALS[ $global_name ] ?? null;
		self::assertIsArray( $registrations );
		$typed_registrations = array();
		foreach ( $registrations as $registration ) {
			self::assertIsArray( $registration );
			$hook_name     = $registration['hook_name'] ?? null;
			$priority      = $registration['priority'] ?? null;
			$accepted_args = $registration['accepted_args'] ?? null;
			self::assertIsString( $hook_name );
			self::assertIsInt( $priority );
			self::assertIsInt( $accepted_args );
			$typed_registrations[] = array(
				'hook_name'     => $hook_name,
				'callback'      => $registration['callback'] ?? null,
				'priority'      => $priority,
				'accepted_args' => $accepted_args,
			);
		}

		return $typed_registrations;
	}

	/**
	 * Returns stored WP-Cron events for one hook.
	 *
	 * @param   string $hook Hook name.
	 *
	 * @return  list<array{schedule: string|false, args: list<mixed>}>
	 */
	private function cron_events_for_hook( string $hook ): array {
		$cron = \get_option( 'cron', array() );
		self::assertIsArray( $cron );

		$matching_events = array();
		foreach ( $cron as $hooks ) {
			self::assertIsArray( $hooks );
			$events = $hooks[ $hook ] ?? array();
			self::assertIsArray( $events );
			foreach ( $events as $event ) {
				self::assertIsArray( $event );
				$schedule = $event['schedule'] ?? null;
				if ( false !== $schedule && ! \is_string( $schedule ) ) {
					self::fail( 'A stored cron event schedule must be a recurrence name or false.' );
				}

				$args = $event['args'] ?? null;
				self::assertIsArray( $args );
				self::assertIsList( $args );
				$matching_events[] = array(
					'schedule' => $schedule,
					'args'     => $args,
				);
			}
		}

		return $matching_events;
	}

	/**
	 * Returns the number of events stored across the WP-Cron option.
	 *
	 * @return  int
	 */
	private function cron_event_count(): int {
		$cron = \get_option( 'cron', array() );
		self::assertIsArray( $cron );

		$count = 0;
		foreach ( $cron as $hooks ) {
			self::assertIsArray( $hooks );
			foreach ( $hooks as $events ) {
				self::assertIsArray( $events );
				$count += \count( $events );
			}
		}

		return $count;
	}

	// endregion.
}

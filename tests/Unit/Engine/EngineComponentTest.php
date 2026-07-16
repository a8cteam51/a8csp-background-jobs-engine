<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\EngineFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Inspection;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real composition root and its boot-order invariants.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Component::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class EngineComponentTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress seams before the composition root is autoloaded.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * Resets the WordPress ledgers and supplies the site-bound database seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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

		$GLOBALS['a8csp_bgte_test_filter_registration_callbacks'] = array();

		$GLOBALS['a8csp_bgte_test_filter_values']     = array();
		$GLOBALS['a8csp_bgte_test_fired_actions']     = array();
		$GLOBALS['a8csp_bgte_test_action_throwables'] = array();
		$GLOBALS['a8csp_bgte_test_blog_id']           = 1;

		$GLOBALS['a8csp_bgte_test_blog_stack']         = array();
		$GLOBALS['a8csp_bgte_test_blog_switch_calls']  = array();
		$GLOBALS['a8csp_bgte_test_blog_restore_calls'] = array();

		$GLOBALS['a8csp_bgte_test_is_multisite']        = false;
		$GLOBALS['a8csp_bgte_test_cache']               = array();
		$GLOBALS['a8csp_bgte_test_cache_calls']         = array();
		$GLOBALS['a8csp_bgte_test_cron_array']          = array();
		$GLOBALS['a8csp_bgte_test_cron_calls']          = array();
		$GLOBALS['a8csp_bgte_test_cron_results']        = array();
		$GLOBALS['a8csp_bgte_test_cron_event_sequence'] = 0;
		$GLOBALS['a8csp_bgte_test_as_calls']            = array();
		$GLOBALS['a8csp_bgte_test_as_results']          = array();
		$GLOBALS['a8csp_bgte_test_did_actions']         = array( 'plugins_loaded' => 1 );
		$GLOBALS['a8csp_bgte_test_doing_actions']       = array();
		$GLOBALS['wpdb']                                = new WpdbLockSpy();
	}

	// endregion.

	// region TESTS.

	/**
	 * The root is always needed and publishes all three retained facades.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_initialize_publishes_the_complete_composition_root(): void {
		$component = new Component();

		self::assertTrue( $component->is_needed() );
		$component->initialize();

		self::assertInstanceOf( EngineFacade::class, Component::get_engine() );
		self::assertInstanceOf( Inspection::class, Component::get_inspection() );
		self::assertNotNull( Component::get_scheduler() );
	}

	/**
	 * The diagnostic sink is registered before any component can emit an engine event.
	 *
	 * @load-bearing structural
	 * @pin-rationale Exact hook order proves construction failures and re-entrant boot paths have a log sink before scheduler, delivery, occurrence, or maintenance registration begins.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_boot_registers_the_sink_before_every_engine_hook(): void {
		( new Component() )->initialize();

		$actions = $this->action_registrations();
		self::assertSame( 'a8csp_background_tasks/log', $actions[0]['hook_name'] ?? null );
		self::assertSame(
			array( 'a8csp_background_tasks/log', 'a8csp_background_tasks/start_batch', 'a8csp_background_tasks/continue_batch', 'a8csp_background_tasks/run_task', 'a8csp_background_tasks/run_chunk', 'a8csp_background_tasks/cleanup_batch', 'a8csp_background_tasks/schedule_due', 'init' ),
			\array_column( $actions, 'hook_name' )
		);
	}

	/**
	 * Re-boot retains the published graph and never duplicates callback registration.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A second composition-root instance proves the published latch retains the same graph and registrations after initialization completes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_reboot_is_idempotent_across_component_instances(): void {
		( new Component() )->initialize();
		$engine     = Component::get_engine();
		$inspection = Component::get_inspection();
		$actions    = $this->action_registrations();
		$filters    = $this->filter_registrations();

		( new Component() )->initialize();

		self::assertSame( $engine, Component::get_engine() );
		self::assertSame( $inspection, Component::get_inspection() );
		self::assertSame( $actions, $this->action_registrations() );
		self::assertSame( $filters, $this->filter_registrations() );
	}

	/**
	 * Re-entry while the scheduler filter is registering cannot build a competing graph.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Scheduler-filter registration re-enters initialize() before publication; one graph and one registration set prove the in-flight latch rejects the competing boot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_scheduler_filter_reentry_during_initialize_has_one_effect(): void {
		$reentered = false;
		$callbacks = $GLOBALS['a8csp_bgte_test_filter_registration_callbacks'] ?? null;
		self::assertIsArray( $callbacks );

		$callbacks['cron_schedules'] = static function () use ( &$reentered ): void {
			$reentered = true;
			( new Component() )->initialize();
		};

		$GLOBALS['a8csp_bgte_test_filter_registration_callbacks'] = $callbacks;

		( new Component() )->initialize();

		self::assertTrue( $reentered );
		self::assertInstanceOf( EngineFacade::class, Component::get_engine() );
		self::assertCount( 8, $this->action_registrations() );
		self::assertCount( 1, $this->filter_registrations() );
	}

	/**
	 * Applying a filter cannot silently bypass an uninitialized registration ledger.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_apply_filters_rejects_an_unset_registration_ledger(): void {
		unset( $GLOBALS['a8csp_bgte_test_filter_registrations'] );

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessageIs( 'Initialize the test filter ledger before applying a filter.' );

		\apply_filters( 'unregistered-filter', 'value' );
	}

	/**
	 * Mid-init boot waits for wp_loaded before synchronizing maintenance.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale An active init bucket must defer to wp_loaded and leave storage untouched so maintenance cannot schedule through Action Scheduler before its init callback completes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_mid_init_boot_defers_maintenance_sync_until_wp_loaded(): void {
		$GLOBALS['a8csp_bgte_test_did_actions']   = array(
			'plugins_loaded' => 1,
			'init'           => 1,
		);
		$GLOBALS['a8csp_bgte_test_doing_actions'] = array( 'init' );

		( new Component() )->initialize();

		$hook_names = \array_column( $this->action_registrations(), 'hook_name' );
		self::assertContains( 'wp_loaded', $hook_names );
		self::assertNotContains( 'init', $hook_names );
		self::assertNull( $this->schedule_registry() );
	}

	/**
	 * Late boot synchronizes maintenance inline without retaining a dead deferral.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_late_boot_syncs_maintenance_inline_without_deferral(): void {
		$GLOBALS['a8csp_bgte_test_did_actions'] = array(
			'plugins_loaded' => 1,
			'init'           => 1,
		);

		( new Component() )->initialize();

		// Deferral outside a running init registers on 'init' ('wp_loaded' is chosen only while doing init), so both lifecycle hooks must be absent.
		$hook_names = \array_column( $this->action_registrations(), 'hook_name' );
		self::assertNotContains( 'init', $hook_names );
		self::assertNotContains( 'wp_loaded', $hook_names );
		$registry = $this->schedule_registry();
		self::assertIsArray( $registry );
		self::assertArrayHasKey( 'a8csp-bgte:maintenance', $registry );
	}

	/**
	 * Deferred maintenance synchronization writes on the boot site and restores the interrupted site.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_deferred_maintenance_sync_retains_boot_site_affinity(): void {
		$GLOBALS['a8csp_bgte_test_did_actions']   = array(
			'plugins_loaded' => 1,
			'init'           => 1,
		);
		$GLOBALS['a8csp_bgte_test_doing_actions'] = array( 'init' );
		$GLOBALS['a8csp_bgte_test_is_multisite']  = true;
		( new Component() )->initialize();
		$registrations = \array_values( \array_filter( $this->action_registrations(), static fn ( array $registration ): bool => 'wp_loaded' === $registration['hook_name'] ) );
		self::assertCount( 1, $registrations );

		$GLOBALS['a8csp_bgte_test_blog_id'] = 2;
		$registrations[0]['callback']();

		self::assertSame( array( 1 ), $GLOBALS['a8csp_bgte_test_blog_switch_calls'] );
		self::assertSame( array( 2 ), $GLOBALS['a8csp_bgte_test_blog_restore_calls'] );
		self::assertSame( 2, $GLOBALS['a8csp_bgte_test_blog_id'] );
	}

	/**
	 * The composed WP-Cron graph accepts task, batch, and schedule operations through a8csp_bgte().
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_front_door_flows_through_the_live_wp_cron_graph(): void {
		$GLOBALS['a8csp_bgte_test_did_actions'] = array(
			'plugins_loaded' => 1,
			'init'           => 1,
		);
		( new Component() )->initialize();
		$consumer = \a8csp_bgte( 'consumer-plugin' );
		self::assertInstanceOf( Consumer::class, $consumer );
		$consumer->tasks()->register( new RecordingTask( 'refresh' ) );
		$consumer->batches()->register( new RecordingBatch( 'catalog-sync' ) );

		self::assertInstanceOf( Success::class, $consumer->tasks()->enqueue( 'refresh', array( 'site_id' => 7 ) ) );
		self::assertInstanceOf( Success::class, $consumer->batches()->start( 'catalog-sync', array( 'site_id' => 7 ) ) );
		self::assertInstanceOf( Success::class, $consumer->schedules()->sync( array( new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh' ) ) ) );

		$cron = \get_option( 'cron', array() );
		self::assertIsArray( $cron );
		self::assertNotEmpty( $cron );
	}

	/**
	 * A ready Action Scheduler graph accepts work without writing WP-Cron state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
		( new Component() )->initialize();
		$consumer = \a8csp_bgte( 'consumer-plugin' );
		$consumer->tasks()->register( new RecordingTask( 'preferred' ) );
		$GLOBALS['a8csp_bgte_test_as_calls']   = array();
		$GLOBALS['a8csp_bgte_test_cron_calls'] = array();

		$result = $consumer->tasks()->enqueue( 'preferred' );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( array( 'as_enqueue_async_action' ), \array_column( $GLOBALS['a8csp_bgte_test_as_calls'], 'function' ) );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_cron_calls'] );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns the decoded authoritative schedule registry when present.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>|null
	 */
	private function schedule_registry(): ?array {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		self::assertInstanceOf( WpdbLockSpy::class, $wpdb );
		$option_name = ScheduleRegistry::option_name( 'a8csp-bgte' );
		$raw         = $wpdb->rows[ $option_name ] ?? null;
		if ( \is_string( $raw ) ) {
			$registry = \maybe_unserialize( $raw );
			self::assertIsArray( $registry );

			return $registry;
		}

		$registry = \get_option( $option_name, null );
		self::assertTrue( null === $registry || \is_array( $registry ) );

		return $registry;
	}

	/**
	 * Returns the validated action-registration ledger.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{hook_name: string, callback: callable, priority: int, accepted_args: int}>
	 */
	private function action_registrations(): array {
		return $this->registrations( 'a8csp_bgte_test_action_registrations' );
	}

	/**
	 * Returns the validated filter-registration ledger.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{hook_name: string, callback: callable, priority: int, accepted_args: int}>
	 */
	private function filter_registrations(): array {
		return $this->registrations( 'a8csp_bgte_test_filter_registrations' );
	}

	/**
	 * Validates one hook-registration ledger before structural assertions consume it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $ledger Global ledger name.
	 *
	 * @return  list<array{hook_name: string, callback: callable, priority: int, accepted_args: int}>
	 */
	private function registrations( string $ledger ): array {
		$registrations = $GLOBALS[ $ledger ] ?? null;
		if ( ! \is_array( $registrations ) ) {
			throw new \LogicException( 'The hook-registration ledger is unavailable.' );
		}

		$validated = array();
		foreach ( $registrations as $registration ) {
			if ( ! \is_array( $registration ) ) {
				throw new \LogicException( 'The hook-registration ledger contains a malformed entry.' );
			}
			$hook_name     = $registration['hook_name'] ?? null;
			$callback      = $registration['callback'] ?? null;
			$priority      = $registration['priority'] ?? null;
			$accepted_args = $registration['accepted_args'] ?? null;
			if ( ! \is_string( $hook_name ) || ! \is_callable( $callback ) || ! \is_int( $priority ) || ! \is_int( $accepted_args ) ) {
				throw new \LogicException( 'The hook-registration ledger contains an invalid entry.' );
			}
			$validated[] = array(
				'hook_name'     => $hook_name,
				'callback'      => $callback,
				'priority'      => $priority,
				'accepted_args' => $accepted_args,
			);
		}

		return $validated;
	}

	// endregion.
}

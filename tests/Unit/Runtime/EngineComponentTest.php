<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\ScopeOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
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

		$GLOBALS['a8csp_bgje_test_options']              = array();
		$GLOBALS['a8csp_bgje_test_option_calls']         = array();
		$GLOBALS['a8csp_bgje_test_option_autoload']      = array();
		$GLOBALS['a8csp_bgje_test_hooks']                = array();
		$GLOBALS['a8csp_bgje_test_action_registrations'] = array();
		$GLOBALS['a8csp_bgje_test_filter_registrations'] = array();

		$GLOBALS['a8csp_bgje_test_filter_registration_callbacks'] = array();

		$GLOBALS['a8csp_bgje_test_filter_values']     = array();
		$GLOBALS['a8csp_bgje_test_fired_actions']     = array();
		$GLOBALS['a8csp_bgje_test_action_throwables'] = array();
		$GLOBALS['a8csp_bgje_test_blog_id']           = 1;

		$GLOBALS['a8csp_bgje_test_blog_stack']         = array();
		$GLOBALS['a8csp_bgje_test_blog_switch_calls']  = array();
		$GLOBALS['a8csp_bgje_test_blog_restore_calls'] = array();

		$GLOBALS['a8csp_bgje_test_is_multisite']        = false;
		$GLOBALS['a8csp_bgje_test_cache']               = array();
		$GLOBALS['a8csp_bgje_test_cache_calls']         = array();
		$GLOBALS['a8csp_bgje_test_cron_array']          = array();
		$GLOBALS['a8csp_bgje_test_cron_calls']          = array();
		$GLOBALS['a8csp_bgje_test_cron_results']        = array();
		$GLOBALS['a8csp_bgje_test_cron_event_sequence'] = 0;
		$GLOBALS['a8csp_bgje_test_as_calls']            = array();
		$GLOBALS['a8csp_bgje_test_as_results']          = array();
		$GLOBALS['a8csp_bgje_test_did_actions']         = array( 'plugins_loaded' => 1 );
		$GLOBALS['a8csp_bgje_test_doing_actions']       = array();
		$GLOBALS['wpdb']                                = new WpdbLockSpy();
	}

	// endregion.

	// region TESTS.

	/**
	 * The root is always needed and publishes a dispatcher that accepts registered work.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_initialize_publishes_a_dispatcher_that_accepts_registered_work(): void {
		$component = new Component();

		self::assertTrue( Component::should_load() );
		$component->initialize();
		$component->register_hooks();
		$dispatcher = Component::get_dispatcher();
		self::assertNotNull( $dispatcher );
		$identity = Identity::compose( 'consumer-plugin', 'published-job' );
		$dispatcher->register( $identity, ( new RecordingJob( 'published-job' ) )->definition() );

		self::assertInstanceOf( Success::class, $dispatcher->dispatch( $identity ) );
		self::assertFalse( Component::get_scheduler()?->has_dormant_candidate() );
		self::assertInstanceOf( Success::class, Component::get_lock_inspection()?->inspect_lanes() );
	}

	/**
	 * The published schedule operations synchronize a client scope.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_initialize_publishes_schedule_operations_that_synchronize_a_scope(): void {
		$component = new Component();
		$component->initialize();
		$component->register_hooks();
		$schedules = Component::get_schedules();
		self::assertNotNull( $schedules );

		self::assertInstanceOf( Success::class, $schedules->sync( 'consumer-plugin', array() ) );
	}

	/**
	 * A completed component lifecycle permits a later lifecycle to publish a fresh graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_completed_component_lifecycle_can_publish_a_fresh_graph(): void {
		$component = new Component();
		$component->initialize();
		$component->register_hooks();
		$dispatcher = Component::get_dispatcher();
		$inspection = Component::get_inspection();

		$GLOBALS['a8csp_bgje_test_hooks']                = array();
		$GLOBALS['a8csp_bgje_test_action_registrations'] = array();
		$GLOBALS['a8csp_bgje_test_filter_registrations'] = array();

		$component = new Component();
		$component->initialize();
		$component->register_hooks();

		self::assertNotSame( $dispatcher, Component::get_dispatcher() );
		self::assertNotSame( $inspection, Component::get_inspection() );
		self::assertCount( 3, $this->action_registrations() );
		self::assertCount( 1, $this->filter_registrations() );
	}

	/**
	 * Re-entry from filter registration retains the published graph and one registration set.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Filter registration is the reachable re-entry seam during boot; this proves it cannot publish a competing graph or duplicate registrations.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_scheduler_filter_reentry_during_hook_registration_has_one_effect(): void {
		$reentered                              = false;
		$GLOBALS['a8csp_bgje_test_did_actions'] = array(
			'plugins_loaded' => 1,
			'init'           => 1,
		);
		\add_filter(
			'cron_schedules',
			static function ( array $schedules ) use ( &$reentered ): array {
				$reentered = true;
				( new Component() )->initialize();

				return $schedules;
			},
			5,
			1
		);

		$component = new Component();
		$component->initialize();
		$dispatcher = Component::get_dispatcher();
		$schedules  = Component::get_schedules();
		$component->register_hooks();

		self::assertTrue( $reentered );
		self::assertSame( $dispatcher, Component::get_dispatcher() );
		self::assertSame( $schedules, Component::get_schedules() );
		self::assertCount( 2, $this->action_registrations() );
		self::assertCount( 2, $this->filter_registrations() );
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
		$GLOBALS['a8csp_bgje_test_did_actions']   = array(
			'plugins_loaded' => 1,
			'init'           => 1,
		);
		$GLOBALS['a8csp_bgje_test_doing_actions'] = array( 'init' );

		$component = new Component();
		$component->initialize();
		$component->register_hooks();

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
		$GLOBALS['a8csp_bgje_test_did_actions'] = array(
			'plugins_loaded' => 1,
			'init'           => 1,
		);

		$component = new Component();
		$component->initialize();
		$component->register_hooks();

		// Deferral outside a running init registers on 'init' ('wp_loaded' is chosen only while doing init), so both lifecycle hooks must be absent.
		$hook_names = \array_column( $this->action_registrations(), 'hook_name' );
		self::assertNotContains( 'init', $hook_names );
		self::assertNotContains( 'wp_loaded', $hook_names );
		$registry = $this->schedule_registry();
		self::assertIsArray( $registry );
		self::assertArrayHasKey( 'a8csp-bgje:maintenance', $registry );
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
		$GLOBALS['a8csp_bgje_test_did_actions']   = array(
			'plugins_loaded' => 1,
			'init'           => 1,
		);
		$GLOBALS['a8csp_bgje_test_doing_actions'] = array( 'init' );
		$GLOBALS['a8csp_bgje_test_is_multisite']  = true;

		$component = new Component();
		$component->initialize();
		$component->register_hooks();
		$registrations = \array_values( \array_filter( $this->action_registrations(), static fn ( array $registration ): bool => 'wp_loaded' === $registration['hook_name'] ) );
		self::assertCount( 1, $registrations );

		$GLOBALS['a8csp_bgje_test_blog_id'] = 2;
		$registrations[0]['callback']();

		self::assertSame( array( 1 ), $GLOBALS['a8csp_bgje_test_blog_switch_calls'] );
		self::assertSame( array( 2 ), $GLOBALS['a8csp_bgje_test_blog_restore_calls'] );
		self::assertSame( 2, $GLOBALS['a8csp_bgje_test_blog_id'] );
	}

	/**
	 * The composed WP-Cron graph accepts job, chunked job, and schedule operations through its client.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_front_door_flows_through_the_live_wp_cron_graph(): void {
		$GLOBALS['a8csp_bgje_test_did_actions'] = array(
			'plugins_loaded' => 1,
			'init'           => 1,
		);

		$component = new Component();
		$component->initialize();
		$component->register_hooks();
		$client = Component::operations( 'consumer-plugin' );
		self::assertInstanceOf( ScopeOperations::class, $client );
		$client->register( ( new RecordingJob( 'refresh' ) )->definition() );
		$client->register( ( new RecordingChunkedJob( 'catalog-sync' ) )->definition() );

		self::assertInstanceOf( Run::class, $client->dispatch( 'refresh', array( 'site_id' => 7 ) ) );
		self::assertInstanceOf( Run::class, $client->dispatch( 'catalog-sync', array( 'site_id' => 7 ) ) );
		self::assertTrue( $client->sync( array( new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh' ) ) ) );

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
		require_once \dirname( __DIR__ ) . '/as-class-stubs.php';
		$GLOBALS['a8csp_bgje_test_did_actions'] = array(
			'plugins_loaded'        => 1,
			'init'                  => 1,
			'action_scheduler_init' => 1,
		);

		$component = new Component();
		$component->initialize();
		$component->register_hooks();
		$client = Component::operations( 'consumer-plugin' );
		$client->register( ( new RecordingJob( 'preferred' ) )->definition() );
		$GLOBALS['a8csp_bgje_test_as_calls']   = array();
		$GLOBALS['a8csp_bgje_test_cron_calls'] = array();

		$result = $client->dispatch( 'preferred' );

		$as_calls = $GLOBALS['a8csp_bgje_test_as_calls'] ?? null;
		self::assertInstanceOf( Run::class, $result );
		self::assertIsArray( $as_calls );
		self::assertSame( array( 'as_enqueue_async_action' ), \array_column( $as_calls, 'function' ) );
		self::assertSame( array(), $GLOBALS['a8csp_bgje_test_cron_calls'] );
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
		$option_name = ScheduleRegistry::option_name( 'a8csp-bgje' );
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
		return $this->registrations( 'a8csp_bgje_test_action_registrations' );
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
		return $this->registrations( 'a8csp_bgje_test_filter_registrations' );
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

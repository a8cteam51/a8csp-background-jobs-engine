<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Verifies the real `uninstall.php` end-to-end through the documented option-prefix and lifecycle-
 * hook contracts: engine rows and scheduled work are reclaimed while an outside canary survives.
 *
 * `uninstall.php` guards on `defined( 'WP_UNINSTALL_PLUGIN' )`, a constant WordPress itself
 * only defines during a real plugin-delete request. This test defines it by hand, so the one
 * test method runs `#[RunInSeparateProcess]` — the constant must not leak into the rest of
 * the suite, where its presence would be indistinguishable from an actual uninstall.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class UninstallTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/**
	 * A canary option the footprint never lists. Its survival is what proves the test
	 * exercises "delete only what's owned" rather than "delete everything".
	 *
	 */
	private const string CANARY_OPTION = 'a8cspXbgteYtest_uninstall_canary';

	/**
	 * One sentinel from every option family documented for operators.
	 *
	 * @var list<string>
	 */
	private const array DOCUMENTED_OPTIONS = array(
		'a8csp_bgte_schedule_registrations_uninstall-test',
		'a8csp_bgte_run_uninstall-test:task_run-1',
		'a8csp_bgte_failed_runs_uninstall-test:task',
		'a8csp_bgte_latest_run_uninstall-test',
		'a8csp_bgte_run_history_uninstall-test',
		'a8csp_bgte_overlap_lock_uninstall-test_args-hash',
		'a8csp_bgte_occurrence_lease_registration-hash',
		'a8csp_bgte_cleanup_intent_registration-hash',
	);

	/** Internal lifecycle hooks that may retain scheduled work. */
	private const array LIFECYCLE_HOOKS = array(
		'a8csp_background_tasks/start_batch',
		'a8csp_background_tasks/continue_batch',
		'a8csp_background_tasks/run_task',
		'a8csp_background_tasks/run_chunk',
		'a8csp_background_tasks/cleanup_batch',
		'a8csp_background_tasks/schedule_due',
	);

	/** Runtime arguments prove uninstall clears each hook without requiring an exact identity. */
	private const array SCHEDULE_ARGS = array( 'uninstall-test', 'run-1', 1 );

	/** Runtime groups prove Action Scheduler cleanup reaches work outside its empty group. */
	private const string SCHEDULE_GROUP = 'uninstall-test|run-1';

	// endregion.

	// region LIFECYCLE.

	/**
	 * Removes options and scheduled work regardless of how the test finished, since this suite
	 * runs against a persistent wp-env database with no per-test transaction rollback.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function tearDown(): void {
		try {
			self::clear_scheduled_work();
			delete_option( self::CANARY_OPTION );
			foreach ( self::DOCUMENTED_OPTIONS as $option ) {
				delete_option( $option );
			}
		} finally {
			parent::tearDown();
		}
	}

	// endregion.

	// region TESTS.

	/**
	 * Seeds every documented option family plus the canary, then runs the real `uninstall.php`.
	 * It also seeds every lifecycle hook in both scheduler stores. The canary
	 * resembles the prefix but replaces its underscores, proving the cleanup query treats those
	 * underscores literally rather than as SQL LIKE wildcards.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	public function test_uninstall_deletes_only_its_own_footprint(): void {
		self::clear_scheduled_work();

		foreach ( self::DOCUMENTED_OPTIONS as $option ) {
			self::assertTrue( update_option( $option, 'sentinel', false ), "The '{$option}' option-family sentinel must be persisted before uninstall" );
		}

		self::assertTrue( update_option( self::CANARY_OPTION, 'sentinel', false ), 'The outside-prefix canary must be persisted before uninstall' );

		$scheduled_at     = \time() + \HOUR_IN_SECONDS;
		$wp_cron          = new WPCronBackend();
		$action_scheduler = new ActionSchedulerBackend( static fn (): bool => true );
		foreach ( self::LIFECYCLE_HOOKS as $hook ) {
			self::assertInstanceOf( Success::class, $wp_cron->schedule_single( $hook, $scheduled_at, self::SCHEDULE_ARGS ) );
			self::assertInstanceOf( Success::class, $action_scheduler->schedule_single( $hook, $scheduled_at, self::SCHEDULE_ARGS, self::SCHEDULE_GROUP ) );
			self::assertSame( $scheduled_at, $wp_cron->get_next_scheduled( $hook, self::SCHEDULE_ARGS ) );
			self::assertSame( $scheduled_at, $action_scheduler->get_next_scheduled( $hook, self::SCHEDULE_ARGS, self::SCHEDULE_GROUP ) );
		}

		\define( 'WP_UNINSTALL_PLUGIN', true );
		require \dirname( __DIR__, 2 ) . '/uninstall.php';

		self::assertSame( array(), self::engine_option_names(), 'uninstall.php must leave no option inside the documented a8csp_bgte_ ownership prefix' );
		foreach ( self::LIFECYCLE_HOOKS as $hook ) {
			self::assertFalse( $wp_cron->is_scheduled( $hook, self::SCHEDULE_ARGS ), "uninstall.php must remove every WP-Cron event for '{$hook}'" );
			self::assertFalse( $action_scheduler->is_scheduled( $hook, self::SCHEDULE_ARGS, self::SCHEDULE_GROUP ), "uninstall.php must remove every pending Action Scheduler action for '{$hook}'" );
		}

		self::assertSame( 'sentinel', get_option( self::CANARY_OPTION ), 'uninstall.php must not delete keys outside its footprint' );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Clears scheduler state that may persist across interrupted integration runs.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private static function clear_scheduled_work(): void {
		foreach ( self::LIFECYCLE_HOOKS as $hook ) {
			\wp_unschedule_hook( $hook );
			if ( \function_exists( 'as_unschedule_all_actions' ) ) {
				\as_unschedule_all_actions( $hook );
			}
		}
	}

	/**
	 * Returns every option inside the documented engine ownership prefix in lexical order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<string>
	 */
	private static function engine_option_names(): array {
		global $wpdb;

		self::assertInstanceOf( \wpdb::class, $wpdb );
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s ORDER BY `option_name` ASC', $wpdb->options, $wpdb->esc_like( 'a8csp_bgte_' ) . '%' ) );
		self::assertIsArray( $names );
		self::assertContainsOnlyString( $names );

		return \array_values( $names );
	}

	// endregion.
}

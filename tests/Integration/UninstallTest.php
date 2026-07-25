<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\AbstractIntegrationTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Verifies the real `uninstall.php` end-to-end through the documented option-prefix and lifecycle-
 * hook contracts: operational rows and scheduled work are reclaimed, diagnostics follow the
 * uninstall policy, and an outside canary survives.
 *
 * `uninstall.php` guards on `defined( 'WP_UNINSTALL_PLUGIN' )`, a constant WordPress itself
 * only defines during a real plugin-delete request. These tests define uninstall constants by
 * hand, so each method runs `#[RunInSeparateProcess]` — the constants must not leak into the rest
 * of the suite, where their presence would be indistinguishable from an actual uninstall.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class UninstallTest extends AbstractIntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/**
	 * A canary option the footprint never lists. Its survival is what proves the test
	 * exercises "delete only what's owned" rather than "delete everything".
	 *
	 */
	private const string LIKE_CANARY_OPTION = 'a8cspXbgjeYtest_uninstall_canary';

	/** A case-distinct canary that may match the prefix query under a case-insensitive collation. */
	private const string BYTE_CANARY_OPTION = 'A8CSP_BGJE_test_uninstall_canary';

	/**
	 * One sentinel from every option family documented for operators.
	 *
	 * @var list<string>
	 */
	private const array DOCUMENTED_OPTIONS = array(
		'a8csp_bgje_schedule_registrations_uninstall-test',
		'a8csp_bgje_active_run_uninstall-test:job_run-1',
		'a8csp_bgje_failed_runs_uninstall-test:job',
		'a8csp_bgje_latest_run_uninstall-test',
		'a8csp_bgje_run_history_uninstall-test',
		'a8csp_bgje_overlap_lock_uninstall-test_args-hash',
		'a8csp_bgje_occurrence_lease_registration-hash',
		'a8csp_bgje_cleanup_intent_registration-hash',
		'a8csp_bgje_cleanup_sweep_cursor',
	);

	/** Internal delivery hooks that may retain scheduled work. */
	private const array DELIVERY_HOOKS = array(
		'a8csp_bgje/internal/deliver',
		'a8csp_bgje/internal/schedule_due',
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
			delete_option( self::LIKE_CANARY_OPTION );
			delete_option( self::BYTE_CANARY_OPTION );
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
	 * Default uninstall preserves diagnostic records while deleting every other documented option
	 * family and both scheduler stores. One canary replaces the prefix underscores to exercise LIKE
	 * escaping; the other differs only by case to exercise the byte-exact deletion boundary under a
	 * case-insensitive option-name collation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	public function test_uninstall_preserves_diagnostics_by_default_and_deletes_other_footprint(): void {
		self::clear_scheduled_work();

		foreach ( self::DOCUMENTED_OPTIONS as $option ) {
			self::assertTrue( update_option( $option, 'sentinel', false ), "The '{$option}' option-family sentinel must be persisted before uninstall" );
		}

		self::assertTrue( update_option( self::LIKE_CANARY_OPTION, 'sentinel', false ), 'The outside-prefix canary must be persisted before uninstall' );
		self::assertTrue( update_option( self::BYTE_CANARY_OPTION, 'sentinel', false ), 'The case-distinct canary must be persisted before uninstall' );

		$scheduled_at     = \time() + \HOUR_IN_SECONDS;
		$wp_cron          = new WPCronBackend();
		$action_scheduler = new ActionSchedulerBackend();
		foreach ( self::DELIVERY_HOOKS as $hook ) {
			self::assertInstanceOf( Success::class, $wp_cron->schedule_single( $hook, $scheduled_at, self::SCHEDULE_ARGS ) );
			self::assertInstanceOf( Success::class, $action_scheduler->schedule_single( $hook, $scheduled_at, self::SCHEDULE_ARGS, self::SCHEDULE_GROUP ) );
			self::assertSame( $scheduled_at, $wp_cron->get_next_scheduled( $hook, self::SCHEDULE_ARGS ) );
			self::assertSame( $scheduled_at, $action_scheduler->get_next_scheduled( $hook, self::SCHEDULE_ARGS, self::SCHEDULE_GROUP ) );
		}

		\define( 'WP_UNINSTALL_PLUGIN', true );
		require \dirname( __DIR__, 2 ) . '/uninstall.php';

		self::assertSame(
			array(
				'a8csp_bgje_failed_runs_uninstall-test:job',
				'a8csp_bgje_run_history_uninstall-test',
			),
			self::engine_option_names(),
			'uninstall.php must preserve only failed-run and run-history diagnostics by default'
		);
		foreach ( self::DELIVERY_HOOKS as $hook ) {
			self::assertFalse( $wp_cron->is_scheduled( $hook, self::SCHEDULE_ARGS ), "uninstall.php must remove every WP-Cron event for '{$hook}'" );
			self::assertFalse( $action_scheduler->is_scheduled( $hook, self::SCHEDULE_ARGS, self::SCHEDULE_GROUP ), "uninstall.php must remove every pending Action Scheduler action for '{$hook}'" );
		}

		self::assertSame( 'sentinel', get_option( self::LIKE_CANARY_OPTION ), 'uninstall.php must not delete keys outside its footprint' );
		self::assertSame( 'sentinel', get_option( self::BYTE_CANARY_OPTION ), 'uninstall.php must preserve byte-distinct option names selected by a case-insensitive collation' );
	}

	/**
	 * Literal boolean true opts into deleting the diagnostic families with the operational rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	public function test_uninstall_removes_diagnostics_when_explicitly_requested(): void {
		foreach ( self::DOCUMENTED_OPTIONS as $option ) {
			self::assertTrue( update_option( $option, 'sentinel', false ), "The '{$option}' option-family sentinel must be persisted before uninstall" );
		}

		\define( 'A8CSP_BGJE_REMOVE_DIAGNOSTICS_ON_UNINSTALL', true );
		\define( 'WP_UNINSTALL_PLUGIN', true );
		require \dirname( __DIR__, 2 ) . '/uninstall.php';

		self::assertSame( array(), self::engine_option_names(), 'The uninstall opt-in must leave zero rows inside the documented a8csp_bgje_ ownership prefix' );
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
		foreach ( self::DELIVERY_HOOKS as $hook ) {
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
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s ORDER BY `option_name` ASC', $wpdb->options, $wpdb->esc_like( 'a8csp_bgje_' ) . '%' ) );
		self::assertIsArray( $names );
		self::assertContainsOnlyString( $names );

		return \array_values( \array_filter( $names, static fn ( string $name ): bool => \str_starts_with( $name, 'a8csp_bgje_' ) ) );
	}

	// endregion.
}

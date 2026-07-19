<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\IntegrationTestCase;
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
		'a8csp_bgje_run_uninstall-test:job_run-1',
		'a8csp_bgje_failed_runs_uninstall-test:job',
		'a8csp_bgje_latest_run_uninstall-test',
		'a8csp_bgje_history_uninstall-test',
		'a8csp_bgje_overlap_lock_uninstall-test_args-hash',
		'a8csp_bgje_occurrence_lease_registration-hash',
		'a8csp_bgje_cleanup_intent_registration-hash',
	);

	/** Internal lifecycle hooks that may retain scheduled work. */
	private const array LIFECYCLE_HOOKS = array(
		'a8csp_jobs_engine/start_chunked_job',
		'a8csp_jobs_engine/continue_chunked_job',
		'a8csp_jobs_engine/run_job',
		'a8csp_jobs_engine/run_chunk',
		'a8csp_jobs_engine/cleanup_chunked_job',
		'a8csp_jobs_engine/schedule_due',
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
	 * Seeds every documented option family plus two canaries, then runs the real `uninstall.php`.
	 * It also seeds every lifecycle hook in both scheduler stores. One canary replaces the prefix
	 * underscores to exercise LIKE escaping; the other differs only by case to exercise the
	 * byte-exact deletion boundary under a case-insensitive option-name collation.
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

		self::assertTrue( update_option( self::LIKE_CANARY_OPTION, 'sentinel', false ), 'The outside-prefix canary must be persisted before uninstall' );
		self::assertTrue( update_option( self::BYTE_CANARY_OPTION, 'sentinel', false ), 'The case-distinct canary must be persisted before uninstall' );

		$scheduled_at     = \time() + \HOUR_IN_SECONDS;
		$wp_cron          = new WPCronBackend();
		$action_scheduler = new ActionSchedulerBackend();
		foreach ( self::LIFECYCLE_HOOKS as $hook ) {
			self::assertInstanceOf( Success::class, $wp_cron->schedule_single( $hook, $scheduled_at, self::SCHEDULE_ARGS ) );
			self::assertInstanceOf( Success::class, $action_scheduler->schedule_single( $hook, $scheduled_at, self::SCHEDULE_ARGS, self::SCHEDULE_GROUP ) );
			self::assertSame( $scheduled_at, $wp_cron->get_next_scheduled( $hook, self::SCHEDULE_ARGS ) );
			self::assertSame( $scheduled_at, $action_scheduler->get_next_scheduled( $hook, self::SCHEDULE_ARGS, self::SCHEDULE_GROUP ) );
		}

		\define( 'WP_UNINSTALL_PLUGIN', true );
		require \dirname( __DIR__, 2 ) . '/uninstall.php';

		self::assertSame( array(), self::engine_option_names(), 'uninstall.php must leave no option inside the documented a8csp_bgje_ ownership prefix' );
		foreach ( self::LIFECYCLE_HOOKS as $hook ) {
			self::assertFalse( $wp_cron->is_scheduled( $hook, self::SCHEDULE_ARGS ), "uninstall.php must remove every WP-Cron event for '{$hook}'" );
			self::assertFalse( $action_scheduler->is_scheduled( $hook, self::SCHEDULE_ARGS, self::SCHEDULE_GROUP ), "uninstall.php must remove every pending Action Scheduler action for '{$hook}'" );
		}

		self::assertSame( 'sentinel', get_option( self::LIKE_CANARY_OPTION ), 'uninstall.php must not delete keys outside its footprint' );
		self::assertSame( 'sentinel', get_option( self::BYTE_CANARY_OPTION ), 'uninstall.php must preserve byte-distinct option names selected by a case-insensitive collation' );
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
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s ORDER BY `option_name` ASC', $wpdb->options, $wpdb->esc_like( 'a8csp_bgje_' ) . '%' ) );
		self::assertIsArray( $names );
		self::assertContainsOnlyString( $names );

		return \array_values( \array_filter( $names, static fn ( string $name ): bool => \str_starts_with( $name, 'a8csp_bgje_' ) ) );
	}

	// endregion.
}

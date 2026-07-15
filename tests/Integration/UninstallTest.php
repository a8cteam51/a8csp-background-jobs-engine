<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Verifies the real `uninstall.php` end-to-end: every option and user-meta key its inline
 * footprint lists is gone after it runs, scheduled engine work is reclaimed, and a sentinel key
 * NOT in the footprint survives — proving the file deletes what it owns and nothing else.
 *
 * `uninstall.php` guards on `defined( 'WP_UNINSTALL_PLUGIN' )`, a constant WordPress itself
 * only defines during a real plugin-delete request. This test defines it by hand, so the one
 * test method runs `#[RunInSeparateProcess]` — the constant must not leak into the rest of
 * the suite, where its presence would be indistinguishable from an actual uninstall.
 *
 */
final class UninstallTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/**
	 * A canary option the footprint never lists. Its survival is what proves the test
	 * exercises "delete only what's owned" rather than "delete everything".
	 *
	 */
	private const CANARY_OPTION = 'a8cspXbgteYtest_uninstall_canary';

	/**
	 * Representative dynamically named option rows owned by orchestration stores.
	 *
	 * @var list<string>
	 */
	private const DYNAMIC_OPTIONS = array(
		'a8csp_bgte_run_uninstall-test_run-1',
		'a8csp_bgte_latest_uninstall-test',
		'a8csp_bgte_history_uninstall-test',
		'a8csp_bgte_lock_uninstall-test_args-hash',
		'a8csp_bgte_failed_uninstall-test',
	);

	/** Internal lifecycle hooks that may retain scheduled work. */
	private const LIFECYCLE_HOOKS = array(
		'a8csp_background_tasks/start',
		'a8csp_background_tasks/continue',
		'a8csp_background_tasks/run',
		'a8csp_background_tasks/cleanup',
	);

	/** Runtime arguments prove uninstall clears each hook without requiring an exact identity. */
	private const SCHEDULE_ARGS = array( 'uninstall-test', 'run-1', 1 );

	/** Runtime groups prove Action Scheduler cleanup reaches work outside its empty group. */
	private const SCHEDULE_GROUP = 'uninstall-test|run-1';

	// endregion.

	// region LIFECYCLE.

	/**
	 * Removes options and scheduled work regardless of how the test finished, since this suite
	 * runs against a persistent wp-env database with no per-test transaction rollback.
	 *
	 * @return  void
	 */
	protected function tearDown(): void {
		try {
			self::clear_scheduled_work();
			delete_option( self::CANARY_OPTION );
			foreach ( self::DYNAMIC_OPTIONS as $option ) {
				delete_option( $option );
			}
		} finally {
			parent::tearDown();
		}
	}

	// endregion.

	// region TESTS.

	/**
	 * Seeds a sentinel for every key the real footprint lists plus the canary, runs the real
	 * `uninstall.php`, then asserts the footprint's keys are gone and the canary survived.
	 * Dynamic rows are seeded separately because their runtime suffixes cannot appear in the fixed
	 * footprint list. It also seeds every lifecycle hook in both scheduler stores. The canary
	 * resembles the prefix but replaces its underscores, proving the cleanup query treats those
	 * underscores literally rather than as SQL LIKE wildcards.
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	public function test_uninstall_deletes_only_its_own_footprint(): void {
		$footprint = self::read_inline_footprint();
		$user_id   = self::an_existing_user_id();
		self::clear_scheduled_work();

		foreach ( $footprint['options'] as $option ) {
			update_option( $option, 'sentinel' );
		}

		foreach ( $footprint['user_meta'] as $meta_key ) {
			update_user_meta( $user_id, $meta_key, 'sentinel' );
		}
		foreach ( self::DYNAMIC_OPTIONS as $option ) {
			update_option( $option, 'sentinel' );
		}

		update_option( self::CANARY_OPTION, 'sentinel' );

		$scheduled_at = \time() + \HOUR_IN_SECONDS;
		foreach ( self::LIFECYCLE_HOOKS as $hook ) {
			self::assertTrue( \wp_schedule_single_event( $scheduled_at, $hook, self::SCHEDULE_ARGS, true ), "wp-env must seed a WP-Cron event for '{$hook}'" );

			$action_id = \as_schedule_single_action( $scheduled_at, $hook, self::SCHEDULE_ARGS, self::SCHEDULE_GROUP );
			self::assertGreaterThan( 0, $action_id, "wp-env must seed an Action Scheduler action for '{$hook}'" );
			self::assertSame( $scheduled_at, \wp_next_scheduled( $hook, self::SCHEDULE_ARGS ) );
			self::assertIsInt( \as_next_scheduled_action( $hook, self::SCHEDULE_ARGS, self::SCHEDULE_GROUP ), "Action Scheduler must retain the seeded '{$hook}' action before uninstall" );
		}

		\define( 'WP_UNINSTALL_PLUGIN', true );
		require \dirname( __DIR__, 2 ) . '/uninstall.php';

		foreach ( $footprint['options'] as $option ) {
			self::assertFalse( get_option( $option ), "uninstall.php must delete the '{$option}' option" );
		}

		foreach ( $footprint['user_meta'] as $meta_key ) {
			self::assertSame( '', get_user_meta( $user_id, $meta_key, true ), "uninstall.php must delete the '{$meta_key}' user-meta key" );
		}
		foreach ( self::DYNAMIC_OPTIONS as $option ) {
			self::assertFalse( get_option( $option ), "uninstall.php must delete the dynamically named '{$option}' option" );
		}
		foreach ( self::LIFECYCLE_HOOKS as $hook ) {
			self::assertFalse( \wp_next_scheduled( $hook, self::SCHEDULE_ARGS ), "uninstall.php must remove every WP-Cron event for '{$hook}'" );
			self::assertFalse( \as_next_scheduled_action( $hook ), "uninstall.php must remove every pending Action Scheduler action for '{$hook}'" );
		}

		self::assertSame( 'sentinel', get_option( self::CANARY_OPTION ), 'uninstall.php must not delete keys outside its footprint' );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Clears scheduler state that may persist across interrupted integration runs.
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
	 * Returns an existing user's ID to seed and verify user-meta deletion against. wp-env's
	 * fixture always provisions the default admin (ID 1); querying for one keeps the test
	 * independent of that assumption instead of hard-coding it.
	 *
	 * @return  int
	 */
	private static function an_existing_user_id(): int {
		$users = get_users(
			array(
				'number' => 1,
				'fields' => 'ID',
			)
		);

		self::assertNotEmpty( $users, 'wp-env must provision at least one user to seed user-meta against' );
		$user_id = $users[0] ?? null;
		self::assertIsNumeric( $user_id, 'get_users() must return a numeric ID when queried for the ID field' );

		return (int) $user_id;
	}

	/**
	 * Extracts `$a8csp_bgte_footprint` from the real `uninstall.php` source without
	 * requiring the file. Requiring it exits unless `WP_UNINSTALL_PLUGIN` is already defined,
	 * and defining that just to read the array would run the delete loops before this test has
	 * seeded anything for them to delete. Locates the array literal by balancing parens from
	 * its own `array(` so the nested `options`/`user_meta` arrays don't confuse the match, then
	 * evaluates only that expression — never uninstall.php's guard or its delete loops.
	 * This eval is safe only because it parses this repository's own version-controlled
	 * `uninstall.php` and must never be generalized to evaluate user input, remote data,
	 * another file, or anything else from outside this repository; if the footprint's shape
	 * grows complex enough that this string-slicing extraction becomes fragile, use a
	 * `token_get_all()`-based reader as the eval-free alternative instead of trying to make
	 * the eval safer.
	 *
	 * @return  array{options: list<string>, user_meta: list<string>}
	 */
	private static function read_inline_footprint(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read of a tracked source file, not a remote resource.
		$source = (string) \file_get_contents( \dirname( __DIR__, 2 ) . '/uninstall.php' );

		$needle = '$a8csp_bgte_footprint';
		$assign = \strpos( $source, $needle );
		self::assertIsInt( $assign, "uninstall.php must declare {$needle} inline" );

		$array_start = \strpos( $source, 'array(', $assign );
		self::assertIsInt( $array_start, "could not find the {$needle} array literal" );

		$depth  = 0;
		$end    = null;
		$length = \strlen( $source );

		for ( $i = $array_start; $i < $length; $i++ ) {
			if ( '(' === $source[ $i ] ) {
				++$depth;
			} elseif ( ')' === $source[ $i ] ) {
				--$depth;

				if ( 0 === $depth ) {
					$end = $i;
					break;
				}
			}
		}

		self::assertIsInt( $end, "could not find the end of the {$needle} array literal" );

		$expression = \substr( $source, $array_start, $end - $array_start + 1 );
		$footprint  = eval( "return {$expression};" ); // phpcs:ignore Squiz.PHP.Eval -- evaluates a version-controlled array literal parsed out of this repo's own uninstall.php, never external input.

		self::assertIsArray( $footprint );
		self::assertArrayHasKey( 'options', $footprint );
		self::assertArrayHasKey( 'user_meta', $footprint );

		$options = $footprint['options'];
		self::assertIsList( $options );
		self::assertContainsOnlyString( $options );

		$user_meta = $footprint['user_meta'];
		self::assertIsList( $user_meta );
		self::assertContainsOnlyString( $user_meta );

		return array(
			'options'   => $options,
			'user_meta' => $user_meta,
		);
	}

	// endregion.
}

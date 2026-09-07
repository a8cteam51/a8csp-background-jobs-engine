<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Provides the database reads required by uninstall tests without loading WordPress.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class UninstallWpdbSpy {
	// region FIELDS AND CONSTANTS.

	/** Options table name. */
	public string $options = 'wp_options';

	/** Site table-name prefix. */
	public string $prefix = 'wp_';

	/** @var list<array{query: string, args: list<mixed>}> Prepared queries. */
	public array $prepared = array();

	// endregion.

	// region METHODS.

	/**
	 * Escapes SQL LIKE wildcards in one literal prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $text Prefix to escape.
	 *
	 * @return  string
	 */
	public function esc_like( string $text ): string {
		return \addcslashes( $text, '_%\\' );
	}

	/**
	 * Records a prepared query and returns a template-identifying token.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $query   Query template.
	 * @param   mixed  ...$args Prepared arguments.
	 *
	 * @return  string
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$this->prepared[] = array(
			'query' => $query,
			'args'  => \array_values( $args ),
		);

		return 'prepared:' . $query;
	}

	/**
	 * Returns the scripted table-existence answer for a table-lookup query.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $query Prepared query token.
	 *
	 * @return  string|null
	 */
	public function get_var( string $query ): ?string {
		$last_prepared = $this->prepared[ \count( $this->prepared ) - 1 ]['args'][0] ?? null;
		if ( ! \is_string( $last_prepared ) ) {
			return null;
		}

		$table = \stripcslashes( $last_prepared );

		/** @var list<string> $missing */
		$missing = $GLOBALS['a8csp_bgje_test_uninstall_missing_tables'] ?? array();
		foreach ( $missing as $missing_suffix ) {
			if ( \str_ends_with( $table, $missing_suffix ) ) {
				return null;
			}
		}

		return $table;
	}

	/**
	 * Returns the stored engine-prefixed option names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $query Prepared query token.
	 *
	 * @return  list<mixed>
	 */
	public function get_col( string $query ): array {
		/** @var array<string, mixed> $options */
		$options = $GLOBALS['a8csp_bgje_test_options'] ?? array();

		return \array_values( \array_filter( \array_keys( $options ), static fn ( string $name ): bool => \str_starts_with( \strtolower( $name ), 'a8csp_bgje_' ) ) );
	}

	// endregion.
}

/**
 * Represents an initialized Action Scheduler runtime for isolated uninstall tests.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class UninstallActionSchedulerStub {
	// region METHODS.

	/**
	 * Reports whether the Action Scheduler data store is initialized.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	public static function is_initialized(): bool {
		return true;
	}

	// endregion.
}

/**
 * Exercises the real uninstall footprint against in-memory option state.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversNothing]
final class UninstallTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array DYNAMIC_OPTIONS   = array(
		'a8csp_bgje_schedule_registrations_consumer-plugin',
		'a8csp_bgje_schedule_registrations_a8csp-bgje',
		'a8csp_bgje_active_run_consumer-plugin:email-digest_00000000001700000000-0000000000000000042',
		'a8csp_bgje_latest_run_consumer-plugin:email-digest',
		'a8csp_bgje_run_history_consumer-plugin:email-digest',
		'a8csp_bgje_run_scratch_consumer-plugin:email-digest_00000000001700000000-0000000000000000042',
		'a8csp_bgje_overlap_lock_consumer-plugin:email-digest_4c1c43efb4ee9ce5c477b82ee52f4938b572d623a0d7c412f1f5e2f116dde7a4',
		'a8csp_bgje_occurrence_lease_4c1c43efb4ee9ce5c477b82ee52f4938b572d623a0d7c412f1f5e2f116dde7a4',
		'a8csp_bgje_cleanup_intent_4c1c43efb4ee9ce5c477b82ee52f4938b572d623a0d7c412f1f5e2f116dde7a4',
		'a8csp_bgje_cleanup_sweep_cursor',
		'a8csp_bgje_failed_runs_consumer-plugin:email-digest',
	);
	private const array DELIVERY_HOOKS    = array(
		'a8csp_bgje/internal/deliver',
		'a8csp_bgje/internal/schedule_due',
	);
	private const array UPDATE_TRANSIENTS = array(
		'a8csp_bgje_github_latest_release_stable',
		'a8csp_bgje_github_latest_release_prerelease',
	);
	private const string BYTE_NEAR_MISS   = 'A8CSP_BGJE_foreign';
	private const string LIKE_NEAR_MISS   = 'a8cspXbgjeYforeign';

	// endregion.

	// region TESTS.

	/**
	 * The explicit diagnostic-removal policy deletes all engine rows and updater transients while
	 * LIKE and byte-prefix near misses survive.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_opt_in_deletes_the_complete_option_footprint(): void {
		require_once __DIR__ . '/wp-options-stubs.php';
		require_once __DIR__ . '/wp-lock-stubs.php';
		require_once __DIR__ . '/wp-cron-stubs.php';
		require_once __DIR__ . '/as-function-stubs.php';
		require_once __DIR__ . '/as-class-stubs.php';

		$options = array_fill_keys( self::DYNAMIC_OPTIONS, 'sentinel' );
		foreach ( self::UPDATE_TRANSIENTS as $transient ) {
			$options[ '_transient_' . $transient ]         = 'sentinel';
			$options[ '_transient_timeout_' . $transient ] = 1_700_003_600;
		}

		$options[ self::BYTE_NEAR_MISS ] = 'sentinel';
		$options[ self::LIKE_NEAR_MISS ] = 'sentinel';

		$GLOBALS['a8csp_bgje_test_options']                = $options;
		$GLOBALS['a8csp_bgje_test_option_calls']           = array();
		$GLOBALS['a8csp_bgje_test_delete_transient_calls'] = array();
		$GLOBALS['a8csp_bgje_test_is_multisite']           = false;
		$GLOBALS['a8csp_bgje_test_blog_id']                = 1;
		$GLOBALS['a8csp_bgje_test_cron_array']             = array();
		$GLOBALS['a8csp_bgje_test_cron_calls']             = array();
		$GLOBALS['a8csp_bgje_test_cron_event_sequence']    = 0;
		$GLOBALS['a8csp_bgje_test_as_calls']               = array();
		$GLOBALS['wpdb']                                   = new UninstallWpdbSpy();

		foreach ( self::DELIVERY_HOOKS as $hook ) {
			\a8csp_bgje_test_store_cron_event( 1_700_000_000, $hook, array( $hook ), false );
		}

		self::assertTrue( \class_alias( UninstallActionSchedulerStub::class, 'ActionScheduler' ) );
		\define( 'A8CSP_BGJE_REMOVE_DIAGNOSTICS_ON_UNINSTALL', true );
		\define( 'WP_UNINSTALL_PLUGIN', true );
		require \dirname( __DIR__, 2 ) . '/uninstall.php';

		$retained = $this->retained_options();
		foreach ( self::DYNAMIC_OPTIONS as $option ) {
			self::assertArrayNotHasKey( $option, $retained );
		}
		foreach ( self::UPDATE_TRANSIENTS as $transient ) {
			self::assertArrayNotHasKey( '_transient_' . $transient, $retained );
			self::assertArrayNotHasKey( '_transient_timeout_' . $transient, $retained );
		}
		self::assertSame( self::UPDATE_TRANSIENTS, $GLOBALS['a8csp_bgje_test_delete_transient_calls'] );
		$option_calls      = $this->option_calls();
		$first_option_call = $option_calls[0] ?? null;
		self::assertIsArray( $first_option_call );
		self::assertSame( array( 'a8csp_bgje_schedule_registrations_consumer-plugin' ), $first_option_call['args'] ?? null );
		self::assertArrayHasKey( self::BYTE_NEAR_MISS, $retained );
		self::assertSame( 'sentinel', $retained[ self::BYTE_NEAR_MISS ] );
		self::assertArrayHasKey( self::LIKE_NEAR_MISS, $retained );
		self::assertSame( 'sentinel', $retained[ self::LIKE_NEAR_MISS ] );
		foreach ( self::DELIVERY_HOOKS as $hook ) {
			self::assertFalse( \wp_next_scheduled( $hook, array( $hook ) ) );
		}
		self::assertSame(
			array(
				array(
					'function' => 'as_unschedule_all_actions',
					'args'     => array( self::DELIVERY_HOOKS[0], array(), '' ),
				),
				array(
					'function' => 'as_unschedule_all_actions',
					'args'     => array( self::DELIVERY_HOOKS[1], array(), '' ),
				),
			),
			$this->action_scheduler_calls(),
			'Uninstall must unschedule both delivery hooks through Action Scheduler'
		);
	}

	/**
	 * Default uninstall preserves diagnostic rows while deleting operational engine rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_preserves_diagnostics_by_default(): void {
		require_once __DIR__ . '/wp-options-stubs.php';
		require_once __DIR__ . '/wp-lock-stubs.php';
		require_once __DIR__ . '/wp-cron-stubs.php';
		require_once __DIR__ . '/as-function-stubs.php';
		require_once __DIR__ . '/as-class-stubs.php';

		$diagnostic_options = array(
			'a8csp_bgje_run_history_consumer-plugin:email-digest' => 'sentinel',
			'a8csp_bgje_failed_runs_consumer-plugin:email-digest' => 'sentinel',
		);

		$GLOBALS['a8csp_bgje_test_options']                = $diagnostic_options + array( 'a8csp_bgje_schedule_registrations_consumer-plugin' => 'sentinel' );
		$GLOBALS['a8csp_bgje_test_option_calls']           = array();
		$GLOBALS['a8csp_bgje_test_delete_transient_calls'] = array();
		$GLOBALS['a8csp_bgje_test_is_multisite']           = false;
		$GLOBALS['a8csp_bgje_test_blog_id']                = 1;
		$GLOBALS['a8csp_bgje_test_cron_array']             = array();
		$GLOBALS['a8csp_bgje_test_cron_calls']             = array();
		$GLOBALS['a8csp_bgje_test_cron_event_sequence']    = 0;
		$GLOBALS['a8csp_bgje_test_as_calls']               = array();
		$GLOBALS['wpdb']                                   = new UninstallWpdbSpy();

		self::assertFalse( \defined( 'A8CSP_BGJE_REMOVE_DIAGNOSTICS_ON_UNINSTALL' ) );
		self::assertTrue( \class_alias( UninstallActionSchedulerStub::class, 'ActionScheduler' ) );
		\define( 'WP_UNINSTALL_PLUGIN', true );
		require \dirname( __DIR__, 2 ) . '/uninstall.php';

		self::assertSame( $diagnostic_options, $GLOBALS['a8csp_bgje_test_options'] );
	}

	/**
	 * A truthy non-boolean diagnostic policy preserves diagnostic rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_preserves_diagnostics_for_truthy_non_boolean_policy(): void {
		require_once __DIR__ . '/wp-options-stubs.php';
		require_once __DIR__ . '/wp-lock-stubs.php';
		require_once __DIR__ . '/wp-cron-stubs.php';
		require_once __DIR__ . '/as-function-stubs.php';
		require_once __DIR__ . '/as-class-stubs.php';

		$diagnostic_options = array(
			'a8csp_bgje_run_history_consumer-plugin:email-digest' => 'sentinel',
			'a8csp_bgje_failed_runs_consumer-plugin:email-digest' => 'sentinel',
		);

		$GLOBALS['a8csp_bgje_test_options']                = $diagnostic_options + array( 'a8csp_bgje_schedule_registrations_consumer-plugin' => 'sentinel' );
		$GLOBALS['a8csp_bgje_test_option_calls']           = array();
		$GLOBALS['a8csp_bgje_test_delete_transient_calls'] = array();
		$GLOBALS['a8csp_bgje_test_is_multisite']           = false;
		$GLOBALS['a8csp_bgje_test_blog_id']                = 1;
		$GLOBALS['a8csp_bgje_test_cron_array']             = array();
		$GLOBALS['a8csp_bgje_test_cron_calls']             = array();
		$GLOBALS['a8csp_bgje_test_cron_event_sequence']    = 0;
		$GLOBALS['a8csp_bgje_test_as_calls']               = array();
		$GLOBALS['wpdb']                                   = new UninstallWpdbSpy();

		self::assertTrue( \class_alias( UninstallActionSchedulerStub::class, 'ActionScheduler' ) );
		\define( 'A8CSP_BGJE_REMOVE_DIAGNOSTICS_ON_UNINSTALL', 1 );
		\define( 'WP_UNINSTALL_PLUGIN', true );
		require \dirname( __DIR__, 2 ) . '/uninstall.php';

		self::assertSame( $diagnostic_options, $GLOBALS['a8csp_bgje_test_options'] );
	}

	/**
	 * A missing Action Scheduler actions table prevents the public cleanup call.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_skips_action_scheduler_cleanup_when_actions_table_is_missing(): void {
		require_once __DIR__ . '/wp-options-stubs.php';
		require_once __DIR__ . '/wp-lock-stubs.php';
		require_once __DIR__ . '/wp-cron-stubs.php';
		require_once __DIR__ . '/as-function-stubs.php';
		require_once __DIR__ . '/as-class-stubs.php';

		$GLOBALS['a8csp_bgje_test_options']                  = array();
		$GLOBALS['a8csp_bgje_test_option_calls']             = array();
		$GLOBALS['a8csp_bgje_test_is_multisite']             = false;
		$GLOBALS['a8csp_bgje_test_blog_id']                  = 1;
		$GLOBALS['a8csp_bgje_test_cron_array']               = array();
		$GLOBALS['a8csp_bgje_test_cron_calls']               = array();
		$GLOBALS['a8csp_bgje_test_cron_event_sequence']      = 0;
		$GLOBALS['a8csp_bgje_test_as_calls']                 = array();
		$GLOBALS['a8csp_bgje_test_uninstall_missing_tables'] = array( 'actionscheduler_actions' );
		$GLOBALS['wpdb']                                     = new UninstallWpdbSpy();

		self::assertTrue( \class_alias( UninstallActionSchedulerStub::class, 'ActionScheduler' ) );
		\define( 'WP_UNINSTALL_PLUGIN', true );
		require \dirname( __DIR__, 2 ) . '/uninstall.php';

		self::assertSame( array(), $this->action_scheduler_calls(), 'A missing actions table must leave the Action Scheduler API untouched' );
	}

	/**
	 * Multisite uninstall visits every site and unschedules work through both scheduler APIs.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_sweeps_each_network_site_and_reclaims_scheduled_work(): void {
		require_once __DIR__ . '/wp-options-stubs.php';
		require_once __DIR__ . '/wp-lock-stubs.php';
		require_once __DIR__ . '/wp-cron-stubs.php';
		require_once __DIR__ . '/as-function-stubs.php';
		require_once __DIR__ . '/as-class-stubs.php';

		$GLOBALS['a8csp_bgje_test_options']            = array( self::DYNAMIC_OPTIONS[0] => 'sentinel' );
		$GLOBALS['a8csp_bgje_test_option_calls']       = array();
		$GLOBALS['a8csp_bgje_test_is_multisite']       = true;
		$GLOBALS['a8csp_bgje_test_site_ids']           = array( 1, 2 );
		$GLOBALS['a8csp_bgje_test_get_sites_calls']    = array();
		$GLOBALS['a8csp_bgje_test_blog_id']            = 1;
		$GLOBALS['a8csp_bgje_test_blog_stack']         = array();
		$GLOBALS['a8csp_bgje_test_blog_switch_calls']  = array();
		$GLOBALS['a8csp_bgje_test_blog_restore_calls'] = array();
		$GLOBALS['a8csp_bgje_test_cron_array']         = array();
		$GLOBALS['a8csp_bgje_test_cron_calls']         = array();
		$GLOBALS['a8csp_bgje_test_cron_site_calls']    = array();
		$GLOBALS['a8csp_bgje_test_as_calls']           = array();
		$GLOBALS['a8csp_bgje_test_as_site_calls']      = array();
		$GLOBALS['wpdb']                               = new UninstallWpdbSpy();

		self::assertTrue( \class_alias( UninstallActionSchedulerStub::class, 'ActionScheduler' ) );
		\define( 'WP_UNINSTALL_PLUGIN', true );
		require \dirname( __DIR__, 2 ) . '/uninstall.php';

		self::assertSame(
			array(
				array(
					'fields' => 'ids',
					'number' => 100,
					'offset' => 0,
				),
			),
			$GLOBALS['a8csp_bgje_test_get_sites_calls']
		);
		self::assertSame( array( 1, 2 ), $GLOBALS['a8csp_bgje_test_blog_switch_calls'] );
		self::assertSame( array( 1, 1 ), $GLOBALS['a8csp_bgje_test_blog_restore_calls'] );
		self::assertSame( 1, $GLOBALS['a8csp_bgje_test_blog_id'] );

		$expected_cron_calls      = array();
		$expected_cron_site_calls = array();
		$expected_as_calls        = array();
		$expected_as_site_calls   = array();
		foreach ( array( 1, 2 ) as $site_id ) {
			foreach ( self::DELIVERY_HOOKS as $hook ) {
				$cron_call = array(
					'function' => 'wp_unschedule_hook',
					'args'     => array( $hook, false ),
				);
				$as_call   = array(
					'function' => 'as_unschedule_all_actions',
					'args'     => array( $hook, array(), '' ),
				);

				$expected_cron_calls[]      = $cron_call;
				$expected_cron_site_calls[] = array(
					'function' => $cron_call['function'],
					'blog_id'  => $site_id,
					'args'     => $cron_call['args'],
				);
				$expected_as_calls[]        = $as_call;
				$expected_as_site_calls[]   = array(
					'function' => $as_call['function'],
					'blog_id'  => $site_id,
					'args'     => $as_call['args'],
				);
			}
		}

		self::assertSame( $expected_cron_calls, $GLOBALS['a8csp_bgje_test_cron_calls'] );
		self::assertSame( $expected_cron_site_calls, $GLOBALS['a8csp_bgje_test_cron_site_calls'] );
		self::assertSame( $expected_as_calls, $this->action_scheduler_calls() );
		self::assertSame( $expected_as_site_calls, $GLOBALS['a8csp_bgje_test_as_site_calls'] );
	}

	/**
	 * Network discovery advances through bounded pages until the final short page.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_pages_network_sites_in_bounded_batches(): void {
		require_once __DIR__ . '/wp-options-stubs.php';
		require_once __DIR__ . '/wp-lock-stubs.php';
		require_once __DIR__ . '/wp-cron-stubs.php';

		$site_ids = \range( 1, 101 );

		$GLOBALS['a8csp_bgje_test_options']                  = array();
		$GLOBALS['a8csp_bgje_test_option_calls']             = array();
		$GLOBALS['a8csp_bgje_test_is_multisite']             = true;
		$GLOBALS['a8csp_bgje_test_site_ids']                 = $site_ids;
		$GLOBALS['a8csp_bgje_test_get_sites_calls']          = array();
		$GLOBALS['a8csp_bgje_test_blog_id']                  = 1;
		$GLOBALS['a8csp_bgje_test_blog_stack']               = array();
		$GLOBALS['a8csp_bgje_test_blog_switch_calls']        = array();
		$GLOBALS['a8csp_bgje_test_blog_restore_calls']       = array();
		$GLOBALS['a8csp_bgje_test_cron_array']               = array();
		$GLOBALS['a8csp_bgje_test_cron_calls']               = array();
		$GLOBALS['a8csp_bgje_test_uninstall_missing_tables'] = array( 'actionscheduler_actions' );
		$GLOBALS['wpdb']                                     = new UninstallWpdbSpy();

		\define( 'WP_UNINSTALL_PLUGIN', true );
		require \dirname( __DIR__, 2 ) . '/uninstall.php';

		self::assertSame(
			array(
				array(
					'fields' => 'ids',
					'number' => 100,
					'offset' => 0,
				),
				array(
					'fields' => 'ids',
					'number' => 100,
					'offset' => 100,
				),
			),
			$GLOBALS['a8csp_bgje_test_get_sites_calls']
		);
		self::assertSame( $site_ids, $GLOBALS['a8csp_bgje_test_blog_switch_calls'] );
		self::assertSame( \array_fill( 0, 101, 1 ), $GLOBALS['a8csp_bgje_test_blog_restore_calls'] );
		self::assertSame( 1, $GLOBALS['a8csp_bgje_test_blog_id'] );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns the surviving option ledger after verifying its runtime representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>
	 */
	private function retained_options(): array {
		$options = $GLOBALS['a8csp_bgje_test_options'] ?? null;
		self::assertIsArray( $options );

		return $options;
	}

	/**
	 * Returns the option-call ledger after verifying its runtime representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>
	 */
	private function option_calls(): array {
		$calls = $GLOBALS['a8csp_bgje_test_option_calls'] ?? null;
		self::assertIsArray( $calls );

		return $calls;
	}

	/**
	 * Returns the Action Scheduler call ledger after verifying its runtime representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>
	 */
	private function action_scheduler_calls(): array {
		$calls = $GLOBALS['a8csp_bgje_test_as_calls'] ?? null;
		self::assertIsArray( $calls );

		return $calls;
	}

	// endregion.
}

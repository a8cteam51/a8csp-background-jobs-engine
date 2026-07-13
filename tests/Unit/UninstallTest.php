<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Records the cold-uninstall option-prefix query without requiring WordPress.
 */
final class UninstallWpdbSpy {
	// region FIELDS AND CONSTANTS.

	/** Options table name. */
	public string $options = 'wp_options';

	/** @var list<array{query: string, args: list<mixed>}> Prepared queries. */
	public array $prepared = array();

	/** @var list<string> Executed column queries. */
	public array $column_queries = array();

	// endregion.

	// region METHODS.

	/**
	 * Escapes SQL LIKE wildcards in one literal prefix.
	 *
	 * @param   string $text Prefix to escape.
	 *
	 * @return  string
	 */
	public function esc_like( string $text ): string {
		return \addcslashes( $text, '_%\\' );
	}

	/**
	 * Records a prepared query and returns an opaque query token.
	 *
	 * @param   string $query Query template.
	 * @param   mixed  ...$args Prepared arguments.
	 *
	 * @return  string
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$this->prepared[] = array(
			'query' => $query,
			'args'  => \array_values( $args ),
		);

		return 'prepared-option-prefix-query';
	}

	/**
	 * Returns the currently stored option names owned by the engine prefix.
	 *
	 * @param   string $query Prepared query token.
	 *
	 * @return  list<string>
	 */
	public function get_col( string $query ): array {
		$this->column_queries[] = $query;

		/** @var array<string, mixed> $options */
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? array();

		return \array_values(
			\array_filter(
				\array_keys( $options ),
				static fn ( string $name ): bool => \str_starts_with( $name, 'a8csp_bgte_' )
			)
		);
	}

	// endregion.
}

/**
 * Exercises the real cold-bootstrap uninstall footprint against in-memory option state.
 *
 */
#[CoversNothing]
final class UninstallTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const DYNAMIC_OPTIONS = array(
		'a8csp_bgte_run_email-digest_run-1',
		'a8csp_bgte_latest_email-digest',
		'a8csp_bgte_history_email-digest',
		'a8csp_bgte_lock_email-digest_args-hash',
		'a8csp_bgte_lease_4c1c43efb4ee9ce5c477b82ee52f4938b572d623a0d7c412f1f5e2f116dde7a4',
		'a8csp_bgte_failed_email-digest',
	);
	private const FIXED_OPTIONS   = array(
		'a8csp_bgte_schedules',
	);
	private const LIFECYCLE_HOOKS = array(
		'a8csp/background_tasks/start',
		'a8csp/background_tasks/continue',
		'a8csp/background_tasks/run',
		'a8csp/background_tasks/cleanup',
		'a8csp/background_tasks/schedule_due',
	);
	private const NEAR_MISS       = 'a8cspXbgteYforeign';

	// endregion.

	// region TESTS.

	/**
	 * Dynamically named rows are removed while an escaped-LIKE near miss survives.
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_deletes_the_complete_option_footprint(): void {
		require_once __DIR__ . '/wp-options-stubs.php';
		require_once __DIR__ . '/wp-lock-stubs.php';
		require_once __DIR__ . '/wp-cron-stubs.php';

		$options = array_fill_keys( array_merge( self::DYNAMIC_OPTIONS, self::FIXED_OPTIONS ), 'sentinel' );

		$options[ self::NEAR_MISS ] = 'sentinel';

		$GLOBALS['a8csp_bgte_test_options']             = $options;
		$GLOBALS['a8csp_bgte_test_option_calls']        = array();
		$GLOBALS['a8csp_bgte_test_is_multisite']        = false;
		$GLOBALS['a8csp_bgte_test_blog_id']             = 1;
		$GLOBALS['a8csp_bgte_test_cron_array']          = array();
		$GLOBALS['a8csp_bgte_test_cron_calls']          = array();
		$GLOBALS['a8csp_bgte_test_cron_event_sequence'] = 0;
		$GLOBALS['wpdb']                                = new UninstallWpdbSpy();

		foreach ( self::LIFECYCLE_HOOKS as $hook ) {
			\a8csp_bgte_test_store_cron_event( 1_700_000_000, $hook, array( $hook ), false );
		}

		\define( 'WP_UNINSTALL_PLUGIN', true );
		require \dirname( __DIR__, 2 ) . '/uninstall.php';

		foreach ( self::DYNAMIC_OPTIONS as $option ) {
			self::assertArrayNotHasKey( $option, $GLOBALS['a8csp_bgte_test_options'] );
		}
		foreach ( self::FIXED_OPTIONS as $option ) {
			self::assertArrayNotHasKey( $option, $GLOBALS['a8csp_bgte_test_options'] );
		}
		$option_calls      = $this->option_calls();
		$first_option_call = $option_calls[0] ?? null;
		self::assertIsArray( $first_option_call );
		self::assertSame(
			array( 'a8csp_bgte_schedules' ),
			$first_option_call['args'] ?? null
		);
		self::assertArrayHasKey( self::NEAR_MISS, $GLOBALS['a8csp_bgte_test_options'] );
		self::assertSame( 'sentinel', $GLOBALS['a8csp_bgte_test_options'][ self::NEAR_MISS ] );
		foreach ( self::LIFECYCLE_HOOKS as $hook ) {
			self::assertFalse( \wp_next_scheduled( $hook, array( $hook ) ) );
		}

		$wpdb = $GLOBALS['wpdb'];
		self::assertInstanceOf( UninstallWpdbSpy::class, $wpdb );
		self::assertSame(
			array(
				array(
					'query' => 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s',
					'args'  => array( 'wp_options', 'a8csp\\_bgte\\_%' ),
				),
			),
			$wpdb->prepared
		);
		self::assertSame( array( 'prepared-option-prefix-query' ), $wpdb->column_queries );
	}

	/**
	 * Multisite uninstall visits every site and reclaims both scheduler stores in each context.
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

		$GLOBALS['a8csp_bgte_test_options']            = array( self::DYNAMIC_OPTIONS[0] => 'sentinel' );
		$GLOBALS['a8csp_bgte_test_option_calls']       = array();
		$GLOBALS['a8csp_bgte_test_is_multisite']       = true;
		$GLOBALS['a8csp_bgte_test_site_ids']           = array( 1, 2 );
		$GLOBALS['a8csp_bgte_test_get_sites_calls']    = array();
		$GLOBALS['a8csp_bgte_test_blog_id']            = 1;
		$GLOBALS['a8csp_bgte_test_blog_stack']         = array();
		$GLOBALS['a8csp_bgte_test_blog_switch_calls']  = array();
		$GLOBALS['a8csp_bgte_test_blog_restore_calls'] = array();
		$GLOBALS['a8csp_bgte_test_cron_array']         = array();
		$GLOBALS['a8csp_bgte_test_cron_calls']         = array();
		$GLOBALS['a8csp_bgte_test_cron_site_calls']    = array();
		$GLOBALS['a8csp_bgte_test_as_calls']           = array();
		$GLOBALS['a8csp_bgte_test_as_site_calls']      = array();
		$GLOBALS['wpdb']                               = new UninstallWpdbSpy();

		\define( 'WP_UNINSTALL_PLUGIN', true );
		require \dirname( __DIR__, 2 ) . '/uninstall.php';

		self::assertSame(
			array(
				array(
					'fields' => 'ids',
					'number' => 0,
				),
			),
			$GLOBALS['a8csp_bgte_test_get_sites_calls']
		);
		self::assertSame( array( 1, 2 ), $GLOBALS['a8csp_bgte_test_blog_switch_calls'] );
		self::assertSame( array( 1, 1 ), $GLOBALS['a8csp_bgte_test_blog_restore_calls'] );
		self::assertSame( 1, $GLOBALS['a8csp_bgte_test_blog_id'] );

		$expected_cron_calls      = array();
		$expected_as_calls        = array();
		$expected_cron_site_calls = array();
		$expected_as_site_calls   = array();
		foreach ( array( 1, 2 ) as $site_id ) {
			foreach ( self::LIFECYCLE_HOOKS as $hook ) {
				$cron_call = array(
					'function' => 'wp_unschedule_hook',
					'args'     => array( $hook, false ),
				);
				$as_call   = array(
					'function' => 'as_unschedule_all_actions',
					'args'     => array( $hook, array(), '' ),
				);

				$expected_cron_calls[]      = $cron_call;
				$expected_as_calls[]        = $as_call;
				$expected_cron_site_calls[] = array(
					'function' => $cron_call['function'],
					'blog_id'  => $site_id,
					'args'     => $cron_call['args'],
				);
				$expected_as_site_calls[]   = array(
					'function' => $as_call['function'],
					'blog_id'  => $site_id,
					'args'     => $as_call['args'],
				);
			}
		}

		self::assertSame( $expected_cron_calls, $GLOBALS['a8csp_bgte_test_cron_calls'] );
		self::assertSame( $expected_as_calls, $GLOBALS['a8csp_bgte_test_as_calls'] );
		self::assertSame( $expected_cron_site_calls, $GLOBALS['a8csp_bgte_test_cron_site_calls'] );
		self::assertSame( $expected_as_site_calls, $GLOBALS['a8csp_bgte_test_as_site_calls'] );

		$wpdb = $GLOBALS['wpdb'];
		self::assertInstanceOf( UninstallWpdbSpy::class, $wpdb );
		self::assertSame(
			array(
				array(
					'query' => 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s',
					'args'  => array( 'wp_options', 'a8csp\\_bgte\\_%' ),
				),
				array(
					'query' => 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s',
					'args'  => array( 'wp_2_options', 'a8csp\\_bgte\\_%' ),
				),
			),
			$wpdb->prepared
		);
		self::assertSame(
			array( 'prepared-option-prefix-query', 'prepared-option-prefix-query' ),
			$wpdb->column_queries
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns the option-call ledger after verifying its runtime representation.
	 *
	 * @return  array<array-key, mixed>
	 */
	private function option_calls(): array {
		$calls = $GLOBALS['a8csp_bgte_test_option_calls'] ?? null;
		self::assertIsArray( $calls );

		return $calls;
	}

	// endregion.
}

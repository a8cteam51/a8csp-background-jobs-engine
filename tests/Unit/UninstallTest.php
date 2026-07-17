<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Records the cold-uninstall option-prefix and Action Scheduler queries without requiring WordPress.
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

	/** @var list<string> Executed column queries. */
	public array $column_queries = array();

	/** @var list<string> Executed single-value queries. */
	public array $var_queries = array();

	/** @var list<string> Executed write queries. */
	public array $write_queries = array();

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
		$this->var_queries[] = $query;

		$last_prepared = $this->prepared[ \count( $this->prepared ) - 1 ]['args'][0] ?? null;
		if ( ! \is_string( $last_prepared ) ) {
			return null;
		}

		$table = \stripcslashes( $last_prepared );

		/** @var list<string> $missing */
		$missing = $GLOBALS['a8csp_bgte_test_uninstall_missing_tables'] ?? array();
		foreach ( $missing as $missing_suffix ) {
			if ( \str_ends_with( $table, $missing_suffix ) ) {
				return null;
			}
		}

		return $table;
	}

	/**
	 * Records one write query and reports the scripted affected-row count.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $query Prepared query token.
	 *
	 * @return  int|false
	 */
	public function query( string $query ): int|false {
		$this->write_queries[] = $query;

		$result = $GLOBALS['a8csp_bgte_test_uninstall_query_result'] ?? 0;
		if ( ! \is_int( $result ) && false !== $result ) {
			throw new \UnexpectedValueException( 'Script the uninstall write result as an integer or false.' );
		}

		return $result;
	}

	/**
	 * Returns scripted group identifiers or the stored engine-prefixed option names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $query Prepared query token.
	 *
	 * @return  list<mixed>
	 */
	public function get_col( string $query ): array {
		$this->column_queries[] = $query;

		if ( \str_contains( $query, 'claim_id' ) ) {
			/** @var list<mixed> $claim_ids */
			$claim_ids = $GLOBALS['a8csp_bgte_test_uninstall_claim_ids'] ?? array();

			return $claim_ids;
		}

		if ( \str_contains( $query, 'group_id' ) ) {
			/** @var list<mixed> $group_ids */
			$group_ids = $GLOBALS['a8csp_bgte_test_uninstall_group_ids'] ?? array();

			return $group_ids;
		}

		/** @var array<string, mixed> $options */
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? array();

		return \array_values( \array_filter( \array_keys( $options ), static fn ( string $name ): bool => \str_starts_with( $name, 'a8csp_bgte_' ) ) );
	}

	// endregion.
}

/**
 * Exercises the real cold-bootstrap uninstall footprint against in-memory option state.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversNothing]
final class UninstallTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const DYNAMIC_OPTIONS = array(
		'a8csp_bgte_schedule_registrations_consumer-plugin',
		'a8csp_bgte_schedule_registrations_a8csp-bgte',
		'a8csp_bgte_run_consumer-plugin:email-digest_00000000001700000000-0000000000000000042',
		'a8csp_bgte_latest_run_consumer-plugin:email-digest',
		'a8csp_bgte_run_history_consumer-plugin:email-digest',
		'a8csp_bgte_overlap_lock_consumer-plugin:email-digest_4c1c43efb4ee9ce5c477b82ee52f4938b572d623a0d7c412f1f5e2f116dde7a4',
		'a8csp_bgte_occurrence_lease_4c1c43efb4ee9ce5c477b82ee52f4938b572d623a0d7c412f1f5e2f116dde7a4',
		'a8csp_bgte_cleanup_intent_4c1c43efb4ee9ce5c477b82ee52f4938b572d623a0d7c412f1f5e2f116dde7a4',
		'a8csp_bgte_failed_runs_consumer-plugin:email-digest',
	);
	private const LIFECYCLE_HOOKS = array(
		'a8csp_background_tasks/start_batch',
		'a8csp_background_tasks/continue_batch',
		'a8csp_background_tasks/run_task',
		'a8csp_background_tasks/run_chunk',
		'a8csp_background_tasks/cleanup_batch',
		'a8csp_background_tasks/schedule_due',
	);
	private const NEAR_MISS       = 'a8cspXbgteYforeign';

	// endregion.

	// region TESTS.

	/**
	 * Dynamically named rows are removed while an escaped-LIKE near miss survives.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_deletes_the_complete_option_footprint(): void {
		require_once __DIR__ . '/wp-options-stubs.php';
		require_once __DIR__ . '/wp-lock-stubs.php';
		require_once __DIR__ . '/wp-cron-stubs.php';

		$options = array_fill_keys( self::DYNAMIC_OPTIONS, 'sentinel' );

		$options[ self::NEAR_MISS ] = 'sentinel';

		$GLOBALS['a8csp_bgte_test_options']             = $options;
		$GLOBALS['a8csp_bgte_test_option_calls']        = array();
		$GLOBALS['a8csp_bgte_test_is_multisite']        = false;
		$GLOBALS['a8csp_bgte_test_blog_id']             = 1;
		$GLOBALS['a8csp_bgte_test_cron_array']          = array();
		$GLOBALS['a8csp_bgte_test_cron_calls']          = array();
		$GLOBALS['a8csp_bgte_test_cron_event_sequence'] = 0;
		$GLOBALS['a8csp_bgte_test_uninstall_claim_ids'] = array( '11' );
		$GLOBALS['a8csp_bgte_test_uninstall_group_ids'] = array( '7' );
		$GLOBALS['wpdb']                                = new UninstallWpdbSpy();

		foreach ( self::LIFECYCLE_HOOKS as $hook ) {
			\a8csp_bgte_test_store_cron_event( 1_700_000_000, $hook, array( $hook ), false );
		}

		\define( 'WP_UNINSTALL_PLUGIN', true );
		require \dirname( __DIR__, 2 ) . '/uninstall.php';

		foreach ( self::DYNAMIC_OPTIONS as $option ) {
			self::assertArrayNotHasKey( $option, $GLOBALS['a8csp_bgte_test_options'] );
		}
		$option_calls      = $this->option_calls();
		$first_option_call = $option_calls[0] ?? null;
		self::assertIsArray( $first_option_call );
		self::assertSame( array( 'a8csp_bgte_schedule_registrations_consumer-plugin' ), $first_option_call['args'] ?? null );
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
			self::prepared_matching( $wpdb, 'option_name' )
		);

		self::assertCount( 4, $wpdb->write_queries, 'Cold cleanup must delete logs, actions, orphaned claims, and orphaned groups' );
		self::assertStringContainsString( '`action_id` IN', $wpdb->write_queries[0] );
		self::assertStringContainsString( '`hook` IN', $wpdb->write_queries[1] );
		self::assertStringContainsString( '`claim_id` IN', $wpdb->write_queries[2] );
		self::assertStringContainsString( '`group_id` IN', $wpdb->write_queries[3] );
		self::assertSame(
			array(
				array(
					'query' => 'DELETE FROM %i WHERE `hook` IN (%s, %s, %s, %s, %s, %s)',
					'args'  => array( \array_merge( array( 'wp_actionscheduler_actions' ), self::LIFECYCLE_HOOKS ) ),
				),
			),
			self::prepared_matching( $wpdb, 'DELETE FROM %i WHERE `hook` IN' ),
			'The action delete must be scoped to exactly the six engine hooks'
		);
		self::assertSame(
			array(
				array(
					'query' => 'DELETE FROM %i WHERE `claim_id` IN (%d) AND `claim_id` NOT IN (SELECT `claim_id` FROM %i)',
					'args'  => array( array( 'wp_actionscheduler_claims', 11, 'wp_actionscheduler_actions' ) ),
				),
			),
			self::prepared_matching( $wpdb, 'DELETE FROM %i WHERE `claim_id` IN' ),
			'Claim deletion must stay scoped to engine claim IDs and preserve claims referenced by surviving actions'
		);
		self::assertStringContainsString( 'NOT IN (SELECT `group_id` FROM %i)', $wpdb->write_queries[3], 'Group deletion must keep any group still referenced by surviving actions' );
	}

	/**
	 * A missing Action Scheduler table leaves every store row untouched.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_skips_action_store_cleanup_when_a_table_is_missing(): void {
		require_once __DIR__ . '/wp-options-stubs.php';
		require_once __DIR__ . '/wp-lock-stubs.php';
		require_once __DIR__ . '/wp-cron-stubs.php';

		$GLOBALS['a8csp_bgte_test_options']                  = array();
		$GLOBALS['a8csp_bgte_test_option_calls']             = array();
		$GLOBALS['a8csp_bgte_test_is_multisite']             = false;
		$GLOBALS['a8csp_bgte_test_blog_id']                  = 1;
		$GLOBALS['a8csp_bgte_test_cron_array']               = array();
		$GLOBALS['a8csp_bgte_test_cron_calls']               = array();
		$GLOBALS['a8csp_bgte_test_cron_event_sequence']      = 0;
		$GLOBALS['a8csp_bgte_test_uninstall_claim_ids']      = array( '11' );
		$GLOBALS['a8csp_bgte_test_uninstall_group_ids']      = array( '7' );
		$GLOBALS['a8csp_bgte_test_uninstall_missing_tables'] = array( 'actionscheduler_groups' );
		$GLOBALS['wpdb']                                     = new UninstallWpdbSpy();

		\define( 'WP_UNINSTALL_PLUGIN', true );
		require \dirname( __DIR__, 2 ) . '/uninstall.php';

		$wpdb = $GLOBALS['wpdb'];
		self::assertInstanceOf( UninstallWpdbSpy::class, $wpdb );
		self::assertSame( array(), $wpdb->write_queries, 'An incomplete Action Scheduler schema must leave every store row untouched' );
	}

	/**
	 * Multisite uninstall visits every site and reclaims both scheduler stores in each context.
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

		$GLOBALS['a8csp_bgte_test_options']             = array( self::DYNAMIC_OPTIONS[0] => 'sentinel' );
		$GLOBALS['a8csp_bgte_test_option_calls']        = array();
		$GLOBALS['a8csp_bgte_test_is_multisite']        = true;
		$GLOBALS['a8csp_bgte_test_site_ids']            = array( 1, 2 );
		$GLOBALS['a8csp_bgte_test_get_sites_calls']     = array();
		$GLOBALS['a8csp_bgte_test_blog_id']             = 1;
		$GLOBALS['a8csp_bgte_test_blog_stack']          = array();
		$GLOBALS['a8csp_bgte_test_blog_switch_calls']   = array();
		$GLOBALS['a8csp_bgte_test_blog_restore_calls']  = array();
		$GLOBALS['a8csp_bgte_test_cron_array']          = array();
		$GLOBALS['a8csp_bgte_test_cron_calls']          = array();
		$GLOBALS['a8csp_bgte_test_cron_site_calls']     = array();
		$GLOBALS['a8csp_bgte_test_uninstall_claim_ids'] = array( '11' );
		$GLOBALS['a8csp_bgte_test_uninstall_group_ids'] = array( '7' );
		$GLOBALS['wpdb']                                = new UninstallWpdbSpy();

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
		$expected_cron_site_calls = array();
		foreach ( array( 1, 2 ) as $site_id ) {
			foreach ( self::LIFECYCLE_HOOKS as $hook ) {
				$cron_call = array(
					'function' => 'wp_unschedule_hook',
					'args'     => array( $hook, false ),
				);

				$expected_cron_calls[]      = $cron_call;
				$expected_cron_site_calls[] = array(
					'function' => $cron_call['function'],
					'blog_id'  => $site_id,
					'args'     => $cron_call['args'],
				);
			}
		}

		self::assertSame( $expected_cron_calls, $GLOBALS['a8csp_bgte_test_cron_calls'] );
		self::assertSame( $expected_cron_site_calls, $GLOBALS['a8csp_bgte_test_cron_site_calls'] );

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
			self::prepared_matching( $wpdb, 'option_name' )
		);

		self::assertCount( 8, $wpdb->write_queries, 'Cold cleanup must run its four deletes on every network site' );
		self::assertSame(
			array(
				array(
					'query' => 'DELETE FROM %i WHERE `claim_id` IN (%d) AND `claim_id` NOT IN (SELECT `claim_id` FROM %i)',
					'args'  => array( array( 'wp_actionscheduler_claims', 11, 'wp_actionscheduler_actions' ) ),
				),
				array(
					'query' => 'DELETE FROM %i WHERE `claim_id` IN (%d) AND `claim_id` NOT IN (SELECT `claim_id` FROM %i)',
					'args'  => array( array( 'wp_2_actionscheduler_claims', 11, 'wp_2_actionscheduler_actions' ) ),
				),
			),
			self::prepared_matching( $wpdb, 'DELETE FROM %i WHERE `claim_id` IN' ),
			'Each site must delete only orphaned engine claims from its own site-prefixed store'
		);
		self::assertSame(
			array(
				array(
					'query' => 'DELETE FROM %i WHERE `hook` IN (%s, %s, %s, %s, %s, %s)',
					'args'  => array( \array_merge( array( 'wp_actionscheduler_actions' ), self::LIFECYCLE_HOOKS ) ),
				),
				array(
					'query' => 'DELETE FROM %i WHERE `hook` IN (%s, %s, %s, %s, %s, %s)',
					'args'  => array( \array_merge( array( 'wp_2_actionscheduler_actions' ), self::LIFECYCLE_HOOKS ) ),
				),
			),
			self::prepared_matching( $wpdb, 'DELETE FROM %i WHERE `hook` IN' ),
			'Each site must delete engine actions from its own site-prefixed store'
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns the option-call ledger after verifying its runtime representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>
	 */
	private function option_calls(): array {
		$calls = $GLOBALS['a8csp_bgte_test_option_calls'] ?? null;
		self::assertIsArray( $calls );

		return $calls;
	}

	/**
	 * Returns the recorded prepared queries whose template contains a marker.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   UninstallWpdbSpy $wpdb   Recording connection.
	 * @param   string           $marker Template substring selecting one query family.
	 *
	 * @return  list<array{query: string, args: list<mixed>}>
	 */
	private static function prepared_matching( UninstallWpdbSpy $wpdb, string $marker ): array {
		return \array_values( \array_filter( $wpdb->prepared, static fn ( array $entry ): bool => \str_contains( $entry['query'], $marker ) ) );
	}

	// endregion.
}

<?php declare( strict_types=1 );
/**
 * Uninstall handler. WordPress runs this file directly when the plugin is deleted. The plugin's
 * entry point, Composer autoloader, and plugin classes are not loaded, so the plugin's footprint
 * stays inline below instead of living in a separately-requirable file: nothing here may reference
 * plugin code.
 *
 * @since       1.0.0
 * @version     1.0.0
 * @package     A8C\SpecialProjects\BackgroundTasksEngine
 */

\defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * The plugin's persisted footprint. Every fixed option and user-meta key any component writes
 * is listed here, in the same change that introduces the write — grouped by owning component
 * so ownership stays reviewable. Runtime-suffixed option families use the prefix sweep below.
 */
$a8csp_bgte_footprint = array(
	'options'   => array(
		'a8csp_bgte_schedules',
	),
	'user_meta' => array(),
);

$a8csp_bgte_lifecycle_hooks = array(
	'a8csp_background_tasks/start',
	'a8csp_background_tasks/continue',
	'a8csp_background_tasks/run_task',
	'a8csp_background_tasks/run_chunk',
	'a8csp_background_tasks/cleanup',
	'a8csp_background_tasks/schedule_due',
);

/*
 * Run, latest-pointer, history, execution-lock, occurrence-lease, and failed-run option names
 * end in task, batch, run, registration-hash, or argument-hash identifiers that do not exist
 * until runtime, so no static list can name every row. The shared prefix is the complete ownership
 * boundary for standalone engine options. Escaping it before appending the wildcard keeps each
 * underscore literal instead of letting SQL LIKE broaden the sweep to similarly spelled foreign
 * options.
 *
 * Selecting the names directly is intentional in this cold bootstrap: delete_option() still
 * performs each deletion so WordPress preserves its normal cache invalidation and hooks. The
 * complete per-site cleanup stays in one closure so the single-site and network paths cannot
 * drift apart.
 */
$a8csp_bgte_uninstall_site = static function () use ( $a8csp_bgte_footprint, $a8csp_bgte_lifecycle_hooks ): void {
	foreach ( $a8csp_bgte_footprint['options'] as $a8csp_bgte_uninstall_option ) {
		delete_option( $a8csp_bgte_uninstall_option );
	}

	global $wpdb;

	/**
	 * WordPress database connection for the current site.
	 *
	 * @var wpdb $wpdb
	 */
	$a8csp_bgte_option_names = $wpdb->get_col( $wpdb->prepare( 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s', $wpdb->options, $wpdb->esc_like( 'a8csp_bgte_' ) . '%' ) );

	foreach ( $a8csp_bgte_option_names as $a8csp_bgte_option_name ) {
		if ( \is_string( $a8csp_bgte_option_name ) ) {
			delete_option( $a8csp_bgte_option_name );
		}
	}

	/*
	 * Both scheduler stores are per site. Action Scheduler's public unschedule function requires
	 * an initialized runtime and only cancels pending rows, while uninstall runs cold, so direct
	 * custom-table deletes cover every engine action status and its logs. Requiring the complete
	 * four-table schema keeps incomplete or migrated stores untouched.
	 */
	foreach ( $a8csp_bgte_lifecycle_hooks as $a8csp_bgte_lifecycle_hook ) {
		wp_unschedule_hook( $a8csp_bgte_lifecycle_hook );
	}

	$a8csp_bgte_action_scheduler_tables = array();
	foreach (
		array(
			'actionscheduler_logs',
			'actionscheduler_claims',
			'actionscheduler_actions',
			'actionscheduler_groups',
		) as $a8csp_bgte_action_scheduler_table_suffix
	) {
		$a8csp_bgte_action_scheduler_table = $wpdb->prefix . $a8csp_bgte_action_scheduler_table_suffix;
		$a8csp_bgte_installed_table        = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $a8csp_bgte_action_scheduler_table ) ) );
		if ( $a8csp_bgte_action_scheduler_table !== $a8csp_bgte_installed_table ) {
			return;
		}

		$a8csp_bgte_action_scheduler_tables[ $a8csp_bgte_action_scheduler_table_suffix ] = $a8csp_bgte_action_scheduler_table;
	}

	$a8csp_bgte_hook_placeholders = \implode( ', ', \array_fill( 0, \count( $a8csp_bgte_lifecycle_hooks ), '%s' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN-list placeholders are array_fill()-built literals; every value still binds through prepare().

	/*
	 * Candidate group IDs must be captured before their matching actions disappear. The final
	 * unreferenced check keeps groups shared with surviving foreign actions structurally out of scope.
	 */
	$a8csp_bgte_group_id_rows = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT `group_id` FROM %i WHERE `hook` IN (' . $a8csp_bgte_hook_placeholders . ')', \array_merge( array( $a8csp_bgte_action_scheduler_tables['actionscheduler_actions'] ), $a8csp_bgte_lifecycle_hooks ) ) );
	$a8csp_bgte_group_ids     = array();
	foreach ( $a8csp_bgte_group_id_rows as $a8csp_bgte_group_id ) {
		$a8csp_bgte_group_id = (int) $a8csp_bgte_group_id;
		if ( 0 < $a8csp_bgte_group_id ) {
			$a8csp_bgte_group_ids[] = $a8csp_bgte_group_id;
		}
	}

	// Every query below binds matching literal placeholders and arguments, so prepare() cannot
	// return null here; the scoped ignores skip unreachable narrowing instead of faking a handler.
	$a8csp_bgte_log_delete_query = $wpdb->prepare(
		'DELETE FROM %i WHERE `action_id` IN (' .
			'SELECT `action_id` FROM %i WHERE `hook` IN (' . $a8csp_bgte_hook_placeholders . ')' .
			')',
		\array_merge(
			array(
				$a8csp_bgte_action_scheduler_tables['actionscheduler_logs'],
				$a8csp_bgte_action_scheduler_tables['actionscheduler_actions'],
			),
			$a8csp_bgte_lifecycle_hooks
		)
	);
	if ( false === $wpdb->query( $a8csp_bgte_log_delete_query ) ) { // @phpstan-ignore argument.type
		return;
	}

	$a8csp_bgte_action_delete_query = $wpdb->prepare( 'DELETE FROM %i WHERE `hook` IN (' . $a8csp_bgte_hook_placeholders . ')', \array_merge( array( $a8csp_bgte_action_scheduler_tables['actionscheduler_actions'] ), $a8csp_bgte_lifecycle_hooks ) );
	if ( false === $wpdb->query( $a8csp_bgte_action_delete_query ) || array() === $a8csp_bgte_group_ids ) { // @phpstan-ignore argument.type
		return;
	}

	$a8csp_bgte_group_placeholders = \implode( ', ', \array_fill( 0, \count( $a8csp_bgte_group_ids ), '%d' ) );
	$a8csp_bgte_group_delete_query = $wpdb->prepare(
		'DELETE FROM %i WHERE `group_id` IN (' . $a8csp_bgte_group_placeholders . ') ' .
			'AND `group_id` NOT IN (SELECT `group_id` FROM %i)',
		\array_merge(
			array(
				$a8csp_bgte_action_scheduler_tables['actionscheduler_groups'],
			),
			$a8csp_bgte_group_ids,
			array( $a8csp_bgte_action_scheduler_tables['actionscheduler_actions'] )
		)
	);
	$wpdb->query( $a8csp_bgte_group_delete_query ); // @phpstan-ignore argument.type
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
};

/*
 * Options, WP-Cron events, and Action Scheduler actions are stored per site, while WordPress runs
 * a multisite uninstall only once for the network. `number => 0` removes get_sites()'s default
 * limit so every site's engine footprint is visited.
 */
if ( is_multisite() ) {
	$a8csp_bgte_uninstall_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $a8csp_bgte_uninstall_site_ids as $a8csp_bgte_uninstall_site_id ) {
		switch_to_blog( $a8csp_bgte_uninstall_site_id );
		$a8csp_bgte_uninstall_site();
		restore_current_blog();
	}
} else {
	$a8csp_bgte_uninstall_site();
}

// User meta is stored network-globally, so one pass covers every site.
foreach ( $a8csp_bgte_footprint['user_meta'] as $a8csp_bgte_uninstall_meta_key ) {
	delete_metadata( 'user', 0, $a8csp_bgte_uninstall_meta_key, '', true );
}

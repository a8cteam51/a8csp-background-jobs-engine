<?php declare( strict_types=1 );
/**
 * Uninstall handler. WordPress runs this file directly when the plugin is deleted. The plugin's
 * entry point, Composer autoloader, and plugin classes are not loaded, so the plugin's footprint
 * stays inline below instead of living in a separately-requirable file: nothing here may reference
 * plugin code.
 *
 * @since       1.0.0
 * @version     1.0.0
 * @package     A8C\SpecialProjects\BackgroundJobsEngine
 */

\defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * The plugin's persisted footprint. Fixed option and user-meta keys outside the reserved-prefix
 * option sweep are listed here. Options within the a8csp_bgje_ ownership boundary use the sweep
 * below.
 */
$a8csp_bgje_footprint = array(
	'options'   => array(),
	'user_meta' => array(),
);

$a8csp_bgje_lifecycle_hooks = array(
	'a8csp_jobs_engine/start_chunked_job',
	'a8csp_jobs_engine/continue_chunked_job',
	'a8csp_jobs_engine/run_job',
	'a8csp_jobs_engine/run_chunk',
	'a8csp_jobs_engine/cleanup_chunked_job',
	'a8csp_jobs_engine/schedule_due',
);

/*
 * Schedule-registration, active-run, failed-run, latest-run, run-history, overlap-lock,
 * occurrence-lease, and cleanup-intent option names end in owner, job, chunked job, run,
 * registration-hash, or argument-hash identifiers that do not exist until runtime. The shared
 * prefix is the complete ownership boundary for standalone engine options. Escaping it before
 * appending the wildcard keeps each underscore literal instead of letting SQL LIKE broaden the
 * sweep to similarly spelled foreign options. The deletion loop repeats the byte-exact prefix
 * check because the option-name column collation may admit case-distinct candidates.
 *
 * Selecting the names directly is intentional in this cold bootstrap: delete_option() still
 * performs each deletion so WordPress preserves its normal cache invalidation and hooks. The
 * complete per-site cleanup stays in one closure so the single-site and network paths cannot
 * drift apart.
 */
$a8csp_bgje_uninstall_site = static function () use ( $a8csp_bgje_lifecycle_hooks ): void {
	global $wpdb;

	/**
	 * WordPress database connection for the current site.
	 *
	 * @var wpdb $wpdb
	 */
	delete_transient( 'a8csp_bgje_github_latest_release_stable' );
	delete_transient( 'a8csp_bgje_github_latest_release_prerelease' );

	$a8csp_bgje_option_names = $wpdb->get_col( $wpdb->prepare( 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s', $wpdb->options, $wpdb->esc_like( 'a8csp_bgje_' ) . '%' ) );

	foreach ( $a8csp_bgje_option_names as $a8csp_bgje_option_name ) {
		if ( \is_string( $a8csp_bgje_option_name ) && \str_starts_with( $a8csp_bgje_option_name, 'a8csp_bgje_' ) ) {
			delete_option( $a8csp_bgje_option_name );
		}
	}

	/*
	 * Both scheduler stores are per site. Action Scheduler's public unschedule function requires
	 * an initialized runtime and only cancels pending rows, while uninstall runs cold, so direct
	 * custom-table deletes cover every engine action status and its logs. Requiring the complete
	 * four-table schema keeps incomplete or migrated stores untouched.
	 */
	foreach ( $a8csp_bgje_lifecycle_hooks as $a8csp_bgje_lifecycle_hook ) {
		wp_unschedule_hook( $a8csp_bgje_lifecycle_hook );
	}

	$a8csp_bgje_action_scheduler_tables = array();
	foreach (
		array(
			'actionscheduler_logs',
			'actionscheduler_claims',
			'actionscheduler_actions',
			'actionscheduler_groups',
		) as $a8csp_bgje_action_scheduler_table_suffix
	) {
		$a8csp_bgje_action_scheduler_table = $wpdb->prefix . $a8csp_bgje_action_scheduler_table_suffix;
		$a8csp_bgje_installed_table        = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $a8csp_bgje_action_scheduler_table ) ) );
		if ( $a8csp_bgje_action_scheduler_table !== $a8csp_bgje_installed_table ) {
			return;
		}

		$a8csp_bgje_action_scheduler_tables[ $a8csp_bgje_action_scheduler_table_suffix ] = $a8csp_bgje_action_scheduler_table;
	}

	$a8csp_bgje_hook_placeholders = \implode( ', ', \array_fill( 0, \count( $a8csp_bgje_lifecycle_hooks ), '%s' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN-list placeholders are array_fill()-built literals; every value still binds through prepare().

	/*
	 * Candidate claim and group IDs must be captured before their matching actions disappear. The
	 * final unreferenced checks keep rows shared with surviving foreign actions out of scope.
	 */
	$a8csp_bgje_claim_id_rows = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT `claim_id` FROM %i WHERE `hook` IN (' . $a8csp_bgje_hook_placeholders . ')', \array_merge( array( $a8csp_bgje_action_scheduler_tables['actionscheduler_actions'] ), $a8csp_bgje_lifecycle_hooks ) ) );
	$a8csp_bgje_claim_ids     = array();
	foreach ( $a8csp_bgje_claim_id_rows as $a8csp_bgje_claim_id ) {
		if ( ! \is_numeric( $a8csp_bgje_claim_id ) ) {
			continue;
		}

		$a8csp_bgje_claim_id = (int) $a8csp_bgje_claim_id;
		if ( 0 < $a8csp_bgje_claim_id ) {
			$a8csp_bgje_claim_ids[] = $a8csp_bgje_claim_id;
		}
	}

	$a8csp_bgje_group_id_rows = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT `group_id` FROM %i WHERE `hook` IN (' . $a8csp_bgje_hook_placeholders . ')', \array_merge( array( $a8csp_bgje_action_scheduler_tables['actionscheduler_actions'] ), $a8csp_bgje_lifecycle_hooks ) ) );
	$a8csp_bgje_group_ids     = array();
	foreach ( $a8csp_bgje_group_id_rows as $a8csp_bgje_group_id ) {
		if ( ! \is_numeric( $a8csp_bgje_group_id ) ) {
			continue;
		}

		$a8csp_bgje_group_id = (int) $a8csp_bgje_group_id;
		if ( 0 < $a8csp_bgje_group_id ) {
			$a8csp_bgje_group_ids[] = $a8csp_bgje_group_id;
		}
	}

	// Every query below binds matching literal placeholders and arguments, so prepare() cannot
	// return null here; the scoped ignores skip unreachable narrowing instead of faking a handler.
	$a8csp_bgje_log_delete_query = $wpdb->prepare(
		'DELETE FROM %i WHERE `action_id` IN (' .
			'SELECT `action_id` FROM %i WHERE `hook` IN (' . $a8csp_bgje_hook_placeholders . ')' .
			')',
		\array_merge(
			array(
				$a8csp_bgje_action_scheduler_tables['actionscheduler_logs'],
				$a8csp_bgje_action_scheduler_tables['actionscheduler_actions'],
			),
			$a8csp_bgje_lifecycle_hooks
		)
	);
	if ( false === $wpdb->query( $a8csp_bgje_log_delete_query ) ) { // @phpstan-ignore argument.type
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The cold uninstall cannot use the plugin logger.
		\error_log( 'a8csp-background-jobs-engine: uninstall left Action Scheduler actions and logs behind; actionscheduler_logs table delete failed.' );
		return;
	}

	$a8csp_bgje_action_delete_query = $wpdb->prepare( 'DELETE FROM %i WHERE `hook` IN (' . $a8csp_bgje_hook_placeholders . ')', \array_merge( array( $a8csp_bgje_action_scheduler_tables['actionscheduler_actions'] ), $a8csp_bgje_lifecycle_hooks ) );
	if ( false === $wpdb->query( $a8csp_bgje_action_delete_query ) ) { // @phpstan-ignore argument.type
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The cold uninstall cannot use the plugin logger.
		\error_log( 'a8csp-background-jobs-engine: uninstall left Action Scheduler actions behind; actionscheduler_actions table delete failed.' );
		return;
	}

	if ( array() !== $a8csp_bgje_claim_ids ) {
		$a8csp_bgje_claim_placeholders = \implode( ', ', \array_fill( 0, \count( $a8csp_bgje_claim_ids ), '%d' ) );
		$a8csp_bgje_claim_delete_query = $wpdb->prepare(
			'DELETE FROM %i WHERE `claim_id` IN (' . $a8csp_bgje_claim_placeholders . ') AND `claim_id` NOT IN (SELECT `claim_id` FROM %i)',
			\array_merge(
				array( $a8csp_bgje_action_scheduler_tables['actionscheduler_claims'] ),
				$a8csp_bgje_claim_ids,
				array( $a8csp_bgje_action_scheduler_tables['actionscheduler_actions'] )
			)
		);
		if ( false === $wpdb->query( $a8csp_bgje_claim_delete_query ) ) { // @phpstan-ignore argument.type
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The cold uninstall cannot use the plugin logger.
			\error_log( 'a8csp-background-jobs-engine: uninstall left orphaned Action Scheduler claims behind; actionscheduler_claims table delete failed.' );
			return;
		}
	}

	if ( array() === $a8csp_bgje_group_ids ) {
		return;
	}

	$a8csp_bgje_group_placeholders = \implode( ', ', \array_fill( 0, \count( $a8csp_bgje_group_ids ), '%d' ) );
	$a8csp_bgje_group_delete_query = $wpdb->prepare(
		'DELETE FROM %i WHERE `group_id` IN (' . $a8csp_bgje_group_placeholders . ') ' .
			'AND `group_id` NOT IN (SELECT `group_id` FROM %i)',
		\array_merge(
			array(
				$a8csp_bgje_action_scheduler_tables['actionscheduler_groups'],
			),
			$a8csp_bgje_group_ids,
			array( $a8csp_bgje_action_scheduler_tables['actionscheduler_actions'] )
		)
	);
	$wpdb->query( $a8csp_bgje_group_delete_query ); // @phpstan-ignore argument.type
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
};

/*
 * Options, WP-Cron events, and Action Scheduler actions are stored per site, while WordPress runs
 * a multisite uninstall only once for the network. Bounded pages keep site discovery memory
 * proportional to one batch while still visiting every site's engine footprint.
 */
if ( is_multisite() ) {
	$a8csp_bgje_uninstall_site_batch_size = 100;
	$a8csp_bgje_uninstall_site_offset     = 0;
	do {
		$a8csp_bgje_uninstall_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => $a8csp_bgje_uninstall_site_batch_size,
				'offset' => $a8csp_bgje_uninstall_site_offset,
			)
		);
		foreach ( $a8csp_bgje_uninstall_site_ids as $a8csp_bgje_uninstall_site_id ) {
			switch_to_blog( $a8csp_bgje_uninstall_site_id );
			$a8csp_bgje_uninstall_site();
			restore_current_blog();
		}

		$a8csp_bgje_uninstall_site_count   = \count( $a8csp_bgje_uninstall_site_ids );
		$a8csp_bgje_uninstall_site_offset += $a8csp_bgje_uninstall_site_batch_size;
	} while ( $a8csp_bgje_uninstall_site_count === $a8csp_bgje_uninstall_site_batch_size );
} else {
	$a8csp_bgje_uninstall_site();
}

// User meta is stored network-globally, so one pass covers every site.
// @phpstan-ignore foreach.emptyArray (The fixed-key footprint starts empty; each fixed-key write lands its entry here.)
foreach ( $a8csp_bgje_footprint['user_meta'] as $a8csp_bgje_uninstall_meta_key ) {
	delete_metadata( 'user', 0, $a8csp_bgje_uninstall_meta_key, '', true );
}

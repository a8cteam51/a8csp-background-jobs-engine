<?php declare( strict_types=1 );
/**
 * Uninstall handler. WordPress runs this file directly when the plugin is deleted, in a cold
 * bootstrap where only `WP_UNINSTALL_PLUGIN` is defined — no Composer autoloader, no Plugin class,
 * no Component registry — so the plugin's footprint stays inline below instead of living in a
 * separately-requirable file: nothing here may reference plugin code.
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
 * This file runs in WordPress's cold uninstall bootstrap (no autoloader, no Plugin or Component
 * classes), so the arrays stay inline: nothing here may reference plugin code.
 */
$a8csp_bgte_footprint = array(
	'options'   => array(
		'a8csp_bgte_schedules',
	),
	'user_meta' => array(),
);

$a8csp_bgte_lifecycle_hooks = array(
	'a8csp/background_tasks/start',
	'a8csp/background_tasks/continue',
	'a8csp/background_tasks/run',
	'a8csp/background_tasks/cleanup',
	'a8csp/background_tasks/schedule_due',
);

/*
 * Run, latest-pointer, history, lock, and failed-run option names end in task, batch, run,
 * or argument-hash identifiers that do not exist until runtime, so no static list can name
 * every row. The shared prefix is the complete ownership boundary for standalone engine
 * options. Escaping it before appending the wildcard keeps each underscore literal instead
 * of letting SQL LIKE broaden the sweep to similarly spelled foreign options.
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
	$a8csp_bgte_option_pattern = $wpdb->esc_like( 'a8csp_bgte_' ) . '%';
	$a8csp_bgte_option_names   = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s',
			$wpdb->options,
			$a8csp_bgte_option_pattern
		)
	);

	foreach ( $a8csp_bgte_option_names as $a8csp_bgte_option_name ) {
		if ( \is_string( $a8csp_bgte_option_name ) ) {
			delete_option( $a8csp_bgte_option_name );
		}
	}

	/*
	 * Both scheduler stores are per site. Action Scheduler 4.0 registers its custom tables in
	 * wpdb's blog-table list and resolves the live wpdb table properties for every store query,
	 * so its database store follows switch_to_blog() just like the options and WP-Cron stores.
	 * The function guard matters because Action Scheduler belongs to another plugin and is not
	 * necessarily loaded during uninstall.
	 */
	foreach ( $a8csp_bgte_lifecycle_hooks as $a8csp_bgte_lifecycle_hook ) {
		wp_unschedule_hook( $a8csp_bgte_lifecycle_hook );
		if ( \function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $a8csp_bgte_lifecycle_hook );
		}
	}
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

<?php declare( strict_types=1 );
/**
 * Uninstall handler. WordPress runs this file directly when the plugin is deleted. The persisted
 * footprint lives in a pure-data manifest; uninstall must not depend on plugin code.
 *
 * @since       1.0.0
 * @version     1.0.0
 * @package     A8C\SpecialProjects\BackgroundJobsEngine
 */

\defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Persisted uninstall footprint.
 *
 * @var array{
 *     option_sweep_prefix: string,
 *     retained_option_prefixes: list<string>,
 *     transient_keys: list<string>,
 *     delivery_hooks: list<string>
 * } $a8csp_bgje_footprint
 */
$a8csp_bgje_footprint = require __DIR__ . '/footprint.php';

/*
 * Options within the a8csp_bgje_ ownership boundary use the sweep below.
 *
 * Schedule-registration, active-run, failed-run, latest-run, run-history, overlap-lock,
 * occurrence-lease, and cleanup-intent option names end in scope, job, chunked job, run,
 * registration-hash, or argument-hash identifiers that do not exist until runtime. The shared
 * prefix is the complete ownership boundary for standalone engine options. Escaping it before
 * appending the wildcard keeps each underscore literal instead of letting SQL LIKE broaden the
 * sweep to similarly spelled foreign options. The deletion loop repeats the byte-exact prefix
 * check because the option-name column collation may admit case-distinct candidates.
 *
 * Failed-run and run-history rows are non-autoloaded diagnostic records, so uninstall retains
 * them by default. The latest-run rows are pointers and remain disposable with the rest of the
 * sweep.
 *
 * Selecting the names directly is intentional: delete_option() still performs each deletion so
 * WordPress preserves its normal cache invalidation and hooks. The per-site cleanup policy stays
 * in one closure so the single-site and network paths cannot drift apart.
 */
$a8csp_bgje_uninstall_site = static function () use ( $a8csp_bgje_footprint ): void {
	global $wpdb;

	/**
	 * WordPress database connection for the current site.
	 *
	 * @var wpdb $wpdb
	 */
	$a8csp_bgje_option_names = $wpdb->get_col( $wpdb->prepare( 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s', $wpdb->options, $wpdb->esc_like( $a8csp_bgje_footprint['option_sweep_prefix'] ) . '%' ) );

	foreach ( $a8csp_bgje_footprint['transient_keys'] as $a8csp_bgje_transient_key ) {
		delete_transient( $a8csp_bgje_transient_key );
	}

	$a8csp_bgje_remove_diagnostics_on_uninstall = \defined( 'A8CSP_BGJE_REMOVE_DIAGNOSTICS_ON_UNINSTALL' ) && true === \constant( 'A8CSP_BGJE_REMOVE_DIAGNOSTICS_ON_UNINSTALL' );

	foreach ( $a8csp_bgje_option_names as $a8csp_bgje_option_name ) {
		if ( ! \is_string( $a8csp_bgje_option_name ) || ! \str_starts_with( $a8csp_bgje_option_name, $a8csp_bgje_footprint['option_sweep_prefix'] ) ) {
			continue;
		}

		if ( ! $a8csp_bgje_remove_diagnostics_on_uninstall ) {
			foreach ( $a8csp_bgje_footprint['retained_option_prefixes'] as $a8csp_bgje_retained_option_prefix ) {
				if ( \str_starts_with( $a8csp_bgje_option_name, $a8csp_bgje_retained_option_prefix ) ) {
					continue 2;
				}
			}
		}

		delete_option( $a8csp_bgje_option_name );
	}

	/*
	 * Both scheduler stores are per site. Action Scheduler's public API is available only after its
	 * runtime is initialized. The schema probe keeps dormant multisite subsites silent when a shared
	 * runtime exists without an Action Scheduler store for the current site.
	 */
	foreach ( $a8csp_bgje_footprint['delivery_hooks'] as $a8csp_bgje_delivery_hook ) {
		wp_unschedule_hook( $a8csp_bgje_delivery_hook );
	}

	$a8csp_bgje_action_scheduler_table = $wpdb->prefix . 'actionscheduler_actions';
	$a8csp_bgje_installed_table        = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $a8csp_bgje_action_scheduler_table ) ) );
	if ( $a8csp_bgje_action_scheduler_table !== $a8csp_bgje_installed_table ) {
		return;
	}

	if ( \function_exists( 'as_unschedule_all_actions' ) && \class_exists( 'ActionScheduler', false ) && \ActionScheduler::is_initialized() ) {
		foreach ( $a8csp_bgje_footprint['delivery_hooks'] as $a8csp_bgje_delivery_hook ) {
			\as_unschedule_all_actions( $a8csp_bgje_delivery_hook );
		}
	}
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

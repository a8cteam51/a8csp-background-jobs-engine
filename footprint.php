<?php
/**
 * Persisted uninstall footprint manifest.
 *
 * @since       1.0.0
 * @version     1.0.0
 * @package     A8C\SpecialProjects\BackgroundJobsEngine
 */

declare( strict_types=1 );

\defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

return array(
	'option_sweep_prefix'      => 'a8csp_bgje_',
	'retained_option_prefixes' => array(
		'a8csp_bgje_failed_runs_',
		'a8csp_bgje_run_history_',
	),
	'transient_keys'           => array(
		'a8csp_bgje_github_latest_release_stable',
		'a8csp_bgje_github_latest_release_prerelease',
	),
	'delivery_hooks'           => array(
		'a8csp_bgje/internal/deliver',
		'a8csp_bgje/internal/schedule_due',
	),
);

<?php declare( strict_types=1 );

/**
 * Fails selected authoritative option-row reads after command setup completes.
 *
 * @package A8C\SpecialProjects\BackgroundTasksEngine
 */

\WP_CLI::add_hook(
	'after_wp_load',
	static function (): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$failed_option_names = array(
			'a8csp_bgte_failed_integration-cli-command:integration-cli-command-list-store',
			'a8csp_bgte_failed_integration-cli-inspection-owner:integration-cli-inspection-task',
			'a8csp_bgte_schedule_registrations_integration-cli-inspection-owner',
		);
		$wpdb->suppress_errors();
		\add_filter(
			'query',
			static function ( string $query ) use ( $failed_option_names ): string {
				if ( \str_contains( $query, 'SELECT `option_value`' ) ) {
					foreach ( $failed_option_names as $option_name ) {
						if ( \str_contains( $query, $option_name ) ) {
							return 'SELECT * FROM `a8csp_bgte_missing_option_rows`';
						}
					}
				}

				return $query;
			}
		);
	}
);

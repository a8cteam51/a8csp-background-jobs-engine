<?php declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

use A8C\SpecialProjects\BackgroundTasks\Plugin;

// region META

/**
 * Returns the plugin's main class instance.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  Plugin
 */
function a8csp_bgt_get_plugin_instance(): Plugin {
	return Plugin::get_instance();
}

/**
 * Returns the plugin's slug.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  string
 */
function a8csp_bgt_get_plugin_slug(): string {
	return sanitize_key( A8CSP_BACKGROUND_TASKS_METADATA['TextDomain'] );
}

// endregion

// region TASKS API

/**
 * Returns the hash of the provided arguments.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   array $args The arguments to hash.
 *
 * @return  string
 */
function a8csp_bgt_hash_task_args( array $args ): string {
	return hash( 'md5', wp_json_encode( $args ) );
}

/**
 * Returns the latest run ID for the provided task name and arguments.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string     $task_name  The name of the task.
 * @param   array|null $start_args Optional. The arguments to start the task.
 *
 * @return  string|null
 */
function a8csp_bgt_get_task_latest_run_id( string $task_name, ?array $start_args = null ): ?string {
	$option_name = "a8csp_bg-task_{$task_name}_latest-run-id";
	if ( ! is_null( $start_args ) ) {
		$args_hash    = a8csp_bgt_hash_task_args( $start_args );
		$option_name .= "_$args_hash";
	}

	return get_option( $option_name, null );
}

/**
 * Returns an array of all run IDs for the provided task name and arguments.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string     $task_name  The name of the task.
 * @param   array|null $start_args Optional. The arguments used to start the task.
 *
 * @return  string[]
 */
function a8csp_bgt_get_task_run_ids( string $task_name, ?array $start_args = null ): array {
	$option_name = "a8csp_bg-task_{$task_name}_run-ids";
	if ( ! is_null( $start_args ) ) {
		$args_hash    = a8csp_bgt_hash_task_args( $start_args );
		$option_name .= "_$args_hash";
	}

	return get_option( $option_name, array() );
}

// endregion

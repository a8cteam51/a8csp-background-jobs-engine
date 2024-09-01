<?php declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Returns the number of run IDs to keep for a given task.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return integer
 */
function a8csp_bgt_task_run_ids_to_keep(): int {
	$run_ids_to_keep = defined( 'A8CSP_BGT_MAX_RUN_IDS' ) ? A8CSP_BGT_MAX_RUN_IDS : 30;
	return absint( $run_ids_to_keep );
}

/**
 * Returns the maximum number of retries for a task run.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  integer
 */
function a8csp_bgt_task_run_max_retries(): int {
	$max_retries = defined( 'A8CSP_BGT_MAX_RETRIES' ) ? A8CSP_BGT_MAX_RETRIES : 3;
	return absint( $max_retries );
}

/**
 * Returns an array of all run IDs for the provided task name and arguments.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string     $task_name The name of the task.
 * @param   array|null $run_args  Optional. The arguments of the task run.
 *
 * @return  string[]
 */
function a8csp_bgt_get_task_run_ids( string $task_name, ?array $run_args = null ): array {
	$option_name = "a8csp_bg-task_{$task_name}_run-ids";
	if ( ! is_null( $run_args ) ) {
		$args_hash    = a8csp_bgt_hash_task_args( $run_args );
		$option_name .= "_$args_hash";
	}

	return get_option( $option_name, array() );
}

/**
 * Returns the latest run ID for the provided task name and arguments.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string     $task_name The name of the task.
 * @param   array|null $run_args  Optional. The arguments of the task run.
 *
 * @return  string|null
 */
function a8csp_bgt_get_task_latest_run_id( string $task_name, ?array $run_args = null ): ?string {
	$option_name = "a8csp_bg-task_{$task_name}_latest-run-id";
	if ( ! is_null( $run_args ) ) {
		$args_hash    = a8csp_bgt_hash_task_args( $run_args );
		$option_name .= "_$args_hash";
	}

	return get_option( $option_name, null );
}

/**
 * Returns an array of all completed run IDs for the provided task name and arguments.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string     $task_name The name of the task.
 * @param   array|null $run_args  Optional. The arguments of the task run.
 *
 * @return  string[]
 */
function a8csp_bgt_get_task_completed_run_ids( string $task_name, ?array $run_args = null ): array {
	$option_name = "a8csp_bg-task_{$task_name}_completed-run-ids";
	if ( ! is_null( $run_args ) ) {
		$args_hash    = a8csp_bgt_hash_task_args( $run_args );
		$option_name .= "_$args_hash";
	}

	return get_option( $option_name, array() );
}

/**
 * Returns the latest completed run ID for the provided task name and arguments.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string     $task_name The name of the task.
 * @param   array|null $run_args  Optional. The arguments of the task run.
 *
 * @return  string|null
 */
function a8csp_bgt_get_task_latest_completed_run_id( string $task_name, ?array $run_args = null ): ?string {
	$completed_run_ids = a8csp_bgt_get_task_completed_run_ids( $task_name, $run_args );
	return empty( $completed_run_ids ) ? null : end( $completed_run_ids );
}

/**
 * Returns the arguments of a given task run.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $task_name The name of the task.
 * @param   string $run_id    The ID of the task run.
 *
 * @return  array|null
 */
function a8csp_bgt_get_task_run_args( string $task_name, string $run_id ): ?array {
	return get_option( "a8csp_bg-task_{$task_name}_run-{$run_id}_args", null );
}

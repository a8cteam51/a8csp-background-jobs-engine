<?php declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Returns the work queue for the provided task name and run ID.
 *
 * @param   string $task_name The name of the task.
 * @param   string $run_id    The ID of the task run.
 *
 * @return  array[]|null
 */
function a8csp_bgt_get_task_run_queue( string $task_name, string $run_id ): ?array {
	return get_option( "a8csp_bg-task_{$task_name}_run-{$run_id}_queue", null );
}

/**
 * Sets the work queue for the provided task name and run ID.
 *
 * @param   string $task_name The name of the task.
 * @param   string $run_id    The ID of the task run.
 * @param   array  $queue     The work queue.
 *
 * @return  boolean
 */
function a8csp_bgt_set_task_run_queue( string $task_name, string $run_id, array $queue ): bool {
	return update_option( "a8csp_bg-task_{$task_name}_run-{$run_id}_queue", $queue, false );
}

/**
 * Clears the work queue for the provided task name and run ID.
 *
 * @param   string $task_name The name of the task.
 * @param   string $run_id    The ID of the task run.
 *
 * @return  boolean
 */
function a8csp_bgt_clear_task_run_queue( string $task_name, string $run_id ): bool {
	return delete_option( "a8csp_bg-task_{$task_name}_run-{$run_id}_queue" );
}

/**
 * Dequeues the args for the next chunk of work from the queue of the provided task name and run ID.
 *
 * @param   string $task_name The name of the task.
 * @param   string $run_id    The ID of the task run.
 *
 * @return  array|null
 */
function a8csp_bgt_dequeue_from_task_queue( string $task_name, string $run_id ): ?array {
	$queue = a8csp_bgt_get_task_run_queue( $task_name, $run_id );

	if ( empty( $queue ) ) {
		$args = null;
		a8csp_bgt_clear_task_run_queue( $task_name, $run_id );
	} else {
		$args = array_shift( $queue );
		a8csp_bgt_set_task_run_queue( $task_name, $run_id, $queue );
	}

	return $args;
}

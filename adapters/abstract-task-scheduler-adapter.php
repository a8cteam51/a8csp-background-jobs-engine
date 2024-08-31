<?php declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Encapsulates common functionality for task scheduler adapters.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
abstract class A8CSP_Abstract_Task_Scheduler_Adapter implements A8CSP_Task_Scheduler_Adapter_Interface {
	/**
	 * Standardizes the arguments for a task.
	 *
	 * @param   string $task_name  The name of the task.
	 * @param   array  $start_args The arguments to start the task.
	 *
	 * @return  array
	 */
	protected static function prepare_task_args( string $task_name, array $start_args ): array {
		return compact( 'task_name', 'start_args' );
	}
}

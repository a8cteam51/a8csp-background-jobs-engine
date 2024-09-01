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
	 * Standardizes the arguments for the task `start` event.
	 *
	 * @param   string $task_name The name of the task.
	 * @param   array  $run_args  The arguments used to start the task run.
	 *
	 * @return  array
	 */
	protected static function prepare_task_start_args( string $task_name, array $run_args ): array {
		return compact( 'task_name', 'run_args' );
	}

	/**
	 * Standardizes the arguments for a task event.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string     $task_name The name of the task.
	 * @param   string     $event     The event to trigger.
	 * @param   string     $run_id    The ID of the task run.
	 * @param   array|null $args      Optional. Additional arguments for the event.
	 *
	 * @throws  InvalidArgumentException If the event is invalid or missing a required argument.
	 *
	 * @return  array
	 */
	protected static function prepare_task_event_args( string $task_name, string $event, string $run_id, ?array $args = null ): array {
		if ( ! in_array( $event, array( 'continue', 'process', 'cleanup' ), true ) ) {
			throw new InvalidArgumentException( 'Invalid event.' );
		}

		return array_filter( compact( 'task_name', 'run_id', 'args' ) );
	}
}

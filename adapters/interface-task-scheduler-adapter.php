<?php declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Interface for an adapter interacting with a scheduling service.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface A8CSP_Task_Scheduler_Adapter_Interface {
	/**
	 * Returns whether a task is scheduled to run with the given arguments.
	 *
	 * @param   string $task_name The name of the task.
	 * @param   array  $run_args  The arguments of the task run.
	 *
	 * @return  boolean
	 */
	public static function has_task_run( string $task_name, array $run_args ): bool;

	/**
	 * Returns an array of all tasks that are scheduled to run.
	 *
	 * @return  \stdClass[]
	 */
	public static function get_task_runs(): array;

	/**
	 * Enqueues a task to be run one time, as soon as possible.
	 *
	 * @param   string $task_name The name of the task to enqueue.
	 * @param   array  $run_args  The arguments of the task run.
	 *
	 * @return  true|WP_Error
	 */
	public static function enqueue_task_run( string $task_name, array $run_args ): true|WP_Error;

	/**
	 * Schedules a task to run one time at a specific time.
	 *
	 * @param   string  $task_name The name of the task to schedule.
	 * @param   integer $timestamp The Unix timestamp (UTC) for when the task should run.
	 * @param   array   $run_args  The arguments of the task run.
	 *
	 * @return  true|WP_Error
	 */
	public static function schedule_task_run( string $task_name, int $timestamp, array $run_args ): true|WP_Error;

	/**
	 * Schedules a recurring task to run at a specific interval.
	 *
	 * @param   string  $task_name The name of the task to schedule.
	 * @param   integer $timestamp The Unix timestamp (UTC) for when the task should first run.
	 * @param   integer $interval  The interval in seconds between each run of the task.
	 * @param   array   $run_args  The arguments of the task run.
	 *
	 * @return  true|WP_Error
	 */
	public static function schedule_recurring_task_run( string $task_name, int $timestamp, int $interval, array $run_args ): true|WP_Error;

	/**
	 * Schedules a task to run on a cron-like schedule.
	 *
	 * @param   string  $task_name The name of the task to schedule.
	 * @param   integer $timestamp The Unix timestamp (UTC) for when the task should first run.
	 * @param   string  $schedule  The cron-like schedule for the task.
	 * @param   array   $run_args  The arguments of the task run.
	 *
	 * @link    http://en.wikipedia.org/wiki/Cron
	 *
	 * @return  true|WP_Error
	 */
	public static function schedule_cron_task( string $task_name, int $timestamp, string $schedule, array $run_args ): true|WP_Error;

	/**
	 * Removes all runs of a task with the given arguments.
	 *
	 * @param   string $task_name The name of the task.
	 * @param   array  $run_args  The arguments of the task run.
	 *
	 * @return  true|WP_Error
	 */
	public static function unschedule_task_runs( string $task_name, array $run_args ): true|WP_Error;

	/**
	 * Enqueues a task event to run as soon as possible.
	 *
	 * @param   string     $task_name The name of the task.
	 * @param   string     $event     The event to run.
	 * @param   string     $run_id    The ID of the task run.
	 * @param   array|null $args      Optional. Arguments to pass to the event.
	 *
	 * @return  true|WP_Error
	 */
	public static function enqueue_task_run_event( string $task_name, string $event, string $run_id, ?array $args = null ): true|WP_Error;

	/**
	 * Schedules a task event to run at a specific time.
	 *
	 * @param   string     $task_name The name of the task.
	 * @param   string     $event     The event to run.
	 * @param   integer    $timestamp The Unix timestamp (UTC) for when the event should run.
	 * @param   string     $run_id    The ID of the task run.
	 * @param   array|null $args      Optional. Arguments to pass to the event.
	 *
	 * @return  true|WP_Error
	 */
	public static function schedule_task_run_event( string $task_name, string $event, int $timestamp, string $run_id, ?array $args = null ): true|WP_Error;

	/**
	 * Removes all run events matching the given arguments.
	 *
	 * @param   string $task_name The name of the task.
	 * @param   string $run_id    The ID of the task run.
	 *
	 * @return  true|WP_Error
	 */
	public static function unschedule_task_run_events( string $task_name, string $run_id ): true|WP_Error;
}

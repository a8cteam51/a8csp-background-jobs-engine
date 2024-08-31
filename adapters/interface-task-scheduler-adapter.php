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
	 * Returns whether a task is scheduled to run.
	 *
	 * @param   string $task_name  The name of the task to check.
	 * @param   array  $start_args The arguments to start the task.
	 *
	 * @return  boolean
	 */
	public static function has_task( string $task_name, array $start_args ): bool;

	/**
	 * Returns an array of all tasks that are scheduled to run.
	 *
	 * @return  array
	 */
	public static function get_tasks(): array;

	/**
	 * Enqueues a task to be run one time, as soon as possible.
	 *
	 * @param   string $task_name  The name of the task to enqueue.
	 * @param   array  $start_args Optional. Arguments to pass to the task.
	 *
	 * @return  true|WP_Error
	 */
	public static function enqueue_task( string $task_name, array $start_args ): true|WP_Error;

	/**
	 * Schedules a task to run one time at a specific time.
	 *
	 * @param   string  $task_name  The name of the task to schedule.
	 * @param   integer $timestamp  The Unix timestamp (UTC) for when the task should run.
	 * @param   array   $start_args Optional. Arguments to pass to the task.
	 *
	 * @return  true|WP_Error
	 */
	public static function schedule_task( string $task_name, int $timestamp, array $start_args ): true|WP_Error;

	/**
	 * Schedules a recurring task to run at a specific interval.
	 *
	 * @param   string  $task_name  The name of the task to schedule.
	 * @param   integer $timestamp  The Unix timestamp (UTC) for when the task should first run.
	 * @param   integer $interval   The interval in seconds between each run of the task.
	 * @param   array   $start_args Optional. Arguments to pass to the task.
	 *
	 * @return  true|WP_Error
	 */
	public static function schedule_recurring_task( string $task_name, int $timestamp, int $interval, array $start_args ): true|WP_Error;

	/**
	 * Schedules a task to run on a cron-like schedule.
	 *
	 * @param   string  $task_name  The name of the task to schedule.
	 * @param   integer $timestamp  The Unix timestamp (UTC) for when the task should first run.
	 * @param   string  $schedule   The cron-like schedule for the task.
	 * @param   array   $start_args Optional. Arguments to pass to the task.
	 *
	 * @link    http://en.wikipedia.org/wiki/Cron
	 *
	 * @return  true|WP_Error
	 */
	public static function schedule_cron_task( string $task_name, int $timestamp, string $schedule, array $start_args ): true|WP_Error;

	/**
	 * Unschedules a task from running.
	 *
	 * @param   string $task_name  The name of the task to unschedule.
	 * @param   array  $start_args Optional. Arguments to pass to the task.
	 *
	 * @return  true|null|WP_Error True if successful, null if there was nothing to unschedule, or WP_Error on failure.
	 */
	public static function unschedule_task( string $task_name, array $start_args ): true|null|WP_Error;
}

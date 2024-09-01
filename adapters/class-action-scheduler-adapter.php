<?php declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for the Action Scheduler library.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @link    https://actionscheduler.org/
 */
class A8CSP_ActionScheduler_Adapter extends A8CSP_Abstract_Task_Scheduler_Adapter {
	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function has_task_run( string $task_name, array $run_args, string $group = '' ): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		$args = static::prepare_task_start_args( $task_name, $run_args );
		return as_has_scheduled_action( 'a8csp/background_tasks/start', $args, $group );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function get_task_runs( array $args = array() ): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}

		$args = array_merge( array( 'hook' => 'a8csp/background_tasks/start' ), $args );
		return as_get_scheduled_actions( $args );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function enqueue_task_run( string $task_name, array $run_args, int $priority = 10 ): true|WP_Error {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new WP_Error( 'action_scheduler_not_loaded', __( 'The Action Scheduler library is not loaded.', 'a8csp-background-tasks' ) );
		}

		$args   = static::prepare_task_start_args( $task_name, $run_args );
		$result = as_enqueue_async_action( 'a8csp/background_tasks/start', $args, '', false, $priority );
		if ( 0 === $result ) {
			return new WP_Error( 'enqueue_failed', __( 'The task could not be enqueued.', 'a8csp-background-tasks' ) );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function schedule_task_run( string $task_name, int $timestamp, array $run_args, int $priority = 10 ): true|WP_Error {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return new WP_Error( 'action_scheduler_not_loaded', __( 'The Action Scheduler library is not loaded.', 'a8csp-background-tasks' ) );
		}

		$args   = static::prepare_task_start_args( $task_name, $run_args );
		$result = as_schedule_single_action( $timestamp, 'a8csp/background_tasks/start', $args, '', false, $priority );
		if ( 0 === $result ) {
			return new WP_Error( 'schedule_failed', __( 'The task could not be scheduled.', 'a8csp-background-tasks' ) );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function schedule_recurring_task_run( string $task_name, int $timestamp, int $interval, array $run_args, int $priority = 10 ): true|WP_Error {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return new WP_Error( 'action_scheduler_not_loaded', __( 'The Action Scheduler library is not loaded.', 'a8csp-background-tasks' ) );
		}

		$args   = static::prepare_task_start_args( $task_name, $run_args );
		$result = as_schedule_recurring_action( $timestamp, $interval, 'a8csp/background_tasks/start', $args, '', false, $priority );
		if ( 0 === $result ) {
			return new WP_Error( 'schedule_failed', __( 'The recurring task could not be scheduled.', 'a8csp-background-tasks' ) );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function schedule_cron_task( string $task_name, int $timestamp, string $schedule, array $run_args, int $priority = 10 ): true|WP_Error {
		if ( ! function_exists( 'as_schedule_cron_action' ) ) {
			return new WP_Error( 'action_scheduler_not_loaded', __( 'The Action Scheduler library is not loaded.', 'a8csp-background-tasks' ) );
		}

		$args   = static::prepare_task_start_args( $task_name, $run_args );
		$result = as_schedule_cron_action( $timestamp, $schedule, 'a8csp/background_tasks/start', $args, '', false, $priority );
		if ( 0 === $result ) {
			return new WP_Error( 'schedule_failed', __( 'The cron-like task could not be scheduled.', 'a8csp-background-tasks' ) );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function unschedule_task_runs( string $task_name, array $run_args ): true|WP_Error {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return new WP_Error( 'action_scheduler_not_loaded', __( 'The Action Scheduler library is not loaded.', 'a8csp-background-tasks' ) );
		}

		$args = compact( 'task_name', 'run_args' );
		as_unschedule_all_actions( 'a8csp/background_tasks/start', $args );

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function enqueue_task_run_event( string $task_name, string $event, string $run_id, ?array $args = null, int $priority = 10 ): true|WP_Error {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new WP_Error( 'action_scheduler_not_loaded', __( 'The Action Scheduler library is not loaded.', 'a8csp-background-tasks' ) );
		}

		$args   = static::prepare_task_event_args( $task_name, $event, $run_id, $args );
		$result = as_enqueue_async_action( "a8csp/{$event}_background_task", $args, "$task_name|$run_id", false, $priority );
		if ( 0 === $result ) {
			return new WP_Error( 'enqueue_failed', __( 'The task event could not be enqueued.', 'a8csp-background-tasks' ) );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function schedule_task_run_event( string $task_name, string $event, int $timestamp, string $run_id, ?array $args = null, int $priority = 10 ): true|WP_Error {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return new WP_Error( 'action_scheduler_not_loaded', __( 'The Action Scheduler library is not loaded.', 'a8csp-background-tasks' ) );
		}

		$args   = static::prepare_task_event_args( $task_name, $event, $run_id, $args );
		$result = as_schedule_single_action( $timestamp, "a8csp/{$event}_background_task", $args, "$task_name|$run_id", false, $priority );
		if ( 0 === $result ) {
			return new WP_Error( 'schedule_failed', __( 'The task event could not be scheduled.', 'a8csp-background-tasks' ) );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function unschedule_task_run_events( string $task_name, string $run_id ): true|WP_Error {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return new WP_Error( 'action_scheduler_not_loaded', __( 'The Action Scheduler library is not loaded.', 'a8csp-background-tasks' ) );
		}

		as_unschedule_all_actions( '', array(), "$task_name|$run_id" );
		return true;
	}
}

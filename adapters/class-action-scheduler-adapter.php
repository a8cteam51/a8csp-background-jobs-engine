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
	public static function has_task( string $task_name, array $start_args, string $group = '' ): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		$args = static::prepare_task_args( $task_name, $start_args );
		return as_has_scheduled_action( 'a8csp/start_background_task', $args, $group );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function get_tasks( array $args = array() ): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}

		return as_get_scheduled_actions(
			array_merge( array( 'hook' => 'a8csp/start_background_task' ), $args ),
			ARRAY_A
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function enqueue_task( string $task_name, array $start_args, string $group = '', bool $unique = false, int $priority = 10 ): true|WP_Error {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new WP_Error( 'action_scheduler_not_loaded', __( 'The Action Scheduler library is not loaded.', 'a8csp-background-tasks' ) );
		}

		$args   = static::prepare_task_args( $task_name, $start_args );
		$result = as_enqueue_async_action( 'a8csp/start_background_task', $args, $group, $unique, $priority );
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
	public static function schedule_task( string $task_name, int $timestamp, array $start_args, string $group = '', bool $unique = false, int $priority = 10 ): true|WP_Error {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return new WP_Error( 'action_scheduler_not_loaded', __( 'The Action Scheduler library is not loaded.', 'a8csp-background-tasks' ) );
		}

		$args   = static::prepare_task_args( $task_name, $start_args );
		$result = as_schedule_single_action( $timestamp, 'a8csp/start_background_task', $args, $group, $unique, $priority );
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
	public static function schedule_recurring_task( string $task_name, int $timestamp, int $interval, array $start_args, string $group = '', bool $unique = false, int $priority = 10 ): true|WP_Error {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return new WP_Error( 'action_scheduler_not_loaded', __( 'The Action Scheduler library is not loaded.', 'a8csp-background-tasks' ) );
		}

		$args   = static::prepare_task_args( $task_name, $start_args );
		$result = as_schedule_recurring_action( $timestamp, $interval, 'a8csp/start_background_task', $args, $group, $unique, $priority );
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
	public static function schedule_cron_task( string $task_name, int $timestamp, string $schedule, array $start_args, string $group = '', bool $unique = false, int $priority = 10 ): true|WP_Error {
		if ( ! function_exists( 'as_schedule_cron_action' ) ) {
			return new WP_Error( 'action_scheduler_not_loaded', __( 'The Action Scheduler library is not loaded.', 'a8csp-background-tasks' ) );
		}

		$args   = static::prepare_task_args( $task_name, $start_args );
		$result = as_schedule_cron_action( $timestamp, $schedule, 'a8csp/start_background_task', $args, $group, $unique, $priority );
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
	public static function unschedule_task( string $task_name, array $start_args, string $group = '' ): true|null|WP_Error {
		if ( ! function_exists( 'as_unschedule_action' ) ) {
			return new WP_Error( 'action_scheduler_not_loaded', __( 'The Action Scheduler library is not loaded.', 'a8csp-background-tasks' ) );
		}

		$args   = static::prepare_task_args( $task_name, $start_args );
		$result = as_unschedule_action( 'a8csp/start_background_task', $args, $group );

		return $result ? true : null;
	}
}

<?php declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for the WP Cron library.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @link    https://developer.wordpress.org/plugins/cron/
 */
class A8CSP_WPCron_Adapter extends A8CSP_Abstract_Task_Scheduler_Adapter {
	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function has_task( string $task_name, array $start_args ): bool {
		$next_scheduled = wp_next_scheduled( 'a8csp/start_background_task', compact( 'task_name', 'start_args' ) );
		return false !== $next_scheduled;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function get_tasks(): array {
		$all_cron_events = _get_cron_array();
		$filtered_events = array();

		foreach ( $all_cron_events as $timestamp => $cron ) {
			foreach ( $cron as $cron_hook => $events ) {
				if ( 'a8csp/start_background_task' !== $cron_hook ) {
					continue;
				}

				foreach ( $events as $event ) {
					$filtered_events[] = array(
						'timestamp' => $timestamp,
						'hook'      => $cron_hook,
						'schedule'  => $event['schedule'],
						'args'      => $event['args'],
						'interval'  => $event['interval'] ?? null,
					);
				}
			}
		}

		return $filtered_events;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function enqueue_task( string $task_name, array $start_args ): true|WP_Error {
		$args = static::prepare_task_args( $task_name, $start_args );
		return wp_schedule_single_event( time(), 'a8csp/start_background_task', $args, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function schedule_task( string $task_name, int $timestamp, array $start_args ): true|WP_Error {
		$args = static::prepare_task_args( $task_name, $start_args );
		return wp_schedule_single_event( $timestamp, 'a8csp/start_background_task', $args, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function schedule_recurring_task( string $task_name, int $timestamp, int $interval, array $start_args ): true|WP_Error {
		$schedules = wp_get_schedules();
		foreach ( $schedules as $schedule_name => $schedule ) {
			if ( $interval === $schedule['interval'] ) {
				$recurrence = $schedule_name;
				break;
			}
		}

		if ( ! isset( $recurrence ) ) {
			return new WP_Error( 'invalid_interval', __( 'The interval is not valid.', 'a8csp-background-tasks' ) );
		}

		$args = static::prepare_task_args( $task_name, $start_args );
		return wp_schedule_event( $timestamp, $recurrence, 'a8csp/start_background_task', $args, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function schedule_cron_task( string $task_name, int $timestamp, string $schedule, array $start_args ): true|WP_Error {
		throw new LogicException( 'Not supported.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function unschedule_task( string $task_name, array $start_args ): true|null|WP_Error {
		$args = static::prepare_task_args( $task_name, $start_args );

		$timestamp = wp_next_scheduled( 'a8csp/start_background_task', $args );
		if ( false === $timestamp ) {
			return null;
		}

		return wp_unschedule_event( $timestamp, 'a8csp/start_background_task', $args );
	}
}

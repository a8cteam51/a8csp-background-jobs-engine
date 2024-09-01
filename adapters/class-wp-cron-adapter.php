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
	public static function has_task_run( string $task_name, array $run_args ): bool {
		$args = static::prepare_task_start_args( $task_name, $run_args );
		return false !== wp_next_scheduled( 'a8csp/background_tasks/start', $args );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function get_task_runs(): array {
		$all_cron_events = _get_cron_array();
		$filtered_events = array();

		foreach ( $all_cron_events as $timestamp => $cron ) {
			foreach ( $cron as $cron_hook => $events ) {
				if ( 'a8csp/background_tasks/start' !== $cron_hook ) {
					continue;
				}

				foreach ( $events as $event ) {
					$filtered_events[] = (object) array(
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
	public static function enqueue_task_run( string $task_name, array $run_args ): true|WP_Error {
		$args = static::prepare_task_start_args( $task_name, $run_args );
		return wp_schedule_single_event( time(), 'a8csp/background_tasks/start', $args, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function schedule_task_run( string $task_name, int $timestamp, array $run_args ): true|WP_Error {
		$args = static::prepare_task_start_args( $task_name, $run_args );
		return wp_schedule_single_event( $timestamp, 'a8csp/background_tasks/start', $args, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function schedule_recurring_task_run( string $task_name, int $timestamp, int $interval, array $run_args ): true|WP_Error {
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

		$args = static::prepare_task_start_args( $task_name, $run_args );
		return wp_schedule_event( $timestamp, $recurrence, 'a8csp/background_tasks/start', $args, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function schedule_cron_task( string $task_name, int $timestamp, string $schedule, array $run_args ): true|WP_Error {
		throw new LogicException( 'Not supported.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function unschedule_task_runs( string $task_name, array $run_args ): true|WP_Error {
		$args = compact( 'task_name', 'run_args' );

		do {
			$next_scheduled = wp_next_scheduled( 'a8csp/background_tasks/start', $args );
			if ( false === $next_scheduled ) {
				$result = true;
				break;
			}

			$result = wp_unschedule_event( $next_scheduled, 'a8csp/background_tasks/start', $args, true );
		} while ( ! is_wp_error( $result ) );

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function enqueue_task_run_event( string $task_name, string $event, string $run_id, ?array $args = null ): true|WP_Error {
		$args = static::prepare_task_event_args( $task_name, $event, $run_id, $args );
		return wp_schedule_single_event( time(), "a8csp/background_tasks/$event", $args, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function schedule_task_run_event( string $task_name, string $event, int $timestamp, string $run_id, ?array $args = null ): true|WP_Error {
		$args = static::prepare_task_event_args( $task_name, $event, $run_id, $args );
		return wp_schedule_single_event( $timestamp, "a8csp/background_tasks/$event", $args, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function unschedule_task_run_events( string $task_name, string $run_id ): true|WP_Error {
		$all_cron_events = _get_cron_array();

		$task_event_hooks = array( 'continue', 'process', 'complete' );
		$task_event_hooks = array_map( static fn( $event ) => "a8csp/background_tasks/$event", $task_event_hooks );

		foreach ( $all_cron_events as $timestamp => $cron ) {
			foreach ( $cron as $cron_hook => $cron_events ) {
				if ( ! in_array( $cron_hook, $task_event_hooks, true ) ) {
					continue;
				}

				foreach ( $cron_events as $event_key => $cron_event ) {
					if ( ! isset( $cron_event['args']['task_name'], $cron_event['args']['run_id'] ) ) {
						continue;
					}

					if ( $task_name === $cron_event['args']['task_name'] && $run_id === $cron_event['args']['run_id'] ) {
						unset( $all_cron_events[ $timestamp ][ $cron_hook ][ $event_key ] );
					}
				}
			}
		}

		return _set_cron_array( $all_cron_events, true );
	}
}

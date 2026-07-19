<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

/**
 * Clears WordPress cron state around each live integration test.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
trait CronIsolationTrait {
	// region METHODS.

	/**
	 * Returns stored WordPress cron events for one exact hook and optional argument identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                       $hook Hook to inspect.
	 * @param   array<array-key, mixed>|null $args Exact arguments to match, or null for every event on the hook.
	 *
	 * @return  list<array{timestamp: int, key: string, schedule: string|false, args: array<array-key, mixed>, interval: int|null}>
	 */
	protected function wordpress_cron_events( string $hook, ?array $args = null ): array {
		$cron = \_get_cron_array();
		if ( ! \is_array( $cron ) ) {
			return array();
		}

		\ksort( $cron, SORT_NUMERIC );
		$events          = array();
		$serialized_args = null === $args ? null : \maybe_serialize( $args );
		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! \is_int( $timestamp ) || ! \is_array( $hooks ) ) {
				continue;
			}

			$hook_events = $hooks[ $hook ] ?? null;
			if ( ! \is_array( $hook_events ) ) {
				continue;
			}

			foreach ( $hook_events as $key => $event ) {
				if ( ! \is_array( $event ) || ! \is_array( $event['args'] ?? null ) ) {
					continue;
				}

				$event_args = $event['args'];
				if ( null !== $serialized_args && \maybe_serialize( $event_args ) !== $serialized_args ) {
					continue;
				}

				$schedule = $event['schedule'] ?? null;
				if ( false !== $schedule && ! \is_string( $schedule ) ) {
					continue;
				}

				$interval = $event['interval'] ?? null;
				$events[] = array(
					'timestamp' => $timestamp,
					'key'       => (string) $key,
					'schedule'  => $schedule,
					'args'      => $event_args,
					'interval'  => \is_int( $interval ) ? $interval : null,
				);
			}
		}

		return $events;
	}

	/**
	 * Runs at most one due event through WP-Cron's reschedule, clear, and dispatch sequence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int Number of events processed.
	 */
	protected function run_next_due_cron_event(): int {
		return $this->run_matching_due_cron_event( static fn ( string $hook, array $args ): bool => true );
	}

	/**
	 * Runs at most one due event accepted by a hook-and-arguments predicate.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing concurrency
	 * @pin-rationale WP-Cron persists a recurring successor before dispatch and removes the due row before invoking consumers; no public seam exposes that ordering window.
	 *
	 * @phpstan-param callable(string, array<array-key, mixed>): bool $matches
	 *
	 * @param   callable $matches Due-event identity predicate.
	 *
	 * @return  int Number of events processed.
	 */
	protected function run_matching_due_cron_event( callable $matches ): int {
		$ready = \wp_get_ready_cron_jobs();
		\ksort( $ready, SORT_NUMERIC );
		foreach ( $ready as $timestamp => $hooks ) {
			if ( ! \is_int( $timestamp ) || ! \is_array( $hooks ) ) {
				continue;
			}

			foreach ( $hooks as $hook => $events ) {
				if ( ! \is_string( $hook ) || '' === $hook || ! \is_array( $events ) ) {
					continue;
				}

				foreach ( $events as $event ) {
					if (
						! \is_array( $event )
						|| ! \is_array( $event['args'] ?? null )
						|| ! \array_is_list( $event['args'] )
					) {
						continue;
					}

					$args = $event['args'];
					if ( ! $matches( $hook, $args ) ) {
						continue;
					}

					$schedule    = $event['schedule'] ?? false;
					$rescheduled = true;
					if ( \is_string( $schedule ) && '' !== $schedule ) {
						$rescheduled = \wp_reschedule_event( $timestamp, $schedule, $hook, $args, true );
					}

					$unscheduled = \wp_unschedule_event( $timestamp, $hook, $args, true );
					\do_action_ref_array( $hook, $args );

					self::assertTrue( true === $rescheduled, 'The WP-Cron drive must persist the recurring successor before dispatching the due occurrence' );
					self::assertTrue( true === $unscheduled, 'The WP-Cron drive must clear the exact due occurrence before invoking its hook' );

					return 1;
				}
			}
		}

		return 0;
	}

	/**
	 * Replaces the persisted cron array with an empty schedule.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function reset_wordpress_cron(): void {
		\_set_cron_array( array() );
	}

	// endregion.
}

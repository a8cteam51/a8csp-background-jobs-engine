<?php declare( strict_types=1 );

/**
 * In-memory WordPress cron functions for unit tests outside WordPress.
 *
 * The fake stores events in the same timestamp, hook, event shape consumed by the backend while
 * exposing scripted WordPress errors and a call ledger. Function guards keep it inert when
 * WordPress supplies the real cron API.
 *
 * @since   1.0.0
 * @version 1.0.0
 * @package A8C\SpecialProjects\BackgroundTasksEngine
 */

require_once __DIR__ . '/wp-hook-stubs.php';
require_once __DIR__ . '/wp-options-stubs.php';
require_once \dirname( __DIR__ ) . '/Support/WPErrorStub.php';

if ( ! \class_exists( 'WP_Error' ) ) {
	\class_alias( A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WPErrorStub::class, 'WP_Error' );
}

if ( ! \function_exists( '__' ) ) {
	/**
	 * Returns source text unchanged.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $text   Source text.
	 * @param   string $domain Text domain.
	 *
	 * @return  string
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! \function_exists( 'maybe_serialize' ) ) {
	/**
	 * Serializes fake option values with WordPress's array and object behavior.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $data Value to serialize when required.
	 *
	 * @return  mixed
	 */
	function maybe_serialize( $data ) {
		if ( \is_array( $data ) || \is_object( $data ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WP-Cron identities use Core's PHP serialization contract.
			return \serialize( $data );
		}

		return $data;
	}
}

if ( ! \function_exists( 'a8csp_bgte_test_record_cron_call' ) ) {
	/**
	 * Appends a cron-function call to the test ledger.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   string $function_name Function name.
	 * @param   array  $args          Function arguments.
	 *
	 * @return  void
	 */
	function a8csp_bgte_test_record_cron_call( string $function_name, array $args ): void {
		/** @var list<array{function: string, args: list<mixed>}> $calls */
		$calls   = $GLOBALS['a8csp_bgte_test_cron_calls'] ?? array();
		$calls[] = array(
			'function' => $function_name,
			'args'     => $args,
		);

		$GLOBALS['a8csp_bgte_test_cron_calls'] = $calls;
	}
}

if ( ! \function_exists( 'a8csp_bgte_test_scripted_cron_result' ) ) {
	/**
	 * Shifts the next scripted result for a cron function.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $function_name Function name.
	 *
	 * @return  true|WP_Error|null Scripted result, or null for the default successful path.
	 */
	function a8csp_bgte_test_scripted_cron_result( string $function_name ): true|WP_Error|null {
		/** @var array<string, list<true|WP_Error>> $scripts */
		$scripts = $GLOBALS['a8csp_bgte_test_cron_results'] ?? array();
		$queue   = $scripts[ $function_name ] ?? array();

		if ( array() === $queue ) {
			return null;
		}

		$result = \array_shift( $queue );

		$scripts[ $function_name ] = $queue;

		$GLOBALS['a8csp_bgte_test_cron_results'] = $scripts;

		return $result;
	}
}

if ( ! \function_exists( 'a8csp_bgte_test_store_cron_event' ) ) {
	/**
	 * Stores an event in the fake cron array.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   int          $timestamp Unix timestamp.
	 * @param   string       $hook      Hook name.
	 * @param   array        $args      Hook arguments.
	 * @param   string|false $schedule  Recurrence name, or false for a single event.
	 *
	 * @return  void
	 */
	function a8csp_bgte_test_store_cron_event( int $timestamp, string $hook, array $args, string|false $schedule ): void {
		/** @var array<int, array<string, array<int, array{schedule: string|false, args: list<mixed>}>>> $cron */
		$cron     = $GLOBALS['a8csp_bgte_test_cron_array'] ?? array();
		$sequence = $GLOBALS['a8csp_bgte_test_cron_event_sequence'] ?? 0;
		if ( ! \is_int( $sequence ) ) {
			throw new \UnexpectedValueException( 'Initialize the fake cron event sequence as an integer before scheduling.' );
		}

		$cron[ $timestamp ][ $hook ][ $sequence ] = array(
			'schedule' => $schedule,
			'args'     => $args,
		);

		$GLOBALS['a8csp_bgte_test_cron_array']          = $cron;
		$GLOBALS['a8csp_bgte_test_cron_event_sequence'] = $sequence + 1;
	}
}

if ( ! \function_exists( 'a8csp_bgte_test_filtered_cron_schedules' ) ) {
	/**
	 * Applies the registered cron-schedule callbacks in priority order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{interval: int, display: string}>
	 */
	function a8csp_bgte_test_filtered_cron_schedules(): array {
		/** @var list<array{hook_name: string, callback: mixed, priority: int, accepted_args: int}> $registrations */
		$registrations = $GLOBALS['a8csp_bgte_test_filter_registrations'] ?? array();
		\usort(
			$registrations,
			static fn ( array $left, array $right ): int => $left['priority'] <=> $right['priority']
		);

		$schedules = array();
		foreach ( $registrations as $registration ) {
			if ( 'cron_schedules' !== $registration['hook_name'] ) {
				continue;
			}

			$callback = $registration['callback'];
			if ( ! \is_callable( $callback ) ) {
				throw new \UnexpectedValueException( 'Register a callable cron_schedules test callback.' );
			}

			$callback_args = 0 === $registration['accepted_args'] ? array() : array( $schedules );
			$filtered      = $callback( ...$callback_args );
			if ( ! \is_array( $filtered ) ) {
				throw new \UnexpectedValueException( 'Return an array from the cron_schedules test callback.' );
			}

			/** @var array<string, array{interval: int, display: string}> $schedules */
			$schedules = $filtered;
		}

		return $schedules;
	}
}

if ( ! \function_exists( 'a8csp_bgte_test_has_duplicate_cron_event' ) ) {
	/**
	 * Returns whether Core's single-event window contains the same serialized identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   int    $timestamp Requested timestamp.
	 * @param   string $hook      Hook name.
	 * @param   array  $args      Hook arguments.
	 *
	 * @return  bool
	 */
	function a8csp_bgte_test_has_duplicate_cron_event( int $timestamp, string $hook, array $args ): bool {
		$now           = \time();
		$min_timestamp = $timestamp < $now + 600 ? 0 : $timestamp - 600;
		$max_timestamp = $timestamp < $now ? $now + 600 : $timestamp + 600;

		$cron = $GLOBALS['a8csp_bgte_test_cron_array'] ?? array();
		if ( ! \is_array( $cron ) ) {
			return false;
		}

		foreach ( $cron as $event_timestamp => $hooks ) {
			if ( ! \is_int( $event_timestamp ) || $event_timestamp < $min_timestamp || $event_timestamp > $max_timestamp || ! \is_array( $hooks ) ) {
				continue;
			}

			$events = $hooks[ $hook ] ?? array();
			if ( ! \is_array( $events ) ) {
				continue;
			}

			foreach ( $events as $event ) {
				if ( ! \is_array( $event ) || ! \array_key_exists( 'args', $event ) ) {
					continue;
				}

				if ( \maybe_serialize( $args ) === \maybe_serialize( $event['args'] ) ) {
					return true;
				}
			}
		}

		return false;
	}
}

if ( ! \function_exists( 'wp_schedule_event' ) ) {
	/**
	 * Stores a recurring event unless a scripted error is present.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int         $timestamp  Unix timestamp.
	 * @param   string      $recurrence Recurrence name.
	 * @param   string      $hook       Hook name.
	 * @param   list<mixed> $args       Hook arguments.
	 * @param   bool        $wp_error   Whether errors are returned as WP_Error objects.
	 *
	 * @return  bool|WP_Error
	 */
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) {
		a8csp_bgte_test_record_cron_call( 'wp_schedule_event', array( $timestamp, $recurrence, $hook, $args, $wp_error ) );

		if ( ! \is_numeric( $timestamp ) || 0 >= $timestamp ) {
			return $wp_error ? new WP_Error( 'invalid_timestamp', 'Event timestamp must be a valid Unix timestamp.' ) : false;
		}

		$result = a8csp_bgte_test_scripted_cron_result( 'wp_schedule_event' );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		if ( ! isset( a8csp_bgte_test_filtered_cron_schedules()[ $recurrence ] ) ) {
			return $wp_error ? new WP_Error( 'invalid_schedule', 'Event schedule does not exist.' ) : false;
		}

		a8csp_bgte_test_store_cron_event( $timestamp, $hook, $args, $recurrence );

		return true;
	}
}

if ( ! \function_exists( 'wp_schedule_single_event' ) ) {
	/**
	 * Stores a single event unless a scripted error is present.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int         $timestamp Unix timestamp.
	 * @param   string      $hook      Hook name.
	 * @param   list<mixed> $args      Hook arguments.
	 * @param   bool        $wp_error  Whether errors are returned as WP_Error objects.
	 *
	 * @return  bool|WP_Error
	 */
	function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
		a8csp_bgte_test_record_cron_call( 'wp_schedule_single_event', array( $timestamp, $hook, $args, $wp_error ) );

		if ( ! \is_numeric( $timestamp ) || 0 >= $timestamp ) {
			return $wp_error ? new WP_Error( 'invalid_timestamp', 'Event timestamp must be a valid Unix timestamp.' ) : false;
		}

		$result = a8csp_bgte_test_scripted_cron_result( 'wp_schedule_single_event' );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		if ( a8csp_bgte_test_has_duplicate_cron_event( $timestamp, $hook, $args ) ) {
			return $wp_error ? new WP_Error( 'duplicate_event', 'A duplicate event already exists.' ) : false;
		}

		a8csp_bgte_test_store_cron_event( $timestamp, $hook, $args, false );

		return true;
	}
}

if ( ! \function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Returns the earliest event matching a hook and its exact arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $hook Hook name.
	 * @param   list<mixed> $args Hook arguments.
	 *
	 * @return  int|false
	 */
	function wp_next_scheduled( $hook, $args = array() ) {
		a8csp_bgte_test_record_cron_call( 'wp_next_scheduled', array( $hook, $args ) );

		/** @var array<int, array<string, array<int, array{schedule: string|false, args: list<mixed>}>>> $cron */
		$cron = $GLOBALS['a8csp_bgte_test_cron_array'] ?? array();
		\ksort( $cron, SORT_NUMERIC );

		foreach ( $cron as $timestamp => $hooks ) {
			foreach ( $hooks[ $hook ] ?? array() as $event ) {
				if ( \maybe_serialize( $args ) === \maybe_serialize( $event['args'] ) ) {
					return $timestamp;
				}
			}
		}

		return false;
	}
}

if ( ! \function_exists( 'wp_unschedule_event' ) ) {
	/**
	 * Removes one exact event unless a scripted error or no-progress mode is present.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int         $timestamp Unix timestamp.
	 * @param   string      $hook      Hook name.
	 * @param   list<mixed> $args      Hook arguments.
	 * @param   bool        $wp_error  Whether errors are returned as WP_Error objects.
	 *
	 * @return  true|WP_Error
	 */
	function wp_unschedule_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
		a8csp_bgte_test_record_cron_call( 'wp_unschedule_event', array( $timestamp, $hook, $args, $wp_error ) );

		/** @var callable(int, string, list<mixed>): void|null $before_unschedule */
		$before_unschedule = $GLOBALS['a8csp_bgte_test_cron_before_unschedule'] ?? null;
		if ( null !== $before_unschedule ) {
			if ( ! \is_callable( $before_unschedule ) ) {
				throw new \UnexpectedValueException( 'Configure the pre-unschedule test hook as a callable.' );
			}

			$before_unschedule( $timestamp, $hook, $args );
		}

		$result = a8csp_bgte_test_scripted_cron_result( 'wp_unschedule_event' );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		if ( true === ( $GLOBALS['a8csp_bgte_test_cron_preserve_on_unschedule'] ?? false ) ) {
			return true;
		}

		/** @var array<int, array<string, array<int, array{schedule: string|false, args: list<mixed>}>>> $cron */
		$cron = $GLOBALS['a8csp_bgte_test_cron_array'] ?? array();

		foreach ( $cron[ $timestamp ][ $hook ] ?? array() as $key => $event ) {
			if ( \maybe_serialize( $args ) !== \maybe_serialize( $event['args'] ) ) {
				continue;
			}

			unset( $cron[ $timestamp ][ $hook ][ $key ] );
			break;
		}

		$GLOBALS['a8csp_bgte_test_cron_array'] = $cron;

		return true;
	}
}

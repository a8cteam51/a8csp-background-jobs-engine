<?php declare( strict_types=1 );

/**
 * Scriptable Action Scheduler functions for unit tests outside WordPress.
 *
 * Each guarded stub records its positional arguments and shifts a scripted return value. The
 * guards keep this file inert when a client loads the real Action Scheduler API.
 *
 * @package A8C\SpecialProjects\BackgroundTasksEngine
 */

if ( ! \function_exists( 'a8csp_bgte_test_record_as_call' ) ) {
	/**
	 * Appends an Action Scheduler function call to the test ledger.
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   string $function_name Function name.
	 * @param   array  $args          Positional arguments.
	 *
	 * @return  void
	 */
	function a8csp_bgte_test_record_as_call( string $function_name, array $args ): void {
		/** @var list<array{function: string, args: list<mixed>}> $calls */
		$calls   = $GLOBALS['a8csp_bgte_test_as_calls'] ?? array();
		$calls[] = array(
			'function' => $function_name,
			'args'     => $args,
		);

		$GLOBALS['a8csp_bgte_test_as_calls'] = $calls;

		$site_calls = $GLOBALS['a8csp_bgte_test_as_site_calls'] ?? null;
		if ( \is_array( $site_calls ) ) {
			$blog_id      = \get_current_blog_id();
			$site_calls[] = array(
				'function' => $function_name,
				'blog_id'  => $blog_id,
				'args'     => $args,
			);

			$GLOBALS['a8csp_bgte_test_as_site_calls'] = $site_calls;
		}
	}
}

if ( ! \function_exists( 'a8csp_bgte_test_scripted_as_result' ) ) {
	/**
	 * Shifts the next scripted result for an Action Scheduler function.
	 *
	 * @param   string $function_name Function name.
	 * @param   mixed  $fallback      Fallback when no result is scripted.
	 *
	 * @return  mixed
	 */
	function a8csp_bgte_test_scripted_as_result( string $function_name, mixed $fallback ): mixed {
		/** @var array<string, list<mixed>> $scripts */
		$scripts = $GLOBALS['a8csp_bgte_test_as_results'] ?? array();
		$queue   = $scripts[ $function_name ] ?? array();

		if ( array() === $queue ) {
			return $fallback;
		}

		$result = \array_shift( $queue );

		$scripts[ $function_name ] = $queue;

		$GLOBALS['a8csp_bgte_test_as_results'] = $scripts;

		return $result;
	}
}

if ( ! \function_exists( 'as_enqueue_async_action' ) ) {
	/**
	 * Records and resolves an async enqueue.
	 *
	 * @param   string      $hook     Hook name.
	 * @param   list<mixed> $args     Hook arguments.
	 * @param   string      $group    Action group.
	 * @param   bool        $unique   Whether the action is unique.
	 * @param   int         $priority Action priority.
	 *
	 * @return  int
	 */
	function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
		a8csp_bgte_test_record_as_call( 'as_enqueue_async_action', array( $hook, $args, $group, $unique, $priority ) );

		$result = a8csp_bgte_test_scripted_as_result( 'as_enqueue_async_action', 1 );
		if ( ! \is_int( $result ) ) {
			throw new \UnexpectedValueException( 'Script as_enqueue_async_action with an integer result.' );
		}

		return $result;
	}
}

if ( ! \function_exists( 'as_schedule_single_action' ) ) {
	/**
	 * Records and resolves a single schedule.
	 *
	 * @param   int         $timestamp Run timestamp.
	 * @param   string      $hook      Hook name.
	 * @param   list<mixed> $args      Hook arguments.
	 * @param   string      $group     Action group.
	 * @param   bool        $unique    Whether the action is unique.
	 * @param   int         $priority  Action priority.
	 *
	 * @return  int
	 */
	function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
		a8csp_bgte_test_record_as_call( 'as_schedule_single_action', array( $timestamp, $hook, $args, $group, $unique, $priority ) );

		$result = a8csp_bgte_test_scripted_as_result( 'as_schedule_single_action', 1 );
		if ( ! \is_int( $result ) ) {
			throw new \UnexpectedValueException( 'Script as_schedule_single_action with an integer result.' );
		}

		return $result;
	}
}

if ( ! \function_exists( 'as_schedule_recurring_action' ) ) {
	/**
	 * Records and resolves a recurring schedule.
	 *
	 * @param   int         $timestamp           First-run timestamp.
	 * @param   int         $interval_in_seconds Recurrence interval.
	 * @param   string      $hook                Hook name.
	 * @param   list<mixed> $args                Hook arguments.
	 * @param   string      $group               Action group.
	 * @param   bool        $unique              Whether the action is unique.
	 * @param   int         $priority            Action priority.
	 *
	 * @return  int
	 */
	function as_schedule_recurring_action( $timestamp, $interval_in_seconds, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
		a8csp_bgte_test_record_as_call( 'as_schedule_recurring_action', array( $timestamp, $interval_in_seconds, $hook, $args, $group, $unique, $priority ) );

		$result = a8csp_bgte_test_scripted_as_result( 'as_schedule_recurring_action', 1 );
		if ( ! \is_int( $result ) ) {
			throw new \UnexpectedValueException( 'Script as_schedule_recurring_action with an integer result.' );
		}

		return $result;
	}
}

if ( ! \function_exists( 'as_unschedule_all_actions' ) ) {
	/**
	 * Records an all-matches unschedule request.
	 *
	 * @param   string      $hook  Hook name.
	 * @param   list<mixed> $args  Hook arguments.
	 * @param   string      $group Action group.
	 *
	 * @return  void
	 */
	function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
		a8csp_bgte_test_record_as_call( 'as_unschedule_all_actions', array( $hook, $args, $group ) );
	}
}

if ( ! \function_exists( 'as_get_scheduled_actions' ) ) {
	/**
	 * Records and resolves a scheduled-action query.
	 *
	 * @param   array<string, mixed> $args          Query arguments.
	 * @param   string               $return_format Return format.
	 *
	 * @return  array<int, object>|list<int>
	 */
	function as_get_scheduled_actions( $args = array(), $return_format = OBJECT ) {
		a8csp_bgte_test_record_as_call( 'as_get_scheduled_actions', array( $args, $return_format ) );

		$result = a8csp_bgte_test_scripted_as_result( 'as_get_scheduled_actions', array() );
		if ( ! \is_array( $result ) ) {
			throw new \UnexpectedValueException( 'Script as_get_scheduled_actions with an array result.' );
		}

		if ( 'ids' === $return_format || 'int' === $return_format ) {
			if ( ! \array_is_list( $result ) ) {
				throw new \UnexpectedValueException( 'Script Action Scheduler IDs with a list result.' );
			}

			foreach ( $result as $action_id ) {
				if ( ! \is_int( $action_id ) ) {
					throw new \UnexpectedValueException( 'Script Action Scheduler IDs with integer action identifiers.' );
				}
			}

			return $result;
		}

		foreach ( $result as $action_id => $action ) {
			if ( ! \is_int( $action_id ) || ! \is_object( $action ) || ! \method_exists( $action, 'get_args' ) || ! \method_exists( $action, 'get_group' ) ) {
				throw new \UnexpectedValueException( 'Script Action Scheduler objects with integer keys, get_args(), and get_group().' );
			}
		}

		return $result;
	}
}

if ( ! \function_exists( 'as_next_scheduled_action' ) ) {
	/**
	 * Records and resolves a next-scheduled query.
	 *
	 * @param   string           $hook  Hook name.
	 * @param   list<mixed>|null $args  Hook arguments, or null for any arguments.
	 * @param   string           $group Action group.
	 *
	 * @return  int|bool
	 */
	function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
		a8csp_bgte_test_record_as_call( 'as_next_scheduled_action', array( $hook, $args, $group ) );

		$result = a8csp_bgte_test_scripted_as_result( 'as_next_scheduled_action', false );
		if ( ! \is_int( $result ) && ! \is_bool( $result ) ) {
			throw new \UnexpectedValueException( 'Script as_next_scheduled_action with an integer or boolean result.' );
		}

		return $result;
	}
}

if ( ! \function_exists( 'as_has_scheduled_action' ) ) {
	/**
	 * Records and resolves an args-aware scheduled-state query.
	 *
	 * @param   string           $hook  Hook name.
	 * @param   list<mixed>|null $args  Hook arguments, or null for any arguments.
	 * @param   string           $group Action group.
	 *
	 * @return  bool
	 */
	function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
		a8csp_bgte_test_record_as_call( 'as_has_scheduled_action', array( $hook, $args, $group ) );

		$result = a8csp_bgte_test_scripted_as_result( 'as_has_scheduled_action', false );
		if ( ! \is_bool( $result ) ) {
			throw new \UnexpectedValueException( 'Script as_has_scheduled_action with a boolean result.' );
		}

		return $result;
	}
}

<?php declare( strict_types=1 );

/**
 * Recording action, filter, and diagnostic stubs for Unit tests outside WordPress.
 * Registration stubs append each hook name to the
 * `$GLOBALS['a8csp_bgje_test_hooks']` ledger so tests can assert which hooks a boot registered.
 * Action registrations retain their callback configuration, while fired actions retain their arguments.
 * Incorrect-use reports retain the function name, corrective message, and introduced version.
 * The `function_exists()` guards keep this file inert wherever WordPress is loaded.
 *
 * @package A8C\SpecialProjects\BackgroundJobsEngine
 */

if ( ! \function_exists( 'did_action' ) ) {
	/**
	 * Returns the scripted fire count for a WordPress action.
	 *
	 * @param   string $hook_name Action name.
	 *
	 * @return  int
	 */
	function did_action( $hook_name ) {
		/** @var array<string, int> $counts */
		$counts = $GLOBALS['a8csp_bgje_test_did_actions'] ?? array();

		return $counts[ $hook_name ] ?? 0;
	}
}

if ( ! \function_exists( 'doing_action' ) ) {
	/**
	 * Returns whether an action is scripted as currently executing.
	 *
	 * @param   string $hook_name Action name.
	 *
	 * @return  bool
	 */
	function doing_action( $hook_name ) {
		/** @var list<string> $doing */
		$doing = $GLOBALS['a8csp_bgje_test_doing_actions'] ?? array();

		return \in_array( $hook_name, $doing, true );
	}
}

if ( ! \function_exists( '_doing_it_wrong' ) ) {
	/**
	 * Records a WordPress incorrect-use report.
	 *
	 * @param   string $function_name Function that was called incorrectly.
	 * @param   string $message       Corrective message.
	 * @param   string $version       Version that introduced the correction.
	 *
	 * @return  void
	 */
	function _doing_it_wrong( $function_name, $message, $version ) {
		$calls = $GLOBALS['a8csp_bgje_test_doing_it_wrong_calls'] ?? array();
		if ( ! \is_array( $calls ) ) {
			throw new \UnexpectedValueException( 'Initialize the incorrect-use test ledger as an array.' );
		}

		$calls[] = array(
			'function_name' => $function_name,
			'message'       => $message,
			'version'       => $version,
		);

		$GLOBALS['a8csp_bgje_test_doing_it_wrong_calls'] = $calls;
	}
}

if ( ! \function_exists( 'add_action' ) ) {
	/**
	 * Records an action registration in the test ledger.
	 *
	 * @param   string   $hook_name     The action hook name.
	 * @param   callable $callback      The callback (recorded but never invoked).
	 * @param   int      $priority      The priority.
	 * @param   int      $accepted_args The accepted argument count.
	 *
	 * @return  true
	 */
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		$hooks                = $GLOBALS['a8csp_bgje_test_hooks'] ?? array();
		$action_registrations = $GLOBALS['a8csp_bgje_test_action_registrations'] ?? array();

		if ( ! \is_array( $hooks ) ) {
			throw new \UnexpectedValueException( 'Initialize the test hook ledger as an array before registering an action.' );
		}

		if ( ! \is_array( $action_registrations ) ) {
			throw new \UnexpectedValueException( 'Initialize the test action ledger as an array before registering an action.' );
		}

		$hooks[] = $hook_name;

		$action_registrations[] = array(
			'hook_name'     => $hook_name,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);

		$GLOBALS['a8csp_bgje_test_hooks']                = $hooks;
		$GLOBALS['a8csp_bgje_test_action_registrations'] = $action_registrations;

		return true;
	}
}

if ( ! \function_exists( 'add_filter' ) ) {
	/**
	 * Records a filter registration and its callback configuration in the test ledgers.
	 *
	 * @template HookName of string
	 *
	 * @param   HookName $hook_name     The filter hook name.
	 * @param   callable $callback      The callback (recorded but never invoked).
	 * @param   int      $priority      The priority.
	 * @param   int      $accepted_args The accepted argument count.
	 *
	 * @phpstan-param (
	 *     HookName is 'update_plugins_github.com'
	 *         ? callable(false|array<string, mixed>, array{Version: string, TextDomain: string}, string): (false|array<string, mixed>)
	 *         : callable
	 * ) $callback
	 *
	 * @return  true
	 */
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		$hooks                = $GLOBALS['a8csp_bgje_test_hooks'] ?? array();
		$filter_registrations = $GLOBALS['a8csp_bgje_test_filter_registrations'] ?? array();

		if ( ! \is_array( $hooks ) ) {
			throw new \UnexpectedValueException( 'Initialize the test hook ledger as an array before registering a filter.' );
		}

		if ( ! \is_array( $filter_registrations ) ) {
			throw new \UnexpectedValueException( 'Initialize the test filter ledger as an array before registering a filter.' );
		}

		$hooks[] = $hook_name;

		$filter_registrations[] = array(
			'hook_name'     => $hook_name,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);

		$GLOBALS['a8csp_bgje_test_hooks']                = $hooks;
		$GLOBALS['a8csp_bgje_test_filter_registrations'] = $filter_registrations;

		$registration_callbacks = $GLOBALS['a8csp_bgje_test_filter_registration_callbacks'] ?? array();
		if ( ! \is_array( $registration_callbacks ) ) {
			throw new \UnexpectedValueException( 'Initialize the test filter-registration callback map as an array.' );
		}
		$registration_callback = $registration_callbacks[ $hook_name ] ?? null;
		if ( \is_callable( $registration_callback ) ) {
			$registration_callback();
		}

		return true;
	}
}

if ( ! \function_exists( 'do_action' ) ) {
	/**
	 * Records a fired action and its arguments in the test ledger.
	 *
	 * @param   string $hook_name Hook name.
	 * @param   mixed  ...$args   Action arguments.
	 *
	 * @return  void
	 */
	function do_action( $hook_name, ...$args ) {
		$actions = $GLOBALS['a8csp_bgje_test_fired_actions'] ?? array();
		if ( ! \is_array( $actions ) ) {
			throw new \UnexpectedValueException( 'Initialize the fired-action test ledger as an array.' );
		}

		$actions[] = array(
			'hook_name' => $hook_name,
			'args'      => $args,
		);

		$GLOBALS['a8csp_bgje_test_fired_actions'] = $actions;

		$observers = $GLOBALS['a8csp_bgje_test_action_observers'] ?? array();
		if ( ! \is_array( $observers ) ) {
			throw new \UnexpectedValueException( 'Initialize the action-observer test ledger as an array.' );
		}
		foreach ( $observers as $observer ) {
			if ( ! \is_callable( $observer ) ) {
				throw new \UnexpectedValueException( 'Action observers must be callable.' );
			}

			$observer( $hook_name, $args );
		}

		$lifecycle_events = $GLOBALS['a8csp_bgje_test_lifecycle_events'] ?? null;
		if ( \is_array( $lifecycle_events ) ) {
			$lifecycle_events[] = array(
				'type'      => 'action',
				'hook_name' => $hook_name,
				'args'      => $args,
			);

			$GLOBALS['a8csp_bgje_test_lifecycle_events'] = $lifecycle_events;
		}

		$callbacks = $GLOBALS['a8csp_bgje_test_action_callbacks'] ?? array();
		if ( ! \is_array( $callbacks ) ) {
			throw new \UnexpectedValueException( 'Initialize the action-callback test map as an array.' );
		}

		$callback = $callbacks[ $hook_name ] ?? null;
		if ( \is_callable( $callback ) ) {
			$callback( ...$args );
		}

		$throwables = $GLOBALS['a8csp_bgje_test_action_throwables'] ?? array();
		if ( ! \is_array( $throwables ) ) {
			throw new \UnexpectedValueException( 'Initialize the action-throwable test map as an array.' );
		}

		$throwable = $throwables[ $hook_name ] ?? null;
		if ( $throwable instanceof \Throwable ) {
			throw $throwable;
		}
	}
}

if ( ! \function_exists( 'apply_filters' ) ) {
	/**
	 * Returns a scripted filter value or applies a scripted callback.
	 *
	 * @param   string $hook_name Hook name.
	 * @param   mixed  $value     Value entering the filter.
	 * @param   mixed  ...$args   Additional filter arguments.
	 *
	 * @return  mixed
	 */
	function apply_filters( $hook_name, $value, ...$args ) {
		/** @var array<string, mixed> $filter_values */
		$filter_values = $GLOBALS['a8csp_bgje_test_filter_values'] ?? array();
		if ( \array_key_exists( $hook_name, $filter_values ) ) {
			$filter = $filter_values[ $hook_name ];

			return \is_callable( $filter ) ? $filter( $value, ...$args ) : $filter;
		}

		$registrations = $GLOBALS['a8csp_bgje_test_filter_registrations'] ?? null;
		if ( ! \is_array( $registrations ) ) {
			throw new \UnexpectedValueException( 'Initialize the test filter ledger before applying a filter.' );
		}
		\usort(
			$registrations,
			static function ( mixed $left, mixed $right ): int {
				$left_priority  = \is_array( $left ) && \is_int( $left['priority'] ?? null ) ? $left['priority'] : 10;
				$right_priority = \is_array( $right ) && \is_int( $right['priority'] ?? null ) ? $right['priority'] : 10;

				return $left_priority <=> $right_priority;
			}
		);
		foreach ( $registrations as $registration ) {
			if ( ! \is_array( $registration ) ) {
				throw new \UnexpectedValueException( 'The test filter ledger contains a malformed entry.' );
			}
			$callback = $registration['callback'] ?? null;
			if ( ( $registration['hook_name'] ?? null ) !== $hook_name || ! \is_callable( $callback ) ) {
				continue;
			}

			$accepted = $registration['accepted_args'] ?? 1;
			$value    = $callback( ...\array_slice( array( $value, ...$args ), 0, \is_int( $accepted ) ? $accepted : 1 ) );
		}

		return $value;
	}
}

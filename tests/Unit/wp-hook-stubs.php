<?php declare( strict_types=1 );

/**
 * Recording action and filter stubs for Unit tests that exercise hooks outside WordPress.
 * Registration stubs append each hook name to the
 * `$GLOBALS['a8csp_bgte_test_hooks']` ledger so tests can assert which hooks a boot registered.
 * Action registrations retain their callback configuration, while fired actions retain their arguments.
 * The `function_exists()` guards keep this file inert wherever WordPress is loaded.
 *
 * @package A8C\SpecialProjects\BackgroundTasksEngine
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
		$counts = $GLOBALS['a8csp_bgte_test_did_actions'] ?? array();

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
		$doing = $GLOBALS['a8csp_bgte_test_doing_actions'] ?? array();

		return \in_array( $hook_name, $doing, true );
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
		$hooks                = $GLOBALS['a8csp_bgte_test_hooks'] ?? array();
		$action_registrations = $GLOBALS['a8csp_bgte_test_action_registrations'] ?? array();

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

		$GLOBALS['a8csp_bgte_test_hooks']                = $hooks;
		$GLOBALS['a8csp_bgte_test_action_registrations'] = $action_registrations;

		return true;
	}
}

if ( ! \function_exists( 'add_filter' ) ) {
	/**
	 * Records a filter registration and its callback configuration in the test ledgers.
	 *
	 * @param   string   $hook_name     The filter hook name.
	 * @param   callable $callback      The callback (recorded but never invoked).
	 * @param   int      $priority      The priority.
	 * @param   int      $accepted_args The accepted argument count.
	 *
	 * @return  true
	 */
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		$hooks                = $GLOBALS['a8csp_bgte_test_hooks'] ?? array();
		$filter_registrations = $GLOBALS['a8csp_bgte_test_filter_registrations'] ?? array();

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

		$GLOBALS['a8csp_bgte_test_hooks']                = $hooks;
		$GLOBALS['a8csp_bgte_test_filter_registrations'] = $filter_registrations;

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
		$actions = $GLOBALS['a8csp_bgte_test_fired_actions'] ?? array();
		if ( ! \is_array( $actions ) ) {
			throw new \UnexpectedValueException( 'Initialize the fired-action test ledger as an array.' );
		}

		$actions[] = array(
			'hook_name' => $hook_name,
			'args'      => $args,
		);

		$GLOBALS['a8csp_bgte_test_fired_actions'] = $actions;

		$lifecycle_events = $GLOBALS['a8csp_bgte_test_lifecycle_events'] ?? null;
		if ( \is_array( $lifecycle_events ) ) {
			$lifecycle_events[] = array(
				'type'      => 'action',
				'hook_name' => $hook_name,
				'args'      => $args,
			);

			$GLOBALS['a8csp_bgte_test_lifecycle_events'] = $lifecycle_events;
		}

		$callbacks = $GLOBALS['a8csp_bgte_test_action_callbacks'] ?? array();
		if ( ! \is_array( $callbacks ) ) {
			throw new \UnexpectedValueException( 'Initialize the action-callback test map as an array.' );
		}

		$callback = $callbacks[ $hook_name ] ?? null;
		if ( \is_callable( $callback ) ) {
			$callback( ...$args );
		}

		$throwables = $GLOBALS['a8csp_bgte_test_action_throwables'] ?? array();
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
		$filter_values = $GLOBALS['a8csp_bgte_test_filter_values'] ?? array();
		if ( ! \array_key_exists( $hook_name, $filter_values ) ) {
			return $value;
		}

		$filter = $filter_values[ $hook_name ];

		return \is_callable( $filter ) ? $filter( $value, ...$args ) : $filter;
	}
}

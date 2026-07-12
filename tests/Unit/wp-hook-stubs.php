<?php declare( strict_types=1 );

/**
 * Recording `add_action()` and `add_filter()` stubs for Unit tests that boot the real component
 * list outside WordPress. Each stub appends its hook name to the
 * `$GLOBALS['a8csp_bgte_test_hooks']` ledger so tests can assert which hooks a boot registered.
 * Action registrations also retain their callback configuration for component-level assertions.
 * The `function_exists()` guards keep this file inert wherever WordPress is loaded.
 *
 * @since   1.0.0
 * @version 1.0.0
 * @package A8C\SpecialProjects\BackgroundTasksEngine
 */

if ( ! \function_exists( 'add_action' ) ) {
	/**
	 * Records an action registration in the test ledger.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
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
